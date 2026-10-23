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
