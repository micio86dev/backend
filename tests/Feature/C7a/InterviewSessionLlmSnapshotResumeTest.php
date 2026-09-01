<?php

declare(strict_types=1);

/**
 * `InterviewSessionLlmSnapshot::stamp()` write-once / downgrade-only / null-guard
 * semantics (pluggable-conversation-llm PR P6a, design D5 — non-negotiable #7).
 *
 * `issue()` IS re-invoked on resume (`InterviewController.php:690`/`:789`), so a
 * naive re-stamp would rewrite a snapshot that must instead behave like the
 * codebase's own `started_at ??= now()` idiom — with two fields carrying a
 * stricter rule than plain write-once.
 *
 * REQ: interview-session "The session records which template/model/binding
 *      state it was issued under, exactly once, and never over-reports a
 *      degraded binding as applied"
 */

use App\Enums\LlmBindingStatus;
use App\Models\AvatarTemplate;
use App\Models\InterviewSession;
use App\Models\LlmCredential;
use App\Models\LlmModel;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Services\ConversationLlm\InterviewSessionLlmSnapshot;
use App\Support\Tenancy\TenantContextScope;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function llmSnapshotModel(): LlmModel
{
    return LlmModel::create([
        'key' => 'gemini-3-flash-preview',
        'vendor' => 'google',
        'display_name' => 'Gemini 3 Flash Preview',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
        'capability' => 'text',
        'is_available' => true,
        'sort_order' => 0,
    ]);
}

function llmSnapshotCredential(int $orgId): LlmCredential
{
    return TenantContextScope::runFor($orgId, function () use ($orgId): LlmCredential {
        $credential = new LlmCredential;
        $credential->forceFill([
            'organization_id' => $orgId,
            'name' => 'Snapshot-cred-'.uniqid(),
            'vendor' => 'google',
            'api_key' => 'sk-real-gemini-key',
            'key_last_four' => 'lkey',
            'key_fingerprint' => hash('sha256', uniqid('', true)),
        ]);
        $credential->save();

        return $credential;
    });
}

function llmSnapshotTemplate(int $orgId, ?int $modelId, ?int $credentialId, ?string $syncStatus): AvatarTemplate
{
    return TenantContextScope::runFor($orgId, function () use ($modelId, $credentialId, $syncStatus): AvatarTemplate {
        $template = AvatarTemplate::create([
            'name' => 'Snapshot template '.uniqid(),
            'provider' => 'heygen',
            'config' => [],
            'is_active' => true,
            'llm_model_id' => $modelId,
            'llm_credential_id' => $credentialId,
        ]);

        // llm_sync_status is NOT mass-assignable (AvatarTemplate::$fillable) —
        // forceFill()->saveQuietly() mirrors the codebase's own convention
        // for writing this column (TavusPalSync/HeygenLlmRegistrar's
        // recordSync(), never a plain save() — see design D7).
        $template->forceFill(['llm_sync_status' => $syncStatus])->saveQuietly();

        return $template->fresh();
    });
}

/**
 * Point a project at a template.
 *
 * These tests create the template and the project in either order, and while
 * `ActiveTemplateResolver` answered with the organization-wide active template
 * that was enough — activating one changed what the session resolved. It is not
 * any more: `projects.avatar_template_id` is NOT NULL and the resolver returns
 * what the PROJECT pinned, so a template built after its project would never be
 * reached and the snapshot would record `unbound` against a binding the test
 * had carefully set up.
 */
function llmSnapshotPin(Project $project, AvatarTemplate $template): void
{
    $project->forceFill(['avatar_template_id' => $template->id])->saveQuietly();
}

function llmSnapshotSession(int $orgId, Project $project, Participant $participant): InterviewSession
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgId);
    $resolver->setBypass(false);

    return InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => 'PRS',
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'heygen',
        'status' => 'pending',
    ]);
}

test('avatar_template_id and llm_model_key are write-once — a resume with a different active template does not rewrite them', function (): void {
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    $project = Project::factory()->create(['status' => 'active']);
    $participant = new Participant;
    $participant->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'snap-'.uniqid(),
        'display_name' => 'Snapshot Candidate',
        'status' => 'in_attesa',
    ]);
    $participant->save();

    $model = llmSnapshotModel();
    $credential = llmSnapshotCredential($org->id);
    $templateA = llmSnapshotTemplate($org->id, $model->id, $credential->id, 'synced');
    llmSnapshotPin($project, $templateA);

    $session = llmSnapshotSession($org->id, $project, $participant);

    $stamper = app(InterviewSessionLlmSnapshot::class);
    $stamper->stamp($session, 'first system prompt');
    $session->save();

    expect($session->avatar_template_id)->toBe($templateA->id)
        ->and($session->llm_model_key)->toBe('gemini-3-flash-preview');

    // A DIFFERENT template becomes the active one for this provider (the
    // operator deactivated A and activated B) before the candidate resumes.
    TenantContextScope::runFor($org->id, function () use ($templateA): void {
        $templateA->forceFill(['is_active' => false])->saveQuietly();
    });
    $modelB = LlmModel::create([
        'key' => 'gemini-3-pro-preview',
        'vendor' => 'google',
        'display_name' => 'Gemini 3 Pro Preview',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
        'capability' => 'text',
        'is_available' => true,
        'sort_order' => 1,
    ]);
    llmSnapshotTemplate($org->id, $modelB->id, $credential->id, 'synced');

    // Simulate the resume re-entry into stamp() (issue() IS called again).
    $stamper->stamp($session, 'second system prompt — a resume');
    $session->save();

    expect($session->avatar_template_id)->toBe($templateA->id)
        ->and($session->llm_model_key)->toBe('gemini-3-flash-preview');
});

test('llm_binding_status is write-once then DOWNGRADE-ONLY — applied never climbs back after degrading', function (): void {
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    $project = Project::factory()->create(['status' => 'active']);
    $participant = new Participant;
    $participant->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'snap-'.uniqid(),
        'display_name' => 'Snapshot Candidate',
        'status' => 'in_attesa',
    ]);
    $participant->save();

    $model = llmSnapshotModel();
    $credential = llmSnapshotCredential($org->id);
    $template = llmSnapshotTemplate($org->id, $model->id, $credential->id, 'synced');
    llmSnapshotPin($project, $template);

    $session = llmSnapshotSession($org->id, $project, $participant);

    $stamper = app(InterviewSessionLlmSnapshot::class);

    // First stretch resolves APPLIED.
    $stamper->stamp($session, 'first prompt');
    $session->save();
    expect($session->llm_binding_status)->toBe(LlmBindingStatus::Applied->value);

    // The credential's sync record is now stale (e.g. a re-save failed to
    // push to the provider) — the SAME template now resolves DEGRADED.
    TenantContextScope::runFor($org->id, function () use ($template): void {
        $template->forceFill(['llm_sync_status' => 'failed'])->saveQuietly();
    });

    $stamper->stamp($session, 'resume prompt, degraded');
    $session->save();
    expect($session->llm_binding_status)->toBe(LlmBindingStatus::Degraded->value);

    // A LATER resume resolves APPLIED again (sync recovered) — must NOT
    // climb back. Under-report, never over-report.
    TenantContextScope::runFor($org->id, function () use ($template): void {
        $template->forceFill(['llm_sync_status' => 'synced'])->saveQuietly();
    });

    $stamper->stamp($session, 'second resume prompt, recovered');
    $session->save();
    expect($session->llm_binding_status)->toBe(LlmBindingStatus::Degraded->value);
});

test('system_prompt_chars is write-once and never overwritten from a null on the degraded resume path', function (): void {
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    $project = Project::factory()->create(['status' => 'active']);
    $participant = new Participant;
    $participant->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'snap-'.uniqid(),
        'display_name' => 'Snapshot Candidate',
        'status' => 'in_attesa',
    ]);
    $participant->save();

    $session = llmSnapshotSession($org->id, $project, $participant);

    $stamper = app(InterviewSessionLlmSnapshot::class);

    $goodPrompt = 'a genuinely composed system prompt, forty chars.';
    $stamper->stamp($session, $goodPrompt);
    $session->save();

    expect($session->system_prompt_chars)->toBe(mb_strlen($goodPrompt));
    $recorded = $session->system_prompt_chars;

    // Degraded RESUME path: composition failed, systemPrompt fabricated null
    // (InterviewController.php:206-213). Must NOT overwrite the recorded value.
    $stamper->stamp($session, null);
    $session->save();

    expect($session->system_prompt_chars)->toBe($recorded);
});

test('an unbound resolved template (or none) snapshots unbound, llm_model_key null', function (): void {
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    $project = Project::factory()->create(['status' => 'active']);
    $participant = new Participant;
    $participant->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'snap-'.uniqid(),
        'display_name' => 'Snapshot Candidate',
        'status' => 'in_attesa',
    ]);
    $participant->save();

    // A template with NO LLM binding — which is what "unbound" means here.
    //
    // This used to read "no active template at all", and that state no longer
    // exists: `projects.avatar_template_id` is NOT NULL, so every project names
    // one. The distinction the test is really about is untouched and is the
    // interesting one: a template can exist, be pinned, and still carry no
    // model/credential pair, and the snapshot must record that honestly rather
    // than reporting a binding it does not have.
    $session = llmSnapshotSession($org->id, $project, $participant);

    $stamper = app(InterviewSessionLlmSnapshot::class);
    $stamper->stamp($session, 'some prompt');
    $session->save();

    expect($session->llm_binding_status)->toBe(LlmBindingStatus::Unbound->value)
        ->and($session->llm_model_key)->toBeNull()
        // The TEMPLATE is recorded — it exists and the session ran on it. Only
        // the BINDING is absent, and conflating the two would lose which
        // template a session used whenever that template had no LLM attached.
        ->and($session->avatar_template_id)->not->toBeNull();
});

test('a successfully applied binding snapshots applied, llm_model_key equals the bound model key', function (): void {
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    $project = Project::factory()->create(['status' => 'active']);
    $participant = new Participant;
    $participant->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'snap-'.uniqid(),
        'display_name' => 'Snapshot Candidate',
        'status' => 'in_attesa',
    ]);
    $participant->save();

    $model = llmSnapshotModel();
    $credential = llmSnapshotCredential($org->id);
    $template = llmSnapshotTemplate($org->id, $model->id, $credential->id, 'synced');
    llmSnapshotPin($project, $template);

    $session = llmSnapshotSession($org->id, $project, $participant);

    $stamper = app(InterviewSessionLlmSnapshot::class);
    $stamper->stamp($session, 'some prompt');
    $session->save();

    expect($session->llm_binding_status)->toBe(LlmBindingStatus::Applied->value)
        ->and($session->llm_model_key)->toBe($model->key)
        ->and($session->avatar_template_id)->toBe($template->id);
});
