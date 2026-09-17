<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `audit_logs.organization_id` becomes NULLABLE: NULL means PLATFORM scope
 * (framework-catalogue-authoring PR8, design D13).
 *
 * A superadmin editing the shared catalogue acts with no acting tenant —
 * `TenantScoped`'s `creating` listener throws `MissingTenantContextException`
 * when none is resolved, so the table as it stood could not record this
 * capability's own audit requirement at all. Stamping the superadmin's
 * currently-acting client was rejected: a platform-wide edit filed under
 * whichever client happened to be selected tells ONE tenant it happened and
 * hides it from every other, and puts a platform event inside a tenant's
 * audit read surface.
 *
 * Tenant reads are UNAFFECTED: `TenantScoped` filters `organization_id = X`,
 * so a NULL row is invisible to every existing tenant query and to the
 * dashboard activity feed by construction — no reader needed a code change.
 * The two composite indexes still lead with `organization_id`; a platform
 * row simply has no organization to be found by either one, which is the
 * correct behaviour here.
 *
 * The FK's `cascadeOnDelete()` is untouched by this change — it already
 * applies only to a non-null value, so a platform row (NULL) is never
 * affected by an organization being deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // `audit_logs` is append-only (see that table's own creation
        // migration docblock) — a rollback that deletes rows to make the
        // restored NOT NULL constraint fit is destroying audit evidence to
        // satisfy a schema change, which this rollback refuses to do.
        // Refusing here, before touching anything, also means the ALTER
        // below never runs against surviving platform rows it would
        // otherwise reject with a constraint violation that reads as
        // corruption instead of an explicit refusal.
        if (DB::table('audit_logs')->whereNull('organization_id')->exists()) {
            throw new RuntimeException(
                'audit_logs holds platform (NULL organization_id) rows and this rollback refuses '
                .'to delete them to restore the NOT NULL constraint — audit logs are append-only. '
                .'Remove or migrate those rows through an explicit, reviewed operation first, or '
                .'keep organization_id nullable instead of rolling back this migration.'
            );
        }

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable(false)->change();
        });
    }
};
