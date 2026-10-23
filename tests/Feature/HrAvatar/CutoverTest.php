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
