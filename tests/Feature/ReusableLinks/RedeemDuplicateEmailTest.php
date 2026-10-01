<?php

declare(strict_types=1);

/**
 * POST /api/reusable-links/redeem (reusable-link-visitor-identity, api-2): an
 * email that is already enrolled in the link's project is refused with 409.
 *
 * The identity is self-declared and unverified, so a redemption must never be
 * able to adopt somebody else's enrolment: a duplicate is a plain refusal, the
 * existing row is never resumed, upserted, touched or described, and no
 * credential is minted for it. The check is case-insensitive (the visitor path
 * stores lower case, while another path may have stored `Ana@Example.test`),
 * runs inside the locked transaction AFTER the disabled re-check and BEFORE any
 * write, and is backed by the `(project_id, email)` unique index for the cases a
 * link-row lock cannot serialise.
 *
 * The 409 is reachable only with a valid, enabled link on an open project and a
 * valid identity, so it cannot be used to probe tokens: every precedence below
 * is pinned.
 *
 * REQ: A Duplicate Email In The Same Project Is Refused With 409 And Never
 *      Resumed, Refused Redemptions Dispatch Nothing
 *      (sdd/reusable-link-visitor-identity/spec/reusable-interview-links and
 *      /spec/participant-sso)
 */

use App\Events\ParticipantCreated;
use App\Models\Participant;
use App\Models\Project;
use App\Models\ReusableInterviewLink;
use App\Models\WebhookDelivery;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Tests\Helpers\ReusableLinkFixtures as Fx;

const DUPLICATE_ENROLMENT_BODY = '{"message":"duplicate_enrolment"}';

beforeEach(function (): void {
    config([
        'reusable_links.redeem.per_ip_per_minute' => 1000,
        'reusable_links.redeem.per_link_per_hour' => 1000,
    ]);
});

/**
 * A participant already enrolled in `$project`, created through the model so it
 * carries the organisation, the public id and every default a real one has.
 *
 * @param  array<string, mixed>  $attributes
 */
function duplicateEmailEnrol(Project $project, string $email, array $attributes = []): Participant
{
    return TenantContextScope::runFor($project->organization_id, fn (): Participant => Participant::factory()
        ->forProject($project)
        ->create(array_merge(['email' => $email, 'display_name' => 'Existing Person'], $attributes)));
}

/**
 * The stored row, raw, past every scope.
 */
function duplicateEmailRow(Participant $participant): object
{
    return DB::table('participants')->where('id', $participant->id)->first();
}

// ─── The refusal ─────────────────────────────────────────────────────────────

test('a duplicate email, whatever its case, is a 409 with a machine code and writes nothing at all', function (): void {
    ['project' => $project, 'link' => $link, 'token' => $token] = Fx::redeemable();
    $existing = duplicateEmailEnrol($project, 'Ana@Example.test', ['candidate_ref' => 'EXT-777', 'status' => 'in_corso']);
    $before = duplicateEmailRow($existing);
    $participants = Participant::query()->count();
    Event::fake([ParticipantCreated::class]);

    $response = $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($token, Fx::identity('ana@example.TEST', 'Ana Typed')));

    $response->assertStatus(409);
    $row = ReusableInterviewLink::withoutGlobalScopes()->findOrFail($link->id);
    expect($response->getContent())->toBe(DUPLICATE_ENROLMENT_BODY)
        ->and($response->json())->not->toHaveKey('access_token')
        ->and(Participant::query()->count())->toBe($participants)
        ->and($row->uses_count)->toBe(0)
        ->and($row->last_used_at)->toBeNull()
        ->and(WebhookDelivery::withoutGlobalScopes()->count())->toBe(0)
        ->and(duplicateEmailRow($existing))->toEqual($before);
    Event::assertNotDispatched(ParticipantCreated::class);
});

test('the 409 body names nothing about the existing participant', function (): void {
    ['project' => $project, 'token' => $token] = Fx::redeemable();
    duplicateEmailEnrol($project, 'ana@example.test', ['candidate_ref' => 'EXT-777', 'display_name' => 'Existing Person', 'status' => 'completato']);

    $content = (string) $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($token, Fx::identity('ana@example.test')))
        ->assertStatus(409)
        ->getContent();

    expect($content)->toBe(DUPLICATE_ENROLMENT_BODY)
        ->not->toContain('EXT-777')
        ->not->toContain('Existing Person')
        ->not->toContain('completato')
        ->not->toContain('ana@example.test');
});

test('an existing participant is never resumed, whatever its status or origin', function (string $status, string $origin): void {
    ['project' => $project, 'link' => $link, 'token' => $token] = Fx::redeemable();
    $attributes = ['status' => $status];

    if ($origin === 'a visitor of another link') {
        $attributes['reusable_interview_link_id'] = Fx::link($project)->id;
    } elseif ($origin === 'a participant with an external reference') {
        $attributes += ['external_id' => 4471, 'source' => 'acme-ats'];
    }

    $existing = duplicateEmailEnrol($project, 'ada@example.test', $attributes);
    $before = duplicateEmailRow($existing);

    $response = $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($token, Fx::identity('ada@example.test')));

    $response->assertStatus(409);
    expect($response->json())->not->toHaveKey('access_token')
        ->and(duplicateEmailRow($existing))->toEqual($before)
        ->and(Fx::visitorsOf($link))->toBe([]);
})->with(['in_attesa', 'in_corso', 'completato', 'errore'])
    ->with(['an operator-created participant', 'a visitor of another link', 'a participant with an external reference']);

// ─── Scope of the check ──────────────────────────────────────────────────────

test('the same email in another project of the organisation, or in another organisation, is not a duplicate', function (): void {
    ['org' => $org, 'project' => $project, 'link' => $link, 'token' => $token] = Fx::redeemable();
    $sibling = Fx::project($org);
    $other = Fx::redeemable();
    duplicateEmailEnrol($sibling, 'ada@example.test');
    duplicateEmailEnrol($other['project'], 'ada@example.test');

    $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($token, Fx::identity('ada@example.test')))->assertOk();

    expect(Fx::visitorsOf($link))->toHaveCount(1)
        ->and(Fx::visitorsOf($link)[0]->project_id)->toBe($project->id);

    // The refusal on the first project reveals nothing about the others: it is
    // the same constant body, whoever else holds the address.
    $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($token, Fx::identity('ada@example.test')))
        ->assertStatus(409);
});

test('the same link twice with one email is a 200 then a 409, and a different email is a 200 again', function (): void {
    ['link' => $link, 'token' => $token] = Fx::redeemable();

    $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($token, Fx::identity('ada@example.test')))->assertOk();
    $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($token, Fx::identity('ADA@example.test')))->assertStatus(409);
    $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($token, Fx::identity('grace@example.test')))->assertOk();

    expect(Fx::visitorsOf($link))->toHaveCount(2)
        ->and(ReusableInterviewLink::withoutGlobalScopes()->findOrFail($link->id)->uses_count)->toBe(2);
});

// ─── Precedence: the 409 is reached last ─────────────────────────────────────

test('a duplicate email never outranks the refusals that come first', function (string $case): void {
    $world = match ($case) {
        'a disabled link' => Fx::redeemable(linkAttributes: ['disabled_at' => now()]),
        'a closed project' => Fx::redeemable(['status' => 'inactive']),
        default => Fx::redeemable(),
    };
    duplicateEmailEnrol($world['project'], 'ada@example.test');

    $response = match ($case) {
        'a disabled link', 'a closed project' => $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($world['token'], Fx::identity('ada@example.test'))),
        'an invalid identity' => $this->postJson(Fx::REDEEM_URL, ['link_token' => $world['token'], 'email' => 'ada@example.test']),
        'a malformed token' => $this->postJson(Fx::REDEEM_URL, Fx::redeemBody('not-a-token', Fx::identity('ada@example.test'))),
    };

    $response->assertStatus(match ($case) {
        'a disabled link', 'a malformed token' => 404,
        'a closed project' => 403,
        'an invalid identity' => 422,
    });
})->with(['a disabled link', 'a closed project', 'an invalid identity', 'a malformed token']);

test('a 409 spends an attempt like any other outcome: the eleventh request from one address is a 429', function (): void {
    config(['reusable_links.redeem.per_ip_per_minute' => 10]);
    ['project' => $project, 'token' => $token] = Fx::redeemable();
    duplicateEmailEnrol($project, 'ada@example.test');

    foreach (range(1, 10) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.140'])
            ->postJson(Fx::REDEEM_URL, Fx::redeemBody($token, Fx::identity('ada@example.test')))
            ->assertStatus(409);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.140'])
        ->postJson(Fx::REDEEM_URL, Fx::redeemBody($token, Fx::identity('grace@example.test')))
        ->assertStatus(429);
});

// ─── The unique index is the guarantee ───────────────────────────────────────

/**
 * Arm a ONE-SHOT listener that runs just before the visitor is inserted. The
 * `creating` event is where a concurrent writer would land between the
 * duplicate check and the insert; hooking it makes that interleaving
 * deterministic. It is one-shot because `HasPublicId` and `TenantScoped` mint in
 * `creating` too, and flushing the model's listeners would break both.
 *
 * @param  callable(Participant): void  $interleave
 */
function duplicateEmailInterleave(callable $interleave): void
{
    $armed = true;

    Event::listen('eloquent.creating: '.Participant::class, function (Participant $participant) use (&$armed, $interleave): void {
        if (! $armed) {
            return;
        }

        $armed = false;
        $interleave($participant);
    });
}

/**
 * A raw insert of a participant, the way a concurrent committed writer leaves it.
 *
 * @param  array<string, mixed>  $overrides
 */
function duplicateEmailRawInsert(Participant $like, array $overrides = []): void
{
    DB::table('participants')->insert(array_merge([
        'organization_id' => $like->organization_id,
        'project_id' => $like->project_id,
        'candidate_ref' => 'twin-'.Str::lower(Str::random(12)),
        'display_name' => 'Twin',
        'email' => 'twin@example.test',
        'status' => 'in_attesa',
        'public_id' => Str::lower(Str::random(26)),
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

test('a same-email twin inserted between the check and the insert is a 409, and the redemption leaves no trace', function (): void {
    ['link' => $link, 'token' => $token] = Fx::redeemable();
    Event::fake([ParticipantCreated::class]);
    Exceptions::fake();
    $reachedTheInsert = false;
    duplicateEmailInterleave(function (Participant $visitor) use (&$reachedTheInsert): void {
        $reachedTheInsert = true;
        duplicateEmailRawInsert($visitor, ['email' => 'ada@example.test']);
    });

    $response = $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($token, Fx::identity('ada@example.test')));

    $response->assertStatus(409);
    $row = ReusableInterviewLink::withoutGlobalScopes()->findOrFail($link->id);
    // The twin was written once the redemption had passed the duplicate check
    // and was about to insert, so the 409 came from the unique index and its
    // mapping, not from the check. (Under the test wrapper the twin shares the
    // redemption's savepoint and is undone with it; the committed case is
    // proved with real processes in RedeemConcurrencyTest.)
    expect($reachedTheInsert)->toBeTrue()
        ->and($response->getContent())->toBe(DUPLICATE_ENROLMENT_BODY)
        ->and(Fx::visitorsOf($link))->toBe([])
        ->and($row->uses_count)->toBe(0)
        ->and($row->last_used_at)->toBeNull();
    Event::assertNotDispatched(ParticipantCreated::class);
    Exceptions::assertNothingReported();
});

test('a violation of another constraint is not mapped: it is rethrown, a 500', function (string $kind): void {
    ['link' => $link, 'token' => $token] = Fx::redeemable();
    Event::fake([ParticipantCreated::class]);

    if ($kind === 'the candidate reference index') {
        // SQLSTATE 23505 too, but NOT the email index: the mapping must name it.
        duplicateEmailInterleave(fn (Participant $visitor) => duplicateEmailRawInsert($visitor, [
            'candidate_ref' => $visitor->candidate_ref,
            'email' => 'twin@example.test',
        ]));
    } else {
        // The email index named in the message but another SQLSTATE (a foreign
        // key violation): the mapping must check the code as well.
        duplicateEmailInterleave(function (): void {
            $previous = new class('violates participants_project_id_email_unique') extends PDOException
            {
                public function __construct(string $message)
                {
                    parent::__construct($message);
                    $this->code = '23503';
                }
            };

            throw new QueryException('pgsql', 'insert into "participants"', [], $previous);
        });
    }

    $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($token, Fx::identity('ada@example.test')))->assertStatus(500);

    expect(Fx::visitorsOf($link))->toBe([])
        ->and(ReusableInterviewLink::withoutGlobalScopes()->findOrFail($link->id)->uses_count)->toBe(0);
    Event::assertNotDispatched(ParticipantCreated::class);
})->with(['the candidate reference index', 'another SQLSTATE naming the email index']);
