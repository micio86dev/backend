<?php

declare(strict_types=1);

/**
 * The external reference stays on the operator and integration read surfaces
 * (candidate-external-reference, slice A3a).
 *
 * `external_id` and `source` are the calling system's own record id and name.
 * Resolved decision D1: webhooks do NOT carry them, and `candidate_ref` remains
 * the correlation handle. The same holds for the webhook delivery log, the
 * dashboard activity feed and the evaluations index, none of which gains the
 * fields.
 *
 * These are GUARDS. They pass before any of these surfaces could have been
 * widened and exist so that adding a field to a participant-shaped surface
 * later is a decision a test forces, not something that arrived because a
 * spread got wider. The marker values are deliberately distinctive, so the raw
 * body check cannot be satisfied by an unrelated id or timestamp.
 *
 * The candidate session and the `typ:candidate` JWT claims have their own
 * guards (CandidateSessionExternalReferenceTest, SsoExchangeExternalReferenceTest).
 *
 * REQ: Surfaces That Never Carry The External Reference
 *      (sdd/candidate-external-reference/spec/participant-sso)
 */

use App\Models\Competency;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\WebhookDelivery;
use App\PublicApi\Serializers\WebhookDeliverySerializer;
use App\Services\Webhooks\EvaluationPayloadAssembler;
use App\Services\Webhooks\ProgressPayloadAssembler;
use App\Support\Tenancy\TenantContextScope;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Str;

const NON_EXPOSURE_ID = 987654321987;
const NON_EXPOSURE_SOURCE = 'acme-ats-marker';
const NON_EXPOSURE_REF = 'non-exposure-ref-001';

/**
 * A completed participant of a fresh organization that holds BOTH reference
 * values, with a completed evaluation and a webhook delivery.
 *
 * @return array{org: Organization, project: Project, participant: Participant, evaluation: Evaluation, delivery: WebhookDelivery}
 */
function nonExposureSubject(): array
{
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id, 'name' => 'Non Exposure Project']);
    $competency = Competency::factory()->create(['code' => 'COL']);
    $project->competencies()->attach($competency->id, ['position' => 0]);

    $participant = Participant::factory()->forProject($project)->withStatus('completato')
        ->withExternalReference(NON_EXPOSURE_ID, NON_EXPOSURE_SOURCE)
        ->create(['candidate_ref' => NON_EXPOSURE_REF]);

    $evaluation = Evaluation::factory()->create([
        'participant_id' => $participant->id,
        'status' => 'completed',
        'evaluated_at' => now(),
    ]);
    CompetencyResult::factory()->create([
        'evaluation_id' => $evaluation->id,
        'competency_code' => 'COL',
        'score' => 4.0,
        'reliability' => 0.83,
    ]);

    $delivery = TenantContextScope::runFor($org->id, fn (): WebhookDelivery => WebhookDelivery::factory()->forParticipant($participant)->create());

    return compact('org', 'project', 'participant', 'evaluation', 'delivery');
}

/**
 * Every key of a document, at any nesting depth.
 *
 * @param  array<array-key, mixed>  $document
 * @return list<string>
 */
function nonExposureKeys(array $document): array
{
    $keys = [];

    foreach ($document as $key => $value) {
        if (is_string($key)) {
            $keys[] = $key;
        }

        if (is_array($value)) {
            $keys = [...$keys, ...nonExposureKeys($value)];
        }
    }

    return $keys;
}

/**
 * Neither key at any depth, and neither value anywhere in the serialised body.
 *
 * @param  array<array-key, mixed>  $document
 */
function expectNoExternalReference(array $document): void
{
    $keys = nonExposureKeys($document);
    $raw = (string) json_encode($document);

    expect($keys)->not->toContain('external_id')->not->toContain('source');
    expect($raw)->not->toContain(NON_EXPOSURE_SOURCE)->not->toContain((string) NON_EXPOSURE_ID);
}

test('the progress webhook payload carries neither key nor value, and still echoes candidate_ref', function (): void {
    $subject = nonExposureSubject();

    $payload = (new ProgressPayloadAssembler)->assemble($subject['participant']->id, $subject['org']->id, (string) Str::uuid());

    expectNoExternalReference($payload);
    expect($payload['candidate_ref'])->toBe(NON_EXPOSURE_REF);
});

test('the evaluation webhook payload carries neither key nor value, and still echoes candidate_ref', function (): void {
    $subject = nonExposureSubject();
    $assembler = app(EvaluationPayloadAssembler::class);

    $completed = $assembler->assembleForEvaluation($subject['evaluation']->id, (string) Str::uuid());
    $failed = $assembler->assembleForFailedParticipant($subject['participant']->id, $subject['org']->id, (string) Str::uuid());

    foreach ([$completed, $failed] as $payload) {
        expectNoExternalReference($payload);
        expect($payload['candidate_ref'])->toBe(NON_EXPOSURE_REF);
    }
});

test('the webhook delivery log serializer carries neither key nor value, and still echoes candidate_ref', function (): void {
    $subject = nonExposureSubject();

    $row = WebhookDeliverySerializer::toArray($subject['delivery']->load(['participant', 'project']));

    expectNoExternalReference($row);
    expect($row['candidate_ref'])->toBe(NON_EXPOSURE_REF);
});

test('the dashboard activity feed carries neither key nor value', function (): void {
    $subject = nonExposureSubject();
    ['token' => $token] = authUserAndTokenForRole($subject['org'], 'admin');
    app(TenantResolver::class)->setOrgId($subject['org']->id);

    $response = $this->withToken($token)->getJson('/api/dashboard/activity');

    $response->assertOk();
    // Non-vacuous: the participant IS in the feed, so its absence of the
    // reference is a property of the resource, not of an empty response.
    expect($response->json('data'))->not->toBeEmpty();
    expect($response->getContent())->toContain(NON_EXPOSURE_REF);
    expectNoExternalReference($response->json());
});

test('the evaluations index carries neither key nor value', function (): void {
    $subject = nonExposureSubject();
    ['token' => $token] = authUserAndTokenForRole($subject['org'], 'admin');
    app(TenantResolver::class)->setOrgId($subject['org']->id);

    $response = $this->withToken($token)->getJson('/api/evaluations');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expectNoExternalReference($response->json());
});
