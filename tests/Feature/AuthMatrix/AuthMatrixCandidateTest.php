<?php

declare(strict_types=1);

/**
 * Authorization matrix, T6a — the candidate routes (`/api/candidate/*`, guard
 * `api-candidate`).
 *
 * A candidate is authenticated by a BEAI-minted JWT (`typ=candidate`) whose
 * `sub` is a participant id, and everything it may touch is named by that
 * participant: its own profile, and interview sessions it owns. So beyond the
 * credential row the interesting questions are:
 *
 *   - can a candidate become another (forged / swapped / expired / wrong-type
 *     token)?  -> 401
 *   - can a candidate name another participant's session, in its own or in
 *     another organization?  -> 404, nothing read, nothing written
 *   - what does each lifecycle state of the participant allow?
 *
 * One REAL request per case, valid payload, the whole database fingerprinted
 * around every refusal ({@see AuthMatrixSnapshot}). The catalogue drives the
 * credential rows; the scenarios below are the attacks the catalogue's
 * vocabulary cannot express.
 */

use App\Enums\ApiKeyMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AuthMatrix\AuthMatrix;
use Tests\Helpers\AuthMatrix\AuthMatrixCandidateFixtures;
use Tests\Helpers\AuthMatrix\AuthMatrixCatalogue;
use Tests\Helpers\AuthMatrix\AuthMatrixMachineDatasets;
use Tests\Helpers\AuthMatrix\AuthMatrixMachineWorld;
use Tests\Helpers\AuthMatrix\AuthMatrixRunner;
use Tests\Helpers\AuthMatrix\AuthMatrixSnapshot;

uses(RefreshDatabase::class);

test('gets the catalogued outcome, and a denial changes nothing', function (string $key, string $actor): void {
    $expected = AuthMatrixCatalogue::entries()[$key]['outcomes'][$actor];

    $m = AuthMatrixMachineWorld::make();
    AuthMatrixCandidateFixtures::prepare($key);

    $participant = AuthMatrixCandidateFixtures::activeParticipant($key, $m);
    $status = 'in_corso';

    if ($actor === AuthMatrix::CANDIDATE_TERMINAL) {
        $participant = $m->participant($m->world->orgA, 'completato', label: 'terminal');
        $status = 'completed';
    }

    $session = AuthMatrixCandidateFixtures::sessionOf($key, $m, $participant, $status);
    $token = match ($actor) {
        AuthMatrix::UNAUTHENTICATED => null,
        AuthMatrix::CANDIDATE_ACTIVE, AuthMatrix::CANDIDATE_TERMINAL => $m->token($participant),
        AuthMatrix::USER_JWT => $m->world->actor(AuthMatrix::ADMIN)['token'],
        AuthMatrix::API_KEY => $m->world->actor(AuthMatrix::API_KEY)['token'],
    };
    $payload = AuthMatrixCandidateFixtures::payload($key, $session);

    $before = AuthMatrixSnapshot::take();
    $response = AuthMatrixRunner::send($this, $key, $token, [], $payload);

    AuthMatrixRunner::judge("{$key} :: {$actor}", $expected, $response, $before, $m->world->marker);
})->with(AuthMatrixMachineDatasets::actors(AuthMatrix::AUTH_CANDIDATE));

test('refuses a forged or unusable candidate token with 401 and changes nothing', function (string $key, string $forgery): void {
    $m = AuthMatrixMachineWorld::make();
    AuthMatrixCandidateFixtures::prepare($key);

    $owner = AuthMatrixCandidateFixtures::activeParticipant($key, $m);
    $victim = $m->participant($m->world->orgA, 'in_corso', label: 'victim');
    $session = AuthMatrixCandidateFixtures::sessionOf($key, $m, $owner);
    $payload = AuthMatrixCandidateFixtures::payload($key, $session);
    $token = AuthMatrixCandidateFixtures::forgedToken($forgery, $m, $owner, $victim);

    $before = AuthMatrixSnapshot::take();
    $response = AuthMatrixRunner::send($this, $key, $token, [], $payload);

    AuthMatrixRunner::judge("{$key} :: {$forgery}", AuthMatrix::UNAUTHENTICATED_401, $response, $before, $m->world->marker);
})->with(AuthMatrixMachineDatasets::candidateForgeries());

test('a candidate token never reaches a session it does not own, in its organization or another', function (string $key, string $victim): void {
    $m = AuthMatrixMachineWorld::make();
    AuthMatrixCandidateFixtures::prepare($key);

    $attacker = $m->participant($m->world->orgA, 'in_corso', label: 'attacker');
    $owner = $victim === 'same_org_participant'
        ? $m->participant($m->world->orgA, 'in_corso', label: 'victim')
        : $m->participant($m->world->orgB, 'in_corso', label: 'victim');
    $victimSession = $m->session($owner);
    $payload = AuthMatrixCandidateFixtures::payload($key, $victimSession);
    $token = $m->token($attacker);

    $before = AuthMatrixSnapshot::take();
    $response = AuthMatrixRunner::send($this, $key, $token, [], $payload);

    AuthMatrixRunner::judge("{$key} :: {$victim}", AuthMatrix::NOT_FOUND, $response, $before, "{$m->world->marker} victim");
})->with(AuthMatrixMachineDatasets::candidateIsolation());

test('a candidate profile and a fresh start describe only the token\'s own participant', function (string $key): void {
    $m = AuthMatrixMachineWorld::make();
    AuthMatrixCandidateFixtures::prepare($key);

    $own = AuthMatrixCandidateFixtures::activeParticipant($key, $m);
    $m->participant($m->world->orgA, 'in_corso', label: 'victim');
    $m->participant($m->world->orgB, 'in_corso', label: 'victim');

    $response = AuthMatrixRunner::send($this, $key, $m->token($own), [], AuthMatrixCandidateFixtures::payload($key, null));

    expect($response->getStatusCode())->toBeLessThan(300)
        ->and((string) $response->getContent())->not->toContain("{$m->world->marker} victim");

    if ($key === 'GET api/candidate/session') {
        expect($response->json('data.id'))->toBe($own->id);
    }
})->with(['GET api/candidate/session', 'POST api/candidate/interview/start']);

test('the participant lifecycle decides what a candidate may still do', function (string $key, string $status, int $expected): void {
    $m = AuthMatrixMachineWorld::make();
    AuthMatrixCandidateFixtures::prepare($key);

    // `/start` runs against the mock provider, which needs an interviewable role.
    $m->startable($m->world->orgA);
    $participant = $m->participant($m->world->orgA, $status, ApiKeyMode::Test, "life {$status} {$key}");
    $session = $m->session($participant, AuthMatrixMachineDatasets::LIFECYCLE[$status], 'mock');
    $payload = AuthMatrixCandidateFixtures::payload($key, AuthMatrixCandidateFixtures::namesSession($key) ? $session : null);

    $before = AuthMatrixSnapshot::take();
    $response = AuthMatrixRunner::send($this, $key, $m->token($participant), [], $payload);

    $outcome = $expected < 300 ? AuthMatrix::ALLOW : (string) $expected;
    AuthMatrixRunner::judge("{$key} :: {$status}", $outcome, $response, $before, $m->world->marker);
    expect($response->getStatusCode())->toBe($expected, "{$key} :: {$status}");
})->with(AuthMatrixMachineDatasets::candidateLifecycle());

test('every candidate route of the catalogue has a fixture, and vice versa', function (): void {
    $catalogued = [];
    foreach (AuthMatrixCatalogue::entries() as $key => $entry) {
        if ($entry['auth'] === AuthMatrix::AUTH_CANDIDATE) {
            $catalogued[] = $key;
        }
    }

    $fixtures = AuthMatrixCandidateFixtures::keys();
    sort($catalogued);
    sort($fixtures);

    expect($fixtures)->toBe($catalogued);
});
