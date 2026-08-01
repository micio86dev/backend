<?php

declare(strict_types=1);

/**
 * RED — Admin Resource scope boundary (C11 PR A2, task 6.3 + orchestrator ruling).
 *
 * A serializer/Resource must never emit fields belonging to a WIDER read scope
 * than the one requested — that is the actual mechanism by which the
 * lifecycle read-gate (LifecycleReadGate, D2) would be bypassed in practice
 * if a Summary-scope resource accidentally carried Transcript or Evaluation
 * data that the caller was never granted (409'd) for.
 *
 * REQ: Lifecycle Read-Gate, Evaluation Serializer Is Scoped
 *      (openspec/changes/admin-dashboards/specs/admin-read-api/spec.md)
 */

use App\Http\Resources\Admin\EvaluationResource;
use App\Http\Resources\Admin\ParticipantDetailResource;
use App\Http\Resources\Admin\ParticipantResource;
use App\Http\Resources\Admin\TranscriptResource;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\IndicatorScore;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Utterance;
use App\Services\Admin\AdminEvaluationSerializer;
use App\Services\Admin\AdminTranscriptSerializer;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Http\Request;

function scopeBoundaryFixture(): Participant
{
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);
    $participant = Participant::factory()->forProject($project)->withStatus('completato')->create();

    $session = InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => 'SLF',
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'fake',
        'status' => 'completed',
    ]);
    Utterance::create([
        'interview_session_id' => $session->id,
        'speaker' => 'Candidate',
        'text' => 'A transcript-only line.',
        'ts' => '2024-01-01 10:00:00',
    ]);

    $evaluation = Evaluation::factory()->completed()->create(['participant_id' => $participant->id]);
    $competencyResult = CompetencyResult::factory()->create([
        'evaluation_id' => $evaluation->id,
        'competency_code' => 'SLF',
        'score' => 4.0,
        'reliability' => 0.67,
    ]);
    IndicatorScore::factory()->create(['competency_result_id' => $competencyResult->id, 'position' => 0]);

    return $participant->fresh();
}

test('Summary-scope resources (list + detail) never carry transcript or evaluation fields', function (): void {
    $participant = scopeBoundaryFixture();
    $request = Request::create('/');

    $listPayload = (new ParticipantResource($participant))->toArray($request);
    $detailPayload = (new ParticipantDetailResource($participant))->toArray($request);

    foreach ([$listPayload, $detailPayload] as $payload) {
        expect($payload)->not->toHaveKey('behaviors');
        expect($payload)->not->toHaveKey('reliability');
        expect($payload)->not->toHaveKey('utterances');
        expect($payload)->not->toHaveKey('sessions');
    }
});

test('the Transcript-scope resource never carries evaluation fields (score, reliability, behaviors)', function (): void {
    $participant = scopeBoundaryFixture();
    $request = Request::create('/');

    $serializer = new AdminTranscriptSerializer;
    $payload = (new TranscriptResource($serializer->serialize($participant)))->toArray($request);

    expect($payload)->not->toHaveKey('reliability');
    expect($payload)->not->toHaveKey('behaviors');
    expect($payload)->not->toHaveKey('score');
    expect(json_encode($payload))->not->toContain('"reliability"');
    expect(json_encode($payload))->not->toContain('"behaviors"');
});

test('the Evaluation-scope resource never carries transcript fields (utterances, speaker)', function (): void {
    $participant = scopeBoundaryFixture();
    $request = Request::create('/');

    $serializer = new AdminEvaluationSerializer;
    $payload = (new EvaluationResource($serializer->serialize($participant)))->toArray($request);

    expect($payload)->not->toHaveKey('utterances');
    expect($payload)->not->toHaveKey('sessions');
    expect(json_encode($payload))->not->toContain('"speaker"');
    expect(json_encode($payload))->not->toContain('"utterances"');
});
