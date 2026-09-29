<?php

declare(strict_types=1);

/**
 * Authorization matrix, T6b — the machine-to-machine routes (`/api/m2m/*`,
 * guard `api-m2m`, opaque API key + ability).
 *
 * A key authenticates as an API client of ONE organization and is authorized
 * by the abilities that client holds. Everything a route then touches must
 * belong to that organization. So, besides the catalogue's credential row:
 *
 *   - a key that is revoked, denylisted, expired, unknown or malformed is 401;
 *   - a valid key holding every ability BUT the required one is 403;
 *   - a key of org A pointed at org B's participants and projects finds
 *     nothing (404), reads nothing and writes nothing.
 *
 * Real requests, valid payloads, the database fingerprinted around every refusal.
 */

use App\Enums\ApiKeyMode;
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
    $ability = AuthMatrixMachineFixtures::M2M_ABILITIES[$key];

    $m = AuthMatrixMachineWorld::make();
    $org = $m->world->orgA;
    AuthMatrixMachineFixtures::prepare($key);

    $request = AuthMatrixMachineFixtures::m2m($key, $m, $org);
    $credential = AuthMatrixMachineCredentials::for($actor, $ability, AuthMatrixMachineFixtures::m2mAbilities(), $m, $org);

    $before = AuthMatrixSnapshot::take();
    $response = AuthMatrixRunner::send($this, $key, $credential['token'], $request['params'], $request['payload'], $credential['headers']);

    AuthMatrixRunner::judge("{$key} :: {$actor}", $expected, $response, $before, $m->world->marker);
})->with(AuthMatrixMachineDatasets::actors(AuthMatrix::AUTH_M2M));

test('refuses a key that does not authenticate with 401, and one holding no ability with 403', function (string $key, string $state): void {
    $ability = AuthMatrixMachineFixtures::M2M_ABILITIES[$key];

    $m = AuthMatrixMachineWorld::make();
    $org = $m->world->orgA;
    AuthMatrixMachineFixtures::prepare($key);

    $request = AuthMatrixMachineFixtures::m2m($key, $m, $org);
    $credential = AuthMatrixMachineCredentials::for($state, $ability, AuthMatrixMachineFixtures::m2mAbilities(), $m, $org);

    // `whoami` demands no ability, so a key that holds none is still let in.
    $expected = match (true) {
        $state !== 'no_abilities' => AuthMatrix::UNAUTHENTICATED_401,
        $ability === null => AuthMatrix::ALLOW,
        default => AuthMatrix::FORBIDDEN,
    };

    $before = AuthMatrixSnapshot::take();
    $response = AuthMatrixRunner::send($this, $key, $credential['token'], $request['params'], $request['payload'], $credential['headers']);

    AuthMatrixRunner::judge("{$key} :: {$state}", $expected, $response, $before, $m->world->marker);
})->with(AuthMatrixMachineDatasets::m2mKeyStates());

test('a key of one organization finds nothing of another organization\'s participants and projects', function (string $key): void {
    $ability = AuthMatrixMachineFixtures::M2M_ABILITIES[$key];

    $m = AuthMatrixMachineWorld::make();
    AuthMatrixMachineFixtures::prepare($key);

    // The request names orgB's resources; the key belongs to orgA and holds the ability.
    $request = AuthMatrixMachineFixtures::m2m($key, $m, $m->world->orgB);
    $credential = AuthMatrixMachineCredentials::for(AuthMatrix::KEY_WITH_ABILITY, $ability, AuthMatrixMachineFixtures::m2mAbilities(), $m, $m->world->orgA);

    $before = AuthMatrixSnapshot::take();
    $response = AuthMatrixRunner::send($this, $key, $credential['token'], $request['params'], $request['payload'], $credential['headers']);

    AuthMatrixRunner::judge("{$key} :: cross-org", AuthMatrix::NOT_FOUND, $response, $before, $m->world->marker);
})->with(AuthMatrixMachineDatasets::m2mCrossOrg());

test('listing participants returns the key\'s own organization and no other', function (): void {
    $m = AuthMatrixMachineWorld::make();
    $own = $m->participant($m->world->orgA, 'in_attesa', ApiKeyMode::Live, 'target');
    $foreign = $m->participant($m->world->orgB, 'in_attesa', ApiKeyMode::Live, 'target');
    $key = 'GET api/m2m/participants';
    $credential = AuthMatrixMachineCredentials::for(AuthMatrix::KEY_WITH_ABILITY, 'participants:read', AuthMatrixMachineFixtures::m2mAbilities(), $m, $m->world->orgA);

    $response = AuthMatrixRunner::send($this, $key, $credential['token'], [], []);

    expect($response->getStatusCode())->toBe(200)
        ->and(array_column($response->json('data'), 'id'))->toBe([$own->id])
        ->and((string) $response->getContent())->not->toContain((string) $foreign->candidate_ref);
});

test('whoami names the key\'s own organization and abilities', function (): void {
    $m = AuthMatrixMachineWorld::make();
    ['raw' => $raw, 'client' => $client] = $m->key($m->world->orgA, ['participants:read']);

    $response = AuthMatrixRunner::send($this, 'GET api/m2m/whoami', $raw, []);

    expect($response->json())->toBe([
        'client_id' => $client->id,
        'organization_id' => $m->world->orgA->id,
        'abilities' => ['participants:read'],
    ]);
});

test('every M2M route has a fixture and an ability that matches the router', function (): void {
    $catalogued = [];
    foreach (AuthMatrixCatalogue::entries() as $key => $entry) {
        if ($entry['auth'] === AuthMatrix::AUTH_M2M) {
            $catalogued[] = $key;
        }
    }

    $enforced = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/m2m/') || ! in_array('auth:api-m2m', $route->gatherMiddleware(), true)) {
            continue;
        }

        $ability = null;
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && preg_match('/(?:CheckAbility|ability):(.+)$/', $middleware, $found) === 1) {
                $ability = $found[1];
            }
        }

        $enforced[implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri()] = $ability;
    }

    ksort($enforced);
    $fixtures = AuthMatrixMachineFixtures::M2M_ABILITIES;
    ksort($fixtures);
    sort($catalogued);

    expect(array_keys($fixtures))->toBe($catalogued)
        ->and($fixtures)->toBe($enforced);
});
