<?php

declare(strict_types=1);

namespace App\Actions\ConversationLlm;

use App\Models\AvatarTemplate;
use App\Models\LlmCredential;

/**
 * Re-push EVERY template bound to a credential, across every provider.
 *
 * Runs after a credential's `api_key` changes. Both providers hold the key
 * remotely — HeyGen behind a `secret_id`, Tavus as a literal `api_key` on the
 * PAL — so a rotation that does not re-push leaves the vendor authenticating
 * with the OLD key while our row still reads `synced`.
 *
 * NO PROVIDER FILTER, and that is the fix rather than an oversight. This sweep
 * used to live inside `HeygenLlmRegistrar::rotateSecret()` behind
 * `->where('provider', 'heygen')`, so rotating a credential re-pushed HeyGen
 * templates and quietly stranded every Tavus one bound to the same credential.
 *
 * PLATFORM-WIDE, which is why the tenant scope is stripped: credentials became
 * platform rows (RATIFIED 2026-09-14), so one key serves every tenant and a
 * rotation has to reach every tenant's bound templates. Narrowing by
 * organization would leave the new key pushed for exactly one organization and
 * silently stale for the rest — the failure mode this class exists to end.
 * That strip is named in `tests/Arch/C11/AdminTenancySafetyArchTest.php`.
 *
 * Never throws: both provider paths document a NEVER-THROWS contract and
 * return a warning instead, and `ResyncTemplateBinding` records that warning on
 * the row. A rotation must not fail because a vendor is briefly unreachable —
 * it must leave evidence that the push did not land.
 */
final class ResyncCredentialBindings
{
    /**
     * @return array{status: 'synced'|'warning', message?: string}
     */
    public function run(LlmCredential $credential): array
    {
        $anyFailed = false;

        // CHUNKED, not `->get()`. This set spans every tenant by design, which
        // is the same fact that moved the sweep off the request — a list large
        // enough to blow a request budget is large enough not to hold in
        // memory all at once. `chunkById` and not `chunk`: the loop writes to
        // the rows it is paging over, and an OFFSET-based pager re-orders
        // under its own writes and silently skips rows.
        //
        // `$binder` is resolved ONCE rather than per row: the container lookup
        // is per-iteration cost for an object with no per-row state.
        $binder = app(ResyncTemplateBinding::class);

        AvatarTemplate::withoutGlobalScopes()
            ->where('llm_credential_id', $credential->id)
            ->chunkById(100, function ($templates) use ($binder, &$anyFailed): void {
                foreach ($templates as $template) {
                    $result = $binder->run($template);

                    // The aggregate agrees with the STAMP, and that alignment
                    // is the fix rather than a detail. `ResyncTemplateBinding`
                    // writes `failed` for a bound template on any non-`synced`
                    // result — `skipped` included, which is what a template
                    // whose `LlmModel` row has vanished produces. Counting only
                    // `warning` here meant the row said `failed` while the
                    // sweep reported `synced` and the job logged nothing: the
                    // one case where the only operator-facing signal left,
                    // now that this runs asynchronously, disagreed with the
                    // data.
                    $isBound = $template->llm_model_id !== null
                        && $template->llm_credential_id !== null;

                    if ($isBound && $result['status'] !== 'synced') {
                        $anyFailed = true;
                    }
                }
            });

        return $anyFailed
            ? ['status' => 'warning', 'message' => 'llm_config_failed']
            : ['status' => 'synced'];
    }
}
