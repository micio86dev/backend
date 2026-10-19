<?php

declare(strict_types=1);

/**
 * Export, import and duplicate for `hr_avatar` templates (hr-avatar-adapter A3b).
 *
 * The config id travels with the template: omitted from a document while null (so every existing
 * export is unchanged), validated on import whenever present, required for `hr_avatar`, and copied by
 * a duplicate. Nothing here sends a request, and another tenant's id never shows up.
 */

use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Support\AvatarTemplates\TemplateDocument;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Helpers\AvatarTemplates\TemplateActors;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::fake();
    Http::preventStrayRequests();
});

/** @param array<string, mixed> $overrides */
function hrpEntry(array $overrides = []): array
{
    return array_merge([
        'name' => 'Imported interviewer',
        'description' => null,
        'provider' => 'hr_avatar',
        'config' => [],
        'hr_avatar_config_id' => 'avc_01HXYZ',
        'answer_min_seconds' => 90,
        'answer_max_seconds' => 150,
    ], $overrides);
}

/** @param list<array<string, mixed>> $entries */
function hrpImport(Organization $org, array $entries): TestResponse
{
    return test()->withToken(TemplateActors::token('acting', $org))
        ->postJson('/api/avatar-templates/import', ['schema' => TemplateDocument::SCHEMA, 'templates' => $entries]);
}

function hrpBareSuperadmin(): string
{
    return auth('api')->login(User::factory()->create(['organization_id' => null, 'is_superadmin' => true]));
}

// ─── Export ──────────────────────────────────────────────────────────────────

test('a null id is omitted from the export document, which keeps exactly its pre-change members', function (string $provider): void {
    $org = Organization::factory()->create();
    hraSeedTemplate($org, ['provider' => $provider, 'config' => ['avatarId' => 'a', 'voiceId' => 'v'], 'hr_avatar_config_id' => null]);

    $entry = test()->withToken(TemplateActors::token('acting', $org))->getJson('/api/avatar-templates/export')
        ->assertOk()->json('templates.0');

    expect(array_keys($entry))->toBe(['name', 'description', 'provider', 'config', 'persona', 'answer_min_seconds', 'answer_max_seconds', 'llm']);
})->with(['heygen', 'tavus']);

test('an hr_avatar template exports its id and round-trips through import as an inactive copy', function (): void {
    hraMakeAvailable();
    $org = Organization::factory()->create();
    hraSeedTemplate($org, ['name' => 'Original', 'hr_avatar_config_id' => 'avc_ROUND1']);
    $token = TemplateActors::token('acting', $org);

    $document = test()->withToken($token)->getJson('/api/avatar-templates/export')->assertOk()->json();
    expect($document['templates'][0]['hr_avatar_config_id'])->toBe('avc_ROUND1');

    test()->withToken($token)->postJson('/api/avatar-templates/import', $document)->assertCreated();

    $copy = TenantContextScope::runFor($org->id, fn () => AvatarTemplate::query()->where('name', 'Original (2)')->firstOrFail());
    expect($copy->provider)->toBe('hr_avatar')
        ->and($copy->hr_avatar_config_id)->toBe('avc_ROUND1')
        ->and($copy->is_active)->toBeFalse();
    Http::assertNothingSent();
});

// ─── Import ──────────────────────────────────────────────────────────────────

test('an import carrying an hr_avatar block fails while unavailable and creates nothing', function (): void {
    $org = Organization::factory()->create();

    hrpImport($org, [hrpEntry()])->assertStatus(422)->assertJsonValidationErrors(['templates.0']);

    expect(DB::table('avatar_templates')->count())->toBe(0);
});

test('an hr_avatar import needs a well-formed id, no knob and no LLM binding, each refused for its own reason', function (array $entry, string $reason): void {
    hraMakeAvailable();
    $org = Organization::factory()->create();

    $errors = hrpImport($org, [hrpEntry($entry)])->assertStatus(422)->json('errors');

    expect($errors['templates.0'][0])->toContain($reason);
    expect(DB::table('avatar_templates')->count())->toBe(0);
})->with([
    'id missing' => [['hr_avatar_config_id' => null], 'hr_avatar_config_id'],
    'id malformed' => [['hr_avatar_config_id' => 'cfg_1'], 'hr_avatar_config_id'],
    'id too long' => [['hr_avatar_config_id' => 'avc_'.str_repeat('a', 61)], 'hr_avatar_config_id'],
    'id not a string' => [['hr_avatar_config_id' => 7], 'hr_avatar_config_id'],
    'knob in config' => [['config' => ['avatarId' => 'x']], 'avatarId'],
    'llm block' => [['llm' => ['model_key' => 'gemini-3-flash-preview', 'credential_name' => 'Anything']], 'llm'],
]);

test('a heygen import validates the id whenever it is present and stores a valid one', function (): void {
    hraMakeAvailable();
    $org = Organization::factory()->create();
    $heygen = ['provider' => 'heygen', 'config' => ['avatarId' => 'a', 'voiceId' => 'v']];

    hrpImport($org, [hrpEntry([...$heygen, 'hr_avatar_config_id' => 'nope'])])->assertStatus(422);
    expect(DB::table('avatar_templates')->count())->toBe(0);

    hrpImport($org, [hrpEntry([...$heygen, 'hr_avatar_config_id' => 'avc_LINKED'])])->assertCreated();
    expect(DB::table('avatar_templates')->value('hr_avatar_config_id'))->toBe('avc_LINKED');

    hrpImport($org, [hrpEntry([...$heygen, 'name' => 'No id', 'hr_avatar_config_id' => null])])->assertCreated();
    expect(DB::table('avatar_templates')->where('name', 'No id')->value('hr_avatar_config_id'))->toBeNull();
});

test('an unknown provider is still refused by name', function (): void {
    hraMakeAvailable();

    $errors = hrpImport(Organization::factory()->create(), [hrpEntry(['provider' => 'nonsense'])])->assertStatus(422)->json('errors');

    expect($errors['templates.0'])->toBe(["Unknown provider 'nonsense'."]);
});

// ─── Duplicate ───────────────────────────────────────────────────────────────

test('a superadmin duplicate copies the id and leaves the original unchanged', function (): void {
    hraMakeAvailable();
    $source = Organization::factory()->create();
    $target = Organization::factory()->create();
    $original = hraSeedTemplate($source, ['name' => 'Ada', 'hr_avatar_config_id' => 'avc_DUP1']);

    test()->withToken(hrpBareSuperadmin())
        ->postJson("/api/avatar-templates/{$original->id}/duplicate", ['target_organization_ids' => [$target->id]])
        ->assertCreated();

    $copy = TenantContextScope::runFor($target->id, fn () => AvatarTemplate::query()->firstOrFail());
    expect($copy->hr_avatar_config_id)->toBe('avc_DUP1')->and($copy->provider)->toBe('hr_avatar')->and($copy->is_active)->toBeFalse();
    expect($original->fresh()->hr_avatar_config_id)->toBe('avc_DUP1');
    Http::assertNothingSent();
});

test('an existing hr_avatar template stays manageable while unavailable: rename, duplicate, delete the copy', function (): void {
    $source = Organization::factory()->create();
    $target = Organization::factory()->create();
    $original = hraSeedTemplate($source);

    test()->withToken(TemplateActors::token('acting', $source))
        ->patchJson("/api/avatar-templates/{$original->id}", ['name' => 'Renamed'])->assertOk();

    test()->withToken(hrpBareSuperadmin())
        ->postJson("/api/avatar-templates/{$original->id}/duplicate", ['target_organization_ids' => [$target->id]])
        ->assertCreated();

    $copyId = TenantContextScope::runFor($target->id, fn () => AvatarTemplate::query()->firstOrFail()->id);
    test()->withToken(TemplateActors::token('acting', $target))->deleteJson("/api/avatar-templates/{$copyId}")->assertNoContent();

    expect(DB::table('avatar_templates')->where('id', $copyId)->whereNull('deleted_at')->exists())->toBeFalse();
    Http::assertNothingSent();
});

// ─── Tenancy ─────────────────────────────────────────────────────────────────

test('another organization\'s id never appears in an export or in the copy it receives', function (): void {
    hraMakeAvailable();
    $a = Organization::factory()->create();
    $b = Organization::factory()->create();
    hraSeedTemplate($a, ['name' => 'Mine', 'hr_avatar_config_id' => 'avc_A']);
    hraSeedTemplate($b, ['name' => 'Theirs', 'hr_avatar_config_id' => 'avc_B']);

    $export = test()->withToken(TemplateActors::token('acting', $a))->getJson('/api/avatar-templates/export')->assertOk();
    expect($export->getContent())->toContain('avc_A')->not->toContain('avc_B');
    expect($export->json('templates'))->toHaveCount(1);

    $mine = TenantContextScope::runFor($a->id, fn () => AvatarTemplate::query()->firstOrFail());
    $c = Organization::factory()->create();
    test()->withToken(hrpBareSuperadmin())
        ->postJson("/api/avatar-templates/{$mine->id}/duplicate", ['target_organization_ids' => [$c->id]])
        ->assertCreated();

    $copies = TenantContextScope::runFor($c->id, fn () => AvatarTemplate::query()->pluck('hr_avatar_config_id')->all());
    expect($copies)->toBe(['avc_A']);
});

// ─── Import is all-or-nothing ────────────────────────────────────────────────

test('an import whose second save fails creates no template at all', function (): void {
    hraMakeAvailable();
    $org = Organization::factory()->create();
    $saves = 0;
    AvatarTemplate::creating(function () use (&$saves): void {
        if (++$saves === 2) {
            throw new RuntimeException('second save failed');
        }
    });

    hrpImport($org, [hrpEntry(['name' => 'First']), hrpEntry(['name' => 'Second'])])->assertStatus(500);

    expect(DB::table('avatar_templates')->count())->toBe(0);
});

test('every refused record of one entry is reported, not only the last', function (): void {
    $org = Organization::factory()->create();

    $errors = hrpImport($org, [['name' => 'Tester', 'configs' => ['heygen' => ['bogusKey' => 1], 'tavus' => ['bogusKey' => 1]]]])
        ->assertStatus(422)->json('errors');

    expect($errors['templates.0'])->toHaveCount(2);
});
