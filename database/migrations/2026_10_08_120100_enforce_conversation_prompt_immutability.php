<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DB-enforced immutability of a prompt set (db-driven-conversation-prompts
 * PR5, design N-4). Mirrors `2026_09_15_201434_enforce_catalogue_published_
 * content_immutability`: one PL/pgSQL function, attached per table, raising
 * SQLSTATE 23514 with a message that names the operation and the table so a
 * test can assert the exact refusal.
 *
 *  - `conversation_prompt_fragments` / `conversation_prompt_overrides`: every
 *    UPDATE and DELETE is refused. These rows are what `content_sha256`
 *    seals; a correction is a NEW set, never an edit.
 *  - `conversation_prompt_sets`: an UPDATE is refused unless the only columns
 *    that change are `is_active`, `activated_at` and `updated_at` (the
 *    activation bookkeeping). Comparing the rows as JSON minus those three
 *    keys keeps the guard correct if a column is added later: a new column is
 *    frozen by default. DELETE of a set is NOT refused here: a set with
 *    content is already protected by the `restrictOnDelete` foreign keys, and
 *    an empty set (a publish that failed before inserting children) must stay
 *    removable.
 *
 * There is deliberately NO INSERT trigger. A publish inserts the set and then
 * its children in one transaction, so an INSERT guard would have to know
 * "sealed yet?" — and a late INSERT into a sealed set is caught by the hash
 * the resolver verifies (PR6a).
 */
return new class extends Migration
{
    public function up(): void
    {
        // `CREATE OR REPLACE`, not a bare `CREATE`: `migrate:fresh` drops every
        // TABLE but never runs `down()`, so a standalone FUNCTION survives it
        // and a bare `CREATE FUNCTION` would then fail "already exists".
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION conversation_prompt_refuse_content_write() RETURNS trigger AS $$
            BEGIN
                IF TG_TABLE_NAME = 'conversation_prompt_sets' THEN
                    IF (to_jsonb(NEW) - 'is_active' - 'activated_at' - 'updated_at')
                        IS NOT DISTINCT FROM (to_jsonb(OLD) - 'is_active' - 'activated_at' - 'updated_at') THEN
                        RETURN NEW;
                    END IF;
                END IF;

                RAISE EXCEPTION 'conversation_prompt_content_immutable: % on table % refused',
                    TG_OP, TG_TABLE_NAME
                    USING ERRCODE = '23514';
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement(
            'CREATE TRIGGER conversation_prompt_sets_refuse_content_write
             BEFORE UPDATE ON conversation_prompt_sets
             FOR EACH ROW EXECUTE FUNCTION conversation_prompt_refuse_content_write()'
        );

        foreach (['conversation_prompt_fragments', 'conversation_prompt_overrides'] as $table) {
            DB::statement(
                "CREATE TRIGGER {$table}_refuse_content_write
                 BEFORE UPDATE OR DELETE ON {$table}
                 FOR EACH ROW EXECUTE FUNCTION conversation_prompt_refuse_content_write()"
            );
        }
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS conversation_prompt_sets_refuse_content_write ON conversation_prompt_sets');

        foreach (['conversation_prompt_fragments', 'conversation_prompt_overrides'] as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_refuse_content_write ON {$table}");
        }

        DB::unprepared('DROP FUNCTION IF EXISTS conversation_prompt_refuse_content_write()');
    }
};
