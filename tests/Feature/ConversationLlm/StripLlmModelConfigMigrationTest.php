<?php

declare(strict_types=1);

/**
 * The one-way JSONB `llmModel` key-strip over `avatar_templates.config`
 * (pluggable-conversation-llm PR P3a, design D3).
 *
 * Mirrors `StripTemplateLanguageMigrationTest.php` exactly — same shape, same
 * reasoning: the migration is unscoped, `down()` cannot restore the values,
 * and the fixture is the only thing standing between a wrong filter and
 * unrecoverable loss.
 *
 * REQ: avatar-templates "A template may bind one conversation model and one
 *      credential, both or neither" — "llmModel is no longer an accepted
 *      Tavus config key"
 */

use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function runLlmModelStripMigration(): void
{
    $migration = require dirname(__DIR__, 3)
        .'/database/migrations/2026_08_26_000004_add_llm_binding_to_avatar_templates.php';

    $migration->up();
}

function llmModelStripMaximalTavusConfig(): array
{
    return [
        'faceId' => 'face-abc',
        'palId' => 'pal-def',
        'audioOnly' => false,
        'maxCallDurationSec' => 900,
        'llmModel' => 'tavus-gemini-2.5-flash',
        'llmTemperature' => 0.5,
        'llmSpeculativeInference' => true,
    ];
}

function llmModelStripSeedTemplate(int $orgId, array $config): int
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgId);
    $resolver->setBypass(false);

    $t = new AvatarTemplate;
    $t->forceFill([
        'organization_id' => $orgId,
        'name' => 'tavus-'.uniqid(),
        'provider' => 'tavus',
        'config' => $config,
        'is_active' => false,
    ]);
    $t->save();

    return $t->id;
}

function llmModelStripRawConfig(int $id): array
{
    $row = DB::table('avatar_templates')->where('id', $id)->value('config');

    return json_decode((string) $row, true) ?? [];
}

test('the strip removes llmModel and nothing else', function (): void {
    $org = Organization::factory()->create();
    $id = llmModelStripSeedTemplate($org->id, llmModelStripMaximalTavusConfig());

    runLlmModelStripMigration();

    $after = llmModelStripRawConfig($id);

    expect($after)->not->toHaveKey('llmModel');

    foreach (llmModelStripMaximalTavusConfig() as $key => $value) {
        if ($key === 'llmModel') {
            continue;
        }
        expect($after[$key] ?? null)->toBe($value, "key `{$key}` must survive the strip");
    }
});

test('a config with no llmModel key is left completely untouched', function (): void {
    $org = Organization::factory()->create();
    $clean = llmModelStripMaximalTavusConfig();
    unset($clean['llmModel']);

    $id = llmModelStripSeedTemplate($org->id, $clean);

    runLlmModelStripMigration();

    expect(llmModelStripRawConfig($id))->toEqualCanonicalizing($clean);
});
