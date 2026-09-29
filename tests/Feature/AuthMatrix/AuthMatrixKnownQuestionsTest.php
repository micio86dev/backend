<?php

declare(strict_types=1);

/**
 * Authorization matrix — suspected bugs, written as the behaviour they SHOULD have.
 *
 * Each test asserts the intended invariant of a `KNOWN_QUESTIONS` record and
 * is skipped with that record's id in the reason, so the suite documents the
 * defect without encoding it as truth. They fail today (verified before the
 * skip was added); delete the `->skip()` when the code is fixed and the test
 * becomes the regression guard, and turn the catalogue cell from `unresolved`
 * into the intended outcome.
 */

use App\Models\AiRequest;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AuthMatrix\AuthMatrix;
use Tests\Helpers\AuthMatrix\AuthMatrixFixtures;
use Tests\Helpers\AuthMatrix\AuthMatrixRunner;
use Tests\Helpers\AuthMatrix\AuthMatrixSnapshot;
use Tests\Helpers\AuthMatrix\AuthMatrixWorld;

uses(RefreshDatabase::class);

test('a bare superadmin authoring a question is refused legibly, never with a 500', function (): void {
    $key = 'POST api/projects/{project}/questions';
    $world = AuthMatrixWorld::make();
    $credential = $world->actor(AuthMatrix::SUPERADMIN_BARE);
    $params = AuthMatrixFixtures::params($key, $world);
    $payload = AuthMatrixFixtures::payload($key, $world, $world->orgA);

    $before = AuthMatrixSnapshot::take();
    $response = AuthMatrixRunner::send($this, $key, $credential['token'], $params, $payload);

    expect($response->getStatusCode())->toBeLessThan(500)
        ->and(AuthMatrixSnapshot::diff($before, AuthMatrixSnapshot::take()))->toBe([]);
})->skip('KQ-1: MissingTenantContextException is uncaught, so this answers 500 (TenantScoped.php:77, ProjectQuestionController.php:111-118); POST /users answers a legible 409 for the same condition.');

test('a bare superadmin reads ONE population from the dashboard metrics, not a mix of scopes', function (): void {
    $key = 'GET api/dashboard/metrics';
    $world = AuthMatrixWorld::make();
    $world->resources()->participant();
    TenantContextScope::runFor($world->orgA->id, fn () => AiRequest::factory()->create([
        'evaluation_id' => $world->resources()->evaluation()->id,
        'input_tokens' => 777,
    ]));
    $credential = $world->actor(AuthMatrix::SUPERADMIN_BARE);

    $data = AuthMatrixRunner::send($this, $key, $credential['token'], [])->json('data');

    // participants_by_status is empty (explicit org filter on a null org), so the
    // evaluation and AI figures must be empty too — not the totals of every tenant.
    expect($data['participants_by_status'])->toBe([])
        ->and($data['evaluations_by_status'])->toBe([])
        ->and($data['ai_usage']['input_tokens'])->toBe(0);
})->skip('KQ-4: evaluations_by_status and ai_usage aggregate EVERY tenant under bypass while participants_by_status is empty (DashboardController.php:107-137).');

test('a bare superadmin gets one consistent answer on every org-scoped write that has no acting client', function (): void {
    $world = AuthMatrixWorld::make();
    $credential = $world->actor(AuthMatrix::SUPERADMIN_BARE);

    $statuses = [];
    foreach ([
        'PATCH api/organization',
        'POST api/organization/logo',
        'DELETE api/organization/logo',
        'POST api/users/{user}/activate',
        'POST api/users/{user}/deactivate',
    ] as $key) {
        AuthMatrixFixtures::prepare($key);
        $statuses[$key] = AuthMatrixRunner::send(
            $this,
            $key,
            $credential['token'],
            AuthMatrixFixtures::params($key, $world),
            AuthMatrixFixtures::payload($key, $world, $world->orgA),
        )->getStatusCode();
    }

    // POST /users and POST /m2m/clients already answer 409 for this condition.
    expect(array_unique($statuses))->toBe([409], 'Statuses: '.json_encode($statuses));
})->skip('KQ-2: the same missing-acting-client condition answers 403, 404 and 404 depending on the route (UpdateOrganizationRequest.php:34-36, OrganizationLogoController.php:142,243, UserAdminReader).');
