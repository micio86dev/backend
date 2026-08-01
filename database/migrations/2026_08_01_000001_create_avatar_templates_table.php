<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * avatar_templates — the avatar/voice configuration an operator chooses (C14).
 *
 * Until now BEAI sent NO avatar id, voice id, language, quality or voice tuning
 * to either provider: config/interview.php holds three keys, and whatever the
 * provider account happened to default to is what every candidate of every
 * tenant received. This table is where that choice starts existing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avatar_templates', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name', 120);
            $table->string('description', 500)->nullable();

            // 'heygen' | 'tavus'. A CHECK rather than a Postgres enum: adding a
            // provider then costs an ALTER of one constraint instead of a type
            // migration, and the application's ProviderName union is the real
            // source of truth either way.
            $table->string('provider', 32);

            // The provider-specific knobs, validated against a field spec at
            // write time AND at session start. Schemaless here on purpose: the
            // two providers share exactly one concept (language) and even that
            // has different value spaces, so a normalized column set would be
            // two disjoint groups of mostly-null columns pretending to be one.
            $table->jsonb('config')->default('{}');

            $table->boolean('is_active')->default(false);

            $table->timestamps();

            // Names are how operators refer to templates out loud, so two with
            // the same name in one org is a support call waiting to happen.
            $table->unique(['organization_id', 'name']);

            $table->index(['organization_id', 'is_active']);
        });

        // THE invariant: at most one active template per organization.
        //
        // Enforced here rather than in application code, because in application
        // code two concurrent activations both read "no other active template",
        // both write, and both win — leaving a tenant with two active templates
        // and the next interview picking whichever the query happens to return
        // first. A check that only holds when nobody is in a hurry is not a
        // check.
        //
        // Partial, so the many INACTIVE templates an org accumulates are
        // unconstrained: a plain unique index on (organization_id, is_active)
        // would cap each org at one inactive template too, which is absurd.
        DB::statement(
            'CREATE UNIQUE INDEX avatar_templates_one_active_per_org
             ON avatar_templates (organization_id)
             WHERE is_active'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('avatar_templates');
    }
};
