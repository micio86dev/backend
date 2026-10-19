<?php

declare(strict_types=1);

/**
 * Template writes for `hr_avatar` (hr-avatar-adapter A3a).
 *
 * `hr_avatar` is a template provider only while the integration is available; it stores an opaque
 * config id and no provider knobs; heygen and tavus keep their exact pre-change behaviour; and no
 * write ever sends a request anywhere.
 */

use App\Models\LlmCredential;
use App\Models\LlmModel;
use App\Models\Organization;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Helpers\AvatarTemplates\TemplateActors;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
});

/** @param array<string, mixed> $overrides */
function hrtPayload(array $overrides = []): array
{
    return array_merge(['name' => 'HR-Avatar interviewer', 'provider' => 'hr_avatar', 'hr_avatar_config_id' => 'avc_01HXYZ'], $overrides);
}

/**
 * A binding that is valid for any other provider, so the only reason to refuse it is `hr_avatar`.
 *
 * @return array{llm_model_id: int, llm_credential_id: int}
 */
function hrtValidBinding(Organization $org): array
{
    $model = LlmModel::create([
        'key' => 'gemini-3-flash-preview',
        'vendor' => 'google',
        'display_name' => 'Gemini 3 Flash Preview',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
        'capability' => 'text',
        'is_available' => true,
        'sort_order' => 0,
    ]);
    $credential = TenantContextScope::runFor($org->id, function (): LlmCredential {
        $credential = new LlmCredential;
        $credential->forceFill([
            'name' => 'Cred-'.uniqid(),
            'vendor' => 'google',
            'api_key' => 'sk-real-key',
            'key_last_four' => 'real',
            'key_fingerprint' => hash('sha256', uniqid('', true)),
        ]);
        $credential->save();

        return $credential;
    });

    return ['llm_model_id' => $model->id, 'llm_credential_id' => $credential->id];
}

// ─── Create ──────────────────────────────────────────────────────────────────

test('an available integration accepts an hr_avatar template: inactive, id returned, config empty', function (): void {
    hraMakeAvailable();
    Http::fake();
    $org = Organization::factory()->create();

    $this->withToken(hraActingToken($org))->postJson('/api/avatar-templates', hrtPayload())
        ->assertCreated()
        ->assertJsonPath('data.provider', 'hr_avatar')
        ->assertJsonPath('data.hr_avatar_config_id', 'avc_01HXYZ')
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.config', []);

    Http::assertNothingSent();
});

test('an unavailable integration refuses hr_avatar like an unknown provider and creates nothing', function (): void {
    Http::fake();
    $org = Organization::factory()->create();

    $this->withToken(hraActingToken($org))->postJson('/api/avatar-templates', hrtPayload())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['provider']);

    expect(DB::table('avatar_templates')->count())->toBe(0);
    Http::assertNothingSent();
});

test('config may be omitted or empty for hr_avatar', function (array $extra): void {
    hraMakeAvailable();
    $org = Organization::factory()->create();

    $this->withToken(hraActingToken($org))->postJson('/api/avatar-templates', hrtPayload($extra))
        ->assertCreated()
        ->assertJsonPath('data.config', []);
})->with([
    'omitted' => [[]],
    'empty object' => [['config' => []]],
]);

test('config stays required for heygen and tavus with exactly the pre-change message', function (string $provider): void {
    hraMakeAvailable();
    $org = Organization::factory()->create();

    $this->withToken(hraActingToken($org))->postJson('/api/avatar-templates', ['name' => 'No config', 'provider' => $provider])
        ->assertStatus(422)
        ->assertExactJson([
            'message' => __('validation.required', ['attribute' => 'config']),
            'errors' => ['config' => [__('validation.required', ['attribute' => 'config'])]],
        ]);
})->with(['tavus', 'heygen']);

test('every field error of an hr_avatar create comes back in ONE 422', function (): void {
    hraMakeAvailable();
    $org = Organization::factory()->create();

    $response = $this->withToken(hraActingToken($org))->postJson('/api/avatar-templates', hrtPayload([
        'hr_avatar_config_id' => 'cfg_1',
        'config' => ['nonsense' => 1],
    ]))->assertStatus(422);

    expect(array_keys($response->json('errors')))->toEqualCanonicalizing(['hr_avatar_config_id', 'config.nonsense']);
    expect($response->json('errors.config.nonsense'))->toBeNull();
    expect($response->json('errors')['config.nonsense'])->toBe(['unknown']);
});

test('config knobs of another provider are each reported as unknown', function (): void {
    hraMakeAvailable();
    $org = Organization::factory()->create();

    $errors = $this->withToken(hraActingToken($org))->postJson('/api/avatar-templates', hrtPayload([
        'config' => ['avatarId' => 'x', 'nonsense' => 1],
    ]))->assertStatus(422)->json('errors');

    expect($errors)->toBe(['config.avatarId' => ['unknown'], 'config.nonsense' => ['unknown']]);
});

test('the config id is required for hr_avatar', function (): void {
    hraMakeAvailable();
    $org = Organization::factory()->create();

    $this->withToken(hraActingToken($org))->postJson('/api/avatar-templates', ['name' => 'No id', 'provider' => 'hr_avatar'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['hr_avatar_config_id']);
});

test('an LLM binding is refused on an hr_avatar template, keyed on each field', function (): void {
    hraMakeAvailable();
    $org = Organization::factory()->create();
    $binding = hrtValidBinding($org);

    $errors = $this->withToken(hraActingToken($org))->postJson('/api/avatar-templates', hrtPayload($binding))
        ->assertStatus(422)->json('errors');

    expect(array_keys($errors))->toEqualCanonicalizing(['llm_model_id', 'llm_credential_id']);
    expect(DB::table('avatar_templates')->count())->toBe(0);
});

// ─── Update ──────────────────────────────────────────────────────────────────

test('an update cannot clear the id, and the stored id is unchanged', function (): void {
    hraMakeAvailable();
    $org = Organization::factory()->create();
    $template = hraSeedTemplate($org);

    $this->withToken(hraActingToken($org))->patchJson("/api/avatar-templates/{$template->id}", ['hr_avatar_config_id' => null])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['hr_avatar_config_id']);

    expect($template->fresh()->hr_avatar_config_id)->toBe('avc_01HXYZ');
});

test('an update cannot bind an LLM model to an hr_avatar template', function (): void {
    hraMakeAvailable();
    $org = Organization::factory()->create();
    $template = hraSeedTemplate($org);

    $this->withToken(hraActingToken($org))->patchJson("/api/avatar-templates/{$template->id}", hrtValidBinding($org))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['llm_model_id']);

    expect($template->fresh()->llm_model_id)->toBeNull();
});

test('an update validates the id format and the config together in one 422', function (): void {
    hraMakeAvailable();
    $org = Organization::factory()->create();
    $template = hraSeedTemplate($org);

    $errors = $this->withToken(hraActingToken($org))->patchJson("/api/avatar-templates/{$template->id}", [
        'hr_avatar_config_id' => 'cfg_1',
        'config' => ['nonsense' => 1],
    ])->assertStatus(422)->json('errors');

    expect(array_keys($errors))->toEqualCanonicalizing(['hr_avatar_config_id', 'config.nonsense']);
});

test('an update may point the template at another config', function (): void {
    $org = Organization::factory()->create();
    $template = hraSeedTemplate($org);

    $this->withToken(hraActingToken($org))->patchJson("/api/avatar-templates/{$template->id}", ['hr_avatar_config_id' => 'avc_NEW1'])
        ->assertOk()
        ->assertJsonPath('data.hr_avatar_config_id', 'avc_NEW1');
});

test('the provider cannot be converted to or from hr_avatar', function (string $from, string $to): void {
    hraMakeAvailable();
    $org = Organization::factory()->create();
    $template = hraSeedTemplate($org, ['provider' => $from, 'config' => $from === 'hr_avatar' ? [] : ['avatarId' => 'a', 'voiceId' => 'v'], 'hr_avatar_config_id' => $from === 'hr_avatar' ? 'avc_01HXYZ' : null]);

    $this->withToken(hraActingToken($org))->patchJson("/api/avatar-templates/{$template->id}", ['provider' => $to])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['provider']);

    expect($template->fresh()->provider)->toBe($from);
})->with([
    'tavus to hr_avatar' => ['tavus', 'hr_avatar'],
    'hr_avatar to tavus' => ['hr_avatar', 'tavus'],
    'heygen to tavus' => ['heygen', 'tavus'],
]);

// ─── Availability gates ──────────────────────────────────────────────────────

test('activation is refused while unavailable and an existing template stays manageable', function (): void {
    Http::fake();
    $org = Organization::factory()->create();
    $template = hraSeedTemplate($org);
    $token = hraActingToken($org);

    $this->withToken($token)->postJson("/api/avatar-templates/{$template->id}/activate")->assertStatus(422);
    expect($template->fresh()->is_active)->toBeFalse();

    $this->withToken($token)->patchJson("/api/avatar-templates/{$template->id}", ['name' => 'Renamed'])->assertOk();
    $this->withToken($token)->deleteJson("/api/avatar-templates/{$template->id}")->assertNoContent();

    Http::assertNothingSent();
});

test('no write sends a request: create, update, activate, deactivate and delete', function (): void {
    hraMakeAvailable();
    Http::fake();
    $org = Organization::factory()->create();
    $token = hraActingToken($org);

    $id = $this->withToken($token)->postJson('/api/avatar-templates', hrtPayload())->assertCreated()->json('data.id');
    $this->withToken($token)->patchJson("/api/avatar-templates/{$id}", ['name' => 'Renamed', 'persona' => ['display_name' => 'Giulia']])->assertOk();
    $this->withToken($token)->postJson("/api/avatar-templates/{$id}/activate")->assertOk()->assertJsonPath('data.is_active', true);
    $this->withToken($token)->postJson("/api/avatar-templates/{$id}/deactivate")->assertOk();
    $this->withToken($token)->deleteJson("/api/avatar-templates/{$id}")->assertNoContent();

    Http::assertNothingSent();
});

test('field specs carry hr_avatar as an empty list only while available', function (): void {
    $org = Organization::factory()->create();
    $token = hraActingToken($org);

    $off = $this->withToken($token)->getJson('/api/avatar-templates/field-specs')->assertOk()->json('data');
    expect(array_keys($off))->toBe(['heygen', 'tavus']);

    hraMakeAvailable();

    $on = $this->withToken($token)->getJson('/api/avatar-templates/field-specs')->assertOk()->json('data');
    expect(array_keys($on))->toBe(['heygen', 'tavus', 'hr_avatar']);
    expect($on['hr_avatar'])->toBe([]);
    expect($on['heygen'])->toBe($off['heygen'])->and($on['tavus'])->toBe($off['tavus']);
});

// ─── Resource ────────────────────────────────────────────────────────────────

test('heygen and tavus templates without an id have no hr_avatar_config_id member', function (string $provider): void {
    $org = Organization::factory()->create();
    $template = hraSeedTemplate($org, ['provider' => $provider, 'config' => ['avatarId' => 'a', 'voiceId' => 'v'], 'hr_avatar_config_id' => null]);
    $token = hraActingToken($org);

    $this->withToken($token)->getJson("/api/avatar-templates/{$template->id}")->assertOk()->assertJsonMissingPath('data.hr_avatar_config_id');
    expect($this->withToken($token)->getJson('/api/avatar-templates')->json('data.0'))->not->toHaveKey('hr_avatar_config_id');
})->with(['heygen', 'tavus']);

test('a tavus template carrying an id issues a Tavus request identical to one without', function (): void {
    Http::fake(['*tavusapi*/v2/conversations*' => Http::response(['conversation_id' => 'tv-1', 'conversation_url' => 'https://tavus.example.test/tv-1'], 200)]);
    [, $project, $participant, $token] = hraStartScenario('tavus');

    $start = fn () => test()->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/candidate/interview/start')->assertCreated();
    $start();

    // The same candidate starts again from scratch, now on a template that carries the id.
    DB::table('interview_sessions')->where('participant_id', $participant->id)->delete();
    DB::table('participants')->where('id', $participant->id)->update(['status' => 'in_attesa']);
    DB::table('avatar_templates')->where('id', $project->avatar_template_id)->update(['hr_avatar_config_id' => 'avc_01HXYZ']);
    $start();

    $bodies = Http::recorded(fn ($request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/conversations'))->map(fn (array $pair): array => $pair[0]->data())->values();

    expect($bodies)->toHaveCount(2)->and($bodies[1])->toBe($bodies[0])->and($bodies[0])->not->toBeEmpty();
});

// ─── Scope ───────────────────────────────────────────────────────────────────

test('the platform store still refuses hr_avatar, even while available', function (): void {
    hraMakeAvailable();
    Http::fake();
    $token = TemplateActors::token('bare', Organization::factory()->create());

    $this->withToken($token)->postJson('/api/admin/avatar-templates', hrtPayload(['config' => ['avatarId' => 'x']]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['provider']);

    Http::assertNothingSent();
});

test('another tenant\'s config id never appears in a list or a read, and its template is a 404', function (): void {
    hraMakeAvailable();
    $a = Organization::factory()->create();
    $b = Organization::factory()->create();
    $theirs = hraSeedTemplate($b, ['hr_avatar_config_id' => 'avc_B']);
    hraSeedTemplate($a, ['hr_avatar_config_id' => 'avc_A']);
    $token = hraActingToken($a);

    $list = $this->withToken($token)->getJson('/api/avatar-templates')->assertOk();
    expect($list->json('data'))->toHaveCount(1);
    expect($list->getContent())->toContain('avc_A')->not->toContain('avc_B');

    $this->withToken($token)->getJson("/api/avatar-templates/{$theirs->id}")->assertNotFound();
    $this->withToken($token)->patchJson("/api/avatar-templates/{$theirs->id}", ['name' => 'x'])->assertNotFound();
});
