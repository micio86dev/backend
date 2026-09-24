<?php

declare(strict_types=1);

/**
 * `App\PublicApi\Serializers\InterviewSerializer` (gga review, step 6
 * follow-up, finding 6) — `competencyCodesByProject()`/
 * `sessionsByParticipant()`'s raw `DB::table()` batch queries must filter
 * by `organization_id` explicitly, the SAME D22 "org-lead, never relied on
 * transitively" discipline every other tenant-scoped query in this
 * codebase already follows — even though `participant_id`/`project_id`
 * are globally unique Postgres identifiers and neither query can actually
 * return a DIFFERENT organization's row through them today, an explicit
 * predicate is what a future caller (or a future column reuse) cannot
 * silently break.
 */

use App\Models\AvatarTemplate;
use App\Models\Competency;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\PublicApi\Serializers\InterviewSerializer;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;

test('progressForMany() filters both its batch queries by organization_id explicitly', function (): void {
    $org = Organization::factory()->create();

    $participant = TenantContextScope::runFor($org->id, function () use ($org) {
        $avatarTemplate = AvatarTemplate::create(['name' => 'Ada', 'provider' => 'heygen', 'config' => []]);
        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'avatar_template_id' => $avatarTemplate->id,
            'status' => 'active',
        ]);
        $competency = Competency::query()->where('code', 'COL')->first()
            ?? Competency::factory()->create(['code' => 'COL']);
        $project->competencies()->attach($competency->id, ['position' => 0]);

        $participant = Participant::factory()->forProject($project)->create(['organization_id' => $org->id])->refresh();

        InterviewSession::create([
            'participant_id' => $participant->id,
            'project_id' => $project->id,
            'question_index' => 0,
            'competency_code' => 'COL',
            'framework_version_id' => $project->framework_version_id,
            'provider' => 'heygen',
            'status' => 'completed',
            'ended_reason' => 'completed',
            'ended_at' => now(),
        ]);

        return $participant;
    });

    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    InterviewSerializer::progressForMany([$participant]);

    $sessionsQuery = collect($statements)->first(fn (string $sql): bool => str_contains($sql, 'interview_sessions'));
    $competenciesQuery = collect($statements)->first(fn (string $sql): bool => str_contains($sql, 'project_competencies'));

    expect($sessionsQuery)->not->toBeNull();
    expect($sessionsQuery)->toContain('organization_id');

    expect($competenciesQuery)->not->toBeNull();
    expect($competenciesQuery)->toContain('organization_id');
});
