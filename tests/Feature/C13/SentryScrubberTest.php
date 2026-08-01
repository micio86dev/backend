<?php

declare(strict_types=1);

/**
 * Nothing confidential leaves for Sentry (C13).
 *
 * `send_default_pii => false` stops Sentry ADDING user and IP context. It does
 * nothing about what this application's own exceptions already carry — and in
 * this product they carry a lot: an exception in the scoring pipeline has
 * prompt text in scope, and prompts contain a candidate's spoken answers.
 *
 * Getting this wrong fails quietly and permanently. The data lands in a
 * third-party service nobody thinks of as a database, indexed and searchable,
 * and no other test in this suite would notice.
 */

use App\Support\Observability\SentryScrubber;
use Sentry\Event;
use Sentry\UserDataBag;

function scrubbedEvent(array $extra = [], array $request = []): Event
{
    $event = Event::createEvent();
    $event->setExtra($extra);

    if ($request !== []) {
        $event->setRequest($request);
    }

    return SentryScrubber::handle($event);
}

test('a candidate answer never reaches the sink', function (): void {
    $answer = 'I once falsified a report under deadline pressure';

    $event = scrubbedEvent([
        'transcript' => $answer,
        'prompt' => "Score this: {$answer}",
        'excerpts' => [$answer],
    ]);

    $encoded = json_encode($event->getExtra());

    // The entire premise of the product is that a candidate's answers stay
    // between them and the organization that assessed them.
    expect($encoded)->not->toContain('falsified');
});

test('tokens and secrets never reach the sink', function (): void {
    $event = scrubbedEvent([
        'authorization' => 'Bearer eyJhbGciOi.LEAKED',
        'api_key' => 'beai_live_LEAKED',
        'webhook_secret' => 'whsec_LEAKED',
        'refresh_token' => 'rt_LEAKED',
    ]);

    expect(json_encode($event->getExtra()))->not->toContain('LEAKED');
});

test('candidate identifiers never reach the sink', function (): void {
    $event = scrubbedEvent([
        'candidate_ref' => 'acme-672',
        'display_name' => 'Mario Rossi',
    ]);

    $encoded = json_encode($event->getExtra());

    // candidate_ref is opaque to BEAI but NOT to the calling system — it is
    // their key back to a named person, which makes it identifying the moment
    // it sits alongside anything else.
    expect($encoded)->not->toContain('acme-672');
    expect($encoded)->not->toContain('Mario Rossi');
});

test('secrets nested at any depth are scrubbed', function (): void {
    $event = scrubbedEvent([
        'context' => ['delivery' => ['payload' => ['answer' => 'NESTED-LEAK']]],
    ]);

    // A top-level-only pass would look like it worked while letting the real
    // payload through — exceptions nest their context by nature.
    expect(json_encode($event->getExtra()))->not->toContain('NESTED-LEAK');
});

test('fields ending in _token, _secret or _key are scrubbed by convention', function (): void {
    $event = scrubbedEvent([
        'provider_api_key' => 'PK-LEAK',
        'session_token' => 'ST-LEAK',
        'signing_secret' => 'SS-LEAK',
    ]);

    // Enumerating every future field name is impossible; the convention covers
    // what the denylist has not been told about yet.
    $encoded = json_encode($event->getExtra());
    expect($encoded)->not->toContain('LEAK');
});

test('user context is dropped entirely', function (): void {
    $event = Event::createEvent();
    $event->setUser(UserDataBag::createFromUserIdentifier('user-42'));

    $scrubbed = SentryScrubber::handle($event);

    // Dropped rather than scrubbed field by field: a candidate is not a Sentry
    // "user", and an operator's identity adds nothing to a stack trace that the
    // organization scope does not already give. There is no case where keeping
    // it is worth the risk of a future Sentry version adding a field this code
    // has never heard of.
    expect($scrubbed->getUser())->toBeNull();
});

test('diagnostic context that is NOT sensitive survives', function (): void {
    $event = scrubbedEvent([
        'evaluation_id' => 42,
        'competency_code' => 'COL',
        'organization_id' => 7,
        'latency_ms' => 1234,
    ]);

    $extra = $event->getExtra();

    // A denylist rather than an allowlist, deliberately: an allowlist would
    // strip the context that makes an error report useful, and an unusable
    // error reporter gets switched off — a worse outcome than a scrubbed one.
    expect($extra['evaluation_id'])->toBe(42);
    expect($extra['competency_code'])->toBe('COL');
    expect($extra['latency_ms'])->toBe(1234);
});

test('the config pins PII off and wires the scrubber', function (): void {
    // send_default_pii is deliberately NOT env-overridable to true: in this
    // product that is not a preference.
    expect(config('sentry.send_default_pii'))->toBeFalse();
    expect(config('sentry.before_send'))->toBe([SentryScrubber::class, 'handle']);
});

test('sentry is inert without a DSN', function (): void {
    // No DSN is configured anywhere in this repo, and none should be: it is a
    // deployment credential. The integration must therefore be a no-op out of
    // the box rather than an error.
    expect(config('sentry.dsn'))->toBeNull();
});
