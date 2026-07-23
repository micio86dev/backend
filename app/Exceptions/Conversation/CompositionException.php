<?php

declare(strict_types=1);

namespace App\Exceptions\Conversation;

/**
 * Thrown when SystemPromptComposer cannot produce a valid system prompt.
 *
 * Distinct from AnchorTranslationMissingException (Scoring domain):
 *   - CompositionException covers structural failures: empty indicator set,
 *     unresolvable role, or any pre-composition guard that is NOT a missing
 *     i18n translation.
 *   - AnchorTranslationMissingException covers per-field locale gaps (reused
 *     from the Scoring domain per M-2 / PromptBuilder.php:72-73 semantics).
 *
 * Both map to HTTP 422 at the controller layer; neither creates a provider
 * session nor flips the participant state.
 *
 * REQ: CompositionException (C8 Phase 3 — task 3.8)
 */
final class CompositionException extends \RuntimeException {}
