<?php

declare(strict_types=1);

/**
 * Activating a template deactivates the previously active one of the same organization and provider. That
 * deactivation changes which face candidates meet just as `POST .../deactivate` does, so it is audited with the same
 * `avatar_template.deactivated` action and the same `before` shape (hr-avatar-adapter A15). Before this, the swap
 * left only an `activated` row and the demoted template vanished from the trail.
 */

use App\Models\AuditLog;
use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\AvatarTemplates\TemplateActors;

uses(RefreshDatabase::class);

function swapSeed(Organization $org, string $name, string $provider, bool $active): AvatarTemplate
{
    return TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => $name, 'provider' => $provider, 'is_active' => $active,
        'config' => ['avatarId' => 'a', 'voiceId' => 'v'],
    ]));
}

/** @return list<AuditLog> */
function swapAudit(string $action): array
{
    return AuditLog::withoutGlobalScopes()->where('action', $action)->orderBy('id')->get()->all();
}

test('activating a template audits the deactivation of the one it replaces', function (): void {
    Http::fake();
    $org = Organization::factory()->create();
    $old = swapSeed($org, 'Old face', 'heygen', true);
    $new = swapSeed($org, 'New face', 'heygen', false);

    $this->withToken(TemplateActors::token('acting', $org))
        ->postJson("/api/avatar-templates/{$new->id}/activate")
        ->assertOk();

    expect(swapAudit('avatar_template.deactivated'))->toHaveCount(1)
        ->and(swapAudit('avatar_template.activated'))->toHaveCount(1);

    [$deactivated] = swapAudit('avatar_template.deactivated');

    expect((int) $deactivated->subject_id)->toBe($old->id)
        ->and((int) $deactivated->organization_id)->toBe($org->id)
        ->and($deactivated->actor_id)->not->toBeNull()
        ->and($deactivated->before)->toBe(['name' => 'Old face', 'provider' => 'heygen']);
});

test('activating with nothing to replace, or only another provider active, writes no deactivation row', function (): void {
    Http::fake();
    $org = Organization::factory()->create();
    $token = TemplateActors::token('acting', $org);
    swapSeed($org, 'Tavus stays', 'tavus', true);
    $first = swapSeed($org, 'Alone', 'heygen', false);

    $this->withToken($token)->postJson("/api/avatar-templates/{$first->id}/activate")->assertOk();

    expect(swapAudit('avatar_template.deactivated'))->toBe([])
        ->and(swapAudit('avatar_template.activated'))->toHaveCount(1);
});
