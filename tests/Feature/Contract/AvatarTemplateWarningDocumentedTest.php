<?php

declare(strict_types=1);

/**
 * The optional top-level `warning` of the avatar-template write responses, and the optional
 * `hr_avatar_config_id` of the template resource, are published exactly as they are served
 * (hr-avatar-adapter A10).
 *
 * `warning` is the stable code of a provider sync that did not take effect: the Tavus persona sync's or the
 * HeyGen LLM configuration's, so the closed set is both. It is absent when nothing went wrong. Like the A9
 * closed sets, it is checked in the COMMITTED `openapi.json`, against the PHP constant, and against the code
 * that produces the values.
 */

use App\Actions\ConversationLlm\ResyncTemplateBinding;
use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Services\ConversationLlm\HeygenLlmRegistrar;
use App\Support\AvatarTemplates\TavusPalSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\AvatarTemplates\TemplateActors;

uses(RefreshDatabase::class);

/**
 * @return array<string, mixed>
 */
function awSpec(): array
{
    return json_decode((string) file_get_contents(base_path('openapi.json')), true, flags: JSON_THROW_ON_ERROR);
}

dataset('warningOperations', [
    'create' => ['/avatar-templates', 'post', '201', 'AvatarTemplateResource'],
    'update' => ['/avatar-templates/{id}', 'patch', '200', 'AvatarTemplateResource'],
    'activate' => ['/avatar-templates/{id}/activate', 'post', '200', 'AvatarTemplateResource'],
    'platform create' => ['/admin/avatar-templates', 'post', '201', 'PlatformAvatarTemplateResource'],
    'platform update' => ['/admin/avatar-templates/{id}', 'patch', '200', 'PlatformAvatarTemplateResource'],
]);

test('the warning is a documented optional enum next to the template, on every write that can carry one', function (string $path, string $method, string $status, string $resource): void {
    $schema = awSpec()['paths'][$path][$method]['responses'][$status]['content']['application/json']['schema'];

    expect($schema['properties']['data']['$ref'])->toBe("#/components/schemas/{$resource}")
        ->and($schema['properties']['warning']['enum'])->toEqualCanonicalizing(ResyncTemplateBinding::WARNING_CODES)
        ->and($schema['required'])->toBe(['data']);
})->with('warningOperations');

test('the warning set is the Tavus persona codes and the HeyGen LLM codes, nothing else', function (): void {
    expect(ResyncTemplateBinding::WARNING_CODES)->toEqualCanonicalizing([...TavusPalSync::CODES, ...HeygenLlmRegistrar::WARNING_CODES])
        ->and(explode('|', str_replace("'", '', ResyncTemplateBinding::WARNING_UNION)))->toEqualCanonicalizing(ResyncTemplateBinding::WARNING_CODES);

    // Every code the HeyGen registrar can answer with is written in its source, so one added there without
    // the constant fails here.
    preg_match_all("/'message' => '([a-z_]+)'/", (string) file_get_contents(app_path('Services/ConversationLlm/HeygenLlmRegistrar.php')), $found);

    expect(array_values(array_unique($found[1])))->toEqualCanonicalizing(HeygenLlmRegistrar::WARNING_CODES);
});

test('a real write response carries a warning from the documented set, and none when nothing went wrong', function (): void {
    Http::preventStrayRequests();
    config()->set('interview.tavus.api_key', '');
    config()->set('interview.preflight.verify_references', false);
    $token = TemplateActors::token('acting', Organization::factory()->create());

    $warned = $this->withToken($token)->postJson('/api/avatar-templates', [
        'name' => 'Warned',
        'provider' => 'tavus',
        'config' => ['faceId' => 'r', 'palId' => 'p_aw', 'llmTemperature' => 0.5],
    ])->assertCreated();

    $quiet = $this->withToken($token)->postJson('/api/avatar-templates', [
        'name' => 'Quiet',
        'provider' => 'tavus',
        'config' => ['faceId' => 'r', 'palId' => 'p_aw'],
    ])->assertCreated();

    expect(ResyncTemplateBinding::WARNING_CODES)->toContain($warned->json('warning'))
        ->and($quiet->json())->not->toHaveKey('warning');
});

test('hr_avatar_config_id is published as an optional string because it is omitted, never null, when unset', function (): void {
    $schema = awSpec()['components']['schemas']['AvatarTemplateResource'];

    expect($schema['properties']['hr_avatar_config_id'])->toBe(['type' => 'string'])
        ->and($schema['required'])->not->toContain('hr_avatar_config_id');

    $token = TemplateActors::token('acting', Organization::factory()->create());
    config()->set('interview.preflight.verify_references', false);
    $id = $this->withToken($token)->postJson('/api/avatar-templates', [
        'name' => 'Unbound',
        'provider' => 'tavus',
        'config' => ['faceId' => 'r', 'palId' => 'p_cfg'],
    ])->assertCreated()->json('data.id');

    $unbound = $this->withToken($token)->getJson("/api/avatar-templates/{$id}")->assertOk()->json('data');
    $bound = $this->withToken($token)->patchJson("/api/avatar-templates/{$id}", ['hr_avatar_config_id' => 'avc_abc123'])->assertOk()->json('data');
    $cleared = $this->withToken($token)->patchJson("/api/avatar-templates/{$id}", ['hr_avatar_config_id' => null])->assertOk()->json('data');

    expect($unbound)->not->toHaveKey('hr_avatar_config_id')
        ->and($bound['hr_avatar_config_id'])->toBe('avc_abc123')
        ->and($cleared)->not->toHaveKey('hr_avatar_config_id')
        ->and(AvatarTemplate::query()->findOrFail($id)->hr_avatar_config_id)->toBeNull();
});
