<?php

declare(strict_types=1);

/**
 * Authorization matrix, T7 — the surfaces a caller reaches WITHOUT a credential:
 * the health probes, the embed session-token exchange, the SSO entry-link
 * exchange and the public organization logo.
 *
 * "Open" is the dangerous half of authorization: nothing refuses the caller,
 * so what keeps these safe is (a) what they SAY and (b) the token that stands
 * in for a credential. So this file pins both:
 *
 *   - every body an anonymous caller can read is an ALLOW-LIST of keys. The
 *     existing health tests deny-list identifying keys; a new field that is
 *     not on a deny-list would still leak. Here a new field fails the test
 *     until somebody decides it is safe to publish;
 *   - a forged, expired, replayed or wrong-typed token opens nothing, changes
 *     nothing and is not distinguishable from any other bad token.
 */

use App\Enums\ApiKeyMode;
use App\Models\Organization;
use App\Models\Participant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Helpers\AuthMatrix\AuthMatrix;
use Tests\Helpers\AuthMatrix\AuthMatrixCandidateFixtures;
use Tests\Helpers\AuthMatrix\AuthMatrixMachineWorld;
use Tests\Helpers\AuthMatrix\AuthMatrixSnapshot;

uses(RefreshDatabase::class);

/**
 * Credentials an anonymous-surface caller might present anyway. None may change the answer.
 *
 * @return array<string, array{string}>
 */
function amxOpenCredentials(): array
{
    return [
        'no credential' => ['none'],
        'garbage bearer' => ['garbage'],
        'user jwt' => ['user_jwt'],
        'candidate jwt' => ['candidate_jwt'],
        'api key' => ['api_key'],
    ];
}

function amxOpenHeaders(string $credential, AuthMatrixMachineWorld $m): array
{
    $token = match ($credential) {
        'none' => null,
        'garbage' => 'not-a-credential',
        'user_jwt' => $m->world->actor(AuthMatrix::ADMIN)['token'],
        'candidate_jwt' => $m->token($m->participant($m->world->orgA, 'in_corso', label: 'bearer')),
        'api_key' => $m->key($m->world->orgA, ['participants:read'])['raw'],
    };

    return $token === null ? [] : ['Authorization' => 'Bearer '.$token];
}

/**
 * The shape of a payload as its dotted key paths, so a new field is a failure.
 *
 * @param  array<string, mixed>  $payload
 * @return list<string>
 */
function amxKeyShape(array $payload): array
{
    $keys = array_keys(Arr::dot($payload));
    sort($keys);

    return $keys;
}

// ─── health probes ───────────────────────────────────────────────────────────

test('GET /health says exactly {status: ok} to anybody, whatever credential rides along', function (string $credential): void {
    $m = AuthMatrixMachineWorld::make();

    $response = $this->withHeaders(amxOpenHeaders($credential, $m))->getJson('/api/health');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->json())->toBe(['status' => 'ok']);
})->with(amxOpenCredentials());

test('GET /v1/health says exactly {status: ok} to anybody', function (string $credential): void {
    $m = AuthMatrixMachineWorld::make();

    $response = $this->withHeaders(amxOpenHeaders($credential, $m))->getJson('/api/v1/health');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->json())->toBe(['status' => 'ok']);
})->with(amxOpenCredentials());

test('GET /health/queue publishes exactly this allow-list of fields, of these types, to anybody', function (string $credential): void {
    $m = AuthMatrixMachineWorld::make();
    Cache::put('beai:queue:heartbeat', now()->timestamp, 300);
    Cache::put('beai:queue:last_processed_at', now()->timestamp, 300);

    $response = $this->withHeaders(amxOpenHeaders($credential, $m))->getJson('/api/health/queue');
    $body = $response->json();

    expect($response->getStatusCode())->toBe(200)
        ->and(amxKeyShape($body))->toBe([
            'failed.count',
            'failed.oldest_age_seconds',
            'mail.delivers',
            'mail.mailer',
            'queue.depth',
            'queue.last_processed_age_seconds',
            'queue.oldest_reserved_age_seconds',
            'queue.reservation_stalled',
            'queue.stalled',
            'redis_eviction_policy',
            'status',
            'worker.alive',
            'worker.last_heartbeat_age_seconds',
        ]);

    // Only counts, ages, booleans and the two short labels a probe needs.
    foreach (Arr::dot($body) as $path => $value) {
        expect($value === null || is_int($value) || is_bool($value) || is_string($value))->toBeTrue("{$path} must be a scalar");

        if (is_string($value)) {
            expect(mb_strlen($value))->toBeLessThanOrEqual(40, "{$path} is a label, not free text");
        }
    }
})->with(amxOpenCredentials());

test('GET /health/queue while the worker is down publishes exactly this allow-list of fields', function (): void {
    $response = $this->getJson('/api/health/queue');

    expect($response->getStatusCode())->toBe(503)
        ->and(amxKeyShape($response->json()))->toBe([
            'failed',
            'mail.delivers',
            'mail.mailer',
            'queue',
            'redis_eviction_policy',
            'status',
            'worker.alive',
            'worker.last_heartbeat_age_seconds',
        ]);
});

test('GET /health/queue never repeats what a failed job carries', function (): void {
    $m = AuthMatrixMachineWorld::make();
    Cache::put('beai:queue:heartbeat', now()->timestamp, 300);

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\Secret', 'data' => ['candidate' => "{$m->world->marker} payload"]]),
        'exception' => "RuntimeException: {$m->world->marker} exception at /var/www/app/Secret.php:1",
        'failed_at' => now()->subMinute(),
    ]);

    $content = (string) $this->getJson('/api/health/queue')->getContent();

    expect($content)->not->toContain($m->world->marker)
        ->and($content)->not->toContain('Secret')
        ->and($content)->not->toContain('/var/www');
});

// ─── embed session-token exchange ────────────────────────────────────────────

/**
 * Ways an embed session token can be wrong. Each yields the token to send.
 *
 * @return array<string, Closure(AuthMatrixMachineWorld, Participant): string>
 */
function amxEmbedForgeries(): array
{
    return [
        'garbage' => fn (): string => 'not-a-token',
        'tampered_signature' => function (AuthMatrixMachineWorld $m, Participant $p): string {
            return AuthMatrixCandidateFixtures::tamperSignature($m->sessionToken($p));
        },
        'wrong_secret' => function (AuthMatrixMachineWorld $m, Participant $p): string {
            $genuine = config('public_api.session_secret');
            config(['public_api.session_secret' => 'another-secret-another-secret-another-secret']);
            $token = $m->sessionToken($p);
            config(['public_api.session_secret' => $genuine]);

            return $token;
        },
        'expired' => function (AuthMatrixMachineWorld $m, Participant $p): string {
            $token = $m->sessionToken($p);
            Carbon::setTestNow(Carbon::now()->addHours(2));

            return $token;
        },
        'candidate_jwt' => fn (AuthMatrixMachineWorld $m, Participant $p): string => $m->token($p),
        'user_jwt' => fn (AuthMatrixMachineWorld $m): string => $m->world->actor(AuthMatrix::ADMIN)['token'],
    ];
}

test('the embed exchange and frame-policy refuse a token that is not a genuine session token with 401, and change nothing', function (string $route, string $forgery): void {
    $m = AuthMatrixMachineWorld::make();
    $participant = $m->participant($m->world->orgA, 'in_attesa', ApiKeyMode::Live, 'embed');
    $token = amxEmbedForgeries()[$forgery]($m, $participant);

    $before = AuthMatrixSnapshot::take();
    $response = $this->getJson("/api/embed/{$route}?token=".urlencode($token));

    expect($response->getStatusCode())->toBe(401)
        ->and($response->json('code'))->toBe('token_invalid')
        ->and((string) $response->getContent())->not->toContain($m->world->marker)
        ->and(AuthMatrixSnapshot::diff($before, AuthMatrixSnapshot::take()))->toBe([]);
})->with(function (): Generator {
    foreach (['exchange', 'frame-policy'] as $route) {
        foreach (array_keys(amxEmbedForgeries()) as $forgery) {
            yield "{$route} :: {$forgery}" => [$route, $forgery];
        }
    }
});

test('the embed exchange and frame-policy refuse a missing or empty token with 401', function (string $route, string $query): void {
    $before = AuthMatrixSnapshot::take();
    $response = $this->getJson("/api/embed/{$route}{$query}");

    expect($response->getStatusCode())->toBe(401)
        ->and(AuthMatrixSnapshot::diff($before, AuthMatrixSnapshot::take()))->toBe([]);
})->with([
    'exchange, no token' => ['exchange', ''],
    'exchange, empty token' => ['exchange', '?token='],
    'frame-policy, no token' => ['frame-policy', ''],
    'frame-policy, empty token' => ['frame-policy', '?token='],
]);

test('a genuine session token is exchanged for a candidate token of ITS participant, exactly once', function (): void {
    $m = AuthMatrixMachineWorld::make();
    $participant = $m->participant($m->world->orgA, 'in_attesa', ApiKeyMode::Live, 'embed');
    $other = $m->participant($m->world->orgA, 'in_attesa', ApiKeyMode::Live, 'bystander');
    $token = $m->sessionToken($participant);

    $first = $this->getJson('/api/embed/exchange?token='.urlencode($token));

    expect($first->getStatusCode())->toBe(200)
        ->and(array_keys($first->json()))->toBe(['access_token']);

    // The candidate token opens THAT participant's profile and nobody else's.
    resetAuthGuardState();
    $profile = $this->flushHeaders()->withToken($first->json('access_token'))->getJson('/api/candidate/session');
    expect($profile->json('data.id'))->toBe($participant->id)
        ->and((string) $profile->getContent())->not->toContain((string) $other->candidate_ref);

    // A replay is refused as CONSUMED, and changes nothing.
    $before = AuthMatrixSnapshot::take();
    $replay = $this->flushHeaders()->getJson('/api/embed/exchange?token='.urlencode($token));

    expect($replay->getStatusCode())->toBe(410)
        ->and($replay->json('code'))->toBe('token_consumed')
        ->and(AuthMatrixSnapshot::diff($before, AuthMatrixSnapshot::take()))->toBe([]);
});

test('a session token that is superseded, or whose participant already started, is refused as consumed', function (string $case): void {
    $m = AuthMatrixMachineWorld::make();
    $participant = $m->participant($m->world->orgA, $case === 'started' ? 'in_corso' : 'in_attesa', ApiKeyMode::Live, 'embed');
    $token = $m->sessionToken($participant);

    if ($case === 'superseded') {
        // POST /v1/interviews/{id}/session-tokens mints a new one and revokes the previous.
        $m->sessionToken($participant);
    }

    $before = AuthMatrixSnapshot::take();
    $response = $this->getJson('/api/embed/exchange?token='.urlencode($token));

    expect($response->getStatusCode())->toBe(410)
        ->and(AuthMatrixSnapshot::diff($before, AuthMatrixSnapshot::take()))->toBe([]);
})->with(['superseded', 'started']);

test('the frame policy of a genuine token publishes exactly the organization\'s allowed domains', function (): void {
    $m = AuthMatrixMachineWorld::make();
    $org = $m->world->orgA;
    $org->forceFill(['allowed_domains' => ['https://hr.example.test']])->save();
    $participant = $m->participant($org, 'in_attesa', ApiKeyMode::Live, 'embed');

    $response = $this->getJson('/api/embed/frame-policy?token='.urlencode($m->sessionToken($participant)));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->json())->toBe(['allowed_domains' => ['https://hr.example.test']]);
});

// ─── SSO entry-link exchange ─────────────────────────────────────────────────

test('the SSO exchange refuses a token that is not a genuine entry link with 401, and changes nothing', function (string $forgery): void {
    $m = AuthMatrixMachineWorld::make();
    $project = $m->project($m->world->orgA);

    // Each forgery mints ONLY what it needs: a mint leaves its claims on the shared JWT
    // singleton, which a token decoded afterwards in the same process would inherit (KQ-6).
    $token = match ($forgery) {
        'garbage' => 'not-a-token',
        'tampered_signature' => AuthMatrixCandidateFixtures::tamperSignature($m->ssoLink($project)),
        'expired' => (function () use ($m, $project): string {
            $genuine = $m->ssoLink($project);
            Carbon::setTestNow(Carbon::now()->addHours(2));

            return $genuine;
        })(),
        'candidate_jwt' => $m->token($m->participant($m->world->orgA, 'in_attesa', ApiKeyMode::Live, 'bearer')),
        'user_jwt' => $m->world->actor(AuthMatrix::ADMIN)['token'],
        'unknown_project' => $m->ssoLink($project, ['project_id' => 987654]),
        default => throw new LogicException("Unknown forgery {$forgery}"),
    };

    $before = AuthMatrixSnapshot::take();
    $response = $this->getJson('/api/sso/exchange?token='.urlencode($token));

    expect($response->getStatusCode())->toBe(401)
        ->and($response->json())->toBe(['message' => 'Unauthenticated.'])
        ->and(AuthMatrixSnapshot::diff($before, AuthMatrixSnapshot::take()))->toBe([]);
})->with(['garbage', 'tampered_signature', 'expired', 'candidate_jwt', 'user_jwt', 'unknown_project']);

test('the SSO exchange refuses a missing token with 401', function (): void {
    expect($this->getJson('/api/sso/exchange')->getStatusCode())->toBe(401)
        ->and($this->getJson('/api/sso/exchange?token=')->getStatusCode())->toBe(401);
});

test('an entry link opens exactly once, in the project\'s own organization, whatever org the token claims', function (): void {
    $m = AuthMatrixMachineWorld::make();
    $project = $m->project($m->world->orgA);
    // The claim names ANOTHER organization; the row must follow the PROJECT, never the claim.
    $token = $m->ssoLink($project, ['org_id' => $m->world->orgB->id]);

    $first = $this->getJson('/api/sso/exchange?token='.urlencode($token));

    expect($first->getStatusCode())->toBe(200)
        ->and(array_keys($first->json()))->toBe(['access_token']);

    $created = Participant::withoutGlobalScopes()->where('project_id', $project->id)->sole();
    expect($created->organization_id)->toBe($m->world->orgA->id);

    // The candidate token opens that participant's profile and nobody else's.
    resetAuthGuardState();
    $profile = $this->flushHeaders()->withToken($first->json('access_token'))->getJson('/api/candidate/session');
    expect($profile->json('data.id'))->toBe($created->id);

    // Replaying the same link is refused, and changes nothing.
    $before = AuthMatrixSnapshot::take();
    $replay = $this->flushHeaders()->getJson('/api/sso/exchange?token='.urlencode($token));

    expect($replay->getStatusCode())->toBe(401)
        ->and(AuthMatrixSnapshot::diff($before, AuthMatrixSnapshot::take()))->toBe([]);
});

test('an entry link to a project that is not open is refused with the same generic 403, and creates nothing', function (): void {
    $m = AuthMatrixMachineWorld::make();
    $project = $m->project($m->world->orgA);
    DB::table('projects')->where('id', $project->id)->update(['status' => 'draft']);
    $token = $m->ssoLink($project);

    $before = AuthMatrixSnapshot::take();
    $response = $this->getJson('/api/sso/exchange?token='.urlencode($token));

    expect($response->getStatusCode())->toBe(403)
        ->and(array_keys($response->json()))->toBe(['message', 'redirect_url'])
        ->and($response->json('message'))->toBe('Access denied.')
        ->and(AuthMatrixSnapshot::diff($before, AuthMatrixSnapshot::take()))->toBe([]);
});

test('a candidate token minted from an SSO link is not a user credential', function (): void {
    $m = AuthMatrixMachineWorld::make();
    $project = $m->project($m->world->orgA);
    $access = $this->getJson('/api/sso/exchange?token='.urlencode($m->ssoLink($project)))->json('access_token');

    resetAuthGuardState();
    $response = $this->flushHeaders()->withToken($access)->getJson('/api/auth/me');

    expect($response->getStatusCode())->toBe(401);
});

// ─── public organization logo ────────────────────────────────────────────────

test('the public logo answers one identical 404 for an unknown organization, no logo, and a key it refuses to sign', function (): void {
    Storage::fake();
    $m = AuthMatrixMachineWorld::make();
    $noLogo = $m->world->orgA;
    $foreignKey = $m->world->orgB;
    $foreignKey->forceFill(['logo_path' => 'recordings/1/2/interview.ogg'])->save();

    $answers = [
        'unknown organization' => $this->getJson('/api/organizations/999999/logo'),
        'no logo' => $this->getJson("/api/organizations/{$noLogo->id}/logo"),
        'key outside the logo prefix' => $this->getJson("/api/organizations/{$foreignKey->id}/logo"),
    ];

    foreach ($answers as $case => $response) {
        expect($response->getStatusCode())->toBe(404, $case);
    }

    expect(array_unique(array_map(static fn ($r): string => (string) $r->getContent(), $answers)))->toHaveCount(1);
});

test('the public logo redirects to a signed URL of exactly the organization\'s own logo', function (): void {
    Storage::fake();
    $m = AuthMatrixMachineWorld::make();
    Storage::put('organization-logos/'.$m->world->orgA->id.'/logo.png', 'png-bytes');
    $m->world->orgA->forceFill(['logo_path' => 'organization-logos/'.$m->world->orgA->id.'/logo.png'])->save();

    $response = $this->get("/api/organizations/{$m->world->orgA->id}/logo");

    expect($response->getStatusCode())->toBe(302)
        ->and((string) $response->headers->get('Location'))->toContain('organization-logos/'.$m->world->orgA->id.'/logo.png')
        ->and(Organization::query()->whereKey($m->world->orgB->id)->value('logo_path'))->toBeNull();
});

test('every public surface is reachable with no credential at all', function (): void {
    // The catalogue lists these as `open`; a guard added by mistake would turn
    // one into a 401 for the very callers it exists for (probes, the embedded frame).
    foreach (['/api/health', '/api/health/queue', '/api/v1/health', '/api/embed/exchange', '/api/embed/frame-policy', '/api/sso/exchange'] as $uri) {
        $status = $this->getJson($uri)->getStatusCode();

        // Answers about the request, never about the (absent) credential:
        // 200/503 for a probe, 401 `token_invalid` for a missing token.
        expect($status)->toBeIn([200, 401, 503], $uri);
    }
});
