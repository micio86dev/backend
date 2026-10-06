<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evaluation-retry authorization timestamp on `evaluations`
 * (scoring-retry-rt-b, design D4): `retry_authorized_at` records WHEN an
 * operator or an M2M client authorized the single re-interview of a `pending`
 * evaluation. It is written once, by App\Actions\Participant\AuthorizeEvaluationRetry,
 * in the same transaction that sets `retry_attempt`.
 *
 * Nullable with no default and no backfill: every existing evaluation, and every
 * evaluation that is never retried, keeps NULL. Adding a nullable column with no
 * default is metadata-only in Postgres (no table rewrite). No index: it is read
 * only through the evaluation row of one participant.
 *
 * No other schema change is needed. `retry_attempt` already exists, and the
 * `webhook_deliveries` unique index `(organization_id, project_id, event_type,
 * dedupe_key)` is untouched because the `:retry` dedupe keys are distinct values.
 *
 * Both directions are guarded so a rerun after a partial failure is safe. The
 * previous release tolerates the extra nullable column, so the deploy window is
 * safe in either order.
 */
return new class extends Migration
{
    private const COLUMN = 'retry_authorized_at';

    public function up(): void
    {
        if (Schema::hasColumn('evaluations', self::COLUMN)) {
            return;
        }

        Schema::table('evaluations', function (Blueprint $table): void {
            $table->timestamp(self::COLUMN)->nullable()->after('retry_attempt');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('evaluations', self::COLUMN)) {
            return;
        }

        Schema::table('evaluations', function (Blueprint $table): void {
            $table->dropColumn(self::COLUMN);
        });
    }
};
