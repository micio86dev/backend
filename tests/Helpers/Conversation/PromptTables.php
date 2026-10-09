<?php

declare(strict_types=1);

namespace Tests\Helpers\Conversation;

use Illuminate\Support\Facades\DB;

/**
 * The prompt tables as they were before the baseline bootstrap migration.
 *
 * Every migrated database now holds an ACTIVE `baseline-1` set (PR7). Tests that
 * exercise the single-active index, the resolver's "no active set" refusal or
 * exact row counts start from an empty state, which they get here: the
 * immutability triggers are disabled for the delete and re-enabled straight
 * after. The statements are transactional DDL, so `RefreshDatabase`'s rollback
 * restores the bootstrapped set for the next test.
 */
final class PromptTables
{
    public static function empty(): void
    {
        foreach (['conversation_prompt_fragments', 'conversation_prompt_overrides', 'conversation_prompt_sets'] as $table) {
            DB::statement("ALTER TABLE {$table} DISABLE TRIGGER USER");
            DB::table($table)->delete();
            DB::statement("ALTER TABLE {$table} ENABLE TRIGGER USER");
        }
    }
}
