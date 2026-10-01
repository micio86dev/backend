<?php

declare(strict_types=1);

/**
 * POST /api/projects/{project}/reusable-links (reusable-interview-links, B2).
 *
 * An admin or operator creates a link bound server-side to one organisation and
 * one project. The response is the ONLY place the raw token ever appears, inside
 * `entry_url`, after the `#`: only its SHA-256 hash and a 16-character display
 * prefix are persisted. These tests read the stored row and the audit trail as
 * well as the response, because a secret that is absent from the response but
 * present in a column or in the trail is the failure this feature exists to
 * prevent.
 *
 * REQ: Creating A Link, The Link URL Is Composed From The Candidate App Origin
 *      With The Token In The Fragment, Token Format Generation And Hash-Only
 *      Storage (sdd/reusable-interview-links/spec/reusable-interview-links)
 *      Reusable Link Mutations Are Audited (sdd/reusable-interview-links/spec/audit-log)
 */

use App\Actions\ReusableLinks\CreateReusableInterviewLink;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ReusableInterviewLink;
use App\Models\User;
use App\Services\ReusableLinkTokenGenerator;
use App\Support\PublicApi\PublicId;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\Helpers\ReusableLinkFixtures as Fx;

function reusableLinkCreateUrl(Project $project): string
{
    return "/api/projects/{$project->id}/reusable-links";
}

beforeEach(function (): void {
    Fx::configureOrigin();
});

// ─── Who may create, and what comes back ─────────────────────────────────────

test('an admin and an operator each create a link and get the data plus the one-time URL', function (string $role): void {
    $org = Organization::factory()->create();
    $project = Fx::project($org);
    ['user' => $user, 'token' => $jwt] = authUserAndTokenForRole($org, $role);

    $response = $this->withToken($jwt)
        ->postJson(reusableLinkCreateUrl($project), ['label' => 'Milan fair stand'])
        ->assertCreated();

    $body = $response->json();

    expect(array_keys($body))->toEqualCanonicalizing(['data', 'entry_url']);
    expect($body['data']['id'])->toMatch('/^rlk_[0-9A-HJKMNP-TV-Z]{26}$/');
    expect($body['data']['label'])->toBe('Milan fair stand');
    expect($body['data']['status'])->toBe('active');
    expect($body['data']['uses_count'])->toBe(0);
    expect($body['data']['last_used_at'])->toBeNull();
    expect($body['data']['disabled_at'])->toBeNull();
    expect($body['data']['token_prefix'])->toHaveLength(16)->toStartWith('beai_rl_');
    expect($body['data']['created_by'])->toBe(['name' => $user->name]);

    $rows = Fx::rowsOf($project);
    expect($rows)->toHaveCount(1);
    expect($rows[0]->created_by)->toBe($user->id);
    expect($rows[0]->organization_id)->toBe($org->id);
    expect($rows[0]->project_id)->toBe($project->id);
})->with(['admin', 'operator']);

test('the URL carries the token exactly once, after the hash, and nothing else in the response is secret', function (): void {
    $org = Organization::factory()->create();
    $project = Fx::project($org);

    $body = $this->withToken(authTokenForRole($org, 'admin'))
        ->postJson(reusableLinkCreateUrl($project), [])
        ->assertCreated()
        ->json();

    $token = Fx::tokenOf($body['entry_url']);

    expect($token)->toMatch(ReusableLinkTokenGenerator::FORMAT);
    expect(substr_count((string) json_encode($body), $token))->toBe(1);
    expect(parse_url($body['entry_url'], PHP_URL_PATH))->not->toContain('beai_rl_');
    expect(parse_url($body['entry_url'], PHP_URL_QUERY))->toBeNull();

    // No key at any depth names a bare token, the hash, or an expiry.
    $keys = Fx::keysAtAnyDepth($body);
    foreach (['token', 'link_token', 'token_hash', 'expires_at', 'ttl', 'expires_in'] as $forbidden) {
        expect($keys)->not->toContain($forbidden);
    }
});

// ─── Hash-only persistence, server-side binding ──────────────────────────────

test('only the hash and the visible prefix are persisted, and the hash is the SHA-256 of the URL token', function (): void {
    $org = Organization::factory()->create();
    $project = Fx::project($org);

    $body = $this->withToken(authTokenForRole($org, 'admin'))
        ->postJson(reusableLinkCreateUrl($project), ['label' => 'Stand'])
        ->assertCreated()
        ->json();

    $token = Fx::tokenOf($body['entry_url']);
    $row = Fx::rowsOf($project)[0];

    expect($row->token_hash)->toBe(hash('sha256', $token));
    expect($row->token_prefix)->toBe(substr($token, 0, 16));

    // The raw token is in no column of the row and nowhere in the audit trail.
    $storedRow = (string) json_encode(DB::table('reusable_interview_links')->get());
    $storedAudit = (string) json_encode(DB::table('audit_logs')->get());

    expect($storedRow)->not->toContain($token);
    expect($storedAudit)->not->toContain($token);
    expect($storedAudit)->not->toContain($row->token_hash);
});

test('the organisation, project and counters come from the route and the server, never from the body', function (): void {
    $org = Organization::factory()->create();
    $otherOrg = Organization::factory()->create();
    $project = Fx::project($org);
    $foreignProject = Fx::project($otherOrg);

    $body = $this->withToken(authTokenForRole($org, 'admin'))
        ->postJson(reusableLinkCreateUrl($project), [
            'organization_id' => $otherOrg->id,
            'project_id' => $foreignProject->id,
            'uses_count' => 99,
            'token_hash' => str_repeat('a', 64),
            'token_prefix' => 'beai_rl_forged123',
            'created_by' => 12345,
        ])
        ->assertCreated()
        ->json();

    $row = Fx::rowsOf($project)[0];

    expect($row->organization_id)->toBe($org->id);
    expect($row->project_id)->toBe($project->id);
    expect($row->uses_count)->toBe(0);
    expect($row->token_hash)->toBe(hash('sha256', Fx::tokenOf($body['entry_url'])));
    expect($row->token_prefix)->not->toBe('beai_rl_forged123');
    expect($row->created_by)->not->toBe(12345);
    expect(Fx::rowsOf($foreignProject))->toBe([]);
});

test('two identical requests create two distinct links with distinct tokens', function (): void {
    $org = Organization::factory()->create();
    $project = Fx::project($org);
    $jwt = authTokenForRole($org, 'admin');

    $first = $this->withToken($jwt)->postJson(reusableLinkCreateUrl($project), ['label' => 'Same'])->assertCreated()->json();
    $second = $this->withToken($jwt)->postJson(reusableLinkCreateUrl($project), ['label' => 'Same'])->assertCreated()->json();

    expect($second['data']['id'])->not->toBe($first['data']['id']);
    expect(Fx::tokenOf($second['entry_url']))->not->toBe(Fx::tokenOf($first['entry_url']));

    $hashes = array_map(static fn (ReusableInterviewLink $link): string => $link->token_hash, Fx::rowsOf($project));
    expect($hashes)->toHaveCount(2)->and(array_unique($hashes))->toHaveCount(2);
});

// ─── Label ───────────────────────────────────────────────────────────────────

test('a label is trimmed, a blank label is stored as NULL, and 120 characters is the ceiling', function (?string $submitted, ?string $stored): void {
    $org = Organization::factory()->create();
    $project = Fx::project($org);

    $this->withToken(authTokenForRole($org, 'admin'))
        ->postJson(reusableLinkCreateUrl($project), ['label' => $submitted])
        ->assertCreated()
        ->assertJsonPath('data.label', $stored);

    expect(Fx::rowsOf($project)[0]->label)->toBe($stored);
})->with([
    'padded' => ['  Milan fair stand  ', 'Milan fair stand'],
    'whitespace only' => ['   ', null],
    'empty string' => ['', null],
    'null' => [null, null],
    'exactly 120 characters' => [str_repeat('é', 120), str_repeat('é', 120)],
]);

test('a label over 120 characters or of the wrong type is a 422 naming label and stores nothing', function (mixed $submitted): void {
    $org = Organization::factory()->create();
    $project = Fx::project($org);

    $this->withToken(authTokenForRole($org, 'admin'))
        ->postJson(reusableLinkCreateUrl($project), ['label' => $submitted])
        ->assertStatus(422)
        ->assertJsonValidationErrors('label');

    expect(Fx::rowsOf($project))->toBe([]);
})->with([
    '121 characters' => [str_repeat('a', 121)],
    'an array' => [['x']],
    'an integer' => [123],
]);

// ─── Language and URL ────────────────────────────────────────────────────────

test('the link language is the project language at creation, frozen, and a lang key in the body is ignored', function (): void {
    $org = Organization::factory()->create();
    $project = Fx::project($org, ['language' => 'en']);
    $jwt = authTokenForRole($org, 'admin');

    $this->withToken($jwt)
        ->postJson(reusableLinkCreateUrl($project), ['lang' => 'it'])
        ->assertCreated();

    TenantContextScope::runFor($org->id, fn () => $project->forceFill(['language' => 'it'])->save());

    // The earlier link keeps its language; only a link created AFTER the change
    // takes the new one.
    $this->withToken($jwt)->postJson(reusableLinkCreateUrl($project), [])->assertCreated();

    $languages = array_map(static fn (ReusableInterviewLink $link): string => $link->lang, Fx::rowsOf($project));

    expect($languages)->toBe(['en', 'it']);
});

test('the URL carries the locale prefix for every language but the default', function (string $language, string $expectedPrefix): void {
    $org = Organization::factory()->create();
    $project = Fx::project($org, ['language' => $language]);

    $url = $this->withToken(authTokenForRole($org, 'admin'))
        ->postJson(reusableLinkCreateUrl($project), [])
        ->assertCreated()
        ->json('entry_url');

    expect($url)->toStartWith(Fx::CANDIDATE_ORIGIN.$expectedPrefix.'/interview/reusable#beai_rl_');
})->with([
    'default language it is unprefixed' => ['it', ''],
    'en is prefixed' => ['en', '/en'],
]);

test('an unset candidate app origin fails loud with a 500 and stores nothing', function (): void {
    config(['interview.candidate_app_url' => null]);
    config(['app.url' => 'https://api.example.com']);

    $org = Organization::factory()->create();
    $project = Fx::project($org);

    $this->withToken(authTokenForRole($org, 'admin'))
        ->postJson(reusableLinkCreateUrl($project), [])
        ->assertStatus(500);

    // No orphan link whose token was never disclosed, and no audit row for it.
    expect(Fx::totalRows())->toBe(0);
    expect(AuditLog::withoutGlobalScopes()->where('action', 'reusable_link.created')->count())->toBe(0);
});

// ─── Project state ───────────────────────────────────────────────────────────

test('a project that is not accessible refuses creation with 403 and stores nothing', function (string $state): void {
    $org = Organization::factory()->create();
    $attributes = match ($state) {
        'draft' => ['status' => 'draft'],
        'inactive' => ['status' => 'inactive'],
        'not yet live' => ['goes_live_at' => now()->addDay()],
        'past deadline' => ['deadline_at' => now()->subDay()],
    };
    $project = Fx::project($org, $attributes);

    $this->withToken(authTokenForRole($org, 'admin'))
        ->postJson(reusableLinkCreateUrl($project), [])
        ->assertForbidden()
        ->assertExactJson(['message' => 'entry_link_project_closed']);

    expect(Fx::totalRows())->toBe(0);
})->with(['draft', 'inactive', 'not yet live', 'past deadline']);

test('an open project that is not interviewable is refused with 422 and stores nothing', function (): void {
    $org = Organization::factory()->create();
    $project = Fx::project($org, interviewable: false);

    $this->withToken(authTokenForRole($org, 'admin'))
        ->postJson(reusableLinkCreateUrl($project), [])
        ->assertStatus(422)
        ->assertJsonPath('error', 'PROJECT_NOT_INTERVIEWABLE')
        ->assertJsonStructure(['competency_codes']);

    expect(Fx::totalRows())->toBe(0);
});

test('another organization project, a soft-deleted project and an unknown id are all 404 and store nothing', function (string $case): void {
    $org = Organization::factory()->create();
    $otherOrg = Organization::factory()->create();
    $jwt = authTokenForRole($org, 'admin');

    $id = match ($case) {
        'other organization' => Fx::project($otherOrg)->id,
        'soft-deleted' => (function () use ($org): int {
            $project = Fx::project($org);
            TenantContextScope::runFor($org->id, fn () => $project->delete());

            return $project->id;
        })(),
        'unknown' => 987654321,
    };

    $this->withToken($jwt)->postJson("/api/projects/{$id}/reusable-links", [])->assertNotFound();

    expect(Fx::totalRows())->toBe(0);
})->with(['other organization', 'soft-deleted', 'unknown']);

test('the action refuses to create outside its project tenant context and stores nothing', function (): void {
    $org = Organization::factory()->create();
    $otherOrg = Organization::factory()->create();
    $project = Fx::project($org);
    $actor = User::factory()->create(['organization_id' => $org->id]);

    // TenantScoped would stamp the OTHER organisation on the row, leaving a link
    // whose organisation and project disagree; the action refuses before writing.
    expect(fn () => TenantContextScope::runFor(
        $otherOrg->id,
        fn () => app(CreateReusableInterviewLink::class)->handle($project, $actor, null),
    ))->toThrow(LogicException::class);

    expect(Fx::totalRows())->toBe(0);
});

// ─── Audit ───────────────────────────────────────────────────────────────────

test('creating a link writes one audit row with the actor, the link and the public identifiers, never the token or the hash', function (): void {
    $org = Organization::factory()->create();
    $project = Fx::project($org);
    ['user' => $user, 'token' => $jwt] = authUserAndTokenForRole($org, 'admin');

    $body = $this->withToken($jwt)
        ->postJson(reusableLinkCreateUrl($project), ['label' => 'Milan fair stand'])
        ->assertCreated()
        ->json();

    $row = Fx::rowsOf($project)[0];
    $audit = AuditLog::withoutGlobalScopes()->where('action', 'reusable_link.created')->get();

    expect($audit)->toHaveCount(1);

    $entry = $audit[0];
    expect($entry->actor_id)->toBe($user->id);
    expect($entry->organization_id)->toBe($org->id);
    expect($entry->subject_type)->toBe('reusable_interview_link');
    expect($entry->subject_id)->toBe($row->id);
    expect($entry->before)->toBeNull();
    // jsonb does not keep key order, so compare the payload by key.
    $after = (array) $entry->after;
    ksort($after);
    expect($after)->toBe([
        'id' => PublicId::encode($row),
        'label' => 'Milan fair stand',
        'lang' => 'en',
        'project_id' => PublicId::encode($project),
        'token_prefix' => $row->token_prefix,
    ]);

    $encoded = (string) json_encode($entry->getAttributes());
    expect($encoded)->not->toContain(Fx::tokenOf($body['entry_url']));
    expect($encoded)->not->toContain($row->token_hash);
});

test('a failing audit write still yields the 201 with the URL and logs the failure', function (): void {
    $org = Organization::factory()->create();
    $project = Fx::project($org);
    $jwt = authTokenForRole($org, 'admin');

    Event::listen('eloquent.creating: '.AuditLog::class, function (): void {
        throw new RuntimeException('audit store is down');
    });
    Log::spy();

    $body = $this->withToken($jwt)
        ->postJson(reusableLinkCreateUrl($project), [])
        ->assertCreated()
        ->json();

    expect(Fx::tokenOf($body['entry_url']))->toMatch(ReusableLinkTokenGenerator::FORMAT);
    expect(Fx::rowsOf($project))->toHaveCount(1);
    Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => $message === 'audit.record.failed')->once();
});
