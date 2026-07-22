<?php

declare(strict_types=1);

namespace App\Exceptions\Scoring;

/**
 * Thrown when any of the four translatable BARS indicator fields
 * (text, anchor_5, anchor_3, anchor_1) is missing a translation for
 * the project locale.
 *
 * Triggers unscorable_reason = 'anchor_translation_missing'.
 * No LLM call is made; no ai_requests row is produced.
 *
 * REQ: PromptBuilder L-2 hard-fail (C9 D6 FIX-11)
 */
final class AnchorTranslationMissingException extends \RuntimeException
{
    public function __construct(string $competencyCode, string $field = '', string $locale = '')
    {
        $detail = $field !== '' ? " (field: {$field}, locale: {$locale})" : '';
        parent::__construct("Anchor translation missing for competency [{$competencyCode}]{$detail}.");
    }
}
