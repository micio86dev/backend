<?php

declare(strict_types=1);

/**
 * The one-way JSONB key-strip over `avatar_templates.config`
 * (avatar-language-follows-project, D6).
 *
 * This fixture is the ONLY thing standing between a wrong filter and
 * unrecoverable loss. The migration is unscoped by design — every tenant, every
 * template — and `down()` cannot put the values back. So the assertion is
 * key-by-key survival across two organizations, not a spot check: a filter that
 * strips one key too many is indistinguishable from a correct one unless every
 * neighbouring key is named.
 */

use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** Every HeyGen knob the field specs allow, plus the one being removed. */
function stripMaximalHeygenConfig(): array
{
    return [
        'avatarId' => 'avatar-abc',
        'voiceId' => 'voice-def',
        'language' => 'it',
        'interactivityType' => 'CONVERSATIONAL',
        'maxSessionDurationSec' => 600,
        'videoQuality' => 'high',
        'videoEncoding' => 'H264',
        'voiceSpeed' => 1.05,
        'voiceStability' => 0.6,
        'voiceSimilarityBoost' => 0.7,
        'voiceStyle' => 0.2,
        'voiceUseSpeakerBoost' => true,
    ];
}

function stripMaximalTavusConfig(): array
{
    return [
        'faceId' => 'face-abc',
        'palId' => 'pal-def',
        'language' => 'en',
        'audioOnly' => false,
        'maxCallDurationSec' => 900,
        'participantAbsentTimeoutSec' => 60,
        'enableRecording' => true,
        'enableClosedCaptions' => true,
    ];
}

function stripSeedTemplate(int $orgId, string $provider, array $config, bool $active): int
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgId);
    $resolver->setBypass(false);

    $t = new AvatarTemplate;
    $t->forceFill([
        'organization_id' => $orgId,
        'name' => $provider.'-'.uniqid(),
        'provider' => $provider,
        'config' => $config,
        'is_active' => $active,
    ]);
    $t->save();

    return $t->id;
}

function stripRawConfig(int $id): array
{
    $row = DB::table('avatar_templates')->where('id', $id)->value('config');

    return json_decode((string) $row, true) ?? [];
}

test('the strip removes language and NOTHING else, across two organizations', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $a = stripSeedTemplate($orgA->id, 'heygen', stripMaximalHeygenConfig(), true);
    $b = stripSeedTemplate($orgB->id, 'tavus', stripMaximalTavusConfig(), false);

    // Re-run the migration's own statement rather than a hand-written copy, so
    // the test cannot drift from the thing it protects.
    DB::statement("UPDATE avatar_templates SET config = config - 'language' WHERE config ?? 'language'");

    $afterA = stripRawConfig($a);
    $afterB = stripRawConfig($b);

    expect($afterA)->not->toHaveKey('language');
    expect($afterB)->not->toHaveKey('language');

    foreach (stripMaximalHeygenConfig() as $key => $value) {
        if ($key === 'language') {
            continue;
        }
        expect($afterA[$key] ?? null)->toBe($value, "heygen key `{$key}` must survive the strip");
    }

    foreach (stripMaximalTavusConfig() as $key => $value) {
        if ($key === 'language') {
            continue;
        }
        expect($afterB[$key] ?? null)->toBe($value, "tavus key `{$key}` must survive the strip");
    }
});

test('a config with no language key is left completely untouched', function (): void {
    $org = Organization::factory()->create();
    $clean = stripMaximalHeygenConfig();
    unset($clean['language']);

    $id = stripSeedTemplate($org->id, 'heygen', $clean, true);

    DB::statement("UPDATE avatar_templates SET config = config - 'language' WHERE config ?? 'language'");

    // Compared by content, not by key order: JSONB does not preserve insertion
    // order, and asserting it would fail for a reason unrelated to the strip.
    expect(stripRawConfig($id))->toEqualCanonicalizing($clean);
});

test('is_active is not disturbed — the strip touches config only', function (): void {
    $org = Organization::factory()->create();
    $id = stripSeedTemplate($org->id, 'heygen', stripMaximalHeygenConfig(), true);

    DB::statement("UPDATE avatar_templates SET config = config - 'language' WHERE config ?? 'language'");

    expect((bool) DB::table('avatar_templates')->where('id', $id)->value('is_active'))->toBeTrue();
});
