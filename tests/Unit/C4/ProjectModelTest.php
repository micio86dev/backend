<?php

declare(strict_types=1);

/**
 * RED — 3.5: Project model invariants (C4).
 *
 * (a) organization_id NOT in $fillable
 * (b) framework_version_id cast to integer
 * (c) webhook_secret cast to encrypted and in $hidden
 * (d) model guard throws ImmutableProjectException on assessment_type/framework_version_id/role_code change when resulting status is active
 * (e) lifecycle guard throws ImmutableProjectException on forbidden transitions (active→draft, archived→active, archived→draft)
 * (f) competencies() relation is belongsToMany with position pivot
 *
 * Refs spec: Org-Scoped Entity; Immutable-Field; Status Lifecycle.
 */

use App\Exceptions\ImmutableProjectException;
use App\Models\Competency;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;

test('organization_id is NOT in Project fillable', function (): void {
    $project = new Project();
    expect($project->getFillable())->not->toContain('organization_id');
});

test('framework_version_id is cast to integer', function (): void {
    $project = new Project();
    $casts = $project->getCasts();
    expect($casts)->toHaveKey('framework_version_id');
    expect($casts['framework_version_id'])->toBe('integer');
});

test('webhook_secret is cast to encrypted', function (): void {
    $project = new Project();
    $casts = $project->getCasts();
    expect($casts)->toHaveKey('webhook_secret');
    expect($casts['webhook_secret'])->toBe('encrypted');
});

test('webhook_secret is in $hidden', function (): void {
    $project = new Project();
    expect($project->getHidden())->toContain('webhook_secret');
});

test('model guard throws ImmutableProjectException when changing assessment_type on active project', function (): void {
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $project = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'status' => 'active',
    ]);

    expect(function () use ($project): void {
        $project->assessment_type = 'potential';
        $project->save();
    })->toThrow(ImmutableProjectException::class);
});

test('model guard throws ImmutableProjectException when changing framework_version_id on active project', function (): void {
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);

    $fv1 = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $fv2 = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $project = Project::factory()->create([
        'framework_version_id' => $fv1->id,
        'status' => 'active',
    ]);

    expect(function () use ($project, $fv2): void {
        $project->framework_version_id = $fv2->id;
        $project->save();
    })->toThrow(ImmutableProjectException::class);
});

test('model guard allows changing assessment_type on draft project', function (): void {
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $project = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'status' => 'draft',
    ]);

    // This should NOT throw — draft project allows changes
    $project->assessment_type = 'potential';
    $project->role_code = null;
    $project->save();

    expect($project->fresh()->assessment_type)->toBe('potential');
});

test('model guard throws ImmutableProjectException on active→draft transition', function (): void {
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'status' => 'active',
    ]);

    expect(function () use ($project): void {
        $project->status = 'draft';
        $project->save();
    })->toThrow(ImmutableProjectException::class);
});

test('model guard throws ImmutableProjectException on archived→active transition', function (): void {
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'status' => 'archived',
    ]);

    expect(function () use ($project): void {
        $project->status = 'active';
        $project->save();
    })->toThrow(ImmutableProjectException::class);
});

test('model guard throws ImmutableProjectException on archived→draft transition', function (): void {
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'status' => 'archived',
    ]);

    expect(function () use ($project): void {
        $project->status = 'draft';
        $project->save();
    })->toThrow(ImmutableProjectException::class);
});

test('model guard allows valid draft→active lifecycle transition', function (): void {
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'status' => 'draft',
    ]);

    $project->status = 'active';
    $project->save();

    expect($project->fresh()->status)->toBe('active');
});

test('competencies() is a belongsToMany relation with position pivot', function (): void {
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);

    $relation = $project->competencies();
    expect($relation)->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsToMany::class);
    expect($relation->getPivotColumns())->toContain('position');
});
