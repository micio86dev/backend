<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Conversation\PublishPromptSet;
use App\Exceptions\Conversation\PromptSetException;
use App\Exceptions\Conversation\PromptTemplateUnresolvableException;
use App\Models\ConversationPromptSet;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

/**
 * `php artisan beai:prompt-set:publish {file} [--label=] [--notes=] [--dry-run]`
 * (db-driven-conversation-prompts PR6b).
 *
 * Publishes a prompt set from a JSON file. The set is stored INACTIVE; use
 * `beai:prompt-set:activate` to make it the one new interviews compose from.
 * Nothing is written when anything is wrong, and errors name keys, locales and
 * tokens only, never a template body.
 *
 * File shape (the one `database/prompt-sets/<label>.json` uses):
 *
 *     {
 *       "label": "v2",                                   // required unless --label is given, 1 to 64 chars, unique
 *       "notes": "why this set exists",                  // optional
 *       "fragments": {                                   // every locale carries EXACTLY the 32 PromptFragmentKey keys
 *         "en": { "header": "...", "advance.with_phrase": "... {{advance_phrase}} ...", ... },
 *         "it": { ... }
 *       },
 *       "overrides": [                                   // optional; plain text, no {{placeholder}}
 *         { "role_code": null, "competency_code": "COL", "locale": "en", "body": "..." }
 *       ]
 *     }
 *
 * `--label` and `--notes` take precedence over the file. `--dry-run` runs every
 * check (payload, placeholder contract, label not taken) and writes nothing.
 */
final class PublishPromptSetCommand extends Command
{
    protected $signature = 'beai:prompt-set:publish
        {file : Path to the prompt set JSON file}
        {--label= : Label of the set (overrides the file)}
        {--notes= : Notes stored with the set (overrides the file)}
        {--dry-run : Validate only; write nothing}';

    protected $description = 'Publish a conversation prompt set from a JSON file (stored inactive)';

    public function handle(PublishPromptSet $publish): int
    {
        try {
            $payload = $this->payload((string) $this->argument('file'));
            $label = is_string($this->option('label')) && $this->option('label') !== '' ? $this->option('label') : $payload['label'];
            $notes = is_string($this->option('notes')) ? $this->option('notes') : $payload['notes'];

            $publish->validate($label, $payload['fragments'], $payload['overrides']);

            if (ConversationPromptSet::query()->where('label', $label)->exists()) {
                throw PromptSetException::duplicateLabel($label);
            }

            $counts = count($payload['fragments']).' fragments, '.count($payload['overrides']).' '.(count($payload['overrides']) === 1 ? 'override' : 'overrides');

            if ($this->option('dry-run')) {
                $this->info("Prompt set [{$label}] is valid ({$counts}). Dry run: nothing was written.");

                return self::SUCCESS;
            }

            $set = $publish->handle($label, $notes, $payload['fragments'], $payload['overrides']);
        } catch (PromptSetException|PromptTemplateUnresolvableException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            // Deliberately no message: a database error carries the bound bodies.
            $this->error('Publishing failed ('.$e::class.'); nothing was persisted.');

            return self::FAILURE;
        }

        $this->info("Published prompt set [{$set->label}] (id {$set->id}, {$counts}), inactive. Activate it with: php artisan beai:prompt-set:activate {$set->label}");

        return self::SUCCESS;
    }

    /**
     * Read the file and flatten it into the rows the action takes.
     *
     * @return array{label: string, notes: string|null, fragments: list<array{key: string, locale: string, body: mixed}>, overrides: list<array<string, mixed>>}
     *
     * @throws PromptSetException
     */
    private function payload(string $file): array
    {
        $json = is_file($file) && is_readable($file) ? file_get_contents($file) : false;

        if ($json === false) {
            throw PromptSetException::invalid(["the file [{$file}] cannot be read"]);
        }

        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw PromptSetException::invalid(['the file is not valid JSON']);
        }

        $byLocale = is_array($data) ? ($data['fragments'] ?? null) : null;
        $overrides = is_array($data) ? ($data['overrides'] ?? []) : [];
        $notes = is_array($data) ? ($data['notes'] ?? null) : null;

        if (! is_array($byLocale) || $byLocale === [] || ! array_is_list($overrides) || ($notes !== null && ! is_string($notes))) {
            throw PromptSetException::invalid(['the file must hold "fragments" as {locale: {key: body}}, an optional "overrides" list and optional string "notes"']);
        }

        $fragments = [];

        foreach ($byLocale as $locale => $bodies) {
            if (! is_array($bodies)) {
                throw PromptSetException::invalid(["fragments for locale [{$locale}] must be an object of key: body"]);
            }

            foreach ($bodies as $key => $body) {
                $fragments[] = ['key' => (string) $key, 'locale' => (string) $locale, 'body' => $body];
            }
        }

        $label = $data['label'] ?? '';

        return ['label' => is_string($label) ? $label : '', 'notes' => $notes, 'fragments' => $fragments, 'overrides' => array_values(array_filter($overrides, 'is_array'))];
    }
}
