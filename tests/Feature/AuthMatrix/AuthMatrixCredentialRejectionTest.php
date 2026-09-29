<?php

declare(strict_types=1);

/**
 * Authorization matrix, T3 — the credential-rejection row of EVERY jwt-user route.
 *
 * A user-JWT route must answer 401 to a request that carries no credential,
 * and to a credential of the wrong KIND: a candidate JWT (typ=candidate) or a
 * live M2M / Public API key. Each of those is presented with a VALID payload
 * where the route has a fixture, so the 401 is proven to come from the guard
 * and not from a validation failure that happened to run first, and each one
 * asserts the request changed nothing (the whole database is fingerprinted).
 *
 * The catalogue drives the cases (all domains); the request is real HTTP.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AuthMatrix\AuthMatrix;
use Tests\Helpers\AuthMatrix\AuthMatrixCatalogue;
use Tests\Helpers\AuthMatrix\AuthMatrixDatasets;
use Tests\Helpers\AuthMatrix\AuthMatrixFixtures;
use Tests\Helpers\AuthMatrix\AuthMatrixRunner;
use Tests\Helpers\AuthMatrix\AuthMatrixSnapshot;
use Tests\Helpers\AuthMatrix\AuthMatrixWorld;

uses(RefreshDatabase::class);

test('rejects a non-user credential with 401 and changes nothing', function (string $key, string $actor): void {
    $expected = AuthMatrixCatalogue::entries()[$key]['outcomes'][$actor];
    expect($expected)->toBe(AuthMatrix::UNAUTHENTICATED_401);

    $world = AuthMatrixWorld::make();
    $credential = $world->actor($actor);
    $params = AuthMatrixFixtures::params($key, $world);
    $payload = AuthMatrixFixtures::payload($key, $world, $world->orgA);

    $before = AuthMatrixSnapshot::take();
    $response = AuthMatrixRunner::send($this, $key, $credential['token'], $params, $payload);

    AuthMatrixRunner::judge("{$key} :: {$actor}", $expected, $response, $before, $world->marker);
})->with(AuthMatrixDatasets::rejectedCredentials());
