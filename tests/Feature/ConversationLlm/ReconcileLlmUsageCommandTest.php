<?php

declare(strict_types=1);

/**
 * `beai:reconcile-llm-usage` — the daily sweep for a session that never
 * reached `/end` (pluggable-conversation-llm PR P6b, design D10 W4).
 *
 * Two holes, two different answers:
 *   (1) Terminal but unswept (`markSessionError()`, outside any client
 *       action) — RECONCILED here, via the SAME `RecordConversationLlmUsage`
 *       action `/end` uses.
 *   (2) Pure abandonment (`in_corso` forever) — ACCEPTED, DOCUMENTED GAP.
 *       This sweep MUST NOT force-terminate an abandoned session: that is a
 *       candidate-state change, not a cost feature.
 *
 * REQ: conversation-llm "A session ended by server-detected error still
 *      gets its LLM cost recorded, without inventing a terminal state for
 *      one that is merely abandoned"
 */

use App\Actions\ConversationLlm\RecordConversationLlmUsage;
use App\Console\Commands\ReconcileLlmUsage;
use App\Enums\LlmBindingStatus;
use App\Models\InterviewSession;
use App\Models\InterviewSessionLlmUsage;
use App\Models\LlmModel;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Utterance;
use App\Support\Tenancy\TenantContextScope;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Carbon;

function reconcileModel(): LlmModel
{
    return LlmModel::firstOrCreate(
        ['key' => 'gemini-3-flash-preview'],
        [
            'vendor' => 'google',
            'display_name' => 'Gemini 3 Flash Preview',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
            'capability' => 'text',
            'is_available' => true,
            'sort_order' => 0,
            'text_input_usd_per_million' => '1.000000',
            'text_output_usd_per_million' => '2.000000',
        ],
    );
}

/**
 * @return array{Organization, InterviewSession}
 */
function reconcileFixture(string $status, string $llmBindingStatus, ?Carbon $updatedAt = null): array
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
        'candidate_ref' => 'reconcile-'.uniqid(),
        'display_name' => 'Reconcile Candidate',
        'status' => 'errore',
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
        'status' => $status,
        'ended_reason' => $status === 'error' ? 'error' : $status,
    ]);
    $session->forceFill([
        'llm_binding_status' => $llmBindingStatus,
        'llm_model_key' => $llmBindingStatus === LlmBindingStatus::Applied->value ? 'gemini-3-flash-preview' : null,
        'system_prompt_chars' => 40,
    ])->save();

    Utterance::create(['interview_session_id' => $session->id, 'speaker' => 'avatar', 'text' => str_repeat('a', 20), 'ts' => now()->subMinutes(3)]);
    Utterance::create(['interview_session_id' => $session->id, 'speaker' => 'candidate', 'text' => str_repeat('b', 20), 'ts' => now()->subMinutes(2)]);

    // updated_at is the grace-window clock (ended_at is never stamped on the
    // markSessionError() path, so ended_at cannot be the filter here).
    InterviewSession::withoutGlobalScopes()
        ->whereKey($session->id)
        ->update(['updated_at' => $updatedAt ?? now()->subHours(2)]);

    return [$org, $session->fresh()];
}

test('a session ended by markSessionError() with no /end is swept into exactly one row', function (): void {
    reconcileModel();
    [, $session] = reconcileFixture('error', LlmBindingStatus::Applied->value);

    $this->artisan(ReconcileLlmUsage::class)->assertExitCode(0);

    expect(InterviewSessionLlmUsage::where('interview_session_id', $session->id)->count())->toBe(1);
});

test('running the sweep twice adds no second row', function (): void {
    reconcileModel();
    [, $session] = reconcileFixture('error', LlmBindingStatus::Applied->value);

    $this->artisan(ReconcileLlmUsage::class)->assertExitCode(0);
    $this->artisan(ReconcileLlmUsage::class)->assertExitCode(0);

    expect(InterviewSessionLlmUsage::where('interview_session_id', $session->id)->count())->toBe(1);
});

test('a session already reconciled by a late /end is left alone by the sweep', function (): void {
    reconcileModel();
    [$org, $session] = reconcileFixture('completed', LlmBindingStatus::Applied->value);

    // The "late /end" already wrote the row via the SAME action.
    TenantContextScope::runFor($org->id, function () use ($session): void {
        app(RecordConversationLlmUsage::class)($session);
    });

    expect(InterviewSessionLlmUsage::where('interview_session_id', $session->id)->count())->toBe(1);

    $this->artisan(ReconcileLlmUsage::class)->assertExitCode(0);

    expect(InterviewSessionLlmUsage::where('interview_session_id', $session->id)->count())->toBe(1);
});

test('unbound and degraded sessions are never swept', function (): void {
    reconcileModel();
    [, $unbound] = reconcileFixture('error', LlmBindingStatus::Unbound->value);
    [, $degraded] = reconcileFixture('error', LlmBindingStatus::Degraded->value);

    $this->artisan(ReconcileLlmUsage::class)->assertExitCode(0);

    expect(InterviewSessionLlmUsage::where('interview_session_id', $unbound->id)->exists())->toBeFalse()
        ->and(InterviewSessionLlmUsage::where('interview_session_id', $degraded->id)->exists())->toBeFalse();
});

test('an abandoned in_corso session is left untouched, not force-terminated', function (): void {
    reconcileModel();
    [, $session] = reconcileFixture('in_corso', LlmBindingStatus::Applied->value);

    $this->artisan(ReconcileLlmUsage::class)->assertExitCode(0);

    $session->refresh();
    expect($session->status)->toBe('in_corso')
        ->and($session->ended_at)->toBeNull()
        ->and(InterviewSessionLlmUsage::where('interview_session_id', $session->id)->exists())->toBeFalse();
});

test('a session inside the grace window is not yet swept', function (): void {
    reconcileModel();
    [, $session] = reconcileFixture('error', LlmBindingStatus::Applied->value, now()->subMinutes(10));

    $this->artisan(ReconcileLlmUsage::class)->assertExitCode(0);

    expect(InterviewSessionLlmUsage::where('interview_session_id', $session->id)->exists())->toBeFalse();
});
