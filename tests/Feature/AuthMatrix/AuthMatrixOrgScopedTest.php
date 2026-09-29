<?php

declare(strict_types=1);

/**
 * Authorization matrix, T4 — the full actor matrix of the org-scoped jwt-user domains.
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
 * Domains covered are listed in `AuthMatrixDatasets::ORG_SCOPED_DOMAINS`; the
 * credential-rejection row lives in AuthMatrixCredentialRejectionTest.
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
