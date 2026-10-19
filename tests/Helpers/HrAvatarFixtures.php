<?php

declare(strict_types=1);

/**
 * Shared fixtures for the HR-Avatar adapter tests.
 *
 * Lives here, not inside a test file, because ParaTest hands test files to
 * workers separately: a helper declared in one spec is undefined in a worker
 * that did not receive it. Autoloaded via composer.json `autoload-dev.files`.
 * Built on the C9 fixtures (`casOrg()`, `casProject()`, `casInTenant()`).
 */

use App\Models\AvatarTemplate;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Services\Provider\QuestionContext;
use App\Support\Jwt\CandidateTokenFactory;
use Illuminate\Support\Facades\DB;

/**
 * An organization, a project of `$competencies` interviewable competencies
 * pinned to a template of `$provider`, and a candidate ready to call /start.
 *
 * @param  array<string, mixed>  $templateAttrs  Overrides for the pinned template.
 * @return array{0: Organization, 1: Project, 2: Participant, 3: string} org, project, participant, bearer token
 */
function hraStartScenario(string $provider, array $templateAttrs = [], int $competencies = 2, string $status = 'in_attesa'): array
{
    $org = casOrg();
    [$project, $catalogue] = casProject($org, $competencies);

    // The competency factory draws a random code; a fixed one keeps response bodies comparable run to run.
    foreach ($catalogue as $position => $competency) {
        DB::table('framework_competencies')->where('id', $competency->id)->update(['code' => 'HRA'.($position + 1)]);
    }

    $template = casInTenant($org, fn (): AvatarTemplate => AvatarTemplate::create(array_merge([
        'name' => 'HR-Avatar fixture '.$provider,
        'provider' => $provider,
        'config' => [],
    ], $templateAttrs)));

    casInTenant($org, fn (): bool => $project->update(['avatar_template_id' => $template->id]));

    $participant = Participant::factory()->forProject($project)->create(['status' => $status]);

    return [$org, $project, $participant, CandidateTokenFactory::mintCandidateToken($participant)];
}

/**
 * An in-memory `hr_avatar` session on a project pinned to a linked `hr_avatar` template.
 *
 * Not persisted: `HrAvatarProvider::issue()` only reads the id, the provider and the project.
 *
 * @param  array<string, mixed>  $templateAttrs  Overrides for the pinned template (e.g. `config`, `hr_avatar_config_id`).
 */
function hraIssueSession(array $templateAttrs = []): InterviewSession
{
    [$org, $project, $participant] = hraStartScenario('hr_avatar', array_merge(['hr_avatar_config_id' => 'avc_01HXYZ'], $templateAttrs), 1);

    $session = new InterviewSession;
    $session->forceFill([
        'id' => 42,
        'organization_id' => $org->id,
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => 'HRA1',
        'provider' => 'hr_avatar',
        'status' => 'pending',
    ]);

    return $session;
}

/**
 * @param  array<string, mixed>  $overrides  Named QuestionContext arguments to replace.
 */
function hraQuestionContext(array $overrides = []): QuestionContext
{
    return new QuestionContext(...array_merge([
        'competencyCode' => 'HRA1',
        'questionIndex' => 0,
        'systemPrompt' => 'You are the interviewer. Ask about teamwork.',
        'promptVersion' => 'conv-test',
        'openingText' => 'Hello, shall we begin?',
        'language' => 'it',
    ], $overrides));
}
