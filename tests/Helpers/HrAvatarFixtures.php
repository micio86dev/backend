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
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
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
