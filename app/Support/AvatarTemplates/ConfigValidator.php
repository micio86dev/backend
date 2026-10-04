<?php

declare(strict_types=1);

namespace App\Support\AvatarTemplates;

use Illuminate\Support\Str;

/**
 * Validates a template's config against its provider's field spec (C14).
 *
 * Returns EVERY problem it finds rather than the first. Reporting one error per
 * round trip turns filling in a seventeen-field form into a guessing game.
 *
 * The error codes are distinguished — `required`, `type`, `range`, `enum`,
 * `unknown` — because each needs different words in front of an operator, and a
 * single generic "invalid" leaves them guessing which.
 */
final class ConfigValidator
{
    /**
     * @param  array<string, mixed>  $config
     * @param  bool  $superadmin  true only when the caller is a superadmin (cost control: an
     *                            external voice is a paid vendor call): a superadmin-only field
     *                            from anyone else is refused (`superadmin_only`), never
     *                            silently stored. The page it is written from does not matter.
     * @return list<array{key: string, code: string}>
     */
    public static function validate(string $provider, array $config, bool $superadmin = false): array
    {
        $fields = ProviderFieldSpecs::for($provider);

        if ($fields === []) {
            return [];
        }

        /** @var array<string, FieldSpec> $byKey */
        $byKey = [];

        foreach ($fields as $field) {
            $byKey[$field->key] = $field;
        }

        $errors = [];

        // Unknown keys first. The column is schemaless, so anything not in the
        // spec would be stored happily and never sent anywhere — an operator
        // who mistypes a field name deserves to be told, not to spend an
        // afternoon wondering why their setting has no effect.
        foreach (array_keys($config) as $key) {
            if (! isset($byKey[$key])) {
                $errors[] = ['key' => (string) $key, 'code' => 'unknown'];
            }
        }

        foreach ($fields as $field) {
            $present = array_key_exists($field->key, $config) && $config[$field->key] !== null;

            $superseded = self::isSuperseded($field, $config);

            if ($superseded && $present) {
                $errors[] = ['key' => $field->key, 'code' => 'superseded_by_'.Str::snake((string) $field->supersededByKey)];

                continue;
            }

            if (! $present) {
                if ($field->required && ! $superseded) {
                    $errors[] = ['key' => $field->key, 'code' => 'required'];
                }

                // Absent means "use the provider default", which is correct for
                // a template that only wants to pin an avatar. A null arrives
                // when a form field is cleared; treating it as a type violation
                // would make "unset this knob" impossible through the UI.
                continue;
            }

            $error = ($field->superadminOnly && ! $superadmin ? 'superadmin_only' : null)
                ?? self::checkValue($field, $config[$field->key])
                ?? self::checkDependentOption($field, $config)
                ?? self::checkEngineSupport($field, $config);

            if ($error !== null) {
                $errors[] = ['key' => $field->key, 'code' => $error];
            }
        }

        return $errors;
    }

    /**
     * The field's governing field holds a value that replaces it.
     *
     * @param  array<string, mixed>  $config
     */
    private static function isSuperseded(FieldSpec $field, array $config): bool
    {
        if ($field->supersededByKey === null || $field->supersededByValues === null) {
            return false;
        }

        $governing = $config[$field->supersededByKey] ?? null;

        return is_string($governing) && in_array($governing, $field->supersededByValues, true);
    }

    /**
     * A HeyGen voice knob the chosen external engine's settings object does not
     * have: accepted and stored it would never be sent.
     *
     * @param  array<string, mixed>  $config
     */
    private static function checkEngineSupport(FieldSpec $field, array $config): ?string
    {
        $engine = $config['ttsEngine'] ?? null;

        if (! is_string($engine)) {
            return null;
        }

        return in_array($field->key, ProviderFieldSpecs::HEYGEN_ENGINE_UNSUPPORTED_KNOBS[$engine] ?? [], true)
            ? 'tts_setting_unsupported'
            : null;
    }

    /**
     * A select whose options depend on another field: the value must belong to
     * the list its governing field's CURRENT value allows. A governing value
     * with no list (`azure`, `tavus-auto`, or unset) allows none, so a model
     * with no engine that takes one is refused rather than saved and never sent.
     *
     * @param  array<string, mixed>  $config
     */
    private static function checkDependentOption(FieldSpec $field, array $config): ?string
    {
        if ($field->optionsDependOn === null || $field->optionsByValue === null) {
            return null;
        }

        $governing = $config[$field->optionsDependOn] ?? null;
        $allowed = is_string($governing) ? ($field->optionsByValue[$governing] ?? []) : [];

        return in_array($config[$field->key], $allowed, true) ? null : 'tts_model_engine_mismatch';
    }

    private static function checkValue(FieldSpec $field, mixed $value): ?string
    {
        // Exhaustive over the enum, with no default arm. A default here would
        // be unreachable today and would silently accept a new field type
        // tomorrow — the match is the thing that forces a decision when one is
        // added.
        return match ($field->type) {
            FieldType::Text => is_string($value) ? null : 'type',
            FieldType::Checkbox => is_bool($value) ? null : 'type',
            FieldType::Select => self::checkSelect($field, $value),
            FieldType::Number => self::checkNumber($field, $value),
        };
    }

    private static function checkSelect(FieldSpec $field, mixed $value): ?string
    {
        if (! is_string($value)) {
            return 'type';
        }

        return in_array($value, $field->options ?? [], true) ? null : 'enum';
    }

    private static function checkNumber(FieldSpec $field, mixed $value): ?string
    {
        // is_bool is excluded explicitly: PHP considers true to be numeric in
        // enough contexts that a checkbox value landing in a number field would
        // otherwise pass and then be sent to the provider as 1.
        if (is_bool($value) || ! is_int($value) && ! is_float($value)) {
            return 'type';
        }

        // An integer where a float is expected is fine — JSON gives 1 for 1.0,
        // and rejecting it would mean a config that validated on save fails on
        // read, after the template is already live.
        if ($field->min !== null && $value < $field->min) {
            return 'range';
        }

        if ($field->max !== null && $value > $field->max) {
            return 'range';
        }

        return null;
    }
}
