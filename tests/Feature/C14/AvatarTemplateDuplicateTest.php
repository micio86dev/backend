<?php

declare(strict_types=1);

/**
 * `POST /api/avatar-templates/{id}/duplicate` (avatar-template-duplicate D1).
 *
 * A superadmin copies one template into one or more OTHER organizations. Each
 * copy is a NEW, INACTIVE row stamped with its TARGET organization through
 * `TenantContextScope::runFor()` — never a shared row, never a payload id.
 */

use App\Models\AuditLog;
use App\Models\AvatarTemplate;
use App\Models\LlmCredential;
use App\Models\LlmModel;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\Http;

function dupTemplate(Organization $org, array $overrides = []): AvatarTemplate
{
    return TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Ada',
        'description' => 'The friendly one',
        'provider' => 'heygen',
        'config' => ['avatarId' => 'av_1', 'voiceId' => 'vo_1'],
        'persona' => ['tone' => 'warm'],
        ...$overrides,
    ]));
}

function dupBareSuperadminToken(): string
{
    $superadmin = User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);

    return auth('api')->login($superadmin);
}

/** @return list<AvatarTemplate> */
function dupTemplatesOf(Organization $org): array
{
    return TenantContextScope::runFor($org->id, fn (): array => AvatarTemplate::query()->orderBy('id')->get()->all());
}

beforeEach(function (): void {
    Http::preventStrayRequests();
});

test('a superadmin copies a template into several organizations, each copy stamped with its own target', function (): void {
    $source = Organization::factory()->create();
    $targetA = Organization::factory()->create();
    $targetB = Organization::factory()->create();
    $template = dupTemplate($source);

    $response = $this->withToken(dupBareSuperadminToken())
        ->postJson("/api/avatar-templates/{$template->id}/duplicate", [
            'target_organization_ids' => [$targetA->id, $targetB->id],
        ]);

    $response->assertCreated();
    $data = $response->json('data');
    expect($data)->toHaveCount(2)
        ->and(array_column($data, 'organization_id'))->toBe([$targetA->id, $targetB->id])
        ->and(array_keys($data[0]))->toBe(['organization_id', 'id', 'name']);

    foreach ([$targetA, $targetB] as $i => $target) {
        $copies = dupTemplatesOf($target);
        expect($copies)->toHaveCount(1);
        $copy = $copies[0];

        expect($copy->id)->toBe($data[$i]['id'])
            ->and($copy->organization_id)->toBe($target->id)
            ->and($copy->name)->toBe('Ada')
            ->and($copy->description)->toBe('The friendly one')
            ->and($copy->provider)->toBe('heygen')
            ->and($copy->config)->toEqualCanonicalizing(['avatarId' => 'av_1', 'voiceId' => 'vo_1'])
            ->and($copy->persona)->toBe(['tone' => 'warm']);
    }
});

test('the source template is untouched and each side is invisible to the other', function (): void {
    $source = Organization::factory()->create();
    $target = Organization::factory()->create();
    $template = dupTemplate($source, ['is_active' => true]);

    $this->withToken(dupBareSuperadminToken())
        ->postJson("/api/avatar-templates/{$template->id}/duplicate", ['target_organization_ids' => [$target->id]])
        ->assertCreated();

    $sourceSide = dupTemplatesOf($source);
    $targetSide = dupTemplatesOf($target);

    expect($sourceSide)->toHaveCount(1)
        ->and($sourceSide[0]->id)->toBe($template->id)
        ->and($sourceSide[0]->is_active)->toBeTrue()
        ->and($targetSide)->toHaveCount(1)
        ->and($targetSide[0]->id)->not->toBe($template->id);

    // An org context never resolves the other's row by id.
    TenantContextScope::runFor($source->id, function () use ($targetSide): void {
        expect(AvatarTemplate::find($targetSide[0]->id))->toBeNull();
    });
    TenantContextScope::runFor($target->id, function () use ($template): void {
        expect(AvatarTemplate::find($template->id))->toBeNull();
    });
});

test('a superadmin acting as the source organization can duplicate its template', function (): void {
    $source = Organization::factory()->create();
    $target = Organization::factory()->create();
    $template = dupTemplate($source);

    $this->withToken(authTokenForRole($source, 'platform'))
        ->postJson("/api/avatar-templates/{$template->id}/duplicate", ['target_organization_ids' => [$target->id]])
        ->assertCreated()
        ->assertJsonPath('data.0.organization_id', $target->id);

    expect(dupTemplatesOf($target))->toHaveCount(1);
});

test('the copy is inactive and carries none of the provider-side or sync state', function (): void {
    $source = Organization::factory()->create();
    $target = Organization::factory()->create();
    $template = dupTemplate($source, ['is_active' => true]);
    $template->forceFill([
        'heygen_llm_configuration_id' => 'hg-config-9',
        'llm_sync_status' => 'synced',
        'llm_synced_at' => now(),
    ])->saveQuietly();

    $this->withToken(dupBareSuperadminToken())
        ->postJson("/api/avatar-templates/{$template->id}/duplicate", ['target_organization_ids' => [$target->id]])
        ->assertCreated();

    $copy = dupTemplatesOf($target)[0];

    expect($copy->is_active)->toBeFalse()
        ->and($copy->heygen_llm_configuration_id)->toBeNull()
        ->and($copy->llm_sync_status)->toBeNull()
        ->and($copy->llm_synced_at)->toBeNull()
        ->and($copy->deleted_at)->toBeNull();
});

test('the model and credential bindings are preserved', function (): void {
    $source = Organization::factory()->create();
    $target = Organization::factory()->create();
    $model = LlmModel::create([
        'key' => 'gemini-3-flash-preview',
        'vendor' => 'google',
        'display_name' => 'Gemini 3 Flash Preview',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
        'capability' => 'text',
        'is_available' => true,
        'sort_order' => 0,
    ]);
    $credential = LlmCredential::create([
        'name' => 'Platform credential',
        'vendor' => 'google',
        'api_key' => 'sk-real-key',
        'key_last_four' => 'real',
        'key_fingerprint' => hash('sha256', 'dup'),
    ]);
    $template = dupTemplate($source, [
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credential->id,
    ]);

    $this->withToken(dupBareSuperadminToken())
        ->postJson("/api/avatar-templates/{$template->id}/duplicate", ['target_organization_ids' => [$target->id]])
        ->assertCreated();

    $copy = dupTemplatesOf($target)[0];

    expect($copy->llm_model_id)->toBe($model->id)
        ->and($copy->llm_credential_id)->toBe($credential->id);
});

test('a colliding name in the target is suffixed, and the suffix keeps counting', function (): void {
    $source = Organization::factory()->create();
    $target = Organization::factory()->create();
    $free = Organization::factory()->create();
    dupTemplate($target, ['name' => 'Ada']);
    $template = dupTemplate($source);
    $token = dupBareSuperadminToken();

    $first = $this->withToken($token)
        ->postJson("/api/avatar-templates/{$template->id}/duplicate", ['target_organization_ids' => [$target->id, $free->id]])
        ->assertCreated();
    $second = $this->withToken($token)
        ->postJson("/api/avatar-templates/{$template->id}/duplicate", ['target_organization_ids' => [$target->id]])
        ->assertCreated();

    expect($first->json('data.0.name'))->toBe('Ada (copy)')
        ->and($first->json('data.1.name'))->toBe('Ada')
        ->and($second->json('data.0.name'))->toBe('Ada (copy 2)');
});

test('an explicit name overrides the source name', function (): void {
    $source = Organization::factory()->create();
    $target = Organization::factory()->create();
    $template = dupTemplate($source);

    $this->withToken(dupBareSuperadminToken())
        ->postJson("/api/avatar-templates/{$template->id}/duplicate", [
            'target_organization_ids' => [$target->id],
            'name' => 'Client welcome',
        ])
        ->assertCreated()
        ->assertJsonPath('data.0.name', 'Client welcome');
});

test('the source organization among the targets is refused', function (): void {
    $source = Organization::factory()->create();
    $target = Organization::factory()->create();
    $template = dupTemplate($source);

    $this->withToken(dupBareSuperadminToken())
        ->postJson("/api/avatar-templates/{$template->id}/duplicate", [
            'target_organization_ids' => [$target->id, $source->id],
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.target_organization_ids.0', 'source_organization_included');

    expect(dupTemplatesOf($target))->toBeEmpty()
        ->and(dupTemplatesOf($source))->toHaveCount(1);
});

test('the target list is validated', function (array $payload, string $errorKey): void {
    $source = Organization::factory()->create();
    $template = dupTemplate($source);

    $this->withToken(dupBareSuperadminToken())
        ->postJson("/api/avatar-templates/{$template->id}/duplicate", $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrorFor($errorKey);
})->with([
    'missing' => [[], 'target_organization_ids'],
    'empty' => [['target_organization_ids' => []], 'target_organization_ids'],
    'unknown organization' => [['target_organization_ids' => [987654321]], 'target_organization_ids.0'],
    'not integers' => [['target_organization_ids' => ['abc']], 'target_organization_ids.0'],
]);

test('duplicate target ids are refused', function (): void {
    $source = Organization::factory()->create();
    $target = Organization::factory()->create();
    $template = dupTemplate($source);

    $this->withToken(dupBareSuperadminToken())
        ->postJson("/api/avatar-templates/{$template->id}/duplicate", [
            'target_organization_ids' => [$target->id, $target->id],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['target_organization_ids.0', 'target_organization_ids.1']);
});

test('a soft-deleted or unknown source is a 404', function (): void {
    $source = Organization::factory()->create();
    $target = Organization::factory()->create();
    $template = dupTemplate($source);
    $template->delete();
    $token = dupBareSuperadminToken();

    $this->withToken($token)
        ->postJson("/api/avatar-templates/{$template->id}/duplicate", ['target_organization_ids' => [$target->id]])
        ->assertNotFound();
    $this->withToken($token)
        ->postJson('/api/avatar-templates/987654321/duplicate', ['target_organization_ids' => [$target->id]])
        ->assertNotFound();
});

test('it is all-or-nothing: a failure on the second target creates no copy at all', function (): void {
    $source = Organization::factory()->create();
    $targetA = Organization::factory()->create();
    $targetB = Organization::factory()->create();
    $template = dupTemplate($source);

    $armed = true;
    $seen = 0;
    AvatarTemplate::creating(function () use (&$armed, &$seen): void {
        if ($armed && ++$seen === 2) {
            throw new RuntimeException('forced failure on the second target');
        }
    });

    try {
        $this->withToken(dupBareSuperadminToken())
            ->withoutExceptionHandling()
            ->postJson("/api/avatar-templates/{$template->id}/duplicate", [
                'target_organization_ids' => [$targetA->id, $targetB->id],
            ]);
        $this->fail('The forced failure should have propagated.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('forced failure');
    } finally {
        $armed = false;
    }

    expect(dupTemplatesOf($targetA))->toBeEmpty()
        ->and(dupTemplatesOf($targetB))->toBeEmpty();
});

test('a failing audit write never fails the duplication it records', function (): void {
    $source = Organization::factory()->create();
    $target = Organization::factory()->create();
    $template = dupTemplate($source);
    $token = dupBareSuperadminToken();

    $armed = true;
    AuditLog::creating(function () use (&$armed): void {
        if ($armed) {
            throw new RuntimeException('audit store down');
        }
    });

    try {
        $this->withToken($token)
            ->postJson("/api/avatar-templates/{$template->id}/duplicate", ['target_organization_ids' => [$target->id]])
            ->assertCreated();
    } finally {
        $armed = false;
    }

    expect(dupTemplatesOf($target))->toHaveCount(1);
});

test('each copy is audited in its target organization without any config content', function (): void {
    $source = Organization::factory()->create();
    $target = Organization::factory()->create();
    $template = dupTemplate($source);

    $this->withToken(dupBareSuperadminToken())
        ->postJson("/api/avatar-templates/{$template->id}/duplicate", ['target_organization_ids' => [$target->id]])
        ->assertCreated();

    $copy = dupTemplatesOf($target)[0];
    $log = AuditLog::withoutGlobalScopes()->where('action', 'avatar_template.duplicated')->sole();

    expect($log->organization_id)->toBe($target->id)
        ->and($log->subject_type)->toBe('avatar_template')
        ->and($log->subject_id)->toBe($copy->id)
        ->and($log->after)->toEqual([
            'name' => 'Ada',
            'provider' => 'heygen',
            'source_template_id' => $template->id,
            'source_organization_id' => $source->id,
            'source_scope' => 'organization',
        ])
        ->and(json_encode($log->after))->not->toContain('av_1');
});

test('a source whose config no longer validates is refused rather than copied', function (): void {
    $source = Organization::factory()->create();
    $target = Organization::factory()->create();
    $template = dupTemplate($source);
    $template->forceFill(['config' => ['definitelyNotAKnob' => 'x']])->saveQuietly();

    $this->withToken(dupBareSuperadminToken())
        ->postJson("/api/avatar-templates/{$template->id}/duplicate", ['target_organization_ids' => [$target->id]])
        ->assertStatus(422);

    expect(dupTemplatesOf($target))->toBeEmpty();
});
