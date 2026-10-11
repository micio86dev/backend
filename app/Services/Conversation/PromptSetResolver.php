<?php

declare(strict_types=1);

namespace App\Services\Conversation;

use App\DTOs\Conversation\PromptTemplateSet;
use App\DTOs\Conversation\ResolvedPromptSet;
use App\Enums\PromptFragmentKey;
use App\Exceptions\Conversation\PromptTemplateUnresolvableException;
use App\Models\ConversationPromptFragment;
use App\Models\ConversationPromptOverride;
use App\Models\ConversationPromptSet;
use App\Support\Conversation\PromptFragmentContract;
use App\Support\Conversation\PromptSetSeal;

/**
 * Resolves the ACTIVE stored prompt set into a verified template set
 * (db-driven-conversation-prompts, design N-6).
 *
 * Every call runs ONE indexed query for the active set. The stored rows are
 * loaded and verified only the first time a (set id, content hash, locale) is
 * seen, then served from memory: sets are immutable, so the entry needs no TTL
 * and no invalidation. Keying by id AND hash means a different active set (or
 * one whose stored hash changed) is a different key, so a long-lived worker
 * can never serve a set after the activation moved.
 *
 * Exactly one set may be active: more than one refuses (`ambiguous_active_set`)
 * even though a partial unique index makes that state impossible, so a broken
 * invariant is loud instead of silently serving whichever row came first.
 *
 * A set holding two rows for one identity (fragment key and locale, or override
 * role, competency and locale) is refused with `duplicate_row` before the seal
 * is checked; rows are read in a fixed order so no row ever wins by arrival.
 *
 * Verification, each a distinct {@see PromptTemplateUnresolvableException} reason:
 * the seal recomputed over ALL rows of the set (every locale, plus overrides)
 * equals the stored one; the locale has rows; its key set equals
 * {@see PromptFragmentKey::cases()} (a missing OR unknown key refuses); every
 * body satisfies {@see PromptFragmentContract}. The override that applies is
 * checked on every call, since it is picked per competency and role.
 *
 * Nothing is cached when verification fails.
 */
final class PromptSetResolver
{
    /**
     * @var array<string, array{templates: PromptTemplateSet, overrides: list<array{role_code: string|null, competency_code: string, body: string}>}>
     */
    private static array $verified = [];

    /** Forget every verified set (tests; a worker never needs it). */
    public static function flushCache(): void
    {
        self::$verified = [];
    }

    /**
     * @throws PromptTemplateUnresolvableException
     */
    public function resolveActive(string $locale, string $competencyCode, ?string $roleCode): ResolvedPromptSet
    {
        // Two rows is enough to know the single-active invariant is broken; never serve either of them.
        $active = ConversationPromptSet::query()->where('is_active', true)->orderBy('id')->limit(2)->get(['id', 'label', 'content_sha256']);

        if ($active->count() > 1) {
            $ids = [];
            foreach ($active as $row) {
                $ids[] = $row->id;
            }

            throw PromptTemplateUnresolvableException::ambiguousActiveSet($ids);
        }

        $set = $active->first();

        if ($set === null) {
            throw PromptTemplateUnresolvableException::noActiveSet();
        }

        $cacheKey = $set->id.':'.$set->content_sha256.':'.$locale;
        $verified = self::$verified[$cacheKey] ??= $this->loadAndVerify($set, $locale);

        return new ResolvedPromptSet(
            $verified['templates'],
            $this->overrideFor($set, $locale, $competencyCode, $roleCode, $verified['overrides']),
            $set->label,
            $set->id,
            $set->content_sha256,
        );
    }

    /**
     * Verify a stored set across EVERY locale it holds, without caching or
     * resolving anything: the gate an activation passes before a set can become
     * the active one. It runs the very checks a composition runs (seal, key set,
     * placeholder contract) plus the contract of every override body.
     *
     * @throws PromptTemplateUnresolvableException
     */
    public function verify(ConversationPromptSet $set): void
    {
        [$fragmentRows, $overrideRows] = $this->rows($set);
        $this->assertSealed($set, $fragmentRows, $overrideRows);

        $locales = array_values(array_unique(array_column($fragmentRows, 'locale')));

        if ($locales === []) {
            throw PromptTemplateUnresolvableException::emptySet($set->label);
        }

        foreach ($locales as $locale) {
            $this->verifiedBodies($set, $locale, $fragmentRows);
        }

        $contract = new PromptFragmentContract;

        foreach ($overrideRows as $row) {
            $violations = $contract->overrideViolations($row['body']);

            if ($violations !== []) {
                throw PromptTemplateUnresolvableException::overrideInvalid($set->label, $row['locale'], $row['competency_code'], $violations);
            }
        }
    }

    /**
     * @return array{templates: PromptTemplateSet, overrides: list<array{role_code: string|null, competency_code: string, body: string}>}
     */
    private function loadAndVerify(ConversationPromptSet $set, string $locale): array
    {
        [$fragmentRows, $overrideRows] = $this->rows($set);
        $this->assertSealed($set, $fragmentRows, $overrideRows);

        $bodies = $this->verifiedBodies($set, $locale, $fragmentRows);

        $overrides = [];
        foreach ($overrideRows as $row) {
            if ($row['locale'] === $locale) {
                $overrides[] = ['role_code' => $row['role_code'], 'competency_code' => $row['competency_code'], 'body' => $row['body']];
            }
        }

        return ['templates' => new PromptTemplateSet($bodies), 'overrides' => $overrides];
    }

    /**
     * Every stored row of the set, as the plain arrays the seal is taken over.
     *
     * @return array{0: list<array{key: string, locale: string, body: string}>, 1: list<array{role_code: string|null, competency_code: string, locale: string, body: string}>}
     */
    private function rows(ConversationPromptSet $set): array
    {
        $fragmentRows = [];
        $fragmentQuery = ConversationPromptFragment::query()->where('prompt_set_id', $set->id)->orderBy('fragment_key')->orderBy('locale')->orderBy('id');
        foreach ($fragmentQuery->toBase()->get(['fragment_key', 'locale', 'body']) as $row) {
            $fragmentRows[] = ['key' => (string) $row->fragment_key, 'locale' => (string) $row->locale, 'body' => (string) $row->body];
        }

        $overrideRows = [];
        $overrideQuery = ConversationPromptOverride::query()->where('prompt_set_id', $set->id)->orderBy('role_code')->orderBy('competency_code')->orderBy('locale')->orderBy('id');
        foreach ($overrideQuery->toBase()->get(['role_code', 'competency_code', 'locale', 'body']) as $row) {
            $overrideRows[] = [
                'role_code' => $row->role_code === null ? null : (string) $row->role_code,
                'competency_code' => (string) $row->competency_code,
                'locale' => (string) $row->locale,
                'body' => (string) $row->body,
            ];
        }

        return [$fragmentRows, $overrideRows];
    }

    /**
     * @param  list<array{key: string, locale: string, body: string}>  $fragmentRows
     * @param  list<array{role_code: string|null, competency_code: string, locale: string, body: string}>  $overrideRows
     */
    private function assertSealed(ConversationPromptSet $set, array $fragmentRows, array $overrideRows): void
    {
        // Before the seal: a second row for one identity is named as such even when the seal covers it,
        // and no row is ever picked over another by arrival order.
        $this->assertNoDuplicates($set, $fragmentRows, $overrideRows);

        if (! hash_equals($set->content_sha256, PromptSetSeal::seal($fragmentRows, $overrideRows))) {
            throw PromptTemplateUnresolvableException::sealMismatch($set->label);
        }
    }

    /**
     * The bodies of one locale once its rows exist, hold exactly the enum's keys
     * and satisfy the placeholder contract.
     *
     * @param  list<array{key: string, locale: string, body: string}>  $fragmentRows
     * @return array<string, string>
     */
    private function verifiedBodies(ConversationPromptSet $set, string $locale, array $fragmentRows): array
    {
        $bodies = [];
        foreach ($fragmentRows as $row) {
            if ($row['locale'] === $locale) {
                $bodies[$row['key']] = $row['body'];
            }
        }

        if ($bodies === []) {
            throw PromptTemplateUnresolvableException::localeMissing($set->label, $locale);
        }

        $expected = array_map(static fn (PromptFragmentKey $key): string => $key->value, PromptFragmentKey::cases());
        $given = array_map('strval', array_keys($bodies));
        $missing = array_values(array_diff($expected, $given));
        $unknown = array_values(array_diff($given, $expected));

        if ($missing !== [] || $unknown !== []) {
            throw PromptTemplateUnresolvableException::keysIncomplete($set->label, $locale, $missing, $unknown);
        }

        $contract = new PromptFragmentContract;
        $violations = [];

        foreach (PromptFragmentKey::cases() as $key) {
            array_push($violations, ...$contract->violations($key, $bodies[$key->value]));
        }

        if ($violations !== []) {
            throw PromptTemplateUnresolvableException::contractViolated($set->label, $locale, $violations);
        }

        return $bodies;
    }

    /**
     * The unique indexes make a duplicate impossible; this keeps the resolver from
     * silently picking one if that guarantee were ever lost.
     *
     * @param  list<array{key: string, locale: string, body: string}>  $fragmentRows
     * @param  list<array{role_code: string|null, competency_code: string, locale: string, body: string}>  $overrideRows
     *
     * @throws PromptTemplateUnresolvableException
     */
    private function assertNoDuplicates(ConversationPromptSet $set, array $fragmentRows, array $overrideRows): void
    {
        $seen = [];
        foreach ($fragmentRows as $row) {
            $identity = json_encode([$row['key'], $row['locale']], JSON_THROW_ON_ERROR);

            if (isset($seen[$identity])) {
                throw PromptTemplateUnresolvableException::duplicateRow($set->label, $row['locale'], "fragment [{$row['key']}]");
            }

            $seen[$identity] = true;
        }

        $seen = [];
        foreach ($overrideRows as $row) {
            $identity = json_encode([$row['role_code'], $row['competency_code'], $row['locale']], JSON_THROW_ON_ERROR);

            if (isset($seen[$identity])) {
                throw PromptTemplateUnresolvableException::duplicateRow(
                    $set->label,
                    $row['locale'],
                    sprintf('the override for competency [%s] and %s', $row['competency_code'], $row['role_code'] === null ? 'no role' : "role [{$row['role_code']}]"),
                );
            }

            $seen[$identity] = true;
        }
    }

    /**
     * The one override that applies: a role-specific row beats the role-less one,
     * they are never combined, and a null role only ever sees role-less rows.
     *
     * @param  list<array{role_code: string|null, competency_code: string, body: string}>  $overrides
     */
    private function overrideFor(ConversationPromptSet $set, string $locale, string $competencyCode, ?string $roleCode, array $overrides): ?string
    {
        $roleLess = null;
        $roleSpecific = null;

        foreach ($overrides as $row) {
            if ($row['competency_code'] !== $competencyCode) {
                continue;
            }

            if ($row['role_code'] === null) {
                $roleLess = $row['body'];
            } elseif ($row['role_code'] === $roleCode) {
                $roleSpecific = $row['body'];
            }
        }

        $body = $roleSpecific ?? $roleLess;

        if ($body === null) {
            return null;
        }

        $violations = (new PromptFragmentContract)->overrideViolations($body);

        if ($violations !== []) {
            throw PromptTemplateUnresolvableException::overrideInvalid($set->label, $locale, $competencyCode, $violations);
        }

        return $body;
    }
}
