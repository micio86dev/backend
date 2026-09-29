<?php

declare(strict_types=1);

/**
 * Authorization matrix, T4/T5 — the full actor matrix of EVERY jwt-user route (org-scoped and platform).
 *
 * One REAL request per (route, actor), built from a valid fixture so the
 * outcome is the authorization decision and not a validation accident:
 *
 *   - the status matches the catalogue (`2xx` is a class, the rest exact);
 *   - a denial (403 / 404 / 409 / 422) changed NOTHING in the database;
 *   - a denied response never mentions the target resource, so a cross-tenant
 *     404/403 is not an existence oracle.
 *
 * Cells the catalogue leaves UNRESOLVED are not asserted: they are the
 * suspected bugs listed in `AuthMatrixCatalogue::knownQuestions()`. The
 * request is still sent so the skip reason states what the code does TODAY.
 *
 * Every jwt-user route of the catalogue is covered (no domain filter): a route
 * without a fixture fails the guard below instead of being probed with
 * placeholder ids. The credential-rejection row lives in
 * AuthMatrixCredentialRejectionTest.
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

test('gets the catalogued outcome, and a denial changes nothing', function (string $key, string $actor): void {
    $expected = AuthMatrixCatalogue::entries()[$key]['outcomes'][$actor];

    AuthMatrixFixtures::prepare($key);
    $world = AuthMatrixWorld::make();
    $credential = $world->actor($actor);
    $params = AuthMatrixFixtures::params($key, $world);
    $payload = AuthMatrixFixtures::payload($key, $world, $credential['org'] ?? $world->orgA);

    $before = AuthMatrixSnapshot::take();
    $response = AuthMatrixRunner::send($this, $key, $credential['token'], $params, $payload);

    if ($expected === AuthMatrix::UNRESOLVED) {
        AuthMatrixRunner::judgeUnresolvedRefusal("{$key} :: {$actor}", $response, $before);

        $question = AuthMatrixRunner::knownQuestion($key, $actor) ?? 'UNTRACKED';
        $observed = "{$question}: {$key} :: {$actor} is unresolved; the code currently answers {$response->getStatusCode()}.";

        // `AUTHMATRIX_REPORT=1` prints the observed status of every unresolved
        // cell, since a skip reason alone does not reach the runner's summary.
        if (getenv('AUTHMATRIX_REPORT') !== false) {
            fwrite(STDERR, "\n[unresolved] {$observed} ".mb_substr((string) $response->getContent(), 0, 200)."\n");
        }

        $this->markTestSkipped($observed);
    }

    AuthMatrixRunner::judge("{$key} :: {$actor}", $expected, $response, $before, $world->marker);
})->with(AuthMatrixDatasets::userActors());

test('every jwt-user route has a fixture, and every fixture names a catalogued route', function (): void {
    $jwtUserRoutes = [];
    foreach (AuthMatrixCatalogue::entries() as $key => $entry) {
        if ($entry['auth'] === AuthMatrix::AUTH_JWT_USER) {
            $jwtUserRoutes[] = $key;
        }
    }

    $withoutFixture = array_values(array_filter($jwtUserRoutes, static fn (string $key): bool => ! AuthMatrixFixtures::has($key)));
    $orphans = array_values(array_diff(AuthMatrixFixtures::keys(), array_keys(AuthMatrixCatalogue::entries())));

    expect($withoutFixture)->toBe([], "jwt-user routes with no fixture (they would be probed with placeholder ids and no payload, so a 403 proves nothing):\n  - ".implode("\n  - ", $withoutFixture))
        ->and($orphans)->toBe([], "Fixtures for routes that are not in the catalogue:\n  - ".implode("\n  - ", $orphans));
});
