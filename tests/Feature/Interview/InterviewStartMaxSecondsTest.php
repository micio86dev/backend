<?php

declare(strict_types=1);

use App\Actions\Interview\BuildInterviewSessionResponse;
use App\Models\FrameworkVersion;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Services\Provider\ProviderToken;
use App\Support\AvatarTemplates\ProviderFieldSpecs;
use App\Support\Tenancy\TenantContextScope;
use Tests\Helpers\AvatarTemplates\PlatformTemplates;

/**
 * The candidate app counts the interview down against the limit the TEMPLATE
 * sets (`maxCallDurationSec` for Tavus), so the start response must carry it.
 */
function maxSecondsSession(Organization $org, ?int $configured): InterviewSession
{
    $template = PlatformTemplates::insertActiveGlobal([
        'provider' => 'tavus',
        'config' => array_filter(['avatarId' => 'av', 'voiceId' => 'vo', 'maxCallDurationSec' => $configured]),
    ]);

    return TenantContextScope::runFor($org->id, function () use ($org, $template): InterviewSession {
        $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'framework_version_id' => $fv->id,
            'avatar_template_id' => $template->id,
        ]);
        $participant = Participant::factory()->create(['organization_id' => $org->id, 'project_id' => $project->id]);

        return InterviewSession::factory()->create([
            'organization_id' => $org->id,
            'participant_id' => $participant->id,
            'project_id' => $project->id,
            'framework_version_id' => $fv->id,
            'provider' => 'tavus',
        ]);
    });
}

test('the start response carries the template maximum call duration', function (): void {
    $org = Organization::factory()->create();
    $session = maxSecondsSession($org, 1200);

    $response = TenantContextScope::runFor($org->id, fn () => app(BuildInterviewSessionResponse::class)->handle(
        $session,
        new ProviderToken(provider: 'tavus', conversation_url: 'https://example.test/room'),
        'it',
    ));

    expect($response->getData(true))->toHaveKey('interview_max_seconds', 1200);
});

test('without a template value the start response falls back to the platform ceiling', function (): void {
    $org = Organization::factory()->create();
    $session = maxSecondsSession($org, null);

    $response = TenantContextScope::runFor($org->id, fn () => app(BuildInterviewSessionResponse::class)->handle(
        $session,
        new ProviderToken(provider: 'tavus', conversation_url: 'https://example.test/room'),
        'it',
    ));

    expect($response->getData(true))->toHaveKey('interview_max_seconds', ProviderFieldSpecs::TAVUS_MAX_SECONDS);
});
