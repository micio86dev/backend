<?php

declare(strict_types=1);

/**
 * `llm_sync_status`/`llm_synced_at` persistence around a Tavus PAL sync
 * (pluggable-conversation-llm PR P4, design D0/D7, non-negotiable #4).
 *
 * Before this PR, `TavusPalSync::sync()` returned its outcome to
 * `AvatarTemplateController::palWarning()`, which turned it into a response
 * banner and PERSISTED NOTHING. `degraded` was therefore unreachable on the
 * Tavus path — a template whose PAL push failed still resolved `applied` at
 * session-issue time and got billed. `recordSync()` closes that gap by
 * writing the outcome via `forceFill([...])->saveQuietly()`.
 *
 * `saveQuietly()` is a RE-ENTRANCY GUARD, not a style choice — see the second
 * test below.
 *
 * REQ: conversation-llm "A failed provider sync is recorded, not just
 *      reported, so a later session resolves `degraded` and is never billed"
 */

use App\Enums\LlmBindingStatus;
use App\Models\AvatarTemplate;
use App\Models\LlmCredential;
use App\Models\LlmModel;
use App\Models\Organization;
use App\Services\ConversationLlm\LlmBindingResolver;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\Http;

function syncStateModel(): LlmModel
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

function syncStateCredential(int $orgId): LlmCredential
{
    return TenantContextScope::runFor($orgId, function () use ($orgId): LlmCredential {
        $credential = new LlmCredential;
        $credential->forceFill([
            'organization_id' => $orgId,
            'name' => 'Sync-state-cred-'.uniqid(),
            'vendor' => 'google',
            'api_key' => 'sk-real-gemini-key',
            'key_last_four' => 'lkey',
            'key_fingerprint' => hash('sha256', uniqid('', true)),
        ]);
        $credential->save();

        return $credential;
    });
}

test('a failed Tavus PAL sync persists llm_sync_status !== synced, and a later session resolve is degraded', function (): void {
    config()->set('interview.tavus.api_key', 'test-key');

    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'platform');
    $model = syncStateModel();
    $credential = syncStateCredential($org->id);

    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Sync-state template',
        'provider' => 'tavus',
        'config' => ['faceId' => 'r', 'palId' => 'p_failing'],
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credential->id,
    ]));

    // A sequence, not two separate fake() calls: Http::fake() STACKS its
    // stub callbacks rather than replacing them, so a second fake() call for
    // the SAME url pattern would leave the first (200) response matching
    // FIRST forever. A sequence is the correct tool for "this endpoint
    // succeeds once, then fails."
    //
    // Push 1: establishes a genuine PRIOR 'synced' fact — proves the
    // assertion below is a real state TRANSITION caused by the failure, not
    // merely "still null, as it always was" (a vacuous pass pre-fix, since
    // nothing persisted anything before this PR).
    // Push 2: the vendor's own real 400 shape (Phase 0.3(c) live
    // smoke-check) — any non-2xx is a sync failure, regardless of the exact
    // status/body.
    Http::fakeSequence('*pals*')
        ->push([], 200)
        ->push(
            json_decode(file_get_contents(base_path('tests/Fixtures/Provider/tavus/pal_patch_missing_api_key_400.json')), true),
            400,
        );

    $this->withToken($token)
        ->patchJson("/api/avatar-templates/{$template->id}", ['name' => 'Sync-state template — first save'])
        ->assertSuccessful();
    expect($template->fresh()->llm_sync_status)->toBe('synced');

    // An unrelated field edit still triggers recordSync() — it runs on every
    // update(), not only when the binding itself changes.
    $this->withToken($token)
        ->patchJson("/api/avatar-templates/{$template->id}", ['name' => 'Sync-state template renamed'])
        ->assertSuccessful();

    $fresh = $template->fresh();

    expect($fresh->llm_sync_status)->not->toBe('synced');
    expect($fresh->llm_synced_at)->toBeNull();

    // The D0 tri-state decision, read from PERSISTED state only — this is
    // the "later session issue" outcome: a bound-but-unsynced template never
    // resolves Applied, so it is never billed. The `interview_session_llm_
    // usage` no-cost-row assertion belongs to PR P6b once that table exists
    // (out of scope here — P6a/P6b are explicitly not started in this batch).
    expect(app(LlmBindingResolver::class)->resolveStatus($fresh))->toBe(LlmBindingStatus::Degraded);
});

test('a successful Tavus PAL sync persists llm_sync_status = synced, and a later session resolve is applied', function (): void {
    config()->set('interview.tavus.api_key', 'test-key');

    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'platform');
    $model = syncStateModel();
    $credential = syncStateCredential($org->id);

    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Sync-state template ok',
        'provider' => 'tavus',
        'config' => ['faceId' => 'r', 'palId' => 'p_ok'],
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credential->id,
    ]));

    Http::fake(['*pals*' => Http::response([], 200)]);

    $this->withToken($token)
        ->patchJson("/api/avatar-templates/{$template->id}", ['name' => 'Sync-state template ok renamed'])
        ->assertSuccessful();

    $fresh = $template->fresh();

    expect($fresh->llm_sync_status)->toBe('synced');
    expect($fresh->llm_synced_at)->not->toBeNull();
    expect(app(LlmBindingResolver::class)->resolveStatus($fresh))->toBe(LlmBindingStatus::Applied);
});

test('recordSync persists via saveQuietly — it fires no saving/saved model event of its own', function (): void {
    config()->set('interview.tavus.api_key', 'test-key');

    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'platform');
    $model = syncStateModel();
    $credential = syncStateCredential($org->id);

    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Sync-state event count',
        'provider' => 'tavus',
        'config' => ['faceId' => 'r', 'palId' => 'p_event'],
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credential->id,
    ]));

    Http::fake(['*pals*' => Http::response([], 200)]);

    $savingCount = 0;
    AvatarTemplate::saving(function () use (&$savingCount): void {
        $savingCount++;
    });

    $this->withToken($token)
        ->patchJson("/api/avatar-templates/{$template->id}", ['name' => 'Sync-state event count renamed'])
        ->assertSuccessful();

    // Exactly ONE `saving` dispatch — the controller's own explicit
    // `$template->update($validated)`. A plain `save()` inside recordSync()
    // would have fired a SECOND `saving` here (re-running I2/I3/I4 on
    // invariants that just passed), proving `saveQuietly()` is load-bearing,
    // not a style choice a future refactor can "tidy" away.
    expect($savingCount)->toBe(1);
});
