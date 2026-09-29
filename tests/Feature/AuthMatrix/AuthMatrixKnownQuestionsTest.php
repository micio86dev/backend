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

use App\Enums\ApiKeyMode;
use App\Models\AiRequest;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AuthMatrix\AuthMatrix;
use Tests\Helpers\AuthMatrix\AuthMatrixFixtures;
use Tests\Helpers\AuthMatrix\AuthMatrixMachineCredentials;
use Tests\Helpers\AuthMatrix\AuthMatrixMachineFixtures;
use Tests\Helpers\AuthMatrix\AuthMatrixMachineWorld;
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

    expect($response->getStatusCode())->toBe(409)
        ->and($response->json('message'))->toBe('organization_context_required')
        ->and(AuthMatrixSnapshot::diff($before, AuthMatrixSnapshot::take()))->toBe([]);
});

test('a bare superadmin creating or importing an avatar template is refused legibly, never with a 500', function (string $key): void {
    $world = AuthMatrixWorld::make();
    $credential = $world->actor(AuthMatrix::SUPERADMIN_BARE);
    $params = AuthMatrixFixtures::params($key, $world);
    $payload = AuthMatrixFixtures::payload($key, $world, $world->orgA);

    $before = AuthMatrixSnapshot::take();
    $response = AuthMatrixRunner::send($this, $key, $credential['token'], $params, $payload);

    expect($response->getStatusCode())->toBe(409)
        ->and($response->json('message'))->toBe('organization_context_required')
        ->and(AuthMatrixSnapshot::diff($before, AuthMatrixSnapshot::take()))->toBe([]);
})->with(['POST api/avatar-templates', 'POST api/avatar-templates/import']);

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

test('a test-mode key cannot mint a session token for a live interview, nor a live key for a test one', function (string $keyMode, string $participantMode): void {
    $key = 'POST api/v1/interviews/{interview}/session-tokens';
    $m = AuthMatrixMachineWorld::make();
    AuthMatrixMachineFixtures::prepare($key);

    $request = AuthMatrixMachineFixtures::v1($key, $m, $m->world->orgA, ApiKeyMode::from($participantMode));
    $credential = AuthMatrixMachineCredentials::for(
        $keyMode === 'test' ? AuthMatrix::TEST_MODE_KEY : AuthMatrix::KEY_WITH_SCOPE,
        'interviews:write',
        AuthMatrixMachineFixtures::v1Scopes(),
        $m,
        $m->world->orgA,
    );

    $before = AuthMatrixSnapshot::take();
    $response = AuthMatrixRunner::send($this, $key, $credential['token'], $request['params'], $request['payload']);

    expect($response->getStatusCode())->toBe(404)
        ->and(AuthMatrixSnapshot::diff($before, AuthMatrixSnapshot::take()))->toBe([]);
})->with([
    'test key, live interview' => ['test', 'live'],
    'live key, test interview' => ['live', 'test'],
])->skip('KQ-5: session-tokens resolves the participant without the key mode filter every sibling route applies, so it answers 201 (SessionTokenController.php:60-63).');

test('minting a candidate token or an entry link leaves no trace on the next user token', function (string $minter): void {
    $m = AuthMatrixMachineWorld::make();
    $user = $m->world->actor(AuthMatrix::ADMIN)['user'];
    $ttlBefore = (int) config('jwt.ttl');

    if ($minter === 'candidate') {
        $m->token($m->participant($m->world->orgA, 'in_corso', label: 'minted'));
    } else {
        $m->ssoLink($m->project($m->world->orgA));
    }

    $token = (string) auth('api')->login($user);
    $claims = json_decode((string) base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);

    expect($claims)->not->toHaveKey('typ')
        ->and($claims)->not->toHaveKey('candidate_ref')
        ->and($claims['exp'] - $claims['iat'])->toBe($ttlBefore * 60);
})->with(['candidate', 'sso-link'])
    ->skip('KQ-6: the shared JWTAuth singleton keeps the custom claims and TTL of the last candidate/entry-link mint, so the next user token carries typ=candidate / typ=sso-link (CandidateTokenFactory.php:77-78, 110-112); harmless under php-fpm (one request per process), real under a long-lived worker.');

test('a user token is never accepted as an entry link, even after one was minted in the same process', function (): void {
    $m = AuthMatrixMachineWorld::make();
    $userJwt = $m->world->actor(AuthMatrix::ADMIN)['token'];
    $m->ssoLink($m->project($m->world->orgA));

    $response = $this->getJson('/api/sso/exchange?token='.urlencode($userJwt));

    expect($response->getStatusCode())->toBe(401);
})->skip('KQ-6: after a mint the shared JWT singleton re-applies its typ=sso-link claims to whatever it decodes next, so /sso/exchange answers 200 and mints a candidate token for a user JWT (SsoExchangeController.php:37-45, CandidateTokenFactory.php:77-78).');
