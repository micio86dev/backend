<?php

declare(strict_types=1);

/**
 * POST /api/reusable-links/redeem (reusable-link-visitor-identity, api-1b): the
 * identity is validated FIRST, and the answer depends on the identity alone.
 *
 * A request whose name or email is missing or invalid is a 422 for EVERY token:
 * valid, unknown, malformed, disabled or absent. The body is byte-identical
 * across them, so the validation step is never a token oracle, and it never
 * echoes the submitted values nor the token. The identity is read from the BODY
 * only (the same rule as the token), so a value that arrives in the query string
 * is a missing value.
 *
 * The inverse also holds: a VALID identity beside a bad token is the generic
 * 404, never a 422.
 *
 * REQ: Identity Fields Are Validated First And Never Disclose The Token
 *      (sdd/reusable-link-visitor-identity/spec/reusable-interview-links)
 */

use App\Events\ParticipantCreated;
use App\Models\Participant;
use App\Models\ReusableInterviewLink;
use App\Services\ReusableLinkTokenGenerator;
use Illuminate\Support\Facades\Event;
use Tests\Helpers\ReusableLinkFixtures as Fx;

beforeEach(function (): void {
    config([
        'reusable_links.redeem.per_ip_per_minute' => 10000,
        'reusable_links.redeem.per_link_per_hour' => 10000,
    ]);
});

/**
 * Every class of token the endpoint distinguishes, for one world.
 *
 * @param  array{token: string}  $world
 * @return array<string, mixed> the value to send as `link_token`; absent means "no token key"
 */
function identityValidationTokens(array $world): array
{
    return [
        'a valid enabled token' => $world['token'],
        'a well-formed unknown token' => ReusableLinkTokenGenerator::generate(),
        'a malformed token' => 'not-a-token',
    ];
}

/**
 * The request body of one token class beside `$identity`.
 *
 * @param  array<string, mixed>  $identity
 * @return array<string, mixed>
 */
function identityValidationBody(string $class, mixed $token, array $identity): array
{
    return $class === 'no token' ? $identity : ['link_token' => $token] + $identity;
}

/**
 * The identities that must fail, each with the fields its 422 must name.
 *
 * @return array<string, array{0: array<string, mixed>, 1: list<string>}>
 */
function identityValidationInvalidIdentities(): array
{
    return [
        'a missing name' => [['email' => 'ada@example.test'], ['display_name']],
        'a missing email' => [['display_name' => 'Ada Lovelace'], ['email']],
        'both missing' => [[], ['display_name', 'email']],
        'a whitespace-only name' => [['display_name' => '   ', 'email' => 'ada@example.test'], ['display_name']],
        'a whitespace-only email' => [['display_name' => 'Ada Lovelace', 'email' => " \t "], ['email']],
    ];
}

// ─── The core matrix ─────────────────────────────────────────────────────────

test('an invalid identity is a 422 naming exactly the invalid fields, for every class of token', function (array $identity, array $invalidFields): void {
    $world = Fx::redeemable();

    foreach ([...identityValidationTokens($world), 'no token' => null] as $class => $token) {
        $response = $this->postJson(Fx::REDEEM_URL, identityValidationBody($class, $token, $identity));

        $response->assertUnprocessable();
        expect(array_keys($response->json('errors')))->toBe($invalidFields, $class);
    }
})->with(identityValidationInvalidIdentities());

test('the 422 body is byte-identical across every class of token for one identity', function (array $identity): void {
    $world = Fx::redeemable();
    $bodies = [];

    foreach ([...identityValidationTokens($world), 'no token' => null] as $class => $token) {
        $bodies[$class] = (string) $this->postJson(Fx::REDEEM_URL, identityValidationBody($class, $token, $identity))
            ->assertUnprocessable()
            ->getContent();
    }

    expect(array_unique($bodies))->toHaveCount(1);
})->with(array_map(fn (array $case): array => [$case[0]], identityValidationInvalidIdentities()));

test('the 422 body never contains the link token nor any submitted value', function (): void {
    $world = Fx::redeemable();
    $identity = ['display_name' => '<script>x</script>', 'email' => 'zz-sentinel'];

    foreach (identityValidationTokens($world) as $class => $token) {
        $content = (string) $this->postJson(Fx::REDEEM_URL, identityValidationBody($class, $token, $identity))
            ->assertUnprocessable()
            ->getContent();

        expect($content)->not->toContain('link_token')
            ->not->toContain($token)
            ->not->toContain('<script>')
            ->not->toContain('zz-sentinel');
    }
});

test('an invalid identity writes nothing: no counter, no timestamp, no participant, no event', function (): void {
    ['link' => $link, 'token' => $token] = Fx::redeemable();
    Event::fake([ParticipantCreated::class]);
    $before = Participant::query()->count();

    foreach (identityValidationInvalidIdentities() as [$identity]) {
        $this->postJson(Fx::REDEEM_URL, identityValidationBody('a valid enabled token', $token, $identity))->assertUnprocessable();
    }

    $row = ReusableInterviewLink::withoutGlobalScopes()->findOrFail($link->id);
    expect($row->uses_count)->toBe(0)
        ->and($row->last_used_at)->toBeNull()
        ->and(Participant::query()->count())->toBe($before);
    Event::assertNotDispatched(ParticipantCreated::class);
});

// ─── Body only ───────────────────────────────────────────────────────────────

test('an identity that arrives only in the query string is a missing identity', function (): void {
    ['link' => $link, 'token' => $token] = Fx::redeemable();

    $response = $this->postJson(Fx::REDEEM_URL.'?display_name=Ada+Lovelace&email=ada@example.test', ['link_token' => $token]);

    $response->assertUnprocessable();
    expect(array_keys($response->json('errors')))->toBe(['display_name', 'email'])
        ->and(Fx::visitorsOf($link))->toBe([]);
});

// ─── Validation outranks the token ───────────────────────────────────────────

test('a disabled link and an unknown token with an invalid identity are a 422, never a 404', function (): void {
    $disabled = Fx::redeemable(linkAttributes: ['disabled_at' => now()]);

    foreach ([$disabled['token'], ReusableLinkTokenGenerator::generate()] as $token) {
        $this->postJson(Fx::REDEEM_URL, ['link_token' => $token, 'email' => 'ada@example.test'])->assertUnprocessable();
    }
});

test('a request with no body at all is a 422 on both fields, never a 404', function (): void {
    $response = $this->postJson(Fx::REDEEM_URL);

    $response->assertUnprocessable();
    expect(array_keys($response->json('errors')))->toBe(['display_name', 'email']);
});

test('a valid identity beside a malformed, unknown or disabled token is the generic 404, never a 422', function (): void {
    $disabled = Fx::redeemable(linkAttributes: ['disabled_at' => now()]);

    foreach (['not-a-token', ReusableLinkTokenGenerator::generate(), $disabled['token']] as $token) {
        $response = $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($token));

        $response->assertNotFound();
        expect($response->getContent())->toBe(Fx::NOT_FOUND_BODY);
    }
});
