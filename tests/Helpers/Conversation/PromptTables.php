<?php

declare(strict_types=1);

namespace Tests\Helpers\Conversation;

use App\Models\ConversationPromptSet;
use App\Support\Conversation\PromptSetSeal;
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
 *
 * Identity counters are NOT reset: this is a DELETE, not a TRUNCATE (which would
 * break the surrounding transaction), so the ids of rows inserted afterwards keep
 * advancing past the bootstrap rows. Tests must not assert a specific numeric id
 * of a prompt set, fragment or override.
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

    /**
     * Replace the body of every override of a set with one the override contract
     * refuses, and reseal the set over the real rows, so that ONLY the override
     * contract is left to fail (publishing would have refused the body).
     */
    public static function breakOverrideBody(ConversationPromptSet $set, string $body): void
    {
        foreach (['conversation_prompt_overrides', 'conversation_prompt_sets'] as $table) {
            DB::statement("ALTER TABLE {$table} DISABLE TRIGGER USER");
        }

        DB::table('conversation_prompt_overrides')->where('prompt_set_id', $set->id)->update(['body' => $body]);
        $fragments = DB::table('conversation_prompt_fragments')->where('prompt_set_id', $set->id)
            ->get(['fragment_key as key', 'locale', 'body'])->map(fn (object $row): array => (array) $row)->all();
        $overrides = DB::table('conversation_prompt_overrides')->where('prompt_set_id', $set->id)
            ->get(['role_code', 'competency_code', 'locale', 'body'])->map(fn (object $row): array => (array) $row)->all();
        DB::table('conversation_prompt_sets')->where('id', $set->id)->update(['content_sha256' => PromptSetSeal::seal($fragments, $overrides)]);

        foreach (['conversation_prompt_overrides', 'conversation_prompt_sets'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE TRIGGER USER");
        }
    }
}
