<?php

declare(strict_types=1);

/**
 * RED+GREEN — A3.5: no column of a failed ai_requests row contains any
 * substring of the raw response body (C13, design.md D6).
 *
 * A GDPR boundary, not a style preference — the fingerprint is
 * DERIVED-SIGNALS-ONLY. Iterates `getAttributes()` (every column actually
 * persisted) and asserts a distinctive marker embedded in the raw response
 * appears in NONE of them.
 */

use App\Contracts\LLMProvider;
use App\Jobs\ScoreEvaluationJob;
use App\Models\AiRequest;
use App\Models\Evaluation;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;
use App\Testing\CassetteLLMProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('no column of a failed ai_requests row contains any substring of the raw response body', function (): void {
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(['status' => 'active', 'language' => 'en']);

    $participant = new Participant;
    $participant->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'noleak-'.uniqid(),
        'display_name' => 'Fingerprint No-Leak Test',
        'email' => uniqid('cand-').'@example.test',
        'status' => 'in_valutazione',
    ]);
    $participant->save();
    $participant = $participant->fresh();

    $setup = setupScoringCompetency($org, $project, $participant, 'STG');
    $competencyCode = $setup['competency']->code;

    // A distinctive marker that would NEVER legitimately appear in any
    // int/bool/enum/hash column — if it leaks anywhere, the fingerprint is
    // not derived-signals-only.
    $marker = 'MARKERQZX7f3a9c2e1b';
    $malformedBody = '{"behaviors": [{"indicator": "'.$marker.'", "score": 5, "explanation": "'.$marker.'", "excerpts": [],}]}';

    $cassette = new CassetteLLMProvider([$competencyCode => $malformedBody]);
    app()->instance(LLMProvider::class, $cassette);

    (new ScoreEvaluationJob($participant->id))->handle();

    $evaluation = Evaluation::withoutGlobalScopes()->where('participant_id', $participant->id)->first();
    $aiRequest = AiRequest::withoutGlobalScopes()->where('evaluation_id', $evaluation->id)->first();

    expect($aiRequest)->not->toBeNull()
        ->and($aiRequest->success)->toBeFalse();

    foreach ($aiRequest->getAttributes() as $column => $value) {
        $stringValue = is_scalar($value) ? (string) $value : json_encode($value);

        expect($stringValue)->not->toContain($marker, "Column [{$column}] must not contain the raw response marker.");
    }
});
