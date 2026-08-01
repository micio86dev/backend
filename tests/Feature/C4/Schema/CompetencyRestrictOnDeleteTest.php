<?php

declare(strict_types=1);

/**
 * RED — 1.3: competency_id restrictOnDelete FK safeguard (C4).
 *
 * Asserts that attempting to delete a Competency referenced by project_competencies
 * throws a DB integrity exception (FK constraint enforced at the DB layer).
 * Refs design: FK safeguard note.
 */

use App\Models\Competency;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('deleting a competency referenced by project_competencies throws DB integrity exception', function (): void {
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);

    // Create a FrameworkVersion and Project with a competency
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $competency = Competency::factory()->create(['code' => 'PRS', 'type' => 'standard']);

    $project = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
    ]);

    // Attach the competency to the project pivot
    DB::table('project_competencies')->insert([
        'project_id' => $project->id,
        'competency_id' => $competency->id,
        'position' => 0,
    ]);

    // Attempting to delete the competency should throw due to restrictOnDelete FK
    expect(fn () => $competency->delete())->toThrow(QueryException::class);
});
