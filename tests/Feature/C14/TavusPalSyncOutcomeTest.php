<?php

declare(strict_types=1);

/**
 * The LAST Tavus PAL sync outcome is a fact about the template, not a banner.
 *
 * `TavusPalSync::sync()` used to return a transient array and persist nothing:
 * a Tavus 400 "Invalid persona_id" (a persona the account cannot edit) was
 * visible only to whoever happened to read one response, and the candidate then
 * heard a different voice with no trace of why. The outcome is now mapped to a
 * stable machine code and persisted on `avatar_templates.pal_sync_*`, which is
 * deliberately NOT `llm_sync_*`: those two columns decide whether a managed-LLM
 * binding is billable (`LlmBindingResolver`) and mean "the BINDING reached the
 * vendor", a different question from "the persona knobs did".
 *
 * Every provider call is faked; a stray request fails the run.
 */

use App\Actions\ConversationLlm\ResyncTemplateBinding;
use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Support\AvatarTemplates\TavusPalSync;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function palOutcomeOrg(): Organization
{
    return Organization::factory()->create();
}

/** @param  array<string, mixed>  $config */
function palOutcomeTemplate(Organization $org, array $config = [], string $provider = 'tavus'): AvatarTemplate
{
    return TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'PAL outcome '.uniqid(),
        'provider' => $provider,
        'config' => $config + ['faceId' => 'r', 'palId' => 'p_outcome', 'llmTemperature' => 0.5],
    ]));
}

beforeEach(function (): void {
    config()->set('interview.tavus.api_key', 'test-key');
    Http::preventStrayRequests();
});

dataset('pal sync responses', [
    'invalid persona_id (case-insensitive)' => [400, ['message' => 'INVALID PERSONA_ID'], 'pal_not_editable'],
    'invalid persona_id in prose' => [400, ['message' => 'Error: Invalid persona_id supplied'], 'pal_not_editable'],
    'other 400' => [400, ['message' => 'layers.tts.tts_model_name is not valid'], 'pal_sync_rejected'],
    '400 without a body' => [400, [], 'pal_sync_rejected'],
    '422' => [422, ['message' => 'nope'], 'pal_sync_rejected'],
    '401' => [401, ['message' => 'bad key'], 'pal_sync_unauthorized'],
    '403' => [403, ['message' => 'forbidden'], 'pal_sync_unauthorized'],
    '404' => [404, ['message' => 'no such persona'], 'pal_not_found'],
    '429' => [429, [], 'pal_sync_failed'],
    '500' => [500, ['message' => 'boom'], 'pal_sync_failed'],
    '503' => [503, [], 'pal_sync_failed'],
]);

test('a Tavus response maps to one stable code', function (int $status, array $body, string $code): void {
    Http::fake(['*' => Http::response($body, $status)]);

    $result = app(TavusPalSync::class)->sync(palOutcomeTemplate(palOutcomeOrg()));

    expect($result)->toBe(['status' => 'warning', 'message' => $code]);
})->with('pal sync responses');

test('a 2xx and a 304 are both synced', function (int $status): void {
    Http::fake(['*' => Http::response([], $status)]);

    expect(app(TavusPalSync::class)->sync(palOutcomeTemplate(palOutcomeOrg()))['status'])->toBe('synced');
})->with([200, 204, 304]);

test('a timeout maps to pal_sync_unreachable', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

    $result = app(TavusPalSync::class)->sync(palOutcomeTemplate(palOutcomeOrg()));

    expect($result)->toBe(['status' => 'warning', 'message' => 'pal_sync_unreachable']);
});

test('the log carries the status and the code, never the provider body or the key', function (): void {
    Http::fake(['*' => Http::response(['message' => 'Invalid persona_id SECRET-BODY-TEXT'], 400)]);
    Log::spy();

    app(TavusPalSync::class)->sync(palOutcomeTemplate(palOutcomeOrg()));

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
        return $context['status'] === 400
            && $context['code'] === 'pal_not_editable'
            && ! str_contains(json_encode($context), 'SECRET-BODY-TEXT')
            && ! str_contains(json_encode($context), 'test-key');
    })->once();
});

// ─── persistence, through the one funnel every caller uses ────────────────

test('a failed save-time sync persists the code on the template and exposes it to an admin', function (): void {
    $org = palOutcomeOrg();
    $token = authTokenForRole($org, 'platform');
    Http::fake(['*' => Http::response(['message' => 'Invalid persona_id'], 400)]);

    $template = palOutcomeTemplate($org, ['llmTemperature' => 0.3]);

    $response = $this->withToken($token)
        ->patchJson("/api/avatar-templates/{$template->id}", ['name' => 'Renamed'])
        ->assertSuccessful()
        // Backward compatible 2xx body: the warning key is the existing one.
        ->assertJsonPath('warning', 'pal_not_editable')
        ->assertJsonPath('data.pal_sync.status', 'warning')
        ->assertJsonPath('data.pal_sync.code', 'pal_not_editable')
        ->assertJsonPath('data.pal_sync.synced_at', null);

    $fresh = $template->fresh();
    expect($fresh->pal_sync_status)->toBe('warning')
        ->and($fresh->pal_sync_code)->toBe('pal_not_editable')
        ->and($fresh->pal_synced_at)->toBeNull();

    // The admin READ (not only the write response) carries it.
    $this->withToken($token)->getJson("/api/avatar-templates/{$template->id}")
        ->assertJsonPath('data.pal_sync.code', 'pal_not_editable');

    expect($response->json('data.pal_sync'))->not->toHaveKey('message');
});

test('a later successful sync clears the code and stamps the time', function (): void {
    $org = palOutcomeOrg();
    $token = authTokenForRole($org, 'platform');
    Http::fakeSequence('*')
        ->push(['message' => 'Invalid persona_id'], 400)
        ->push([], 200);

    $template = palOutcomeTemplate($org);

    $this->withToken($token)->patchJson("/api/avatar-templates/{$template->id}", ['name' => 'One'])->assertSuccessful();
    $this->withToken($token)->patchJson("/api/avatar-templates/{$template->id}", ['name' => 'Two'])
        ->assertJsonMissingPath('warning')
        ->assertJsonPath('data.pal_sync.status', 'synced')
        ->assertJsonPath('data.pal_sync.code', null);

    expect($template->fresh()->pal_synced_at)->not->toBeNull();
});

test('a template with no persona knobs records skipped, and a heygen template records nothing', function (): void {
    $org = palOutcomeOrg();
    Http::fake();

    $tavus = palOutcomeTemplate($org, ['llmTemperature' => null]);
    $heygen = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'hg '.uniqid(), 'provider' => 'heygen', 'config' => ['avatarId' => 'a', 'voiceId' => 'v'],
    ]));

    app(ResyncTemplateBinding::class)->run($tavus);
    app(ResyncTemplateBinding::class)->run($heygen);

    expect($tavus->fresh()->pal_sync_status)->toBe('skipped')
        ->and($tavus->fresh()->pal_sync_code)->toBeNull()
        ->and($heygen->fresh()->pal_sync_status)->toBeNull();
});

test('activating a template persists the outcome too', function (): void {
    $org = palOutcomeOrg();
    $token = authTokenForRole($org, 'platform');
    Http::fake(['*' => Http::response([], 404)]);

    $template = palOutcomeTemplate($org);

    $this->withToken($token)->postJson("/api/avatar-templates/{$template->id}/activate")
        ->assertSuccessful()
        ->assertJsonPath('warning', 'pal_not_found');

    expect($template->fresh()->pal_sync_code)->toBe('pal_not_found');
});

test('the code is never mass-assignable', function (): void {
    $template = new AvatarTemplate;

    expect($template->isFillable('pal_sync_status'))->toBeFalse()
        ->and($template->isFillable('pal_sync_code'))->toBeFalse()
        ->and($template->isFillable('pal_synced_at'))->toBeFalse();
});
