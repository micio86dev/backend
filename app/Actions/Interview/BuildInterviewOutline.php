<?php

declare(strict_types=1);

namespace App\Actions\Interview;

use App\Enums\AssessmentType;
use App\Models\Competency;
use App\Models\InterviewSession;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Role;
use App\Support\Catalogue\CatalogueRevisionResolver;
use App\Support\Interview\CompetencyProgress;

/**
 * Builds the candidate's interview outline (live-interview-call-chrome D3/D4).
 *
 * Read-only: it resolves, it never writes. The competency order and the "open" rule come from
 * `CompetencyProgress`, the same code `POST /start` uses, so the entry reported `current` is by
 * construction the competency the next `/start` serves.
 *
 * Nothing here returns a competency code or an identifier: the outline feeds a screen the candidate
 * reads, and a machine code on it is exactly what the call chrome keeps out.
 *
 * REQ: interview-session "GET /candidate/interview/outline"
 */
final class BuildInterviewOutline
{
    public function __construct(
        private readonly CompetencyProgress $progress,
        private readonly CatalogueRevisionResolver $revisions,
        private readonly ResolvePrimaryQuestions $primaries,
    ) {}

    /**
     * @return array{assessment_type: string, role_name: string|null, avatar_name: string|null, candidate_name: string|null, primary_total_all: int, competencies: list<array{name: string|null, ordinal: int, status: string, primary_total: int}>}
     */
    public function handle(Participant $participant, Project $project, AssessmentType $type): array
    {
        $locale = $project->language ?? (string) config('app.fallback_locale', 'en');
        $ordered = $this->progress->ordered($project->id)->values();

        $sessions = InterviewSession::where('participant_id', $participant->id)
            ->where('project_id', $project->id)
            ->get(['competency_code', 'status', 'error_count', 'primary_questions'])
            ->keyBy('competency_code');

        // Revision-unique ids: no code lookup and no resolver call is needed for the names.
        $competencies = Competency::whereIn('id', $ordered->pluck('competency_id'))->get()->keyBy('id');

        $entries = [];
        $currentFound = false;

        foreach ($ordered as $index => $row) {
            $session = $sessions->get($row->competency_code);
            $open = CompetencyProgress::isOpen($session);

            $status = match (true) {
                $open && ! $currentFound => 'current',
                $open && $session !== null => 'retry',
                $session === null => 'upcoming',
                default => 'done',
            };
            $currentFound = $currentFound || $status === 'current';

            $competency = $competencies->get($row->competency_id);

            $entries[] = [
                'name' => $competency === null ? null : $this->localizedName($competency, $locale),
                'ordinal' => $index + 1,
                'status' => $status,
                'primary_total' => $this->primaryTotal($project, (int) $row->competency_id, $session),
            ];
        }

        return [
            'assessment_type' => $type->value,
            'role_name' => $type === AssessmentType::Standard ? $this->roleName($project, $locale) : null,
            'avatar_name' => $project->avatarTemplate?->personaDisplayName(),
            'candidate_name' => $this->nonBlank($participant->display_name),
            'competencies' => $entries,
            'primary_total_all' => array_sum(array_column($entries, 'primary_total')),
        ];
    }

    /**
     * What the system will ask: the count frozen in the session when one exists (what the
     * classifier counts against), otherwise the count it would freeze.
     */
    private function primaryTotal(Project $project, int $competencyId, ?InterviewSession $session): int
    {
        if ($session?->primary_questions !== null) {
            return count($session->primary_questions);
        }

        // ponytail: per-competency primaries query (at most ~18 small indexed reads), batch into one
        // grouped read if the outline shows up in p95.
        return count($this->primaries->handle($project, $competencyId));
    }

    private function roleName(Project $project, string $locale): ?string
    {
        if ($project->role_code === null) {
            return null;
        }

        $revisionId = $this->revisions->tryForProject($project);

        if ($revisionId === null) {
            return null;
        }

        $role = Role::where('code', $project->role_code)->where('revision_id', $revisionId)->first();

        return $role === null ? null : $this->localizedName($role, $locale);
    }

    /**
     * The project language, then the catalogue default; never the code (V3).
     */
    private function localizedName(Competency|Role $model, string $locale): ?string
    {
        foreach ([$locale, (string) config('app.fallback_locale', 'en')] as $candidate) {
            $name = $this->nonBlank($model->getTranslation('name', $candidate, false));

            if ($name !== null) {
                return $name;
            }
        }

        return null;
    }

    private function nonBlank(mixed $value): ?string
    {
        $trimmed = is_string($value) ? trim($value) : '';

        return $trimmed === '' ? null : $trimmed;
    }
}
