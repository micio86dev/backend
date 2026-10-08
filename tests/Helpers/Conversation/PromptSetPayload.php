<?php

declare(strict_types=1);

namespace Tests\Helpers\Conversation;

use App\Support\Conversation\BaselinePromptFragments;

/**
 * Builds prompt set payloads from the code baseline for the publish, activate
 * and console tests (db-driven-conversation-prompts PR6b).
 */
final class PromptSetPayload
{
    /**
     * The `fragments` object of the JSON file: `{locale: {key: body}}`.
     *
     * @param  list<string>  $locales
     * @param  array<string, string>  $replace  key => body, applied to every locale
     * @return array<string, array<string, string>>
     */
    public static function byLocale(array $locales = ['en', 'it'], array $replace = []): array
    {
        $byLocale = [];

        foreach ($locales as $locale) {
            $byLocale[$locale] = array_replace(BaselinePromptFragments::forLocale('en'), $replace);
        }

        return $byLocale;
    }

    /**
     * The flat rows the publish action takes.
     *
     * @param  list<string>  $locales
     * @param  array<string, string>  $replace
     * @return list<array{key: string, locale: string, body: string}>
     */
    public static function fragments(array $locales = ['en', 'it'], array $replace = []): array
    {
        $rows = [];

        foreach (self::byLocale($locales, $replace) as $locale => $bodies) {
            foreach ($bodies as $key => $body) {
                $rows[] = ['key' => $key, 'locale' => $locale, 'body' => $body];
            }
        }

        return $rows;
    }

    /** @return array{role_code: string|null, competency_code: string, locale: string, body: string} */
    public static function override(?string $role, string $competency, string $locale, string $body): array
    {
        return ['role_code' => $role, 'competency_code' => $competency, 'locale' => $locale, 'body' => $body];
    }

    /**
     * Write the JSON file shape and return its path.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function writeFile(array $payload): string
    {
        $path = tempnam(sys_get_temp_dir(), 'prompt-set-');
        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return $path;
    }
}
