<?php

declare(strict_types=1);

namespace App\Support\AvatarTemplates;

use App\Models\AvatarTemplate;
use Illuminate\Database\Eloquent\Collection;

/**
 * The portable avatar-template document (C14, design D6).
 *
 * Configuration is tuned in the sibling `quint-avatar-tester` project; this is
 * the shape it travels in.
 *
 * VERSIONED on purpose. An unversioned config blob forces the importer to
 * guess, and guessing is how a stale file silently produces a template pointing
 * at an avatar that no longer exists.
 *
 * REQ: Avatar template configuration is exportable and importable as JSON
 */
final class TemplateDocument
{
    public const SCHEMA = 'beai.avatar-template/1';

    /**
     * @param  Collection<int, AvatarTemplate>  $templates
     * @return array<string, mixed>
     */
    public static function export(Collection $templates): array
    {
        // Eager-loaded so a multi-template export is not an N+1 — the
        // collection here is exactly the set the caller is about to iterate.
        $templates->loadMissing(['llmModel:id,key', 'llmCredential:id,name']);

        return [
            'schema' => self::SCHEMA,
            'exported_at' => now()->toIso8601String(),
            'templates' => $templates->map(fn (AvatarTemplate $t): array => [
                'name' => $t->name,
                'description' => $t->description,
                'provider' => $t->provider,
                'config' => $t->config,
                // Persona is optional: a template may be pure provider config.
                'persona' => $t->persona ?? null,
                // The binding travels by NAME, never by id or key material
                // (design D13) — an id is meaningless in another org, and a
                // fingerprint is key-derived material with no import use.
                'llm' => $t->llm_model_id === null ? null : [
                    'model_key' => $t->llmModel?->key,
                    'credential_name' => $t->llmCredential?->name,
                ],
            ])->values()->all(),
        ];
    }

    /**
     * Flattens an entry into one record per provider (D9).
     *
     * An `avatar-tester` row carries `heygen_config` and `tavus_config`
     * together, while a BEAI template belongs to exactly one provider and that
     * provider is immutable after creation. Rejecting such a file would push
     * the job of splitting JSON by hand onto the operator; collapsing it would
     * lose a block.
     *
     * @param  array<string, mixed>  $entry
     * @return list<array{name: string, description: string|null, provider: string, config: array<string, mixed>, persona: array<string, mixed>|null, llm: array{model_key: string, credential_name: string}|null}>
     */
    public static function flatten(array $entry): array
    {
        $name = is_string($entry['name'] ?? null) ? $entry['name'] : '';
        $description = is_string($entry['description'] ?? null) ? $entry['description'] : null;
        $persona = is_array($entry['persona'] ?? null) ? $entry['persona'] : null;

        // Single-provider shape: BEAI's own export.
        if (isset($entry['provider'])) {
            return [[
                'name' => $name,
                'description' => $description,
                'provider' => is_string($entry['provider']) ? $entry['provider'] : '',
                'config' => is_array($entry['config'] ?? null) ? $entry['config'] : [],
                'persona' => $persona,
                'llm' => self::flattenLlm($entry['llm'] ?? null),
            ]];
        }

        // Multi-provider shape: `avatar-tester`'s. It has no concept of a
        // credential, so every flattened record here is unbound — correct
        // for a document from a tool that never had one.
        $records = [];

        foreach ((array) ($entry['configs'] ?? []) as $provider => $config) {
            if (! is_string($provider) || ! is_array($config)) {
                continue;
            }

            $records[] = [
                // The provider goes in the name because two templates that
                // differ only by provider are indistinguishable in a list.
                'name' => $name === '' ? $provider : "{$name} ({$provider})",
                'description' => $description,
                'provider' => $provider,
                'config' => $config,
                'persona' => $persona,
                'llm' => null,
            ];
        }

        return $records;
    }

    /**
     * @return array{model_key: string, credential_name: string}|null
     */
    private static function flattenLlm(mixed $llm): ?array
    {
        if (! is_array($llm)) {
            return null;
        }

        $modelKey = $llm['model_key'] ?? null;
        $credentialName = $llm['credential_name'] ?? null;

        if (! is_string($modelKey) || ! is_string($credentialName)) {
            return null;
        }

        return ['model_key' => $modelKey, 'credential_name' => $credentialName];
    }
}
