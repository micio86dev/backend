<?php

declare(strict_types=1);

/**
 * A link BEAI emails lives longer than a link BEAI only returns.
 *
 * Owner decision, 2026-10-05: the invitation a candidate finds in an inbox is
 * opened hours later, not within half an hour, so every sso-link BEAI delivers
 * by email lives a configurable lifetime (default 24 hours). A link that is only
 * RETURNED to a caller (M2M mint, an operator mint with no mail queued, a
 * reusable-link visitor, a placeholder address) stays at 30 minutes: the caller
 * who holds it decides how it travels, and a bearer token sitting in an
 * integrator's logs for a day is a bigger window for no benefit.
 *
 * The lifetime follows the DELIVERY CHANNEL, decided in one place
 * (`EntryLinkMinter`), so `email_sent` and the token's `exp` cannot disagree.
 * Single use is unchanged, and `expires_at` and the emailed label are read from
 * the token's own `exp`.
 *
 * REQ: Emailed Invitation Links Live 24 Hours
 *      (openspec/changes/scoring-retry-rt-b/specs/participant-sso/spec.md)
 */

use App\Enums\ParticipantSchedulingStatus;
use App\Jobs\SendCandidateInvitationJob;
use App\Models\ApiClient;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Services\ApiKeyGenerator;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Participant\PlaceholderEmail;
use App\Support\Sso\EntryLinkMinter;
use App\Support\Sso\LinkDelivery;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Helpers\ReusableLinkFixtures as Fx;
use Tymon\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Fx::configureOrigin();
    config([
        'reusable_links.redeem.per_ip_per_minute' => 100000,
        'reusable_links.redeem.per_link_per_hour' => 100000,
    ]);
});

/**
 * The claims of a JWT, read straight from its payload segment.
 *
 * @return array<string, mixed>
 */
function emailedTtlClaims(string $jwt): array
{
    return json_decode((string) base64_decode(strtr(explode('.', $jwt)[1], '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
}

/** The sso-link carried by an entry URL: the segment after `/interview/`. */
function emailedTtlTokenOf(string $entryUrl): string
{
    return Str::afterLast($entryUrl, '/interview/');
}

/**
 * `POST /api/entry-links` as an operator of the world's organisation.
 *
 * @param  array{project: Project, org: Organization}  $world
 * @param  array<string, mixed>  $extra
 */
function emailedTtlOperatorMint(object $test, array $world, string $candidateRef, string $email, array $extra = []): TestResponse
{
    $operator = authTokenForRole($world['org'], 'operator');
    resetAuthGuardState();

    return $test->withToken($operator)->postJson('/api/entry-links', [
        'project_id' => $world['project']->id,
        'candidate_ref' => $candidateRef,
        'display_name' => 'Ada Lovelace',
        'email' => $email,
        ...$extra,
    ]);
}

/** @param  array<string, mixed>  $world */
function emailedTtlVisitorRow(array $world, string $candidateRef, string $email): Participant
{
    $visitor = Participant::factory()->forProject($world['project'])->create([
        'candidate_ref' => $candidateRef,
        'email' => $email,
        'display_name' => 'Ada Lovelace',
    ]);
    DB::table('participants')->where('id', $visitor->id)->update([
        'reusable_interview_link_id' => $world['link']->id,
    ]);

    return $visitor;
}

// ─── Operator mint: the lifetime follows the channel ─────────────────────────

test('an operator mint that queues the invitation email lives the configured 24 hours', function (): void {
    Queue::fake();
    $world = Fx::redeemable();

    $response = emailedTtlOperatorMint($this, $world, 'emailed-1', 'ada@example.test')->assertCreated();

    $claims = emailedTtlClaims(emailedTtlTokenOf($response->json('entry_url')));
    expect($response->json('email_sent'))->toBeTrue()
        ->and($claims['typ'])->toBe('sso-link')
        ->and($claims['exp'] - $claims['iat'])->toBe(1440 * 60)
        ->and(Carbon::parse($response->json('expires_at'))->getTimestamp())->toBe($claims['exp']);
    Queue::assertPushed(SendCandidateInvitationJob::class, 1);
});

test('the lifetime is configuration, not code', function (): void {
    Queue::fake();
    config(['candidate_invitations.emailed_link_ttl_minutes' => 720]);
    $world = Fx::redeemable();

    $response = emailedTtlOperatorMint($this, $world, 'emailed-720', 'ada@example.test')->assertCreated();

    $claims = emailedTtlClaims(emailedTtlTokenOf($response->json('entry_url')));
    expect($claims['exp'] - $claims['iat'])->toBe(720 * 60);
});

test('an operator mint with send_email false stays at 30 minutes and says no email was sent', function (): void {
    Queue::fake();
    $world = Fx::redeemable();

    $response = emailedTtlOperatorMint($this, $world, 'returned-1', 'ada@example.test', ['send_email' => false])->assertCreated();

    $claims = emailedTtlClaims(emailedTtlTokenOf($response->json('entry_url')));
    expect($response->json('email_sent'))->toBeFalse()
        ->and($claims['exp'] - $claims['iat'])->toBe(CandidateTokenFactory::SSO_LINK_TTL_MINUTES * 60)
        ->and(Carbon::parse($response->json('expires_at'))->getTimestamp())->toBe($claims['exp']);
    Queue::assertNotPushed(SendCandidateInvitationJob::class);
});

test('an operator re-issue for a participant holding a placeholder address stays at 30 minutes', function (): void {
    Queue::fake();
    $world = Fx::redeemable();
    $placeholder = PlaceholderEmail::forPurged('purged-ref');
    Participant::factory()->forProject($world['project'])->create([
        'candidate_ref' => 'purged-ref',
        'display_name' => '[purged]',
        'email' => $placeholder,
    ]);

    $response = emailedTtlOperatorMint($this, $world, 'purged-ref', $placeholder, ['display_name' => '[purged]'])->assertCreated();

    $claims = emailedTtlClaims(emailedTtlTokenOf($response->json('entry_url')));
    expect($response->json('email_sent'))->toBeFalse()
        ->and($claims['exp'] - $claims['iat'])->toBe(1800);
    Queue::assertNotPushed(SendCandidateInvitationJob::class);
});

test('an operator re-issue for a reusable-link visitor stays at 30 minutes even with send_email true', function (): void {
    Queue::fake();
    $world = Fx::redeemable();
    emailedTtlVisitorRow($world, 'rlv_01JABCDEFGHJKMNPQRSTVWXYZ1', 'visitor@example.test');

    $response = emailedTtlOperatorMint($this, $world, 'rlv_01JABCDEFGHJKMNPQRSTVWXYZ1', 'visitor@example.test', ['send_email' => true])->assertCreated();

    $claims = emailedTtlClaims(emailedTtlTokenOf($response->json('entry_url')));
    expect($response->json('email_sent'))->toBeFalse()
        ->and($claims['exp'] - $claims['iat'])->toBe(1800);
    Queue::assertNotPushed(SendCandidateInvitationJob::class);
});

test('the emailed expiry label equals the token exp, in the candidate language', function (): void {
    Queue::fake();
    $world = Fx::redeemable();

    $response = emailedTtlOperatorMint($this, $world, 'label-1', 'ada@example.test', ['lang' => 'it'])->assertCreated();

    $exp = emailedTtlClaims(emailedTtlTokenOf($response->json('entry_url')))['exp'];
    $expected = Carbon::createFromTimestamp($exp)->locale('it')->isoFormat('LLL');
    Queue::assertPushed(SendCandidateInvitationJob::class, function (SendCandidateInvitationJob $job) use ($expected): bool {
        $label = new ReflectionProperty($job, 'expiresAtLabel');

        return $label->getValue($job) === $expected;
    });
});

// ─── The other issuers are unchanged ─────────────────────────────────────────

test('the M2M sso-link mint stays at 30 minutes', function (): void {
    $world = Fx::redeemable();
    $key = ApiKeyGenerator::generate();
    ApiClient::factory()->withRawKey($key)->create([
        'organization_id' => $world['org']->id,
        'is_active' => true,
        'abilities' => ['sso_link:generate'],
    ]);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$key])->postJson('/api/m2m/sso-link', [
        'project_id' => $world['project']->id,
        'candidate_ref' => 'm2m-1',
        'display_name' => 'Ada Lovelace',
        'email' => 'ada@example.test',
        'role_code' => 'ICO',
        'lang' => 'en',
    ])->assertCreated();

    $claims = emailedTtlClaims($response->json('token'));
    expect(array_keys($response->json()))->toBe(['token'])
        ->and($claims['exp'] - $claims['iat'])->toBe(1800);
});

test('a reusable-link redemption mints no sso-link at all and its candidate credential keeps its own lifetime', function (): void {
    $world = Fx::redeemable();

    $response = $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($world['token']))->assertOk();

    $claims = emailedTtlClaims((string) $response->json('access_token'));
    expect($claims['typ'])->toBe('candidate')
        ->and($claims['exp'] - $claims['iat'])->toBe(120 * 60);
});

test('the raw factory mint still defaults to 30 minutes and honours an explicit lifetime', function (): void {
    $world = Fx::redeemable();
    $claims = [
        'candidate_ref' => 'raw-1',
        'display_name' => 'Ada Lovelace',
        'email' => 'ada@example.test',
        'project_id' => $world['project']->id,
        'org_id' => $world['org']->id,
        'role_code' => 'ICO',
        'lang' => 'en',
    ];

    $default = emailedTtlClaims(CandidateTokenFactory::mintSsoLink($claims));
    $explicit = emailedTtlClaims(CandidateTokenFactory::mintSsoLink($claims, 90));
    $afterwards = emailedTtlClaims(CandidateTokenFactory::mintSsoLink($claims));

    expect($default['exp'] - $default['iat'])->toBe(1800)
        ->and($explicit['exp'] - $explicit['iat'])->toBe(5400)
        ->and($afterwards['exp'] - $afterwards['iat'])->toBe(1800);
});

test('a long sso-link mint does not leak its lifetime into the next token of the same process', function (): void {
    $world = Fx::redeemable();
    $user = User::factory()->create(['organization_id' => $world['org']->id]);
    $before = emailedTtlClaims(JWTAuth::fromUser($user));

    CandidateTokenFactory::mintSsoLink([
        'candidate_ref' => 'leak-1',
        'display_name' => 'Ada Lovelace',
        'email' => 'ada@example.test',
        'project_id' => $world['project']->id,
        'org_id' => $world['org']->id,
        'role_code' => 'ICO',
        'lang' => 'en',
    ], 1440);

    // A user access token sets no lifetime of its own: it takes whatever the
    // shared factory holds, which is what a leaked 24 hours would corrupt.
    $after = emailedTtlClaims(JWTAuth::fromUser($user));

    expect($before['exp'] - $before['iat'])->toBe(config('jwt.ttl') * 60)
        ->and($after['exp'] - $after['iat'])->toBe($before['exp'] - $before['iat']);
});

// ─── Scheduled-start sweep ───────────────────────────────────────────────────

test('the scheduled-start sweep mints a link that lives the configured 24 hours', function (): void {
    Bus::fake();
    $world = Fx::redeemable();
    $participant = Participant::factory()->forProject($world['project'])->create([
        'scheduled_at' => now()->subMinute(),
        'scheduling_status' => ParticipantSchedulingStatus::NoticeSent,
    ]);

    Artisan::call('beai:dispatch-scheduled-invitations');

    Bus::assertDispatched(SendCandidateInvitationJob::class, function (SendCandidateInvitationJob $job) use ($participant): bool {
        $url = (new ReflectionProperty($job, 'entryUrl'))->getValue($job);
        $claims = emailedTtlClaims(emailedTtlTokenOf($url));
        $label = (new ReflectionProperty($job, 'expiresAtLabel'))->getValue($job);

        expect($claims['candidate_ref'])->toBe($participant->candidate_ref)
            ->and($claims['exp'] - $claims['iat'])->toBe(1440 * 60)
            ->and($label)->toBe(Carbon::createFromTimestamp($claims['exp'])->locale('en')->isoFormat('LLL'));

        return true;
    });
});

// ─── Range doctrine: refused, never clamped ──────────────────────────────────

test('an emailed lifetime outside [15, 10080] or not an integer is refused at mint time', function (mixed $value): void {
    config(['candidate_invitations.emailed_link_ttl_minutes' => $value]);
    $world = Fx::redeemable();

    expect(fn () => app(EntryLinkMinter::class)->mint(
        $world['project'], 'range-1', 'Ada Lovelace', 'ada@example.test', 'ICO', 'en',
        delivery: LinkDelivery::Emailed,
    ))->toThrow(RuntimeException::class, 'candidate_invitations.emailed_link_ttl_minutes');
})->with([
    'below the floor' => [14],
    'above the ceiling' => [10081],
    'zero' => [0],
    'a word' => ['abc'],
    'a fraction' => [1440.5],
]);

test('the boundary lifetimes 15 and 10080 are accepted', function (int $minutes): void {
    config(['candidate_invitations.emailed_link_ttl_minutes' => $minutes]);
    $world = Fx::redeemable();

    $minted = app(EntryLinkMinter::class)->mint(
        $world['project'], 'range-ok', 'Ada Lovelace', 'ada@example.test', 'ICO', 'en',
        delivery: LinkDelivery::Emailed,
    );

    $claims = emailedTtlClaims($minted->token);
    expect($claims['exp'] - $claims['iat'])->toBe($minutes * 60)
        ->and($minted->delivery)->toBe(LinkDelivery::Emailed);
})->with([15, 10080]);

test('a returned link ignores the emailed lifetime setting, even a broken one', function (): void {
    config(['candidate_invitations.emailed_link_ttl_minutes' => 'abc']);
    $world = Fx::redeemable();

    $minted = app(EntryLinkMinter::class)->mint(
        $world['project'], 'returned-ok', 'Ada Lovelace', 'ada@example.test', 'ICO', 'en',
    );

    $claims = emailedTtlClaims($minted->token);
    expect($claims['exp'] - $claims['iat'])->toBe(1800)
        ->and($minted->delivery)->toBe(LinkDelivery::Returned);
});

test('the minter reports the effective channel: an Emailed request for a visitor target is downgraded to Returned', function (): void {
    $world = Fx::redeemable();
    emailedTtlVisitorRow($world, 'rlv_01JABCDEFGHJKMNPQRSTVWXYZ2', 'visitor@example.test');

    $visitor = app(EntryLinkMinter::class)->mint(
        $world['project'], 'rlv_01JABCDEFGHJKMNPQRSTVWXYZ2', 'Ada Lovelace', 'visitor@example.test', 'ICO', 'en',
        delivery: LinkDelivery::Emailed,
    );
    $ordinary = app(EntryLinkMinter::class)->mint(
        $world['project'], 'ordinary-1', 'Grace Hopper', 'grace@example.test', 'ICO', 'en',
        delivery: LinkDelivery::Emailed,
    );

    $visitorClaims = emailedTtlClaims($visitor->token);
    $ordinaryClaims = emailedTtlClaims($ordinary->token);
    expect($visitor->delivery)->toBe(LinkDelivery::Returned)
        ->and($visitorClaims['exp'] - $visitorClaims['iat'])->toBe(1800)
        ->and($ordinary->delivery)->toBe(LinkDelivery::Emailed)
        ->and($ordinaryClaims['exp'] - $ordinaryClaims['iat'])->toBe(1440 * 60);
});

// ─── Exchange: single use and the expiry boundary ────────────────────────────

test('an emailed link exchanges one minute before its lifetime ends and not a second time', function (): void {
    Queue::fake();
    $world = Fx::redeemable();
    $token = emailedTtlTokenOf(emailedTtlOperatorMint($this, $world, 'edge-ok', 'ada@example.test')->assertCreated()->json('entry_url'));

    $this->travel(1440 - 1)->minutes();
    $this->flushHeaders()->getJson('/api/sso/exchange?token='.$token)->assertOk();
    $this->getJson('/api/sso/exchange?token='.$token)->assertUnauthorized();

    expect(Participant::query()->where('project_id', $world['project']->id)->where('candidate_ref', 'edge-ok')->exists())->toBeTrue();
});

test('an emailed link is refused one minute after its lifetime and the participant is untouched', function (): void {
    Queue::fake();
    $world = Fx::redeemable();
    $token = emailedTtlTokenOf(emailedTtlOperatorMint($this, $world, 'edge-late', 'ada@example.test')->assertCreated()->json('entry_url'));

    $this->travel(1440 + 1)->minutes();
    $this->flushHeaders()->getJson('/api/sso/exchange?token='.$token)->assertUnauthorized();

    expect(Participant::query()->where('project_id', $world['project']->id)->where('candidate_ref', 'edge-late')->exists())->toBeFalse();
});

test('a 30-minute returned link is still refused 31 minutes after its mint, so the longer lifetime is not global', function (): void {
    $world = Fx::redeemable();
    $token = emailedTtlTokenOf(emailedTtlOperatorMint($this, $world, 'edge-returned', 'ada@example.test', ['send_email' => false])->assertCreated()->json('entry_url'));

    $this->travel(31)->minutes();
    $this->flushHeaders()->getJson('/api/sso/exchange?token='.$token)->assertUnauthorized();
});

test('the consumed-jti record of an emailed link lives at least until the token exp', function (): void {
    Queue::fake();
    $world = Fx::redeemable();
    $token = emailedTtlTokenOf(emailedTtlOperatorMint($this, $world, 'jti-1', 'ada@example.test')->assertCreated()->json('entry_url'));
    $jti = emailedTtlClaims($token)['jti'];

    $seconds = null;
    Event::listen(KeyWritten::class, function (KeyWritten $event) use ($jti, &$seconds): void {
        if (str_contains($event->key, 'sso_jti:'.$jti)) {
            $seconds = $event->seconds;
        }
    });

    $this->flushHeaders()->getJson('/api/sso/exchange?token='.$token)->assertOk();

    expect($seconds)->toBeGreaterThanOrEqual(1440 * 60 - 5);
});
