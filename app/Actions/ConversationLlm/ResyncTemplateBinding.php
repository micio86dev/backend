<?php

declare(strict_types=1);

namespace App\Actions\ConversationLlm;

use App\Models\AvatarTemplate;
use App\Services\ConversationLlm\HeygenLlmRegistrar;
use App\Support\AvatarTemplates\TavusPalSync;

/**
 * Push ONE template's binding to its provider and stamp the outcome on the row.
 *
 * Extracted from `AvatarTemplateController::recordSync()`, which was the only
 * place in the codebase that translated a provider sync result into
 * `llm_sync_status`. That made every OTHER path that re-pushes a binding a
 * path that silently left the column stale — and `LlmBindingResolver::
 * resolveStatus()` reads `'synced'` as `Applied`, the one state its docblock
 * calls BILLABLE. A row that lies about that is not a cosmetic defect.
 *
 * Credential rotation was exactly such a path, and it was worse than stale: it
 * lived inside `HeygenLlmRegistrar`, so it re-pushed HeyGen templates and left
 * every TAVUS template bound to the same credential pointing at a key the
 * vendor no longer honours. `TavusPalSync` puts `api_key` ON THE WIRE and
 * Tavus does not retain it across PATCHes, so nothing recovered those until a
 * human happened to open and save the template.
 *
 * One owner for "sync a template and record what happened", reached by every
 * caller that needs it.
 */
final class ResyncTemplateBinding
{
    /**
     * @return array{status: 'skipped'|'synced'|'warning', message?: string}
     */
    public function run(AvatarTemplate $template): array
    {
        $result = match ($template->provider) {
            'tavus' => app(TavusPalSync::class)->sync($template),
            'heygen' => app(HeygenLlmRegistrar::class)->ensureConfiguration($template),
            default => ['status' => 'skipped'],
        };

        // Only a provider with a sync path gets a stamp, and this tests the
        // PROVIDER rather than the result. Reading `status === 'skipped'`
        // instead looks like the same question and is not: `TavusPalSync`
        // returns `skipped` for a template whose layers are empty, and
        // `ensureConfiguration()` returns it for an unbound one — both of which
        // MUST still be stamped (`not_required`), because that is how a
        // template that was just unbound stops reading `synced`.
        //
        // Carried over verbatim from `AvatarTemplateController::recordSync()`,
        // whose behaviour this extraction must not change.
        if (! in_array($template->provider, ['tavus', 'heygen'], true)) {
            return $result;
        }

        $isBound = $template->llm_model_id !== null && $template->llm_credential_id !== null;

        // saveQuietly() — NOT save() — IS A RE-ENTRANCY GUARD, NOT A STYLE
        // CHOICE. A plain save() re-fires the `saving` event, which re-runs
        // AvatarTemplate::booted()'s I2/I3/I4 invariants on a binding that
        // already passed them for this same request, and dispatches `saved`
        // observers a second time for a write that is bookkeeping ABOUT a sync,
        // not a save a user made. Do NOT "tidy" this to save() in a future
        // refactor.
        $template->forceFill([
            'llm_sync_status' => $result['status'] === 'synced'
                ? 'synced'
                : ($isBound ? 'failed' : 'not_required'),
            'llm_synced_at' => $result['status'] === 'synced' ? now() : null,
        ])->saveQuietly();

        return $result;
    }
}
