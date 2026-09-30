<?php

declare(strict_types=1);

/**
 * Every platform template mutation is audited through `PlatformAuditWriter`,
 * atomically with the write (A3, design D8).
 *
 * An unaudited platform mutation must not exist, so the audit insert runs in
 * the SAME transaction and is not swallowed: when it fails, the mutation rolls
 * back and the request fails loudly (unlike the tenant `AuditRecorder`).
 * Config VALUES are never recorded: provider ids are closer to credentials
 * than to settings, and `changed_fields` names the keys that moved.
 */

use App\Models\AuditLog;
use App\Models\AvatarTemplate;
use App\Models\LlmCredential;
use App\Models\LlmModel;
use App\Models\Organization;
use App\Support\Superadmin\PlatformAuditWriter;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\AvatarTemplates\PlatformTemplates;
use Tests\Helpers\AvatarTemplates\TemplateActors;

uses(RefreshDatabase::class);

/** @return list<object> */
function pauRows(string $action): array
{
    return DB::table('audit_logs')->where('action', $action)->orderBy('id')->get()->all();
}

/** @return array<string, mixed> */
function pauJson(?string $value): array
{
    return $value === null ? [] : json_decode($value, true, flags: JSON_THROW_ON_ERROR);
}

function pauSuperadminToken(string $actor = 'bare'): string
{
    return TemplateActors::token($actor, Organization::factory()->create());
}

test('create writes exactly one platform audit row and no config value', function (): void {
    $token = pauSuperadminToken();

    $response = $this->withToken($token)->postJson('/api/admin/avatar-templates', [
        'name' => 'Audited', 'provider' => 'heygen', 'config' => ['avatarId' => 'av_secret_1', 'voiceId' => 'vo_secret_1'],
    ])->assertCreated();

    $rows = pauRows('avatar_template.created');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->organization_id)->toBeNull()
        ->and($rows[0]->subject_type)->toBe('avatar_template')
        ->and($rows[0]->subject_id)->toBe($response->json('data.id'))
        ->and($rows[0]->actor_id)->not->toBeNull()
        ->and(pauJson($rows[0]->after))->toEqual(['name' => 'Audited', 'provider' => 'heygen', 'scope' => 'platform'])
        ->and($rows[0]->after)->not->toContain('av_secret_1')
        ->and($rows[0]->after)->not->toContain('vo_secret_1');
});

test('update records the changed field names and the usage at edit time, never a config value', function (): void {
    $global = PlatformTemplates::insertGlobal(['name' => 'Before']);

    $this->withToken(pauSuperadminToken('acting'))
        ->patchJson("/api/admin/avatar-templates/{$global->id}", ['name' => 'After', 'config' => ['avatarId' => 'av_secret_2', 'voiceId' => 'vo_secret_2']])
        ->assertOk();

    $rows = pauRows('avatar_template.updated');
    $after = pauJson($rows[0]->after);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->organization_id)->toBeNull()
        ->and(pauJson($rows[0]->before))->toBe(['name' => 'Before'])
        ->and($after['name'])->toBe('After')
        ->and($after['scope'])->toBe('platform')
        ->and($after['changed_fields'])->toEqualCanonicalizing(['name', 'config'])
        ->and($after['usage'])->toEqual(['organization_count' => 0, 'project_count' => 0])
        ->and($rows[0]->after)->not->toContain('av_secret_2')
        ->and($rows[0]->after)->not->toContain('vo_secret_2');
});

test('a PATCH that changes nothing writes no audit row', function (): void {
    $global = PlatformTemplates::insertGlobal(['name' => 'Stable']);

    $this->withToken(pauSuperadminToken())
        ->patchJson("/api/admin/avatar-templates/{$global->id}", ['name' => 'Stable', 'config' => ['avatarId' => 'av_platform', 'voiceId' => 'vo_platform']])
        ->assertOk();

    expect(pauRows('avatar_template.updated'))->toBe([]);
});

test('the platform writer redacts a credential-named key at any depth', function (): void {
    app(PlatformAuditWriter::class)->record(null, 'avatar_template.updated', 'avatar_template', 1, null, ['nested' => ['api_key' => 'sk-live-123'], 'name' => 'kept']);

    $after = pauJson(pauRows('avatar_template.updated')[0]->after);

    expect($after['nested']['api_key'])->toBe('[redacted]')->and($after['name'])->toBe('kept');
});

test('a failing audit write rolls the mutation back and fails the request loudly', function (): void {
    $global = PlatformTemplates::insertGlobal(['name' => 'Before']);

    DB::unprepared('CREATE FUNCTION pau_refuse_audit() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION \'audit refused\'; END $$ LANGUAGE plpgsql');
    DB::unprepared('CREATE TRIGGER pau_refuse_audit BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION pau_refuse_audit()');

    $token = pauSuperadminToken();

    $this->withToken($token)->patchJson("/api/admin/avatar-templates/{$global->id}", ['name' => 'After'])->assertStatus(500);
    $this->withToken($token)->postJson('/api/admin/avatar-templates', ['name' => 'Never', 'provider' => 'heygen', 'config' => ['avatarId' => 'a', 'voiceId' => 'v']])->assertStatus(500);

    expect(AvatarTemplate::platformOnly()->pluck('name')->all())->toBe(['Before']);
});

test('the audit trail of an organization never returns a platform row', function (): void {
    $org = Organization::factory()->create();
    $this->withToken(pauSuperadminToken())->postJson('/api/admin/avatar-templates', [
        'name' => 'Platform only', 'provider' => 'heygen', 'config' => ['avatarId' => 'a', 'voiceId' => 'v'],
    ])->assertCreated();

    expect(DB::table('audit_logs')->where('action', 'avatar_template.created')->count())->toBe(1)
        ->and(TenantContextScope::runFor($org->id, fn () => AuditLog::query()->count()))->toBe(0);
});

test('binding and unbinding a platform template are audited by names, never ids or keys', function (): void {
    Http::fake(['*' => Http::response([], 200)]);
    $model = LlmModel::create([
        'key' => 'gemini-3-flash-preview', 'vendor' => 'google', 'display_name' => 'Gemini 3 Flash Preview',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/', 'capability' => 'text',
        'is_available' => true, 'sort_order' => 0,
    ]);
    $credential = (new LlmCredential)->forceFill([
        'name' => 'Platform credential', 'vendor' => 'google', 'api_key' => 'sk-real-key',
        'key_last_four' => 'real', 'key_fingerprint' => hash('sha256', 'platform-credential'),
    ]);
    $credential->save();
    $global = PlatformTemplates::insertGlobal();
    $url = "/api/admin/avatar-templates/{$global->id}";
    $token = pauSuperadminToken();

    $this->withToken($token)->patchJson($url, ['llm_model_id' => $model->id, 'llm_credential_id' => $credential->id])->assertOk();
    $this->withToken($token)->patchJson($url, ['llm_model_id' => null, 'llm_credential_id' => null])->assertOk();

    $bound = pauRows('avatar_template.llm_bound')[0];
    $unbound = pauRows('avatar_template.llm_unbound')[0];

    expect(pauJson($bound->after))->toBe(['model_key' => 'gemini-3-flash-preview', 'credential_name' => 'Platform credential'])
        ->and(pauJson($unbound->before))->toBe(['model_key' => 'gemini-3-flash-preview', 'credential_name' => 'Platform credential'])
        ->and($unbound->after)->toBeNull()
        ->and($bound->organization_id)->toBeNull()
        ->and($bound->after.$unbound->before)->not->toContain('sk-real-key');
});
