<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bootstrap the baseline conversation prompt set (db-driven-conversation-prompts
 * PR7, design N-10): store `database/prompt-sets/baseline-1.json`, seal it and
 * activate it, so every database (production, and every `RefreshDatabase` test
 * database) has an active set after `migrate`. Production never runs
 * `DatabaseSeeder`, which is why this is a migration and not a seeder.
 *
 * Nothing reads the set yet: the cut-over that makes the composer resolve it is
 * a later, separate change. Until then an interview is composed exactly as before.
 *
 * INLINE ON PURPOSE. Like `backfill_baseline_revision`, this migration uses
 * `DB::table` and no application class: a migration is history and must keep
 * meaning what it meant the day it ran, however `PromptSetSeal`, the enum or the
 * models change later. For the same reason the seal algorithm is FROZEN in
 * {@see seal()} below; `BaselinePromptSetMigrationTest` asserts it equals
 * `PromptSetSeal`, so a drift between the two is a failing test, never a silent
 * mismatch. The seal here covers fragments only: the baseline carries no overrides,
 * which `PromptSetSeal` encodes as the constant tail `"overrides":[]`.
 *
 * THE FILE IS THE SOURCE. The rows come from `baseline-1.json`, a frozen artefact
 * written by `beai:prompt-set:dump-baseline` and never regenerated (a stored set
 * is immutable). A changed baseline is a NEW label with its own file and its own
 * data migration, or a publish plus activate; this label is never touched again.
 *
 * IDEMPOTENT, keyed on the unique `label`. When `baseline-1` already exists the
 * migration inserts and changes nothing, and verifies instead: the stored hash
 * must equal the hash of the file, and must equal the hash of the stored rows.
 * Anything else throws, so the deploy stops rather than serving a set that is not
 * the baseline.
 *
 * ACTIVATION. The baseline is activated only when NO set is active. If an
 * operator already published and activated another set, that choice wins: the
 * baseline is inserted INACTIVE and the incumbent stays active. The one-active
 * partial unique index would refuse a second active row anyway, and silently
 * replacing an operator's active set from a migration would change what
 * interviews send.
 *
 * ROLLBACK. `down()` removes nothing. The immutability triggers refuse DELETE of
 * fragments, and nothing may be removed from under a set that may be active or
 * stamped into a session reference. A full rollback still works: the earlier
 * schema migration's `down()` drops the three tables with their rows (DROP TABLE
 * fires no row trigger). Rolling back only this migration and migrating again is
 * safe because `up()` is idempotent.
 */
return new class extends Migration
{
    private const LABEL = 'baseline-1';

    public function up(): void
    {
        [$notes, $fragments] = $this->readFile(database_path('prompt-sets/'.self::LABEL.'.json'));
        $hash = $this->seal($fragments);

        DB::transaction(function () use ($notes, $fragments, $hash): void {
            $existing = DB::table('conversation_prompt_sets')->where('label', self::LABEL)->lockForUpdate()->first();

            if ($existing !== null) {
                $this->verifyExisting($existing, $hash);

                return;
            }

            // The incumbent, if any, wins; see the ACTIVATION note above.
            $activate = ! DB::table('conversation_prompt_sets')->where('is_active', true)->exists();

            $setId = DB::table('conversation_prompt_sets')->insertGetId([
                'label' => self::LABEL,
                'content_sha256' => $hash,
                'is_active' => $activate,
                'activated_at' => $activate ? now() : null,
                'notes' => $notes,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('conversation_prompt_fragments')->insert(array_map(
                static fn (array $row): array => [
                    'prompt_set_id' => $setId,
                    'fragment_key' => $row['key'],
                    'locale' => $row['locale'],
                    'body' => $row['body'],
                    'created_at' => now(),
                ],
                $fragments,
            ));
        });
    }

    /** Removes nothing; see the ROLLBACK note above. */
    public function down(): void {}

    /**
     * The FROZEN seal: SHA-256 (lowercase hex) of the canonical JSON
     *
     *     {"fragments":[{"key":K,"locale":L,"body":B},...],"overrides":[]}
     *
     * with fragments sorted by (key, locale) by byte-wise `strcmp`, no whitespace,
     * and `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR`.
     * Equal to `PromptSetSeal::seal($fragments)` today; never edited to follow it.
     *
     * @param  list<array{key: string, locale: string, body: string}>  $fragments
     */
    public function seal(array $fragments): string
    {
        $rows = [];

        foreach ($fragments as $row) {
            $rows[] = ['key' => $row['key'], 'locale' => $row['locale'], 'body' => $row['body']];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['key'], $b['key']) ?: strcmp($a['locale'], $b['locale']));

        return hash('sha256', json_encode(
            ['fragments' => $rows, 'overrides' => []],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * @return array{0: string|null, 1: list<array{key: string, locale: string, body: string}>}
     */
    private function readFile(string $path): array
    {
        $json = is_file($path) ? file_get_contents($path) : false;

        if ($json === false) {
            throw new RuntimeException("Cannot bootstrap the baseline prompt set: the file [{$path}] cannot be read.");
        }

        $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        $byLocale = is_array($data) ? ($data['fragments'] ?? null) : null;
        $notes = is_array($data) ? ($data['notes'] ?? null) : null;

        if (! is_array($data) || ($data['label'] ?? null) !== self::LABEL || ! is_array($byLocale) || $byLocale === [] || ! (is_string($notes) || $notes === null)) {
            throw new RuntimeException('Cannot bootstrap the baseline prompt set: '.self::LABEL.'.json must hold the label "'.self::LABEL.'" and a non-empty "fragments" object.');
        }

        $rows = [];

        foreach ($byLocale as $locale => $bodies) {
            if (! is_array($bodies) || $bodies === []) {
                throw new RuntimeException("Cannot bootstrap the baseline prompt set: locale [{$locale}] has no fragments.");
            }

            foreach ($bodies as $key => $body) {
                if (! is_string($body)) {
                    throw new RuntimeException("Cannot bootstrap the baseline prompt set: fragment [{$key}] of locale [{$locale}] is not a string.");
                }

                $rows[] = ['key' => (string) $key, 'locale' => (string) $locale, 'body' => $body];
            }
        }

        return [$notes, $rows];
    }

    private function verifyExisting(object $existing, string $fileHash): void
    {
        $stored = [];

        foreach (DB::table('conversation_prompt_fragments')->where('prompt_set_id', $existing->id)->get(['fragment_key', 'locale', 'body']) as $row) {
            $stored[] = ['key' => (string) $row->fragment_key, 'locale' => (string) $row->locale, 'body' => (string) $row->body];
        }

        if (! hash_equals((string) $existing->content_sha256, $fileHash)) {
            throw new RuntimeException('The prompt set ['.self::LABEL.'] already exists with a stored hash that differs from '.self::LABEL.'.json; refusing to continue.');
        }

        if (! hash_equals((string) $existing->content_sha256, $this->seal($stored))) {
            throw new RuntimeException('The prompt set ['.self::LABEL.'] already exists but its stored rows do not match its stored hash; refusing to continue.');
        }
    }
};
