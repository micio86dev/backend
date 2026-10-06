<?php

declare(strict_types=1);

/**
 * RED — scoring-retry-rt-b PR1a: `evaluations.retry_authorized_at`.
 *
 * The column records WHEN an evaluation retry was authorized (set by the
 * authorization action in PR1b). It is nullable with no default and no
 * backfill: every existing evaluation, and every evaluation that is never
 * retried, keeps NULL. `retry_attempt` already exists and the
 * `webhook_deliveries` unique index needs no change.
 *
 * Runs inside RefreshDatabase's wrapping transaction (Postgres DDL is
 * transactional), so rolling the migration back and re-applying it leaves the
 * suite's schema untouched.
 */

use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function retryAuthorizedAtMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_10_05_100000_add_retry_authorized_at_to_evaluations_table.php');

    return $migration;
}

function retryAuthorizedAtEvaluation(): Evaluation
{
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);
    $participant = Participant::factory()->forProject($project)->withStatus('completato')->create();

    return Evaluation::factory()->pending()->create(['participant_id' => $participant->id]);
}

test('evaluations.retry_authorized_at is a nullable timestamp with no default', function (): void {
    expect(Schema::hasColumn('evaluations', 'retry_authorized_at'))->toBeTrue();

    $column = DB::selectOne(
        "SELECT is_nullable, column_default, data_type FROM information_schema.columns
         WHERE table_name = 'evaluations' AND column_name = 'retry_authorized_at'"
    );

    expect($column->is_nullable)->toBe('YES');
    expect($column->column_default)->toBeNull();
    expect($column->data_type)->toBe('timestamp without time zone');
});

test('Evaluation casts retry_authorized_at to a date and accepts it as fillable', function (): void {
    $evaluation = retryAuthorizedAtEvaluation();
    expect($evaluation->fresh()->retry_authorized_at)->toBeNull();

    $stamp = Carbon::parse('2026-10-05 09:30:00');
    $evaluation->update(['retry_authorized_at' => $stamp]);

    $reloaded = $evaluation->fresh()->retry_authorized_at;
    expect($reloaded)->toBeInstanceOf(DateTimeInterface::class);
    expect($reloaded->format('Y-m-d H:i:s'))->toBe('2026-10-05 09:30:00');
});

test('down() removes the column and up() restores it', function (): void {
    $migration = retryAuthorizedAtMigration();

    $migration->down();
    expect(Schema::hasColumn('evaluations', 'retry_authorized_at'))->toBeFalse();

    $migration->up();
    expect(Schema::hasColumn('evaluations', 'retry_authorized_at'))->toBeTrue();
});

test('up() and down() are idempotent on rerun', function (): void {
    $migration = retryAuthorizedAtMigration();

    $migration->up();
    expect(Schema::hasColumn('evaluations', 'retry_authorized_at'))->toBeTrue();

    $migration->down();
    $migration->down();
    expect(Schema::hasColumn('evaluations', 'retry_authorized_at'))->toBeFalse();

    $migration->up();
});
