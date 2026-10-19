<?php

declare(strict_types=1);

/**
 * Default-path invariance for the HR-Avatar adapter.
 *
 * The adapter is additive and opt-in. These tests pin what a Tavus and a
 * HeyGen interview start returns, recorded ONCE on the unmodified base (before
 * any HR-Avatar code existed) into `tests/Fixtures/hr_avatar/`, and compare the
 * live response to those bytes with the HR-Avatar settings both unset and
 * fully set. A set-but-unselected integration must change nothing a candidate
 * of another provider can observe, and no request may ever leave for an
 * HR-Avatar host.
 *
 * Volatile members (database ids) are normalised to fixed placeholders; the
 * provider fakes return fixed ids, so nothing else varies between runs.
 *
 * Record mode (once, on the base): HR_AVATAR_RECORD_GOLDEN=1 vendor/bin/pest <this file>
 * With no fixture the comparison fails ("fixture missing"): that is the RED state.
 */

use App\Models\Organization;
use App\Support\AvatarTemplates\ProviderFieldSpecs;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Helpers\AvatarTemplates\TemplateActors;

uses(RefreshDatabase::class);

const HRA_GOLDEN_HOST = 'hra-golden.example.test';

/** @return array<string, PromiseInterface> */
function hraGoldenFakes(string $provider): array
{
    if ($provider === 'tavus') {
        return [
            '*tavusapi*/v2/conversations*' => Http::response([
                'conversation_id' => 'golden-tavus-conversation',
                'conversation_url' => 'https://tavus.example.test/golden-tavus-conversation',
            ], 200),
        ];
    }

    return [
        '*liveavatar*/contexts*' => Http::response(['data' => ['id' => 'golden-heygen-context']], 200),
        '*liveavatar*/sessions/token*' => Http::response([
            'data' => ['session_id' => 'golden-heygen-session', 'session_token' => 'golden-heygen-token'],
        ], 200),
        '*liveavatar*/sessions/*' => Http::response([], 200),
    ];
}

/** Replace database-assigned ids so the body is stable across runs. */
function hraGoldenNormalise(mixed $value, ?string $key = null): mixed
{
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = hraGoldenNormalise($v, (string) $k);
        }

        return $out;
    }

    return in_array($key, ['session_id'], true) ? '<id>' : $value;
}

function hraGoldenPath(string $provider): string
{
    return dirname(__DIR__, 2).'/Fixtures/hr_avatar/start-'.$provider.'.json';
}

function hraGoldenEncode(mixed $body): string
{
    return json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)."\n";
}

function hraSetHrAvatarSettings(): void
{
    config([
        'hr_avatar.base_url' => 'https://'.HRA_GOLDEN_HOST,
        'hr_avatar.secret_key' => 'hra_sk_live_GOLDENSECRET',
        'hr_avatar.publishable_key' => 'hra_pk_live_GOLDENPUBLISHABLE',
    ]);
}

dataset('hr_avatar_settings', [
    'HR-Avatar settings unset' => [false],
    'HR-Avatar settings fully set' => [true],
]);

dataset('golden_providers', ['tavus', 'heygen']);

test('a :dataset start response is byte-identical to the recorded base fixture', function (string $provider, bool $settingsSet): void {
    if ($settingsSet) {
        hraSetHrAvatarSettings();
    }

    Http::fake(hraGoldenFakes($provider));
    Http::preventStrayRequests();
    Queue::fake();

    [, , , $token] = hraStartScenario($provider, ['name' => 'Golden '.$provider]);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/candidate/interview/start');

    $response->assertStatus(201);
    $actual = hraGoldenEncode(hraGoldenNormalise($response->json()));

    if (getenv('HR_AVATAR_RECORD_GOLDEN') === '1') {
        file_put_contents(hraGoldenPath($provider), $actual);
    }

    expect(is_file(hraGoldenPath($provider)))->toBeTrue("golden fixture missing: {$provider}");
    expect($actual)->toBe(file_get_contents(hraGoldenPath($provider)));
    expect($response->json())->not->toHaveKey('hr_avatar');
})->with('golden_providers')->with('hr_avatar_settings');

test('a :dataset start sends no request to an HR-Avatar host', function (string $provider, bool $settingsSet): void {
    if ($settingsSet) {
        hraSetHrAvatarSettings();
    }

    Http::fake(hraGoldenFakes($provider));
    Http::preventStrayRequests();
    Queue::fake();

    [, , , $token] = hraStartScenario($provider);

    $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/candidate/interview/start')->assertStatus(201);

    Http::assertSent(fn ($request): bool => true);
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), HRA_GOLDEN_HOST));
})->with('golden_providers')->with('hr_avatar_settings');

test('the field specs are exactly the pre-change object while the integration is unavailable', function (bool $partiallySet): void {
    if ($partiallySet) {
        config(['hr_avatar.base_url' => 'https://'.HRA_GOLDEN_HOST]);
    }

    $token = TemplateActors::token('acting', Organization::factory()->create());

    $data = $this->withToken($token)->getJson('/api/avatar-templates/field-specs')->assertOk()->json('data');

    expect($data)->toBe([
        'heygen' => array_map(fn ($field): array => $field->toArray(), ProviderFieldSpecs::forCaller('heygen', true)),
        'tavus' => array_map(fn ($field): array => $field->toArray(), ProviderFieldSpecs::forCaller('tavus', true)),
    ]);
})->with([
    'nothing set' => [false],
    'only the base URL set' => [true],
]);
