<?php

declare(strict_types=1);

namespace App\Support\AvatarTemplates;

use App\Models\AvatarTemplate;
use Illuminate\Support\Collection;

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
     * @return list<array{name: string, description: string|null, provider: string, config: array<string, mixed>, persona: array<string, mixed>|null}>
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
            ]];
        }

        // Multi-provider shape: `avatar-tester`'s.
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
            ];
        }

        return $records;
    }
}
