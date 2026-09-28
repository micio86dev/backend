<?php

declare(strict_types=1);

namespace App\Services\Provider;

use App\Support\AvatarTemplates\ActiveTemplateResolver;
use App\Support\AvatarTemplates\TemplateReferenceValidator;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Everything an interview needs to be startable, checked BEFORE any provider
 * call.
 *
 * Without this, a template that names something the provider refuses only
 * fails at `POST /interview/start`, where Tavus/HeyGen answer a 4xx, the
 * controller classifies it as our own fault, and the candidate — who did
 * nothing wrong — receives a bare 500 `provider_error` while the participant
 * is left at a dead end. The operator who can fix it learns nothing. Here the
 * same problem is a 422 with a machine code, raised before a session exists,
 * with the detail logged server-side.
 *
 * Checks, per provider:
 *  - credentials: the platform key for the provider is configured
 *    (`provider_key_missing`);
 *  - identity: an avatar can be resolved from the pinned template or the
 *    platform default (`avatar_missing`, and `voice_missing` for HeyGen);
 *  - references: whatever the pinned template names actually exists at the
 *    provider and the pieces are compatible with each other
 *    (`TemplateReferenceValidator`: `avatar_not_found`, `voice_not_found`,
 *    `pal_not_found`, `tts_*`). Skipped when
 *    `interview.preflight.verify_references` is off — a kill switch, because a
 *    provider inventory that is wrong or incomplete would otherwise block every
 *    interview, and it is the only check that depends on a third party's data.
 *
 * NEVER returns provider content, keys or exception messages: results are
 * `{key, code}` pairs chosen by this codebase.
 */
final class ProviderPreflight
{
    public function __construct(
        private readonly ActiveTemplateResolver $templates,
    ) {}

    /**
     * @return list<array{key: string, code: string}> empty when the interview may proceed
     */
    public function check(string $provider, ?int $projectId): array
    {
        if (! in_array($provider, ['tavus', 'heygen'], true)) {
            // `mock` (test mode) and anything else has no external dependency.
            return [];
        }

        $templateConfig = $this->templateConfig($provider, $projectId);

        $errors = $provider === 'tavus'
            ? $this->tavus($templateConfig)
            : $this->heygen($templateConfig);

        if ($errors === [] && $templateConfig !== []) {
            $errors = TemplateReferenceValidator::validate($provider, $templateConfig);
        }

        if ($errors !== []) {
            Log::warning('Interview pre-flight refused the start', [
                'provider' => $provider,
                'project_id' => $projectId,
                'reasons' => $errors,
            ]);
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $templateConfig
     * @return list<array{key: string, code: string}>
     */
    private function tavus(array $templateConfig): array
    {
        $errors = [];

        if ($this->blank(config('interview.tavus.api_key'))) {
            $errors[] = ['key' => 'credentials', 'code' => 'provider_key_missing'];
        }

        // Tavus needs a face, given directly or via a PAL that has a default
        // one. The platform default (`interview.tavus.*`) is the floor a
        // template overrides key by key — same precedence as TavusProvider.
        $face = $templateConfig['faceId'] ?? config('interview.tavus.replica_id');
        $pal = $templateConfig['palId'] ?? config('interview.tavus.persona_id');

        if ($this->blank($face) && $this->blank($pal)) {
            $errors[] = ['key' => 'faceId', 'code' => 'avatar_missing'];
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $templateConfig
     * @return list<array{key: string, code: string}>
     */
    private function heygen(array $templateConfig): array
    {
        $errors = [];

        if ($this->blank(config('interview.heygen.api_key'))) {
            $errors[] = ['key' => 'credentials', 'code' => 'provider_key_missing'];
        }

        if ($this->blank($templateConfig['avatarId'] ?? config('interview.heygen.avatar_id'))) {
            $errors[] = ['key' => 'avatarId', 'code' => 'avatar_missing'];
        }

        if ($this->blank($templateConfig['voiceId'] ?? config('interview.heygen.voice_id'))) {
            $errors[] = ['key' => 'voiceId', 'code' => 'voice_missing'];
        }

        return $errors;
    }

    /**
     * The pinned (or active) template's config, or [] — resolution failures are
     * swallowed exactly as the providers swallow them, so pre-flight can never
     * be the thing that breaks an interview the providers would have started.
     *
     * @return array<string, mixed>
     */
    private function templateConfig(string $provider, ?int $projectId): array
    {
        try {
            $template = $this->templates->resolve($provider, $projectId);

            return $template === null ? [] : $template->config;
        } catch (Throwable) {
            return [];
        }
    }

    private function blank(mixed $value): bool
    {
        return ! is_string($value) || trim($value) === '';
    }
}
