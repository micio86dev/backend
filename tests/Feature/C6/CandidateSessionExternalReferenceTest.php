<?php

declare(strict_types=1);

/**
 * GET /api/candidate/session never carries the external reference
 * (candidate-external-reference, slice A3a).
 *
 * `external_id` and `source` are the calling system's own identifiers: operator
 * and integration metadata. The candidate is an outsider holding a short-lived
 * token and has no use for them, so the candidate-facing response shape must
 * stay exactly what it was before the reference existed.
 *
 * These are GUARDS: they pass before the operator/M2M responses switch to
 * `ParticipantEnrolmentResource` and must keep passing after it. They are
 * written first because the shared resource is the one place where a field
 * added for operators would leak to candidates by default.
 *
 * REQ: Surfaces That Never Carry The External Reference,
 *      Candidate Session Endpoint (MODIFIED)
 *      (sdd/candidate-external-reference/spec/participant-sso)
 */

use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantResolver;

/**
 * A participant of a fresh organization, with or without a stored reference.
 */
function candidateSessionParticipant(bool $withReference): Participant
{
    $org = Organization::factory()->create();
    app(TenantResolver::class)->setOrgId($org->id);

    $project = Project::factory()->create(['organization_id' => $org->id]);
    $factory = Participant::factory()->forProject($project);

    if ($withReference) {
        $factory = $factory->withExternalReference(987654321987, 'acme-ats-marker');
    }

    return $factory->create();
}

/**
 * Every key of a decoded JSON document, at any nesting depth.
 *
 * @param  array<array-key, mixed>  $document
 * @return list<string>
 */
function candidateSessionKeysAtAnyDepth(array $document): array
{
    $keys = [];

    foreach ($document as $key => $value) {
        if (is_string($key)) {
            $keys[] = $key;
        }

        if (is_array($value)) {
            $keys = [...$keys, ...candidateSessionKeysAtAnyDepth($value)];
        }
    }

    return $keys;
}

test('the session of a participant WITH an external reference carries neither key nor the source value', function (): void {
    $participant = candidateSessionParticipant(withReference: true);
    $token = CandidateTokenFactory::mintCandidateToken($participant->fresh());

    $response = $this->withToken($token)->getJson('/api/candidate/session');

    $response->assertOk();
    expect($response->json('data'))->not->toHaveKeys(['external_id', 'source']);
    expect(candidateSessionKeysAtAnyDepth($response->json()))->not->toContain('external_id')->not->toContain('source');
    // The raw body too: a value could reach the wire under a renamed key.
    expect($response->getContent())->not->toContain('acme-ats-marker')->not->toContain('987654321987');
});

test('the session key set is identical with and without a stored reference', function (): void {
    $with = candidateSessionParticipant(withReference: true);
    $without = candidateSessionParticipant(withReference: false);

    $keysFor = function (Participant $participant): array {
        $token = CandidateTokenFactory::mintCandidateToken($participant->fresh());
        $response = $this->withToken($token)->getJson('/api/candidate/session');
        $response->assertOk();

        $keys = array_keys($response->json('data'));
        sort($keys);

        return $keys;
    };

    $withKeys = $keysFor->call($this, $with);
    $withoutKeys = $keysFor->call($this, $without);

    expect($withKeys)->toBe($withoutKeys);
    expect($withKeys)->toContain('candidate_ref')->toContain('branding')->toContain('project');
});
