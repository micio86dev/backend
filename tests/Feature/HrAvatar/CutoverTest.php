<?php

declare(strict_types=1);

/**
 * `hr-avatar:cutover` (hr-avatar-cutover, PR1): dry run by default; `--apply` creates one INACTIVE `hr_avatar`
 * twin per eligible exported source and repins nothing.
 */

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\AvatarTemplates\PlatformTemplates;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
});

/**
 * @param  array<string, mixed>  $options
 * @return array{0: int, 1: string} exit code and output
 */
function hrcRun(array $options = []): array
{
    $exit = Artisan::call('hr-avatar:cutover', $options);

    return [$exit, Artisan::output()];
}

/** @return list<array<string, mixed>> */
function hrcRows(string $table = 'avatar_templates'): array
{
    return DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
}

/** @param array<string, mixed> $attributes */
function hrcSource(Organization $org, string $provider = 'tavus', array $attributes = []): int
{
    return hraSeedTemplate($org, array_merge([
        'provider' => $provider,
        'config' => $provider === 'tavus' ? ['faceId' => 'r1', 'palId' => 'p1'] : ['avatarId' => 'a1', 'voiceId' => 'v1'],
        'hr_avatar_config_id' => 'avc_SRC'.uniqid(),
    ], $attributes))->id;
}

// ─── Options ─────────────────────────────────────────────────────────────────

test('an unknown or non-numeric organization id is refused with exit 2 and nothing is written', function (): void {
    $org = Organization::factory()->create();
    hrcSource($org);
    $before = hrcRows();

    foreach (['abc', '0', (string) ($org->id + 999)] as $value) {
        [$exit, $output] = hrcRun(['--organization' => [$value], '--apply' => true]);

        expect($exit)->toBe(2)->and($output)->toContain($value);
    }

    expect(hrcRows())->toBe($before)->and(hrcRows('audit_logs'))->toBe([]);
    Http::assertNothingSent();
});

// ─── Dry run ─────────────────────────────────────────────────────────────────

test('a dry run plans a twin per linked tavus or heygen source, skips unlinked ones, and writes nothing', function (): void {
    $a = Organization::factory()->create();
    $b = Organization::factory()->create();
    $ada = hrcSource($a, 'tavus', ['name' => 'Ada']);
    $bruno = hrcSource($a, 'heygen', ['name' => 'Bruno']);
    $chiara = hrcSource($b, 'tavus', ['name' => 'Chiara']);
    $unlinked = hrcSource($a, 'tavus', ['name' => 'Unlinked', 'hr_avatar_config_id' => null]);
    hrcSource($b, 'tavus', ['name' => 'Gone']);
    DB::table('avatar_templates')->where('name', 'Gone')->update(['deleted_at' => now()]);
    hraSeedTemplate($a, ['name' => 'Native']);
    PlatformTemplates::insertGlobal(['name' => 'Platform one']);
    $before = hrcRows();

    [$exit, $output] = hrcRun();

    expect($exit)->toBe(0)
        ->and($output)->toContain('mode: dry-run')
        ->and($output)->toContain("org={$a->id}")
        ->and($output)->toContain("template={$ada} provider=tavus name=\"Ada\" would_twin")
        ->and($output)->toContain("template={$bruno} provider=heygen name=\"Bruno\" would_twin")
        ->and($output)->toContain("template={$chiara} provider=tavus name=\"Chiara\" would_twin")
        ->and($output)->toContain("template={$unlinked} provider=tavus name=\"Unlinked\" skipped not_exported")
        ->and($output)->toContain('totals: twinned=0 reused=0 would_twin=3 skipped=1 failed=0')
        ->and($output)->not->toContain('Gone')->not->toContain('Platform one')->not->toContain('Native');
    expect(hrcRows())->toBe($before)->and(hrcRows('audit_logs'))->toBe([]);
    Http::assertNothingSent();
});

// ─── Apply: availability ─────────────────────────────────────────────────────

test('--apply aborts before any write while unavailable, naming only the missing variables', function (): void {
    config(['hr_avatar.base_url' => 'https://hra.example.test']);
    hrcSource(Organization::factory()->create());
    $before = hrcRows();

    [$exit, $output] = hrcRun(['--apply' => true]);

    expect($exit)->toBe(1)
        ->and($output)->toContain('HR_AVATAR_SECRET_KEY')->toContain('HR_AVATAR_PUBLISHABLE_KEY')
        ->and($output)->not->toContain('hra.example.test')->not->toContain('HR_AVATAR_BASE_URL')
        ->and($output)->not->toContain('would_twin');
    expect(hrcRows())->toBe($before)->and(hrcRows('audit_logs'))->toBe([]);
    Http::assertNothingSent();
});

// ─── Apply: twin creation ────────────────────────────────────────────────────

/** @return list<array<string, mixed>> */
function hrcTwins(): array
{
    return array_values(array_filter(hrcRows(), fn (array $row): bool => $row['provider'] === 'hr_avatar'));
}

test('--apply creates one inactive twin per exported source with the exact attributes and one audit row', function (): void {
    hraMakeAvailable();
    $a = Organization::factory()->create();
    $ada = hrcSource($a, 'tavus', [
        'name' => 'Ada',
        'description' => 'Warm',
        'persona' => ['display_name' => 'Ada Persona'],
        'answer_min_seconds' => 40,
        'answer_max_seconds' => 80,
        'hr_avatar_config_id' => 'avc_ADA',
        'is_active' => true,
    ]);
    $unlinked = hrcSource($a, 'heygen', ['name' => 'Unlinked', 'hr_avatar_config_id' => null]);
    $sourcesBefore = array_values(array_filter(hrcRows(), fn (array $row): bool => $row['provider'] !== 'hr_avatar'));

    [$exit, $output] = hrcRun(['--apply' => true]);

    $twins = hrcTwins();
    expect($exit)->toBe(0)->and($twins)->toHaveCount(1);
    $twin = $twins[0];
    expect($twin['organization_id'])->toBe($a->id)
        ->and($twin['name'])->toBe('Ada (HR-Avatar)')
        ->and($twin['description'])->toBe('Warm')
        ->and(json_decode((string) $twin['persona'], true))->toBe(['display_name' => 'Ada Persona'])
        ->and(json_decode((string) $twin['config'], true))->toBe([])
        ->and($twin['hr_avatar_config_id'])->toBe('avc_ADA')
        ->and($twin['llm_model_id'])->toBeNull()
        ->and($twin['llm_credential_id'])->toBeNull()
        ->and($twin['answer_min_seconds'])->toBe(40)
        ->and($twin['answer_max_seconds'])->toBe(80)
        ->and((bool) $twin['is_active'])->toBeFalse()
        ->and($output)->toContain('mode: apply')
        ->and($output)->toContain("template={$ada} provider=tavus name=\"Ada\" twinned twin={$twin['id']}")
        ->and($output)->toContain("template={$unlinked} provider=heygen name=\"Unlinked\" skipped not_exported")
        ->and($output)->toContain('totals: twinned=1 reused=0 would_twin=0 skipped=1 failed=0');

    expect(array_values(array_filter(hrcRows(), fn (array $row): bool => $row['provider'] !== 'hr_avatar')))->toBe($sourcesBefore);

    $audit = hrcRows('audit_logs');
    expect($audit)->toHaveCount(1)
        ->and($audit[0]['action'])->toBe('avatar_template.hr_avatar_twin_created')
        ->and($audit[0]['subject_type'])->toBe('avatar_template')
        ->and($audit[0]['subject_id'])->toBe($twin['id'])
        ->and($audit[0]['organization_id'])->toBe($a->id)
        ->and(json_decode((string) $audit[0]['after'], true))->toBe([
            'name' => 'Ada (HR-Avatar)',
            'provider' => 'hr_avatar',
            'source_template_id' => $ada,
            'hr_avatar_config_id' => 'avc_ADA',
        ]);
    Http::assertNothingSent();
});

// ─── Idempotency ─────────────────────────────────────────────────────────────

test('a second apply reuses the twin by natural key and writes nothing, and a dry run reports it as reused', function (): void {
    hraMakeAvailable();
    $a = Organization::factory()->create();
    $ada = hrcSource($a, 'tavus', ['name' => 'Ada', 'hr_avatar_config_id' => 'avc_ADA']);
    hrcRun(['--apply' => true]);
    $twinId = hrcTwins()[0]['id'];
    $rows = hrcRows();
    $audit = hrcRows('audit_logs');

    [$exit, $output] = hrcRun(['--apply' => true]);

    expect($exit)->toBe(0)
        ->and($output)->toContain("template={$ada} provider=tavus name=\"Ada\" reused twin={$twinId}")
        ->and($output)->toContain('totals: twinned=0 reused=1 would_twin=0 skipped=0 failed=0');
    expect(hrcRows())->toBe($rows)->and(hrcRows('audit_logs'))->toBe($audit);

    [, $dryOutput] = hrcRun();

    expect($dryOutput)->toContain("reused twin={$twinId}")->toContain('would_twin=0');
    expect(hrcRows())->toBe($rows);
});
