<?php

declare(strict_types=1);

namespace App\Actions\Conversation;

use App\Enums\PromptFragmentKey;
use App\Exceptions\Conversation\PromptSetException;
use App\Exceptions\Conversation\PromptTemplateUnresolvableException;
use App\Models\ConversationPromptFragment;
use App\Models\ConversationPromptOverride;
use App\Models\ConversationPromptSet;
use App\Services\Conversation\PromptSetResolver;
use App\Support\Conversation\PromptFragmentContract;
use App\Support\Conversation\PromptSetSeal;
use Illuminate\Support\Facades\DB;

/**
 * Publishes a prompt set: validates the whole payload, seals it, and stores the
 * set INACTIVE with its fragments and overrides in ONE transaction
 * (db-driven-conversation-prompts PR6b, design N-5). It never activates.
 *
 * The seal is computed from the validated payload BEFORE the set is inserted,
 * because the immutability trigger forbids updating it afterwards. Once the
 * children are written, the stored rows are read back and verified exactly as a
 * composition and an activation verify them, so a seal that does not match what
 * the database really holds rolls everything back.
 *
 * Findings name keys, locales and tokens, never a body.
 */
final class PublishPromptSet
{
    public function __construct(private readonly PromptSetResolver $resolver) {}

    /**
     * @param  list<array<string, mixed>>  $fragments  Rows with `key`, `locale`, `body`.
     * @param  list<array<string, mixed>>  $overrides  Rows with `role_code` (null = every role), `competency_code`, `locale`, `body`.
     *
     * @throws PromptSetException When the payload is invalid or the label is taken.
     * @throws PromptTemplateUnresolvableException When the stored rows fail the read-back verification.
     */
    public function handle(string $label, ?string $notes, array $fragments, array $overrides = []): ConversationPromptSet
    {
        $this->validate($label, $fragments, $overrides);
        $seal = PromptSetSeal::seal($fragments, $overrides);

        return DB::transaction(function () use ($label, $notes, $fragments, $overrides, $seal): ConversationPromptSet {
            if (ConversationPromptSet::query()->where('label', $label)->exists()) {
                throw PromptSetException::duplicateLabel($label);
            }

            $set = ConversationPromptSet::query()->create(['label' => $label, 'notes' => $notes, 'content_sha256' => $seal]);

            foreach ($fragments as $row) {
                ConversationPromptFragment::query()->create(['prompt_set_id' => $set->id, 'fragment_key' => $row['key'], 'locale' => $row['locale'], 'body' => $row['body']]);
            }

            foreach ($overrides as $row) {
                ConversationPromptOverride::query()->create(['prompt_set_id' => $set->id, 'role_code' => $row['role_code'] ?? null, 'competency_code' => $row['competency_code'], 'locale' => $row['locale'], 'body' => $row['body']]);
            }

            $this->resolver->verify($set);

            return $set->refresh();
        });
    }

    /**
     * Every finding of a payload without touching the database; throws when any.
     *
     * @param  list<array<string, mixed>>  $fragments
     * @param  list<array<string, mixed>>  $overrides
     *
     * @throws PromptSetException
     */
    public function validate(string $label, array $fragments, array $overrides = []): void
    {
        $violations = [];

        if ($label === '' || mb_strlen($label) > 64) {
            $violations[] = 'the label must be 1 to 64 characters';
        }

        $bodiesByLocale = $this->fragmentViolations($fragments, $violations);
        $contract = new PromptFragmentContract;
        $seen = [];

        foreach ($overrides as $row) {
            $competency = $row['competency_code'] ?? null;
            $role = $row['role_code'] ?? null;
            $locale = $row['locale'] ?? null;
            $body = $row['body'] ?? null;
            $name = 'override ['.(is_string($competency) ? $competency : '?').'/'.(is_string($locale) ? $locale : '?').']';

            if (! is_string($competency) || trim($competency) === '') {
                $violations[] = "{$name} needs a non-empty competency_code";
            }

            if ($role !== null && (! is_string($role) || trim($role) === '')) {
                $violations[] = "{$name} role_code must be null or a non-empty string";
            }

            if (! is_string($locale) || ! isset($bodiesByLocale[$locale])) {
                $violations[] = "{$name} targets locale [".(is_string($locale) ? $locale : '?').'] which has no fragments';
            }

            if (! is_string($body) || ! mb_check_encoding($body, 'UTF-8')) {
                $violations[] = "{$name} body is not valid UTF-8 text";
            } else {
                array_push($violations, ...array_map(static fn (string $v): string => "{$name}: {$v}", $contract->overrideViolations($body)));
            }

            $identity = json_encode([$role, $competency, $locale]);

            if (isset($seen[$identity])) {
                $violations[] = "{$name} is given twice for the same role";
            }

            $seen[$identity] = true;
        }

        if ($violations !== []) {
            throw PromptSetException::invalid($violations);
        }
    }

    /**
     * Validate the fragment rows and return the keys present per locale.
     *
     * @param  list<array<string, mixed>>  $fragments
     * @param  list<string>  $violations
     * @return array<string, true>
     */
    private function fragmentViolations(array $fragments, array &$violations): array
    {
        $contract = new PromptFragmentContract;
        $keysByLocale = [];

        foreach ($fragments as $row) {
            $key = $row['key'] ?? null;
            $locale = $row['locale'] ?? null;
            $body = $row['body'] ?? null;

            if (! is_string($key) || ! is_string($locale) || $locale === '' || mb_strlen($locale) > 8 || ! is_string($body)) {
                $violations[] = 'a fragment needs a string key, a locale of 1 to 8 characters and a string body';

                continue;
            }

            if (isset($keysByLocale[$locale][$key])) {
                $violations[] = "fragment [{$key}] is given twice for locale [{$locale}]";

                continue;
            }

            $keysByLocale[$locale][$key] = true;
            $enum = PromptFragmentKey::tryFrom($key);

            if ($enum === null) {
                $violations[] = "fragment [{$key}] for locale [{$locale}] is not a known fragment key";
            } else {
                array_push($violations, ...array_map(static fn (string $v): string => "locale [{$locale}]: {$v}", $contract->violations($enum, $body)));
            }
        }

        if ($keysByLocale === []) {
            $violations[] = 'at least one locale of fragments is required';
        }

        $expected = array_map(static fn (PromptFragmentKey $key): string => $key->value, PromptFragmentKey::cases());

        foreach ($keysByLocale as $locale => $keys) {
            foreach (array_diff($expected, array_keys($keys)) as $missing) {
                $violations[] = "locale [{$locale}] is missing the fragment [{$missing}]";
            }
        }

        return array_fill_keys(array_map('strval', array_keys($keysByLocale)), true);
    }
}
