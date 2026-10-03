<?php

declare(strict_types=1);

/**
 * GET /api/avatar-templates/catalogue-sample — the SERVER downloads a Cartesia catalogue sample
 * with the platform key and serves the bytes, so the browser never calls files.cartesia.ai and
 * never holds a key (cartesia-catalogue-sample-proxy).
 *
 * Every test runs against `Http::fake` with stray requests prevented: nothing here may reach a
 * provider.
 *
 * Authorization: the SAME `AvatarTemplatePolicy::create` gate as the synthesised sample, so only
 * a superadmin is served — asserted below rather than assumed.
 */

use App\Models\Organization;
use App\Models\User;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

const CATALOGUE_SAMPLE_URI = '/api/avatar-templates/catalogue-sample';
const CATALOGUE_SAMPLE_KEY = 'TEST_CARTESIA_KEY';
const CATALOGUE_SAMPLE_FILE_URL = 'https://files.cartesia.ai/files/file_01SECRETFILEID/download?format=playback';
const CATALOGUE_SAMPLE_WAV = "RIFF\x24\x00\x00\x00WAVEfmt FAKE-WAV-BYTES";

function catalogueSampleSuperadmin(): string
{
    $user = User::factory()->create(['organization_id' => null]);
    $user->forceFill(['is_superadmin' => true])->save();

    return auth('api')->login($user);
}

function catalogueSampleOrgActor(string $role): string
{
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $user->assignRole(SpatieRole::firstOrCreate(['name' => $role, 'guard_name' => 'api', 'team_id' => $org->id]));

    return auth('api')->login($user);
}

/** The two upstream hops: the voice lookup, then the file download. */
function fakeCartesiaSample(?string $fileUrl = CATALOGUE_SAMPLE_FILE_URL, mixed $download = null): void
{
    Http::preventStrayRequests();
    Http::fake([
        'api.cartesia.ai/voices/*' => Http::response(['id' => 'v1', 'name' => 'Elena'] + ($fileUrl === null ? [] : ['preview_file_url' => $fileUrl]), 200),
        // A closure: a streamed body can be read once, so every download needs a fresh response.
        'files.cartesia.ai/*' => $download ?? fn () => Http::response(CATALOGUE_SAMPLE_WAV, 200, ['Content-Type' => 'audio/wav']),
    ]);
}

beforeEach(function (): void {
    Storage::fake();
    Cache::flush();
    RateLimiter::clear('avatar-catalogue-sample');
    config(['services.cartesia.api_key' => CATALOGUE_SAMPLE_KEY]);
});

// ─── Authorization ──────────────────────────────────────────────────────────

test('an unauthenticated caller gets 401', function (): void {
    $this->getJson(CATALOGUE_SAMPLE_URI.'?provider=cartesia&voice_id=v1')->assertUnauthorized();
});

test('an org admin, operator and viewer are refused, exactly as they are for creating a template', function (string $role): void {
    Http::fake();

    $this->withToken(catalogueSampleOrgActor($role))
        ->getJson(CATALOGUE_SAMPLE_URI.'?provider=cartesia&voice_id=v1')
        ->assertForbidden();

    Http::assertNothingSent();
})->with(['admin', 'operator', 'viewer']);

test('a bare superadmin is served the audio bytes', function (): void {
    fakeCartesiaSample();

    $response = $this->withToken(catalogueSampleSuperadmin())->get(CATALOGUE_SAMPLE_URI.'?provider=cartesia&voice_id=v1');

    $response->assertOk();
    expect($response->getContent())->toBe(CATALOGUE_SAMPLE_WAV)
        ->and($response->headers->get('Content-Type'))->toBe('audio/wav')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

// ─── The upstream calls ─────────────────────────────────────────────────────

test('the file is fetched from files.cartesia.ai with the platform key as a Bearer and the Cartesia version', function (): void {
    fakeCartesiaSample();

    $this->withToken(catalogueSampleSuperadmin())->get(CATALOGUE_SAMPLE_URI.'?provider=cartesia&voice_id=v1')->assertOk();

    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === CATALOGUE_SAMPLE_FILE_URL
        && $request->header('Authorization') === ['Bearer '.CATALOGUE_SAMPLE_KEY]
        && $request->header('Cartesia-Version') === [config('avatar_preview.cartesia_version')]);
    Http::assertSent(fn (HttpRequest $request): bool => str_starts_with($request->url(), 'https://api.cartesia.ai/voices/v1')
        && str_contains($request->url(), 'expand%5B%5D=preview_file_url'));
});

test('the key is never sent to any other host', function (): void {
    fakeCartesiaSample();

    $this->withToken(catalogueSampleSuperadmin())->get(CATALOGUE_SAMPLE_URI.'?provider=cartesia&voice_id=v1')->assertOk();

    Http::assertSent(fn (HttpRequest $request): bool => in_array(parse_url($request->url(), PHP_URL_HOST), ['api.cartesia.ai', 'files.cartesia.ai'], true));
});

test('a client-supplied url is never fetched', function (): void {
    fakeCartesiaSample();

    $this->withToken(catalogueSampleSuperadmin())
        ->get(CATALOGUE_SAMPLE_URI.'?provider=cartesia&voice_id=v1&url='.urlencode('https://evil.test/steal').'&preview_file_url='.urlencode('https://evil.test/x'))
        ->assertOk();

    Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'evil.test'));
});

// ─── Host allowlist (SSRF) ──────────────────────────────────────────────────

test('a catalogue row pointing anywhere but https://files.cartesia.ai is refused and nothing is downloaded', function (string $url): void {
    fakeCartesiaSample($url);

    $response = $this->withToken(catalogueSampleSuperadmin())->get(CATALOGUE_SAMPLE_URI.'?provider=cartesia&voice_id=v1');

    $response->assertStatus(422)->assertExactJson(['message' => 'voice_preview_unavailable']);
    Http::assertSentCount(1);
    Http::assertNotSent(fn (HttpRequest $request): bool => str_starts_with($request->url(), 'https://files.cartesia.ai') || str_contains($request->url(), 'evil.test'));
    expect(Storage::allFiles())->toBe([]);
})->with([
    'foreign host' => ['https://evil.test/files/file_1/download'],
    'plain http' => ['http://files.cartesia.ai/files/file_1/download'],
    'lookalike suffix' => ['https://files.cartesia.ai.evil.test/files/file_1/download'],
    'lookalike prefix' => ['https://evilfiles.cartesia.ai/files/file_1/download'],
    'userinfo trick' => ['https://files.cartesia.ai@evil.test/files/file_1/download'],
    'userinfo on the real host' => ['https://user@files.cartesia.ai/files/file_1/download'],
    'password on the real host' => ['https://user:pw@files.cartesia.ai/files/file_1/download'],
    'other port' => ['https://files.cartesia.ai:8443/files/file_1/download'],
    'another cartesia host' => ['https://api.cartesia.ai/voices'],
    'link-local metadata' => ['https://169.254.169.254/latest/meta-data'],
    'not a url' => ['file:///etc/passwd'],
]);

test('a redirect from the file host is not followed', function (): void {
    fakeCartesiaSample(download: Http::response('', 302, ['Location' => 'https://evil.test/steal']));

    $this->withToken(catalogueSampleSuperadmin())
        ->get(CATALOGUE_SAMPLE_URI.'?provider=cartesia&voice_id=v1')
        ->assertStatus(502)
        ->assertExactJson(['message' => 'voice_preview_provider_error']);

    Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'evil.test'));
});

// ─── Size cap and content type ──────────────────────────────────────────────

test('an oversize or empty download is rejected and not cached', function (string $body): void {
    config(['avatar_preview.catalogue_sample.max_bytes' => 16]);
    fakeCartesiaSample(download: Http::response($body, 200, ['Content-Type' => 'audio/wav']));

    $this->withToken(catalogueSampleSuperadmin())
        ->get(CATALOGUE_SAMPLE_URI.'?provider=cartesia&voice_id=v1')
        ->assertStatus(502)
        ->assertExactJson(['message' => 'voice_preview_provider_error']);

    expect(Storage::allFiles())->toBe([]);
})->with(['oversize' => [str_repeat('x', 17)], 'empty' => ['']]);

test('a download that is not audio is rejected and not cached', function (?string $contentType): void {
    fakeCartesiaSample(download: Http::response('<html>nope</html>', 200, $contentType === null ? [] : ['Content-Type' => $contentType]));

    $this->withToken(catalogueSampleSuperadmin())
        ->get(CATALOGUE_SAMPLE_URI.'?provider=cartesia&voice_id=v1')
        ->assertStatus(502)
        ->assertExactJson(['message' => 'voice_preview_provider_error']);

    expect(Storage::allFiles())->toBe([]);
})->with(['html' => ['text/html'], 'json' => ['application/json'], 'octet-stream' => ['application/octet-stream']]);

test('a body of exactly max_bytes is served and one byte more is refused', function (): void {
    config(['avatar_preview.catalogue_sample.max_bytes' => 16]);
    $token = catalogueSampleSuperadmin();

    fakeCartesiaSample(download: Http::response(str_repeat('x', 16), 200, ['Content-Type' => 'audio/mpeg']));
    $this->withToken($token)->get(CATALOGUE_SAMPLE_URI.'?provider=cartesia&voice_id=v1')->assertOk();

    Cache::flush();
    Storage::fake();
    fakeCartesiaSample(download: Http::response(str_repeat('x', 17), 200, ['Content-Type' => 'audio/mpeg']));
    $this->withToken($token)->get(CATALOGUE_SAMPLE_URI.'?provider=cartesia&voice_id=v1')
        ->assertStatus(502)
        ->assertExactJson(['message' => 'voice_preview_provider_error']);
    expect(Storage::allFiles())->toBe([]);
});

test('a body that fails while it is being read is a clean provider error, with nothing leaked or cached', function (Throwable $failure): void {
    $logged = [];
    Log::listen(function ($event) use (&$logged): void {
        $logged[] = $event->message.json_encode($event->context);
    });
    fakeCartesiaSample(download: function () use ($failure) {
        $body = FnStream::decorate(Utils::streamFor('RIFF-partial'), [
            'read' => function () use ($failure): never {
                throw $failure;
            },
            'eof' => fn (): bool => false,
        ]);

        return Create::promiseFor(new PsrResponse(200, ['Content-Type' => 'audio/wav'], $body));
    });

    $response = $this->withToken(catalogueSampleSuperadmin())->get(CATALOGUE_SAMPLE_URI.'?provider=cartesia&voice_id=v1');

    $response->assertStatus(502)->assertExactJson(['message' => 'voice_preview_provider_error']);
    expect(Storage::allFiles())->toBe([])
        ->and(Cache::has('catalogue-sample:v1:'.hash('sha256', 'cartesia|v1')))->toBeFalse()
        ->and($response->getContent().implode('', $logged))
        ->not->toContain(CATALOGUE_SAMPLE_KEY)
        ->not->toContain('SECRETFILEID')
        ->not->toContain('files.cartesia.ai')
        ->not->toContain('mid-body');
})->with([
    'guzzle transfer error' => [fn () => new TransferException('mid-body '.CATALOGUE_SAMPLE_KEY.CATALOGUE_SAMPLE_FILE_URL)],
    'stream runtime error' => [fn () => new RuntimeException('mid-body '.CATALOGUE_SAMPLE_KEY.CATALOGUE_SAMPLE_FILE_URL)],
]);

// ─── Cache ──────────────────────────────────────────────────────────────────

test('a second request is served from the cache with NO upstream call', function (): void {
    fakeCartesiaSample();
    $token = catalogueSampleSuperadmin();

    $this->withToken($token)->get(CATALOGUE_SAMPLE_URI.'?provider=cartesia&voice_id=v1')->assertOk();
    Http::assertSentCount(2);

    $second = $this->withToken($token)->get(CATALOGUE_SAMPLE_URI.'?provider=cartesia&voice_id=v1');

    $second->assertOk();
    expect($second->getContent())->toBe(CATALOGUE_SAMPLE_WAV)
        ->and($second->headers->get('Content-Type'))->toBe('audio/wav');
    Http::assertSentCount(2);
});

test('the cache is per voice', function (): void {
    fakeCartesiaSample();
    $token = catalogueSampleSuperadmin();

    $this->withToken($token)->get(CATALOGUE_SAMPLE_URI.'?provider=cartesia&voice_id=v1')->assertOk();
    $this->withToken($token)->get(CATALOGUE_SAMPLE_URI.'?provider=cartesia&voice_id=v2')->assertOk();

    Http::assertSentCount(4);
    expect(Storage::allFiles())->toHaveCount(2);
});

test('an expired cache entry is downloaded again', function (): void {
    fakeCartesiaSample();
    $token = catalogueSampleSuperadmin();

    $this->withToken($token)->get(CATALOGUE_SAMPLE_URI.'?provider=cartesia&voice_id=v1')->assertOk();
    $this->travel(config('avatar_preview.catalogue_sample.cache_seconds') + 1)->seconds();
    $this->withToken($token)->get(CATALOGUE_SAMPLE_URI.'?provider=cartesia&voice_id=v1')->assertOk();

    Http::assertSentCount(4);
    expect(Storage::allFiles())->toHaveCount(1);
});

// ─── Provider failures: clean codes, no leakage ─────────────────────────────

test('provider failures map to clean codes', function (string $case, int $status, string $code): void {
    Http::preventStrayRequests();
    Http::fake(match ($case) {
        'unknown voice' => ['api.cartesia.ai/voices/*' => Http::response(['error' => 'nope'], 404)],
        'lookup refused' => ['api.cartesia.ai/voices/*' => Http::response(['error' => CATALOGUE_SAMPLE_KEY], 401)],
        'lookup unreachable' => ['api.cartesia.ai/voices/*' => fn () => throw new ConnectionException('cURL error 28 '.CATALOGUE_SAMPLE_KEY)],
        'download refused' => [
            'api.cartesia.ai/voices/*' => Http::response(['preview_file_url' => CATALOGUE_SAMPLE_FILE_URL], 200),
            'files.cartesia.ai/*' => Http::response('Unauthorized '.CATALOGUE_SAMPLE_KEY, 401),
        ],
        'download unreachable' => [
            'api.cartesia.ai/voices/*' => Http::response(['preview_file_url' => CATALOGUE_SAMPLE_FILE_URL], 200),
            'files.cartesia.ai/*' => fn () => throw new ConnectionException('cURL error 28 '.CATALOGUE_SAMPLE_FILE_URL),
        ],
        'no preview' => ['api.cartesia.ai/voices/*' => Http::response(['id' => 'v1'], 200)],
    });

    $this->withToken(catalogueSampleSuperadmin())
        ->get(CATALOGUE_SAMPLE_URI.'?provider=cartesia&voice_id=v1')
        ->assertStatus($status)
        ->assertExactJson(['message' => $code]);
})->with([
    ['unknown voice', 404, 'voice_preview_voice_not_found'],
    ['lookup refused', 502, 'voice_preview_provider_error'],
    ['lookup unreachable', 502, 'voice_preview_provider_error'],
    ['download refused', 502, 'voice_preview_provider_error'],
    ['download unreachable', 502, 'voice_preview_provider_error'],
    ['no preview', 422, 'voice_preview_unavailable'],
]);

test('a missing Cartesia key is 503 and no call is made', function (): void {
    config(['services.cartesia.api_key' => '']);
    Http::fake();

    $this->withToken(catalogueSampleSuperadmin())
        ->get(CATALOGUE_SAMPLE_URI.'?provider=cartesia&voice_id=v1')
        ->assertStatus(503)
        ->assertExactJson(['message' => 'voice_preview_provider_not_configured']);

    Http::assertNothingSent();
});

test('neither the key nor the upstream file url reaches a response, a log line or the cache', function (): void {
    $logged = [];
    Log::listen(function ($event) use (&$logged): void {
        $logged[] = $event->message.json_encode($event->context);
    });
    $token = catalogueSampleSuperadmin();
    Http::preventStrayRequests();

    $responses = [];
    foreach ([
        'download refused' => ['files.cartesia.ai/*' => Http::response('Unauthorized '.CATALOGUE_SAMPLE_KEY.CATALOGUE_SAMPLE_FILE_URL, 401)],
        'download unreachable' => ['files.cartesia.ai/*' => fn () => throw new ConnectionException('cURL error 28 '.CATALOGUE_SAMPLE_FILE_URL.CATALOGUE_SAMPLE_KEY)],
        'html' => ['files.cartesia.ai/*' => Http::response(CATALOGUE_SAMPLE_KEY, 200, ['Content-Type' => 'text/html'])],
        'ok' => ['files.cartesia.ai/*' => Http::response(CATALOGUE_SAMPLE_WAV, 200, ['Content-Type' => 'audio/wav'])],
    ] as $case => $download) {
        Cache::flush();
        Http::fake(['api.cartesia.ai/voices/*' => Http::response(['preview_file_url' => CATALOGUE_SAMPLE_FILE_URL], 200)] + $download);
        $response = $this->withToken($token)->get(CATALOGUE_SAMPLE_URI.'?provider=cartesia&voice_id=v1');
        $responses[$case] = $response->getContent().$response->headers->__toString();
    }

    $everything = implode('', $responses).implode('', $logged);

    expect($everything)->not->toContain(CATALOGUE_SAMPLE_KEY)
        ->not->toContain('SECRETFILEID')
        ->not->toContain('files.cartesia.ai');
});

// ─── Validation ─────────────────────────────────────────────────────────────

test('invalid input is a 422 and never reaches a provider', function (string $query): void {
    Http::fake();

    $this->withToken(catalogueSampleSuperadmin())->getJson(CATALOGUE_SAMPLE_URI.$query)->assertStatus(422);

    Http::assertNothingSent();
})->with([
    'missing provider' => ['?voice_id=v1'],
    'missing voice id' => ['?provider=cartesia'],
    'elevenlabs is a public CDN, not proxied' => ['?provider=elevenlabs&voice_id=v1'],
    'heygen' => ['?provider=heygen&voice_id=v1'],
    'path traversal' => ['?provider=cartesia&voice_id=..%2F..%2Fv1'],
    'slash' => ['?provider=cartesia&voice_id=a%2Fb'],
    'query injection' => ['?provider=cartesia&voice_id=a%3Fb%3D1'],
    'overlong' => ['?provider=cartesia&voice_id='.str_repeat('a', 81)],
]);

// ─── Throttle ───────────────────────────────────────────────────────────────

test('the endpoint is throttled per user', function (): void {
    config(['avatar_preview.throttle_per_minute' => 3]);
    fakeCartesiaSample();
    $token = catalogueSampleSuperadmin();

    foreach (range(1, 3) as $i) {
        $this->withToken($token)->get(CATALOGUE_SAMPLE_URI."?provider=cartesia&voice_id=v{$i}")->assertOk();
    }

    $throttled = $this->withToken($token)->getJson(CATALOGUE_SAMPLE_URI.'?provider=cartesia&voice_id=v4')->assertStatus(429);

    // The openapi.json declares this header on the 429, so the throttle must really send it.
    expect((int) $throttled->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
});
