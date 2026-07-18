<?php

/**
 * Architecture guard: every model with an organization_id property that is NOT
 * User or Organization MUST extend TenantModel (not Model directly).
 *
 * This is enforced at CI time via Pest architecture tests so the invariant
 * cannot be silently broken by future authors.
 */

use App\Models\Organization;
use App\Models\TenantModel;
use App\Models\User;

// Pest architecture test: no model in App\Models that is NOT User or Organization
// should extend Model directly when it declares an organization_id property.
// We assert the existing authored models conform to the rule.
arch('TenantModel is abstract')
    ->expect('App\Models\TenantModel')
    ->toBeAbstract();

arch('User does not extend TenantModel (excluded by design)')
    ->expect('App\Models\User')
    ->not->toExtend(TenantModel::class);

arch('Organization does not extend TenantModel (excluded by design)')
    ->expect('App\Models\Organization')
    ->not->toExtend(TenantModel::class);

// Structural guard: every model class in App\Models that is NOT User or Organization
// and NOT TenantModel itself must extend TenantModel.
// This runs as a unit-style check against the actual class hierarchy.
test('all non-excluded models with organization_id extend TenantModel', function (): void {
    // Discover all model classes in App\Models (non-recursive — direct models only)
    $modelFiles = glob(base_path('app/Models/*.php')) ?: [];

    $excluded = [
        User::class,
        Organization::class,
        TenantModel::class,
        // C3 Framework Catalog — GLOBAL models (no organization_id by design).
        // These are shared across all tenants and intentionally do NOT extend TenantModel.
        // FrameworkVersion DOES extend TenantModel (it IS tenant-scoped).
        \App\Models\Role::class,
        \App\Models\Competency::class,
        \App\Models\BarsIndicator::class,
        \App\Models\FrameworkGap::class,
        \App\Models\CatalogMeta::class,
        // C5 M2M — ApiClient intentionally does NOT extend TenantModel.
        // The api-m2m guard queries ApiClient RAW/UNSCOPED because TenantResolver
        // is not stamped yet at guard-resolution time (TenantContextM2m runs after
        // the guard). A TenantScoped global scope would filter by the wrong/null org
        // and the key would never resolve. This is a deliberate design decision.
        // See design.md §"ApiClient is NOT a TenantModel".
        \App\Models\ApiClient::class,
    ];

    $violations = [];

    foreach ($modelFiles as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');

        if (! class_exists($class)) {
            continue;
        }

        if (in_array($class, $excluded, true)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        // Only check concrete models that declare or inherit organization_id.
        // We check by inspecting the model's fillable, casts, or DB columns
        // via a property-exists or hasAttribute check. For C2 the simplest
        // reliable check is whether it extends TenantModel.
        // Future models with organization_id will be caught here automatically.
        if (! $reflection->isAbstract() && ! is_subclass_of($class, TenantModel::class)) {
            // Check if this model would need tenancy (has organization_id).
            // Non-excluded, non-abstract, non-TenantModel-extending models are a violation.
            // We collect them so the failure message is informative.
            $violations[] = $class;
        }
    }

    expect($violations)
        ->toBe([])
        ->and(count($violations) === 0 ? true : false)
        ->toBeTrue('The following models extend Model directly instead of TenantModel: '
            .implode(', ', $violations)
            .'. Non-excluded models MUST extend TenantModel (see D1/TenantModel structural guard).');
})->group('arch');
