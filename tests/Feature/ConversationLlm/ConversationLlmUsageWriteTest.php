<?php

declare(strict_types=1);

/**
 * `RecordConversationLlmUsage` — the shared write path for both `/end`
 * (PR P6b) and the daily `beai:reconcile-llm-usage` sweep, design D10.
 *
 * REQ: conversation-llm "A session that ran on the vendor default gets no
 *      Gemini cost row; a session billed at Gemini rates is billed exactly
 *      once, and its historical cost survives a later price edit"
 */

use App\Actions\ConversationLlm\RecordConversationLlmUsage;
use App\Enums\LlmBindingStatus;
use App\Models\InterviewSession;
use App\Models\InterviewSessionLlmUsage;
use App\Models\LlmModel;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Utterance;
use App\Support\Tenancy\TenantResolver;

function usageWriteModel(): LlmModel
{
    return LlmModel::create([
        'key' => 'gemini-3-flash-preview',
        'vendor' => 'google',
        'display_name' => 'Gemini 3 Flash Preview',
        'email' => uniqid('cand-').'@example.test',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
        'capability' => 'text',
        'is_available' => true,
        'sort_order' => 0,
        'text_input_usd_per_million' => '1.000000',
        'text_output_usd_per_million' => '2.000000',
    ]);
}

/**
 * @return array{Organization, Project, Participant, InterviewSession}
 */
function usageWriteFixture(string $llmBindingStatus, ?string $llmModelKey, ?int $systemPromptChars = 40): array
{
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(['status' => 'active']);

    $participant = new Participant;
    $participant->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'usage-'.uniqid(),
        'display_name' => 'Usage Write Candidate',
        'email' => uniqid('cand-').'@example.test',
        'status' => 'in_corso',
    ]);
    $participant->save();

    $session = InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => 'PRS',
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'tavus',
        'provider_session_ref' => 'ref-'.uniqid(),
        'status' => 'in_corso',
    ]);
    $session->forceFill([
        'llm_binding_status' => $llmBindingStatus,
        'llm_model_key' => $llmModelKey,
        'system_prompt_chars' => $systemPromptChars,
    ])->save();

    Utterance::create(['interview_session_id' => $session->id, 'speaker' => 'avatar', 'text' => str_repeat('a', 20), 'ts' => now()->subMinutes(2)]);
    Utterance::create(['interview_session_id' => $session->id, 'speaker' => 'candidate', 'text' => str_repeat('b', 20), 'ts' => now()->subMinute()]);
    Utterance::create(['interview_session_id' => $session->id, 'speaker' => 'avatar', 'text' => str_repeat('c', 20), 'ts' => now()]);

    return [$org, $project, $participant, $session->fresh()];
}

test('a session with llm_binding_status = applied gets exactly one usage row', function (): void {
    usageWriteModel();
    [, , , $session] = usageWriteFixture(LlmBindingStatus::Applied->value, 'gemini-3-flash-preview');

    $recorder = app(RecordConversationLlmUsage::class);
    $recorder($session);

    expect(InterviewSessionLlmUsage::where('interview_session_id', $session->id)->count())->toBe(1);
});

test('calling the recorder twice for the same session is a no-op (firstOrCreate)', function (): void {
    usageWriteModel();
    [, , , $session] = usageWriteFixture(LlmBindingStatus::Applied->value, 'gemini-3-flash-preview');

    $recorder = app(RecordConversationLlmUsage::class);
    $recorder($session);
    $recorder($session);

    expect(InterviewSessionLlmUsage::where('interview_session_id', $session->id)->count())->toBe(1);
});

test('unbound writes no row', function (): void {
    [, , , $session] = usageWriteFixture(LlmBindingStatus::Unbound->value, null);

    $recorder = app(RecordConversationLlmUsage::class);
    $recorder($session);

    expect(InterviewSessionLlmUsage::where('interview_session_id', $session->id)->exists())->toBeFalse();
});

test('degraded writes no row', function (): void {
    usageWriteModel();
    [, , , $session] = usageWriteFixture(LlmBindingStatus::Degraded->value, 'gemini-3-flash-preview');

    $recorder = app(RecordConversationLlmUsage::class);
    $recorder($session);

    expect(InterviewSessionLlmUsage::where('interview_session_id', $session->id)->exists())->toBeFalse();
});

test('a null system_prompt_chars yields estimated_cost_usd null with system_prompt_chars_missing recorded', function (): void {
    usageWriteModel();
    [, , , $session] = usageWriteFixture(LlmBindingStatus::Applied->value, 'gemini-3-flash-preview', null);

    $recorder = app(RecordConversationLlmUsage::class);
    $row = $recorder($session);

    expect($row)->not->toBeNull()
        ->and($row->estimated_cost_usd)->toBeNull()
        ->and($row->rate_card['missing_reason'])->toBe('system_prompt_chars_missing');
});

test('the persisted rate_card snapshot survives a later registry price edit', function (): void {
    $model = usageWriteModel();
    [, , , $session] = usageWriteFixture(LlmBindingStatus::Applied->value, 'gemini-3-flash-preview');

    $recorder = app(RecordConversationLlmUsage::class);
    $row = $recorder($session);
    $originalCost = $row->estimated_cost_usd;

    // A later price edit — the historical row must not move.
    $model->update(['text_input_usd_per_million' => '999.000000']);

    $row->refresh();

    expect((string) $row->estimated_cost_usd)->toBe((string) $originalCost)
        ->and($row->rate_card['text_input_usd_per_million'])->toBe('1.000000');
});
