<?php

declare(strict_types=1);

/**
 * The persona knobs actually reach Tavus (C14 PR5).
 *
 * Nine of the seventeen Tavus fields live on the PERSONA, not the conversation.
 * Sent on a conversation they do nothing at all — no error, no effect, and an
 * operator watching a setting they configured make no difference.
 *
 * Shipping them without this sync would be exactly the dead-knob defect this
 * change refused to port from avatar-tester's `voiceProvider` — nine times over.
 */

use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Support\AvatarTemplates\TavusPalSync;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

function palTemplate(array $config, string $provider = 'tavus'): AvatarTemplate
{
    $org = Organization::factory()->create();

    return TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'PAL '.uniqid(),
        'provider' => $provider,
        'config' => $config,
    ]));
}

beforeEach(function (): void {
    config()->set('interview.tavus.api_key', 'test-key');
});

test('a heygen template is skipped', function (): void {
    Http::fake();

    $result = app(TavusPalSync::class)->sync(palTemplate(['avatarId' => 'a', 'voiceId' => 'v'], 'heygen'));

    expect($result['status'])->toBe('skipped');
    Http::assertNothingSent();
});

test('a config with no persona knobs sends nothing', function (): void {
    Http::fake();

    $result = app(TavusPalSync::class)->sync(palTemplate(['faceId' => 'r', 'palId' => 'p']));

    // A PATCH carrying an empty layers object would WIPE the persona's existing
    // settings. Nothing to say must mean saying nothing.
    expect($result['status'])->toBe('skipped');
    Http::assertNothingSent();
});

test('persona knobs are patched onto the PAL', function (): void {
    Http::fake(['*' => Http::response([], 200)]);

    $result = app(TavusPalSync::class)->sync(palTemplate([
        'faceId' => 'r', 'palId' => 'p_123',
        'llmTemperature' => 0.5, 'turnTakingPatience' => 'high',
    ]));

    expect($result['status'])->toBe('synced');

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        // ONE op replacing the WHOLE /layers node, not one op per leaf. A
        // replace on /layers/llm/model fails outright when /layers/llm does not
        // exist — and a persona nobody has configured yet is the common case.
        return str_contains($request->url(), '/pals/p_123')
            && $body[0]['op'] === 'add'
            && $body[0]['path'] === '/layers'
            && $body[0]['value']['llm']['extra_body']['temperature'] === 0.5;
    });
});

test('a 304 counts as synced', function (): void {
    Http::fake(['*' => Http::response([], 304)]);

    // The PAL already holds these exact values, so Tavus made no change. That
    // is a successful no-op — reporting it as a failure would have operators
    // chasing a problem that does not exist.
    expect(app(TavusPalSync::class)->sync(palTemplate([
        'faceId' => 'r', 'palId' => 'p', 'llmTemperature' => 0.5,
    ]))['status'])->toBe('synced');
});

test('a provider failure is a warning, never an exception', function (): void {
    Http::fake(['*' => Http::response(['error' => 'nope'], 500)]);

    $result = app(TavusPalSync::class)->sync(palTemplate([
        'faceId' => 'r', 'palId' => 'p', 'llmTemperature' => 0.5,
    ]));

    // A template save must not fail because a cosmetic setting could not be
    // pushed to a third party. The operator's intent is already recorded in our
    // own database, and saving again retries.
    expect($result['status'])->toBe('warning');
});

test('an unreachable provider is a warning, never an exception', function (): void {
    Http::fake(fn () => throw new ConnectionException('down'));

    $result = app(TavusPalSync::class)->sync(palTemplate([
        'faceId' => 'r', 'palId' => 'p', 'llmTemperature' => 0.5,
    ]));

    expect($result['status'])->toBe('warning');
});

test('the warning never carries the provider\'s own words', function (): void {
    Http::fake(['*' => Http::response(['message' => 'Tavus persona 404 at tavusapi.com'], 404)]);

    $result = app(TavusPalSync::class)->sync(palTemplate([
        'faceId' => 'r', 'palId' => 'p', 'llmTemperature' => 0.5,
    ]));

    // Provider error text names the vendor and can echo request content, and
    // this string travels to a UI. A stable code is what the backoffice
    // translates; the detail goes to the log.
    expect($result['message'])->toBe('pal_sync_failed');
    expect(json_encode($result))->not->toContain('tavusapi');
});

test('a missing API key is reported rather than attempted', function (): void {
    config()->set('interview.tavus.api_key', '');
    Http::fake();

    $result = app(TavusPalSync::class)->sync(palTemplate([
        'faceId' => 'r', 'palId' => 'p', 'llmTemperature' => 0.5,
    ]));

    expect($result['message'])->toBe('tavus_key_missing');
    Http::assertNothingSent();
});

test('a missing palId is reported rather than attempted', function (): void {
    Http::fake();

    $result = app(TavusPalSync::class)->sync(palTemplate([
        'faceId' => 'r', 'llmTemperature' => 0.5,
    ]));

    // Without a persona id there is nothing to patch. Guessing one, or creating
    // a persona as a side effect of a save, would leave orphaned personas on
    // the account that nobody knows to clean up.
    expect($result['message'])->toBe('pal_id_missing');
    Http::assertNothingSent();
});
