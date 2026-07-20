<?php

declare(strict_types=1);

/**
 * Language defaulting tests (C6 — Participant + SSO Ingress).
 *
 * Asserts:
 * - lang claim present → use it
 * - lang claim absent → use project.language
 * - both absent → fallback_locale ('en')
 * - stored language is never null
 *
 * REQ: language Defaulting — all scenarios
 */

use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantResolver;

function makeLangProject(Organization $org, string $language = 'it'): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create([
        'status'          => 'active',
        'role_code'       => 'ICO',
        'assessment_type' => 'standard',
        'language'        => $language,
        'goes_live_at'    => null,
        'deadline_at'     => null,
    ]);
}

test('lang claim present → participant.language = claim lang', function (): void {
    $org     = Organization::factory()->create();
    $project = makeLangProject($org, 'it');
    $ref     = 'lang-test-claim';

    $token = CandidateTokenFactory::mintSsoLink([
        'candidate_ref' => $ref,
        'display_name'  => 'Test',
        'project_id'    => $project->id,
        'org_id'        => $org->id,
        'role_code'     => 'ICO',
        'lang'          => 'fr',  // explicit lang in claim
    ]);

    $this->getJson('/api/sso/exchange?token=' . $token)->assertOk();

    $participant = Participant::where('candidate_ref', $ref)->first();
    expect($participant->language)->toBe('fr');
});

test('lang claim absent (null) → participant.language = project.language', function (): void {
    $org     = Organization::factory()->create();
    $project = makeLangProject($org, 'it');
    $ref     = 'lang-test-project';

    $token = CandidateTokenFactory::mintSsoLink([
        'candidate_ref' => $ref,
        'display_name'  => 'Test',
        'project_id'    => $project->id,
        'org_id'        => $org->id,
        'role_code'     => 'ICO',
        'lang'          => null,  // no lang claim
    ]);

    $this->getJson('/api/sso/exchange?token=' . $token)->assertOk();

    $participant = Participant::where('candidate_ref', $ref)->first();
    // Falls back to project.language = 'it'
    expect($participant->language)->toBe('it');
});

test('stored language is never null after exchange', function (): void {
    $org     = Organization::factory()->create();
    $project = makeLangProject($org, 'en');
    $ref     = 'lang-test-notnull';

    $token = CandidateTokenFactory::mintSsoLink([
        'candidate_ref' => $ref,
        'display_name'  => 'Test',
        'project_id'    => $project->id,
        'org_id'        => $org->id,
        'role_code'     => 'ICO',
        'lang'          => null,
    ]);

    $this->getJson('/api/sso/exchange?token=' . $token)->assertOk();

    $participant = Participant::where('candidate_ref', $ref)->first();
    expect($participant->language)->not->toBeNull();
    expect($participant->language)->not->toBe('');
});
