<?php

declare(strict_types=1);

/**
 * Authorization matrix, T5 — what the status-only matrix cannot see on the
 * platform surfaces.
 *
 * The matrix proves WHO gets which status and that a denial changed no DATABASE
 * row. Three things escape it, and each is asserted here:
 *
 *   - state that lives OUTSIDE the database (the acting-client switch and the
 *     API-key revocation flag are cache entries, and the snapshot skips `cache`);
 *   - the EFFECT of an allowed call (a 2xx from `publish` or `deactivate` says
 *     nothing about the row having changed);
 *   - where a bare superadmin (no client selected) and an acting one genuinely
 *     differ, which is the whole reason both are actors.
 */

use App\Models\ApiClient;
use App\Models\AvatarTemplate;
use App\Models\FrameworkCatalogRevision;
use App\Support\Tenancy\ActingOrganization;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Helpers\AuthMatrix\AuthMatrix;
use Tests\Helpers\AuthMatrix\AuthMatrixRunner;
use Tests\Helpers\AuthMatrix\AuthMatrixWorld;

uses(RefreshDatabase::class);

const NON_SUPERADMIN_ACTORS = [AuthMatrix::ADMIN, AuthMatrix::OPERATOR, AuthMatrix::VIEWER, AuthMatrix::NO_ROLE, AuthMatrix::CROSS_TENANT_ADMIN];

function amxTemplateOf(AuthMatrixWorld $world, bool $orgA, string $label): AvatarTemplate
{
    $org = $orgA ? $world->orgA : $world->orgB;

    return TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => "{$world->marker} {$label}",
        'provider' => 'heygen',
        'config' => ['avatarId' => 'av', 'voiceId' => 'vo'],
    ]));
}

// ─── state outside the database ──────────────────────────────────────────

test('a tenant user cannot switch the acting client, and nothing is stored', function (string $actor): void {
    $world = AuthMatrixWorld::make();
    $credential = $world->actor($actor);

    $response = AuthMatrixRunner::send($this, 'PUT api/admin/acting-organization', $credential['token'], [], ['organization_id' => $world->orgA->id]);

    expect($response->getStatusCode())->toBe(403)
        ->and(app(ActingOrganization::class)->for((int) $credential['user']->id))->toBeNull();
})->with(NON_SUPERADMIN_ACTORS);

test('a superadmin selects, reads back and clears the acting client, and an unknown client is refused', function (): void {
    $world = AuthMatrixWorld::make();
    $credential = $world->actor(AuthMatrix::SUPERADMIN_BARE);
    $userId = (int) $credential['user']->id;

    AuthMatrixRunner::send($this, 'PUT api/admin/acting-organization', $credential['token'], [], ['organization_id' => $world->orgA->id])->assertOk();
    expect(app(ActingOrganization::class)->for($userId))->toBe($world->orgA->id);

    AuthMatrixRunner::send($this, 'GET api/admin/organizations', $credential['token'], [])
        ->assertOk()
        ->assertJsonPath('acting_organization_id', $world->orgA->id);

    AuthMatrixRunner::send($this, 'PUT api/admin/acting-organization', $credential['token'], [], ['organization_id' => 999999])->assertUnprocessable();
    expect(app(ActingOrganization::class)->for($userId))->toBe($world->orgA->id);

    AuthMatrixRunner::send($this, 'PUT api/admin/acting-organization', $credential['token'], [], ['organization_id' => null])->assertOk();
    expect(app(ActingOrganization::class)->for($userId))->toBeNull();
});

test('a denied key revocation leaves the key live and sets no revocation flag', function (string $actor): void {
    $world = AuthMatrixWorld::make();
    $client = $world->platform()->apiClient();
    $credential = $world->actor($actor);

    $response = AuthMatrixRunner::send($this, 'DELETE api/m2m/clients/{apiClient}', $credential['token'], ['apiClient' => $client->id]);

    expect($response->getStatusCode())->toBe(403)
        ->and($client->refresh()->is_active)->toBeTrue()
        ->and(Cache::has('client_revoked:'.$client->id))->toBeFalse();
})->with([AuthMatrix::OPERATOR, AuthMatrix::VIEWER, AuthMatrix::NO_ROLE, AuthMatrix::CROSS_TENANT_ADMIN]);

// ─── KQ-I2: Gate::before over the ApiClient policy ───────────────────────

test('DOCUMENTED (KQ-I2): any superadmin revokes any organization\'s key, even one acting as another client', function (): void {
    // ApiClientPolicy::delete demands the key belong to the RESOLVED organization, but
    // Gate::before (AppServiceProvider.php:156) answers true for a superadmin first, so the
    // org check never runs. This is the behaviour today; it is asserted, not endorsed.
    $world = AuthMatrixWorld::make();

    foreach ([AuthMatrix::SUPERADMIN_BARE, AuthMatrix::SUPERADMIN_ACTING] as $actor) {
        $client = ApiClient::factory()->create(['organization_id' => $world->orgB->id, 'name' => "{$world->marker} {$actor} client"]);
        $credential = $world->actor($actor);

        // `superadmin_acting` acts as orgA; the key belongs to orgB.
        AuthMatrixRunner::send($this, 'DELETE api/m2m/clients/{apiClient}', $credential['token'], ['apiClient' => $client->id])->assertNoContent();

        expect($client->refresh()->is_active)->toBeFalse()
            ->and(Cache::has('client_revoked:'.$client->id))->toBeTrue();
    }
});

// ─── the effect of an allowed call ───────────────────────────────────────

test('a superadmin deactivates a template, idempotently, and a denied admin leaves it active', function (): void {
    $world = AuthMatrixWorld::make();
    $template = $world->platform()->activeTemplate();
    $key = 'POST api/avatar-templates/{id}/deactivate';

    AuthMatrixRunner::send($this, $key, $world->actor(AuthMatrix::ADMIN)['token'], ['id' => $template->id])->assertForbidden();
    expect($template->refresh()->is_active)->toBeTrue();

    $token = $world->actor(AuthMatrix::SUPERADMIN_ACTING)['token'];
    AuthMatrixRunner::send($this, $key, $token, ['id' => $template->id])->assertOk()->assertJsonPath('data.is_active', false);
    expect($template->refresh()->is_active)->toBeFalse();

    AuthMatrixRunner::send($this, $key, $token, ['id' => $template->id])->assertOk();
    expect($template->refresh()->is_active)->toBeFalse();
});

test('a superadmin publishes the draft catalogue revision, and a denied admin leaves it open', function (): void {
    $world = AuthMatrixWorld::make();
    $draft = $world->platform()->draft();
    $key = 'POST api/catalogue/revisions/publish';

    AuthMatrixRunner::send($this, $key, $world->actor(AuthMatrix::ADMIN)['token'], [])->assertForbidden();
    expect(FrameworkCatalogRevision::openDraft()?->id)->toBe($draft->id);

    AuthMatrixRunner::send($this, $key, $world->actor(AuthMatrix::SUPERADMIN_BARE)['token'], [])->assertOk()->assertJsonPath('data.state', 'published');

    expect(FrameworkCatalogRevision::openDraft())->toBeNull()
        ->and($draft->refresh()->state)->toBe('published');
});

// ─── bare vs acting superadmin ───────────────────────────────────────────

test('a bare superadmin lists every tenant\'s templates while an acting one sees only its client\'s', function (): void {
    $world = AuthMatrixWorld::make();
    amxTemplateOf($world, true, 'template of a');
    amxTemplateOf($world, false, 'template of b');

    $names = fn (string $actor): array => collect(
        AuthMatrixRunner::send($this, 'GET api/avatar-templates', $world->actor($actor)['token'], [])->assertOk()->json('data'),
    )->pluck('name')->sort()->values()->all();

    expect($names(AuthMatrix::SUPERADMIN_BARE))->toBe(["{$world->marker} template of a", "{$world->marker} template of b"])
        ->and($names(AuthMatrix::SUPERADMIN_ACTING))->toBe(["{$world->marker} template of a"]);
});

test('the client overview shows every organization to a superadmin, acting or not', function (string $actor): void {
    $world = AuthMatrixWorld::make();

    $ids = collect(AuthMatrixRunner::send($this, 'GET api/admin/clients', $world->actor($actor)['token'], [])->assertOk()->json('data'))->pluck('id');

    expect($ids->all())->toContain($world->orgA->id, $world->orgB->id);
})->with([AuthMatrix::SUPERADMIN_BARE, AuthMatrix::SUPERADMIN_ACTING]);

test('the catalogue is platform-global: a bare and an acting superadmin read the same revision', function (): void {
    $world = AuthMatrixWorld::make();
    $world->platform()->role();

    $read = fn (string $actor): array => AuthMatrixRunner::send($this, 'GET api/catalogue/roles', $world->actor($actor)['token'], [])->assertOk()->json('data');

    expect($read(AuthMatrix::SUPERADMIN_BARE))->toBe($read(AuthMatrix::SUPERADMIN_ACTING))
        ->and($read(AuthMatrix::SUPERADMIN_BARE))->toHaveCount(1);
});
