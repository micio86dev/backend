<?php

declare(strict_types=1);

namespace App\Support\AvatarTemplates;

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
     * @return list<array{key: string, code: string}>
     */
    public static function validate(string $provider, array $config): array
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

            if (! $present) {
                if ($field->required) {
                    $errors[] = ['key' => $field->key, 'code' => 'required'];
                }

                // Absent means "use the provider default", which is correct for
                // a template that only wants to pin an avatar. A null arrives
                // when a form field is cleared; treating it as a type violation
                // would make "unset this knob" impossible through the UI.
                continue;
            }

            $error = self::checkValue($field, $config[$field->key]);

            if ($error !== null) {
                $errors[] = ['key' => $field->key, 'code' => $error];
            }
        }

        return $errors;
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
