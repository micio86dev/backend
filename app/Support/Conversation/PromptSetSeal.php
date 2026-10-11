<?php

declare(strict_types=1);

namespace App\Support\Conversation;

use InvalidArgumentException;

/**
 * The tamper seal of a prompt set (db-driven-conversation-prompts, design N-5).
 *
 * `conversation_prompt_sets.content_sha256` is computed here BEFORE the set is
 * inserted (the immutability trigger forbids a later UPDATE of the column) and
 * recomputed by the resolver from the loaded rows, so any change to a stored
 * body, key, locale or override is detected.
 *
 * Canonical form, byte for byte:
 *
 *     {"fragments":[{"key":K,"locale":L,"body":B},...],
 *      "overrides":[{"role_code":R|null,"competency_code":C,"locale":L,"body":B},...]}
 *
 * - no whitespace, object keys in exactly the order above, whatever order or
 *   extra columns the caller's rows carry;
 * - `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR`,
 *   so text is hashed as UTF-8 and invalid UTF-8 throws instead of being sealed;
 * - fragments sorted by (key, locale), overrides by (role_code, competency_code,
 *   locale), every comparison a byte-wise `strcmp`, never locale-aware;
 * - a NULL `role_code` (applies to every role) sorts BEFORE every string and is
 *   encoded as JSON `null`, so it differs from the empty string `""`.
 *
 * Pure: no IO, no clock, no config.
 */
final class PromptSetSeal
{
    /**
     * SHA-256 (lowercase hex) of {@see canonicalJson()}.
     *
     * @param  list<array<string, mixed>>  $fragments  Rows with `key`, `locale`, `body`.
     * @param  list<array<string, mixed>>  $overrides  Rows with `role_code` (nullable), `competency_code`, `locale`, `body`.
     *
     * @throws InvalidArgumentException When a row lacks a field or a field has the wrong type.
     * @throws \JsonException When a value is not valid UTF-8.
     */
    public static function seal(array $fragments, array $overrides = []): string
    {
        return hash('sha256', self::canonicalJson($fragments, $overrides));
    }

    /**
     * The canonical JSON the hash is taken over.
     *
     * @param  list<array<string, mixed>>  $fragments
     * @param  list<array<string, mixed>>  $overrides
     *
     * @throws InvalidArgumentException
     * @throws \JsonException
     */
    public static function canonicalJson(array $fragments, array $overrides = []): string
    {
        $fragmentRows = [];

        foreach ($fragments as $row) {
            $fragmentRows[] = [
                'key' => self::string($row, 'key'),
                'locale' => self::string($row, 'locale'),
                'body' => self::string($row, 'body'),
            ];
        }

        $overrideRows = [];

        foreach ($overrides as $row) {
            $role = $row['role_code'] ?? null;

            if ($role !== null && ! is_string($role)) {
                throw new InvalidArgumentException('PromptSetSeal: field [role_code] must be a string or null.');
            }

            $overrideRows[] = [
                'role_code' => $role,
                'competency_code' => self::string($row, 'competency_code'),
                'locale' => self::string($row, 'locale'),
                'body' => self::string($row, 'body'),
            ];
        }

        usort($fragmentRows, static fn (array $a, array $b): int => strcmp($a['key'], $b['key']) ?: strcmp($a['locale'], $b['locale']));
        usort($overrideRows, static fn (array $a, array $b): int => (($a['role_code'] !== null) <=> ($b['role_code'] !== null))
            ?: strcmp((string) $a['role_code'], (string) $b['role_code'])
            ?: strcmp($a['competency_code'], $b['competency_code'])
            ?: strcmp($a['locale'], $b['locale']));

        return json_encode(
            ['fragments' => $fragmentRows, 'overrides' => $overrideRows],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;

        if (! is_string($value)) {
            throw new InvalidArgumentException("PromptSetSeal: field [{$field}] must be a string.");
        }

        return $value;
    }
}
