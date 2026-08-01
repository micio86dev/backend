<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ai_requests spec conformance (C13, observability delta).
 *
 * `openspec/specs/observability/spec.md` promises that "total token usage and
 * estimated cost per provider and model are computable from those records
 * alone". Without `provider` and `estimated_cost_usd` it is not — which is why
 * C11 shipped token counts instead of money and said so outright.
 *
 * `success` / `failure_reason` exist because a provider call that returns
 * unparseable JSON has still been MADE and BILLED. Recording only the usable
 * calls under-reports spend, and under-reports it precisely when something has
 * gone wrong.
 *
 * Additive and backfilled: existing rows are, by construction, successful calls
 * (the only INSERT ever ran on the success path), so `success = true` is the
 * honest value for them. `provider` is backfilled from the configured default
 * rather than left null — an unattributable cost record is not a cost record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_requests', function (Blueprint $table): void {
            $table->string('provider', 32)->nullable()->after('evaluation_id');

            // numeric, not float: money. 6 decimal places because per-call costs
            // on cheap models run to fractions of a cent, and rounding them to
            // 2dp would floor a whole campaign's spend to zero.
            $table->decimal('estimated_cost_usd', 12, 6)->nullable()->after('output_tokens');

            $table->boolean('success')->nullable()->after('estimated_cost_usd');
            $table->string('failure_reason', 64)->nullable()->after('success');
        });

        // Backfill before tightening: every existing row came from the success
        // path, so this is a statement of fact, not a guess.
        DB::table('ai_requests')->whereNull('success')->update(['success' => true]);
        DB::table('ai_requests')->whereNull('provider')->update([
            'provider' => (string) config('scoring.provider', 'anthropic'),
        ]);
        DB::table('ai_requests')->whereNull('estimated_cost_usd')->update(['estimated_cost_usd' => 0]);

        Schema::table('ai_requests', function (Blueprint $table): void {
            $table->string('provider', 32)->nullable(false)->change();
            $table->boolean('success')->nullable(false)->change();
            $table->decimal('estimated_cost_usd', 12, 6)->nullable(false)->change();
        });

        // failure_reason is present exactly when the call failed. An equivalence,
        // not an implication: a successful row carrying a failure reason is as
        // illegal as a failed row without one, and both would corrupt any
        // aggregation by failure class.
        DB::statement(
            'ALTER TABLE ai_requests ADD CONSTRAINT ai_requests_failure_reason_check
             CHECK ((success = false) = (failure_reason IS NOT NULL))'
        );

        // Cost is never negative. Cheap to state, and it catches a sign error in
        // the rate table before it reaches a dashboard.
        DB::statement(
            'ALTER TABLE ai_requests ADD CONSTRAINT ai_requests_cost_non_negative_check
             CHECK (estimated_cost_usd >= 0)'
        );

        // The cost dashboard groups by (org, provider, model) over a window.
        DB::statement(
            'CREATE INDEX ai_requests_org_provider_model_created_index
             ON ai_requests (organization_id, provider, model, created_at)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ai_requests DROP CONSTRAINT IF EXISTS ai_requests_failure_reason_check');
        DB::statement('ALTER TABLE ai_requests DROP CONSTRAINT IF EXISTS ai_requests_cost_non_negative_check');
        DB::statement('DROP INDEX IF EXISTS ai_requests_org_provider_model_created_index');

        Schema::table('ai_requests', function (Blueprint $table): void {
            $table->dropColumn(['provider', 'estimated_cost_usd', 'success', 'failure_reason']);
        });
    }
};
