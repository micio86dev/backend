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
        // A platform (NULL-org) row cannot survive a NOT NULL column, and it
        // has no tenant to attribute it to — deleted rather than left to fail
        // the ALTER with a constraint violation that reads as corruption.
        DB::table('audit_logs')->whereNull('organization_id')->delete();

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable(false)->change();
        });
    }
};
