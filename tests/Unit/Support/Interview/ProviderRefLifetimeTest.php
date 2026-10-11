<?php

declare(strict_types=1);

/**
 * RED/GREEN — tavus-single-session-interview API-05.1 (design A10, N7).
 *
 * A provider conversation's age is the span from the earliest live period that held its ref,
 * and its ceiling is the one `SessionLiveClock` already resolves (template first, platform
 * second). The class owns neither number.
 */

use App\Models\AvatarTemplate;
use App\Models\FrameworkVersion;
use App\Models\InterviewSession;
use App\Models\InterviewSessionLivePeriod;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Interview\ProviderRefLifetime;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['conversation.ceiling_headroom_seconds' => 480]);
});

/** @return array{0: InterviewSession, 1: Project} */
function prlSession(?int $templateCap = null): array
{
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['organization_id' => $org->id, 'framework_version_id' => $fv->id]);

    if ($templateCap !== null) {
        $project->forceFill(['avatar_template_id' => AvatarTemplate::create([
            'name' => 'prl '.uniqid(), 'provider' => 'tavus', 'config' => ['maxCallDurationSec' => $templateCap],
        ])->id])->save();
    }

    $participant = Participant::factory()->create(['organization_id' => $org->id, 'project_id' => $project->id]);

    return [InterviewSession::factory()->create([
        'organization_id' => $org->id, 'participant_id' => $participant->id, 'project_id' => $project->id,
        'framework_version_id' => $fv->id, 'provider' => 'tavus', 'status' => 'in_corso',
    ]), $project];
}

function prlPeriod(InterviewSession $session, string $ref, int $startedSecondsAgo, bool $open = true): void
{
    InterviewSessionLivePeriod::create([
        'interview_session_id' => $session->id, 'provider_session_ref' => $ref,
        'started_at' => now()->subSeconds($startedSecondsAgo),
        'ended_at' => $open ? null : now()->subSeconds($startedSecondsAgo - 10),
        'closed_reason' => $open ? null : 'end',
    ]);
}

test('age is the span from the earliest period of the ref, not a sum of stretches', function (): void {
    [$session] = prlSession();
    $this->travelTo(now()->startOfSecond());
    prlPeriod($session, 'conv-a', 1000, open: false);
    prlPeriod($session, 'conv-other', 5000, open: false);
    $second = InterviewSession::factory()->create([
        'organization_id' => $session->organization_id, 'participant_id' => $session->participant_id,
        'project_id' => $session->project_id, 'framework_version_id' => $session->framework_version_id,
        'provider' => 'tavus', 'status' => 'in_corso', 'competency_code' => 'ZZZ', 'question_index' => 9,
    ]);
    prlPeriod($second, 'conv-a', 200);

    expect(app(ProviderRefLifetime::class)->ageSeconds('conv-a'))->toBe(1000)
        ->and(app(ProviderRefLifetime::class)->ageSeconds('unknown'))->toBe(0);
});

test('the ceiling is the template cap when one is configured and the platform cap otherwise', function (): void {
    [$withCap] = prlSession(900);
    expect(app(ProviderRefLifetime::class)->ceilingSeconds($withCap))->toBe(900);

    [$without] = prlSession();
    expect(app(ProviderRefLifetime::class)->ceilingSeconds($without))->toBe(3600);
});

test('near ceiling is age plus headroom reaching the ceiling', function (int $age, bool $near): void {
    [$session] = prlSession(900);
    $this->travelTo(now()->startOfSecond());
    prlPeriod($session, 'conv-a', $age);

    expect(app(ProviderRefLifetime::class)->isNearCeiling($session, 'conv-a'))->toBe($near);
})->with([
    'well inside' => [100, false],
    'one second short' => [419, false],
    'exactly at the headroom' => [420, true],
    'past the ceiling' => [2000, true],
]);

test('the ceiling has one owner: the class neither names the platform cap nor reads the template key', function (): void {
    $source = (string) file_get_contents(app_path('Support/Interview/ProviderRefLifetime.php'));

    expect($source)->not->toContain('TAVUS_MAX_SECONDS')
        ->and($source)->not->toContain('ProviderFieldSpecs')
        ->and($source)->not->toContain('maxCallDurationSec')
        ->and($source)->not->toMatch('/\b3600\b|\b900\b/')
        ->and($source)->toContain('SessionLiveClock');
});
