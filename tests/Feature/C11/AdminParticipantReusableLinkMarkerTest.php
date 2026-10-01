<?php

declare(strict_types=1);

/**
 * The admin participant list and detail carry the reusable link origin
 * (reusable-interview-links, slice B4).
 *
 * `reusable_link` is ALWAYS present on both resources: `null` for a participant
 * that did not come from a reusable link (SSO exchange, M2M, operator entry
 * link), and for a visitor an object with exactly two keys, the link's public id
 * (`rlk_...`) and its label. Nothing else about the link is exposed: not its
 * internal id, prefix, language, counters, hash, token or any URL.
 *
 * Two properties are guarded as hard as the shape:
 *   - the list resolves the origin through one eager load, never a query per row;
 *   - the marker is ADMIN-ONLY. The public `/v1` interview, the M2M participant,
 *     the candidate session and token, exports and webhook payloads never carry
 *     it. Those guards pass at write time and are mutation-checked.
 *
 * REQ: Participant List And Detail Carry The Reusable Link Origin,
 *      The Reusable Link Origin Is Classified And Typed
 *      (sdd/reusable-interview-links/spec/admin-read-api)
 */

use App\Enums\ExportFormat;
use App\Jobs\PublicApi\GenerateExportJob;
use App\Models\ApiClient;
use App\Models\Export;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\ReusableInterviewLink;
use App\Services\ApiKeyGenerator;
use App\Services\Webhooks\EvaluationPayloadAssembler;
use App\Services\Webhooks\ProgressPayloadAssembler;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\PublicApi\PublicId;
use App\Support\Tenancy\TenantContextScope;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Helpers\PublicApi\Step6Fixtures;
use Tests\Helpers\ReusableLinkFixtures as Fx;

/**
 * A label no other fixture in the suite can contain, so a hit in a body is
 * always attributable to the marker.
 */
function rlMarkerLabel(): string
{
    return 'Milan fair stand 7f3a';
}

/**
 * A visitor of `$link`: a participant carrying the origin marker.
 */
function rlMarkerVisitor(ReusableInterviewLink $link): Participant
{
    return Participant::factory()->fromReusableLink($link)->create()->refresh();
}

/**
 * The list row and the detail object of one participant, read as `$token`.
 *
 * @return array{row: array<string, mixed>|null, detail: array<string, mixed>}
 */
function rlMarkerRead(mixed $test, string $token, Participant $participant): array
{
    resetAuthGuardState();
    $list = $test->flushHeaders()->withToken($token)->getJson('/api/participants')->assertOk();

    resetAuthGuardState();
    $detail = $test->flushHeaders()->withToken($token)->getJson("/api/participants/{$participant->id}")->assertOk();

    return [
        'row' => collect($list->json('data'))->firstWhere('id', $participant->id),
        'detail' => $detail->json('data'),
    ];
}

/**
 * Asserts that a serialized surface says nothing about the link, one needle at
 * a time (a second argument to a negated `toContain` is another needle, not a
 * failure message, and would make the assertion unable to fail).
 */
function rlMarkerAssertNoLinkMention(string $body, ReusableInterviewLink $link): void
{
    expect($body)->not->toContain('reusable_link');
    expect($body)->not->toContain('reusable_interview_link_id');
    expect($body)->not->toContain(PublicId::encode($link));
    expect($body)->not->toContain((string) $link->public_id);
    expect($body)->not->toContain((string) $link->label);
}

// ─── The shape ───────────────────────────────────────────────────────────────

test('an ordinary participant carries reusable_link as null, always present, on list and detail', function (): void {
    $org = Organization::factory()->create();
    $project = Fx::project($org);
    $ordinary = Participant::factory()->forProject($project)->create();

    ['row' => $row, 'detail' => $detail] = rlMarkerRead($this, authTokenForRole($org, 'admin'), $ordinary);

    expect(array_key_exists('reusable_link', $row))->toBeTrue();
    expect($row['reusable_link'])->toBeNull();
    expect(array_key_exists('reusable_link', $detail))->toBeTrue();
    expect($detail['reusable_link'])->toBeNull();
});

test('a visitor carries the link public id and label on list and detail', function (): void {
    $org = Organization::factory()->create();
    $link = Fx::link(Fx::project($org), ['label' => rlMarkerLabel()]);
    $visitor = rlMarkerVisitor($link);

    ['row' => $row, 'detail' => $detail] = rlMarkerRead($this, authTokenForRole($org, 'admin'), $visitor);

    $expected = ['id' => PublicId::encode($link), 'label' => rlMarkerLabel()];
    expect($expected['id'])->toStartWith('rlk_');
    expect($row['reusable_link'])->toBe($expected);
    expect($detail['reusable_link'])->toBe($expected);
});

test('a label-less link carries a null label and still its public id', function (): void {
    $org = Organization::factory()->create();
    $link = Fx::link(Fx::project($org), ['label' => null]);
    $visitor = rlMarkerVisitor($link);

    ['row' => $row, 'detail' => $detail] = rlMarkerRead($this, authTokenForRole($org, 'admin'), $visitor);

    expect($row['reusable_link'])->toBe(['id' => PublicId::encode($link), 'label' => null]);
    expect($detail['reusable_link'])->toBe(['id' => PublicId::encode($link), 'label' => null]);
});

test('the marker has exactly the keys id and label, whatever else the link holds', function (): void {
    $org = Organization::factory()->create();
    $link = Fx::link(Fx::project($org), [
        'label' => rlMarkerLabel(),
        'uses_count' => 7,
        'last_used_at' => now(),
        'disabled_at' => now(),
    ]);
    $visitor = rlMarkerVisitor($link);

    ['row' => $row, 'detail' => $detail] = rlMarkerRead($this, authTokenForRole($org, 'admin'), $visitor);

    foreach ([$row, $detail] as $payload) {
        $keys = array_keys($payload['reusable_link']);
        sort($keys);
        expect($keys)->toBe(['id', 'label']);
    }

    // A disabled, used link is still the visitor's origin: the marker states
    // where the person came from, not whether the link works today.
    expect($row['reusable_link']['id'])->toBe(PublicId::encode($link));
});

test('the marker never discloses the token, its hash, its prefix or an internal id', function (): void {
    $org = Organization::factory()->create();
    $link = Fx::link(Fx::project($org), ['label' => rlMarkerLabel()]);
    $visitor = rlMarkerVisitor($link);
    $token = authTokenForRole($org, 'admin');

    resetAuthGuardState();
    $list = (string) $this->flushHeaders()->withToken($token)->getJson('/api/participants')->assertOk()->getContent();
    resetAuthGuardState();
    $detail = (string) $this->flushHeaders()->withToken($token)->getJson("/api/participants/{$visitor->id}")->assertOk()->getContent();

    foreach ([$list, $detail] as $body) {
        expect($body)->not->toContain($link->token_hash);
        expect($body)->not->toContain($link->token_prefix);
        expect($body)->not->toContain('beai_rl_');
        expect($body)->not->toContain('token_hash');
        expect($body)->not->toContain('reusable_interview_link_id');
    }
});

test('every role that may read participants sees the same marker on list and detail', function (string $role): void {
    $org = Organization::factory()->create();
    $link = Fx::link(Fx::project($org), ['label' => rlMarkerLabel()]);
    $visitor = rlMarkerVisitor($link);

    ['row' => $row, 'detail' => $detail] = rlMarkerRead($this, authTokenForRole($org, $role), $visitor);

    $expected = ['id' => PublicId::encode($link), 'label' => rlMarkerLabel()];
    expect($row['reusable_link'])->toBe($expected);
    expect($detail['reusable_link'])->toBe($expected);
})->with(['admin', 'operator', 'viewer']);

test('a visitor whose link row was deleted degrades to null', function (): void {
    $org = Organization::factory()->create();
    $link = Fx::link(Fx::project($org), ['label' => rlMarkerLabel()]);
    $visitor = rlMarkerVisitor($link);

    DB::table('reusable_interview_links')->where('id', $link->id)->delete();

    ['row' => $row, 'detail' => $detail] = rlMarkerRead($this, authTokenForRole($org, 'admin'), $visitor);

    // The participant survived (the FK is ON DELETE SET NULL) and reads as an
    // ordinary one rather than failing the page.
    expect($visitor->fresh()->reusable_interview_link_id)->toBeNull();
    expect($row['reusable_link'])->toBeNull();
    expect($detail['reusable_link'])->toBeNull();
});

// ─── Tenant isolation ────────────────────────────────────────────────────────

test('a marker never resolves to another organization link, even when the key points at it', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $foreignLink = Fx::link(Fx::project($orgB), ['label' => 'Other tenant label']);

    $own = Participant::factory()->forProject(Fx::project($orgA))->create();
    // Not reachable through the API (the column is never mass-assignable); a
    // corrupted or hand-edited row is exactly the case this guards.
    $own->forceFill(['reusable_interview_link_id' => $foreignLink->id])->save();

    $token = authTokenForRole($orgA, 'admin');
    ['row' => $row, 'detail' => $detail] = rlMarkerRead($this, $token, $own);

    expect($row['reusable_link'])->toBeNull();
    expect($detail['reusable_link'])->toBeNull();

    resetAuthGuardState();
    $body = (string) $this->flushHeaders()->withToken($token)->getJson('/api/participants')->assertOk()->getContent();
    expect($body)->not->toContain('Other tenant label');
    expect($body)->not->toContain(PublicId::encode($foreignLink));
});

test('the detail of a visitor of another organization is not found and discloses nothing', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $foreignLink = Fx::link(Fx::project($orgB), ['label' => 'Other tenant label']);
    $foreignVisitor = rlMarkerVisitor($foreignLink);

    $token = authTokenForRole($orgA, 'admin');

    $response = $this->withToken($token)->getJson("/api/participants/{$foreignVisitor->id}")->assertNotFound();
    expect((string) $response->getContent())->not->toContain('Other tenant label');
    expect((string) $response->getContent())->not->toContain('reusable_link');

    resetAuthGuardState();
    $list = (string) $this->flushHeaders()->withToken($token)->getJson('/api/participants')->assertOk()->getContent();
    expect($list)->not->toContain('Other tenant label');
});

// ─── No query per row ────────────────────────────────────────────────────────

test('exposing the marker adds no query per row to the list', function (): void {
    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'admin');
    $project = Fx::project($org);

    rlMarkerVisitor(Fx::link($project, ['label' => 'First']));

    // Warm-up: Spatie's role lookups are cached per process after the first
    // authenticated request, which would otherwise read as fewer queries on the
    // second call for reasons unrelated to the number of rows.
    $queryCount = function () use ($token): int {
        resetAuthGuardState();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->flushHeaders()->withToken($token)->getJson('/api/participants')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };
    $queryCount();

    $oneVisitor = $queryCount();

    // Nine more rows: six more visitors, each of a DIFFERENT link so that a lazy
    // load per row could not be served from one cached relation, and three
    // ordinary rows.
    foreach (range(1, 6) as $n) {
        rlMarkerVisitor(Fx::link($project, ['label' => "Link {$n}"]));
    }
    Participant::factory()->forProject($project)->count(3)->create();

    $tenRows = $queryCount();

    expect($tenRows)->toBe($oneVisitor, 'Query count must not grow with row count: resolving the link per row would be an N+1.');
});

// ─── The runtime path ────────────────────────────────────────────────────────

test('a participant created by a real redemption reads back with its origin', function (): void {
    config([
        'reusable_links.redeem.per_ip_per_minute' => 100000,
        'reusable_links.redeem.per_link_per_hour' => 100000,
    ]);
    $world = Fx::redeemable(linkAttributes: ['label' => rlMarkerLabel()]);

    $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($world['token']))->assertOk();
    [$visitor] = Fx::visitorsOf($world['link']);

    ['row' => $row, 'detail' => $detail] = rlMarkerRead($this, authTokenForRole($world['org'], 'admin'), $visitor);

    $expected = ['id' => PublicId::encode($world['link']), 'label' => rlMarkerLabel()];
    expect($visitor->candidate_ref)->toStartWith('rlv_');
    expect($row['reusable_link'])->toBe($expected);
    expect($detail['reusable_link'])->toBe($expected);
});

// ─── The marker is admin-only (GUARDS) ───────────────────────────────────────

/**
 * An organization with a live-key client and a visitor of a labelled link, on a
 * project the interview fixtures can read.
 *
 * @return array{org: Organization, project: Project, link: ReusableInterviewLink, visitor: Participant, key: string}
 */
function rlMarkerPublicWorld(): array
{
    ['org' => $org, 'key' => $key] = Step6Fixtures::orgWithScopedKey(['interviews:read', 'participants:read', 'exports:write']);
    $project = Step6Fixtures::project($org);
    $link = Fx::link($project, ['label' => rlMarkerLabel()]);
    $visitor = Step6Fixtures::participantWithTranscript($org, $project, 'in_valutazione');
    $visitor->forceFill(['reusable_interview_link_id' => $link->id])->save();

    return ['org' => $org, 'project' => $project, 'link' => $link, 'visitor' => $visitor->refresh(), 'key' => $key];
}

test('the public /v1 interview list and show never carry the marker', function (): void {
    $world = rlMarkerPublicWorld();
    $id = PublicId::encode($world['visitor']);

    $list = $this->withToken($world['key'])->getJson('/api/v1/interviews')->assertOk();
    $show = $this->withToken($world['key'])->getJson("/api/v1/interviews/{$id}")->assertOk();

    // The control: both bodies really describe the visitor.
    expect((string) $list->getContent())->toContain($world['visitor']->candidate_ref);
    expect((string) $show->getContent())->toContain($world['visitor']->candidate_ref);

    rlMarkerAssertNoLinkMention((string) $list->getContent(), $world['link']);
    rlMarkerAssertNoLinkMention((string) $show->getContent(), $world['link']);
});

test('the M2M participant show and list never carry the marker', function (): void {
    $world = rlMarkerPublicWorld();
    ApiClient::factory()->withRawKey($m2mKey = ApiKeyGenerator::generate())->create([
        'organization_id' => $world['org']->id,
        'is_active' => true,
        'abilities' => ['participants:read'],
    ]);

    $show = $this->withToken($m2mKey)->getJson("/api/m2m/participants/{$world['visitor']->id}")->assertOk();
    $list = $this->withToken($m2mKey)->getJson('/api/m2m/participants')->assertOk();

    expect((string) $show->getContent())->toContain($world['visitor']->candidate_ref);
    expect((string) $list->getContent())->toContain($world['visitor']->candidate_ref);

    rlMarkerAssertNoLinkMention((string) $show->getContent(), $world['link']);
    rlMarkerAssertNoLinkMention((string) $list->getContent(), $world['link']);
});

test('the candidate session and the candidate token claims never carry the marker', function (): void {
    $world = rlMarkerPublicWorld();
    app(TenantResolver::class)->setOrgId($world['org']->id);

    $jwt = CandidateTokenFactory::mintCandidateToken($world['visitor']);
    $claims = (string) base64_decode(strtr(explode('.', $jwt)[1], '-_', '+/'));

    resetAuthGuardState();
    $session = $this->flushHeaders()->withToken($jwt)->getJson('/api/candidate/session')->assertOk();

    // The controls: the claims name the participant, the session returns it.
    expect($claims)->toContain($world['visitor']->candidate_ref);
    expect((string) $session->getContent())->toContain($world['visitor']->candidate_ref);

    rlMarkerAssertNoLinkMention($claims, $world['link']);
    rlMarkerAssertNoLinkMention((string) $session->getContent(), $world['link']);
});

test('an export never carries the marker, in JSONL or CSV', function (ExportFormat $format): void {
    Storage::fake();
    $world = rlMarkerPublicWorld();

    $export = TenantContextScope::runFor($world['org']->id, fn () => Export::factory()->create(['format' => $format]));
    GenerateExportJob::dispatch($export->id);

    $fresh = TenantContextScope::runFor($world['org']->id, fn () => Export::find($export->id));
    $content = (string) Storage::get($fresh->object_key);

    expect($content)->toContain($world['visitor']->candidate_ref);
    rlMarkerAssertNoLinkMention($content, $world['link']);
})->with([ExportFormat::Jsonl, ExportFormat::Csv]);

test('webhook payloads never carry the marker', function (): void {
    $world = rlMarkerPublicWorld();
    $organizationId = $world['org']->id;
    $participantId = $world['visitor']->id;

    $progress = json_encode(
        (new ProgressPayloadAssembler)->assemble($participantId, $organizationId, 'dlv_marker_test'),
        JSON_THROW_ON_ERROR,
    );
    $evaluation = json_encode(
        app(EvaluationPayloadAssembler::class)->assembleForFailedParticipant($participantId, $organizationId, 'dlv_marker_test'),
        JSON_THROW_ON_ERROR,
    );

    foreach ([$progress, $evaluation] as $payload) {
        expect($payload)->toContain($world['visitor']->candidate_ref);
        rlMarkerAssertNoLinkMention($payload, $world['link']);
    }
});
