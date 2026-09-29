<?php

declare(strict_types=1);

/**
 * Authorization matrix, T6c — the Public API (`/api/v1/*`, opaque API key + scope).
 *
 * The same key model as the internal M2M surface with three differences that
 * matter here: a `beai_test_` key IS valid (against test-mode data only), a
 * live key is refused from a browser context, and errors are RFC 7807
 * problems. Besides the catalogue's credential row:
 *
 *   - a key that is revoked, denylisted, expired, unknown or malformed is 401;
 *   - a valid key holding every scope BUT the required one is 403;
 *   - a key of one organization pointed at another's exports, interviews,
 *     projects, session tokens or webhook deliveries finds nothing (404) and
 *     changes nothing;
 *   - a collection endpoint returns only the key's own organization's rows.
 *
 * `ExposureCatalogue` (T-EXPOSE-001) is about WHICH FIELDS leave the API and
 * is deliberately not touched: this file is about WHO may call.
 */

use App\Enums\ApiKeyMode;
use App\Support\PublicApi\PublicId;
use App\Support\PublicApi\WebhookDeliveryId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Helpers\AuthMatrix\AuthMatrix;
use Tests\Helpers\AuthMatrix\AuthMatrixCatalogue;
use Tests\Helpers\AuthMatrix\AuthMatrixMachineCredentials;
use Tests\Helpers\AuthMatrix\AuthMatrixMachineDatasets;
use Tests\Helpers\AuthMatrix\AuthMatrixMachineFixtures;
use Tests\Helpers\AuthMatrix\AuthMatrixMachineWorld;
use Tests\Helpers\AuthMatrix\AuthMatrixRunner;
use Tests\Helpers\AuthMatrix\AuthMatrixSnapshot;

uses(RefreshDatabase::class);

test('gets the catalogued outcome, and a denial changes nothing', function (string $key, string $actor): void {
    $expected = AuthMatrixCatalogue::entries()[$key]['outcomes'][$actor];
    $scope = AuthMatrixMachineFixtures::V1_SCOPES[$key];
    $mode = $actor === AuthMatrix::TEST_MODE_KEY ? ApiKeyMode::Test : ApiKeyMode::Live;

    $m = AuthMatrixMachineWorld::make();
    $org = $m->world->orgA;
    AuthMatrixMachineFixtures::prepare($key);

    $request = AuthMatrixMachineFixtures::v1($key, $m, $org, $mode);
    $credential = AuthMatrixMachineCredentials::for($actor, $scope, AuthMatrixMachineFixtures::v1Scopes(), $m, $org);

    $before = AuthMatrixSnapshot::take();
    $response = AuthMatrixRunner::send($this, $key, $credential['token'], $request['params'], $request['payload'], $credential['headers']);

    AuthMatrixRunner::judge("{$key} :: {$actor}", $expected, $response, $before, $m->world->marker);
})->with(AuthMatrixMachineDatasets::actors(AuthMatrix::AUTH_PUBLIC_API_KEY));

test('refuses a key that does not authenticate with 401, and one holding no scope with 403', function (string $key, string $state): void {
    $scope = AuthMatrixMachineFixtures::V1_SCOPES[$key];

    $m = AuthMatrixMachineWorld::make();
    $org = $m->world->orgA;
    AuthMatrixMachineFixtures::prepare($key);

    $request = AuthMatrixMachineFixtures::v1($key, $m, $org);
    $credential = AuthMatrixMachineCredentials::for($state, $scope, AuthMatrixMachineFixtures::v1Scopes(), $m, $org);

    // `GET /v1/organization` demands no scope, so a key that holds none is still let in.
    $expected = match (true) {
        $state !== 'no_abilities' => AuthMatrix::UNAUTHENTICATED_401,
        $scope === null => AuthMatrix::ALLOW,
        default => AuthMatrix::FORBIDDEN,
    };

    $before = AuthMatrixSnapshot::take();
    $response = AuthMatrixRunner::send($this, $key, $credential['token'], $request['params'], $request['payload'], $credential['headers']);

    AuthMatrixRunner::judge("{$key} :: {$state}", $expected, $response, $before, $m->world->marker);
})->with(AuthMatrixMachineDatasets::publicApiKeyStates());

test('a key of one organization finds nothing of another organization\'s resources', function (string $key): void {
    $scope = AuthMatrixMachineFixtures::V1_SCOPES[$key];

    $m = AuthMatrixMachineWorld::make();
    AuthMatrixMachineFixtures::prepare($key);

    // The request names orgB's resources; the key belongs to orgA and holds the scope.
    $request = AuthMatrixMachineFixtures::v1($key, $m, $m->world->orgB);
    $credential = AuthMatrixMachineCredentials::for(AuthMatrix::KEY_WITH_SCOPE, $scope, AuthMatrixMachineFixtures::v1Scopes(), $m, $m->world->orgA);

    $before = AuthMatrixSnapshot::take();
    $response = AuthMatrixRunner::send($this, $key, $credential['token'], $request['params'], $request['payload'], $credential['headers']);

    AuthMatrixRunner::judge("{$key} :: cross-org", AuthMatrix::NOT_FOUND, $response, $before, $m->world->marker);
})->with(AuthMatrixMachineDatasets::publicApiResourceRoutes());

test('a test-mode key never reaches a live-mode resource of its own organization', function (string $key): void {
    $scope = AuthMatrixMachineFixtures::V1_SCOPES[$key];

    $m = AuthMatrixMachineWorld::make();
    AuthMatrixMachineFixtures::prepare($key);

    $request = AuthMatrixMachineFixtures::v1($key, $m, $m->world->orgA, ApiKeyMode::Live);
    $credential = AuthMatrixMachineCredentials::for(AuthMatrix::TEST_MODE_KEY, $scope, [...AuthMatrixMachineFixtures::v1Scopes()], $m, $m->world->orgA);

    $before = AuthMatrixSnapshot::take();
    $response = AuthMatrixRunner::send($this, $key, $credential['token'], $request['params'], $request['payload'], $credential['headers']);

    AuthMatrixRunner::judge("{$key} :: test key on live data", AuthMatrix::NOT_FOUND, $response, $before, $m->world->marker);
})->with(AuthMatrixMachineDatasets::publicApiModeBoundRoutes());

test('a collection returns the key\'s own organization\'s rows and no other', function (string $key, Closure $ownIds, Closure $foreignIds): void {
    $m = AuthMatrixMachineWorld::make();
    AuthMatrixMachineFixtures::prepare($key);

    $own = $ownIds($m);
    $foreign = $foreignIds($m);
    $credential = AuthMatrixMachineCredentials::for(
        AuthMatrix::KEY_WITH_SCOPE,
        AuthMatrixMachineFixtures::V1_SCOPES[$key],
        AuthMatrixMachineFixtures::v1Scopes(),
        $m,
        $m->world->orgA,
    );

    $response = AuthMatrixRunner::send($this, $key, $credential['token'], [], []);
    $returned = array_column($response->json('data'), 'id');

    expect($response->getStatusCode())->toBe(200)
        ->and($returned)->toBe($own)
        ->and(array_intersect($returned, $foreign))->toBe([]);
})->with([
    'GET api/v1/exports' => [
        'GET api/v1/exports',
        fn (AuthMatrixMachineWorld $m): array => [PublicId::encode($m->export($m->world->orgA))],
        fn (AuthMatrixMachineWorld $m): array => [PublicId::encode($m->export($m->world->orgB))],
    ],
    'GET api/v1/interviews' => [
        'GET api/v1/interviews',
        fn (AuthMatrixMachineWorld $m): array => [PublicId::encode($m->readable($m->world->orgA))],
        fn (AuthMatrixMachineWorld $m): array => [PublicId::encode($m->readable($m->world->orgB))],
    ],
    'GET api/v1/projects' => [
        'GET api/v1/projects',
        fn (AuthMatrixMachineWorld $m): array => [PublicId::encode($m->project($m->world->orgA))],
        fn (AuthMatrixMachineWorld $m): array => [PublicId::encode($m->project($m->world->orgB))],
    ],
    'GET api/v1/webhooks/deliveries' => [
        'GET api/v1/webhooks/deliveries',
        fn (AuthMatrixMachineWorld $m): array => [WebhookDeliveryId::encode($m->delivery($m->world->orgA))],
        fn (AuthMatrixMachineWorld $m): array => [WebhookDeliveryId::encode($m->delivery($m->world->orgB))],
    ],
]);

test('usage counts only the key\'s own organization', function (): void {
    $m = AuthMatrixMachineWorld::make();
    $m->readable($m->world->orgA);
    $m->readable($m->world->orgB);
    $m->participant($m->world->orgB, 'completato', label: 'more');
    $m->participant($m->world->orgB, 'in_attesa', label: 'pending');
    $credential = AuthMatrixMachineCredentials::for(AuthMatrix::KEY_WITH_SCOPE, 'usage:read', AuthMatrixMachineFixtures::v1Scopes(), $m, $m->world->orgA);

    $response = AuthMatrixRunner::send($this, 'GET api/v1/usage', $credential['token'], []);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->json('interviews.completed'))->toBe(1)
        ->and($response->json('interviews.pending'))->toBe(0);
});

test('the organization endpoint describes the key\'s own organization', function (): void {
    $m = AuthMatrixMachineWorld::make();
    $credential = AuthMatrixMachineCredentials::for(AuthMatrix::KEY_WITH_SCOPE, null, AuthMatrixMachineFixtures::v1Scopes(), $m, $m->world->orgA);

    $response = AuthMatrixRunner::send($this, 'GET api/v1/organization', $credential['token'], []);

    expect($response->getStatusCode())->toBe(200)
        ->and((string) $response->getContent())->toContain("{$m->world->marker} org a")
        ->and((string) $response->getContent())->not->toContain("{$m->world->marker} org b");
});

test('every Public API route has a fixture and a scope that matches the router', function (): void {
    $catalogued = [];
    foreach (AuthMatrixCatalogue::entries() as $key => $entry) {
        if ($entry['auth'] === AuthMatrix::AUTH_PUBLIC_API_KEY) {
            $catalogued[] = $key;
        }
    }

    $enforced = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $middleware = array_map(static fn (mixed $m): string => is_string($m) ? $m : '', $route->gatherMiddleware());
        $authenticated = array_filter($middleware, static fn (string $m): bool => str_ends_with($m, 'AuthenticatePublicApi'));

        if (! str_starts_with($route->uri(), 'api/v1/') || $authenticated === []) {
            continue;
        }

        $scope = null;
        foreach ($middleware as $name) {
            if (preg_match('/(?:RequireScope|^scope):(.+)$/', $name, $found) === 1) {
                $scope = $found[1];
            }
        }

        $enforced[implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri()] = $scope;
    }

    ksort($enforced);
    $fixtures = AuthMatrixMachineFixtures::V1_SCOPES;
    ksort($fixtures);
    sort($catalogued);

    expect(array_keys($fixtures))->toBe($catalogued)
        ->and($fixtures)->toBe($enforced);
});
