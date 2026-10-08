<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Storage for DB-driven conversation prompts (db-driven-conversation-prompts
 * PR5, design N-3). Additive: nothing reads these tables until PR6a/PR8.
 *
 * GLOBAL, no `organization_id`: a prompt set is a platform artefact (like the
 * catalogue revisions and the LLM rate cards), never tenant data. The three
 * models are excluded from tenant scoping in `TenantModelArchTest`.
 *
 *  - `conversation_prompt_sets`: one row per published set. `content_sha256`
 *    seals the children (written at INSERT, verified by the resolver) and
 *    `is_active` marks the single set new interviews compose from. The
 *    partial unique index is the `avatar_templates` active-index idiom: only
 *    ACTIVE rows are constrained, so any number of inactive sets coexist and
 *    two concurrent activations cannot both win.
 *  - `conversation_prompt_fragments`: one row per (set, key, locale). No CHECK
 *    on `fragment_key`: membership is enforced against the `PromptFragmentKey`
 *    enum by publish and by the resolver, since a CHECK would duplicate the
 *    enum and need a migration per key.
 *  - `conversation_prompt_overrides`: operator-authored text for one
 *    competency, optionally narrowed to one role. Keyed by CODE, never by
 *    foreign key: catalogue roles and competencies are cloned with new ids on
 *    every catalogue revision, so an id-keyed override would silently stop
 *    matching. The codes are `varchar(255)` like `roles.code` and
 *    `competencies.code`. A plain UNIQUE cannot constrain the role-less rows
 *    (NULLs are distinct), hence the pair of partial unique indexes.
 *
 * Both child tables are `restrictOnDelete`: a set with content can never be
 * deleted. Immutability of the rows themselves is the next migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_prompt_sets', function (Blueprint $table): void {
            $table->id();
            $table->string('label', 64);
            $table->char('content_sha256', 64);
            $table->boolean('is_active')->default(false);
            $table->timestampTz('activated_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique('label', 'conversation_prompt_sets_label_unique');
        });

        DB::statement(
            "ALTER TABLE conversation_prompt_sets
             ADD CONSTRAINT conversation_prompt_sets_content_sha256_hex
             CHECK (content_sha256 ~ '^[0-9a-f]{64}$')"
        );

        DB::statement(
            'CREATE UNIQUE INDEX conversation_prompt_sets_one_active
             ON conversation_prompt_sets (is_active)
             WHERE is_active'
        );

        Schema::create('conversation_prompt_fragments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prompt_set_id')->constrained('conversation_prompt_sets')->restrictOnDelete();
            $table->string('fragment_key', 48);
            $table->string('locale', 8);
            $table->text('body');
            $table->timestamp('created_at')->nullable();

            $table->unique(['prompt_set_id', 'fragment_key', 'locale'], 'conversation_prompt_fragments_set_key_locale_unique');
        });

        Schema::create('conversation_prompt_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prompt_set_id')->constrained('conversation_prompt_sets')->restrictOnDelete();
            $table->string('role_code')->nullable();
            $table->string('competency_code');
            $table->string('locale', 8);
            $table->text('body');
            $table->timestamp('created_at')->nullable();
        });

        DB::statement(
            'CREATE UNIQUE INDEX conversation_prompt_overrides_role_specific_unique
             ON conversation_prompt_overrides (prompt_set_id, role_code, competency_code, locale)
             WHERE role_code IS NOT NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX conversation_prompt_overrides_role_less_unique
             ON conversation_prompt_overrides (prompt_set_id, competency_code, locale)
             WHERE role_code IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_prompt_overrides');
        Schema::dropIfExists('conversation_prompt_fragments');
        Schema::dropIfExists('conversation_prompt_sets');
    }
};
