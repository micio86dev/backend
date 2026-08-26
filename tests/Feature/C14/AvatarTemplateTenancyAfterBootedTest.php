<?php

declare(strict_types=1);

/**
 * Guards the `parent::booted()` trap (pluggable-conversation-llm PR P3a,
 * design D4).
 *
 * `AvatarTemplate` had no `booted()` before this change and inherited
 * `TenantScoped`'s registration from `TenantModel::bootTenantScoped()`.
 * Declaring a `booted()` method WITHOUT calling `parent::booted()` would
 * silently unregister the tenant global scope on the single model this
 * entire change hangs off — a tenancy hole introduced by an authorization
 * guard.
 *
 * REQ: avatar-templates "Another tenant's template is a 404, not a 403"
 */

use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Support\Tenancy\TenantContextScope;

test('a cross-org AvatarTemplate::find() still returns null after booted() is declared', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $templateB = TenantContextScope::runFor($orgB->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Org B template',
        'provider' => 'tavus',
        'config' => ['faceId' => 'f', 'palId' => 'p'],
    ]));

    TenantContextScope::runFor($orgA->id, function () use ($templateB): void {
        expect(AvatarTemplate::find($templateB->id))->toBeNull();
    });
});
