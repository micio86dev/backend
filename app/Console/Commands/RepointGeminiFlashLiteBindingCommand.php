<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ConversationLlm\ResyncTemplateBinding;
use App\Models\AvatarTemplate;
use App\Models\LlmModel;
use App\Models\Organization;
use App\Support\Logging\SafeDbContext;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `php artisan beai:repoint-gemini-flash-lite [--dry-run]`
 * (fix/heygen-gemini-flash-lite — production ONE-OFF, run once after deploy).
 *
 * THE GAP THIS CLOSES. `gemini-3-flash-preview` is a THINKING model, and
 * HeyGen's turn-based FULL-mode custom-LLM path has no room for a thinking
 * pass between a candidate's turn ending and the avatar's next line — the
 * session stalls on the model's own first real turn (the opening line is
 * composed server-side and never touches this model at all, so it always
 * plays before the stall is visible). `LlmModelRegistrySeeder` adding
 * `gemini-3.1-flash-lite-preview` to the catalog only makes the replacement
 * SELECTABLE; it does nothing for an `avatar_templates` row an operator
 * already bound to the old one — production template id 7, per the original
 * diagnosis, chief among them.
 *
 * WHY A COMMAND, NOT A PLAIN MIGRATION. Repointing `llm_model_id` is a
 * one-column update, but making that change take effect on the vendor side
 * needs `ResyncTemplateBinding` — the same action every other binding
 * change goes through — to push the new model to HeyGen (or Tavus) and stamp
 * `llm_sync_status`. A schema migration has no business making an outbound
 * HTTP call, which is the same reasoning `beai:backfill-project-questions`
 * documents for staying a command.
 *
 * SCOPE: every `avatar_templates` row across every tenant currently bound to
 * the old model — HeyGen or Tavus alike. `llm_model_id` is not
 * provider-specific, and a Tavus template pinned to the same thinking model
 * deserves the same fix.
 *
 * PER-ORGANIZATION, NOT A FLAT `withoutGlobalScopes()` SWEEP. Unlike
 * `ResyncCredentialBindings` — which resolves rows by `llm_credential_id`, a
 * PLATFORM row with no single owning organization to attribute the sweep to
 * — every `avatar_templates` row here has its own `organization_id`, so each
 * repoint can and must run under ITS row's own tenant context. This command
 * lists every organization (`Organization::withoutGlobalScopes()`, a read —
 * Organization carries no tenant scope of its own to strip, but the platform
 * roster itself spans every tenant by definition) and, for each one, runs the
 * repoint inside `TenantContextScope::runFor($organization->id, …)`. Every
 * `AvatarTemplate` query inside that closure is then ORDINARILY tenant-scoped
 * — no `withoutGlobalScopes()` on `AvatarTemplate` anywhere in this command —
 * so a write for organization A can never resolve or touch organization B's
 * row. Safe against a soft-deleted row regardless: `AvatarTemplate::
 * booted()`'s `deleted` hook already nulls `llm_model_id` on soft delete, so
 * a trashed template can never match the `WHERE llm_model_id = …` filter
 * below whether or not the soft-delete scope is applied.
 *
 * IDEMPOTENT AND SAFE TO RE-RUN. `chunkById` re-queries `WHERE llm_model_id
 * = <old id>` on every page, and a row this command already repointed no
 * longer matches that filter — a second run finds nothing bound to the old
 * model and is a no-op. A row that failed to sync keeps `llm_sync_status =
 * 'failed'` and its NEW `llm_model_id`, so re-running does not repoint it
 * again but `ResyncTemplateBinding` is idempotent to re-push, which is why
 * a failed sync should be retried directly rather than via this command —
 * see the printed guidance below.
 *
 * `--dry-run` NEVER calls the vendor. Unlike `beai:backfill-project-
 * questions`'s transaction-then-rollback preview, `ResyncTemplateBinding`
 * makes a REAL outbound HTTP call — a DB rollback cannot undo a PATCH
 * HeyGen already received. A dry run therefore only lists which templates
 * WOULD be repointed, with no write and no vendor call at all.
 */
final class RepointGeminiFlashLiteBindingCommand extends Command
{
    private const OLD_MODEL_KEY = 'gemini-3-flash-preview';

    private const NEW_MODEL_KEY = 'gemini-3.1-flash-lite-preview';

    protected $signature = 'beai:repoint-gemini-flash-lite
        {--dry-run : List the templates that would be repointed — writes nothing and calls no vendor}';

    protected $description = 'Repoint every avatar_templates row bound to gemini-3-flash-preview to gemini-3.1-flash-lite-preview and re-sync it to its provider (fix/heygen-gemini-flash-lite)';

    public function handle(ResyncTemplateBinding $resyncTemplateBinding): int
    {
        $newModel = LlmModel::where('key', self::NEW_MODEL_KEY)->first();

        if ($newModel === null) {
            $this->error(sprintf(
                "Target model '%s' is not in the registry. Run `beai:sync-llm-registry` first.",
                self::NEW_MODEL_KEY,
            ));

            return self::FAILURE;
        }

        $oldModel = LlmModel::where('key', self::OLD_MODEL_KEY)->first();

        if ($oldModel === null) {
            $this->info(sprintf("No '%s' model in the registry. Nothing to repoint.", self::OLD_MODEL_KEY));

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $count = 0;
        $synced = 0;
        $needsRetry = 0;
        $syncThrew = 0;
        $saveThrew = 0;

        $organizations = Organization::withoutGlobalScopes()->orderBy('id')->get();

        foreach ($organizations as $organization) {
            TenantContextScope::runFor($organization->id, function () use (
                $oldModel,
                $newModel,
                $dryRun,
                $resyncTemplateBinding,
                &$count,
                &$synced,
                &$needsRetry,
                &$syncThrew,
                &$saveThrew,
            ): void {
                // Ordinarily tenant-scoped — TenantContextScope::runFor()
                // above already established this organization, so
                // TenantScoped's global scope filters this query to exactly
                // this organization's own rows. No withoutGlobalScopes()
                // here: a row belonging to a DIFFERENT organization can never
                // enter this closure.
                AvatarTemplate::where('llm_model_id', $oldModel->id)
                    ->chunkById(100, function ($templates) use (
                        $dryRun,
                        $newModel,
                        $resyncTemplateBinding,
                        &$count,
                        &$synced,
                        &$needsRetry,
                        &$syncThrew,
                        &$saveThrew,
                    ): void {
                        foreach ($templates as $template) {
                            $count++;

                            if ($dryRun) {
                                $this->line(sprintf(
                                    '[dry-run] would repoint template %d (%s, provider=%s, org=%d) to %s',
                                    $template->id,
                                    $template->name,
                                    $template->provider,
                                    $template->organization_id,
                                    self::NEW_MODEL_KEY,
                                ));

                                continue;
                            }

                            // Wrapped for the same reason the sync push below is:
                            // `AvatarTemplate::booted()`'s `saving` hook re-validates
                            // I1-I5 on EVERY save, not only a fresh bind, so a row
                            // whose credential vendor drifted since it was originally
                            // bound (I4), or whose target model became unavailable
                            // between this command's own registry check and this
                            // exact write (I5), throws here — and an unhandled throw
                            // would abort the sweep mid-loop, same class of bug as
                            // the uncaught `run()` this fix is modelled on. Since the
                            // save never happened, there is nothing new in the
                            // database and nothing new to push: `run()` is not
                            // attempted for this template.
                            try {
                                $template->llm_model_id = $newModel->id;
                                $template->save();
                            } catch (Throwable $e) {
                                $saveThrew++;
                                Log::error('RepointGeminiFlashLiteBindingCommand: template save threw', [
                                    'template_id' => $template->id,
                                    'organization_id' => $template->organization_id,
                                    ...SafeDbContext::for($e),
                                ]);
                                $this->warn(sprintf(
                                    'template %d (%s) could not be saved to %s (%s) — it stays bound to %s and nothing was pushed to its provider',
                                    $template->id,
                                    $template->name,
                                    self::NEW_MODEL_KEY,
                                    $e::class,
                                    self::OLD_MODEL_KEY,
                                ));

                                continue;
                            }

                            // `run()` pushes to HeyGen or Tavus and is NOT wrapped
                            // above: a thrown exception here (a network fault, or an
                            // unguarded DB write inside ensureConfiguration()/
                            // ensureSecret() — see their own docblocks) must never
                            // abort the sweep mid-loop. The row's `llm_model_id` is
                            // already saved and stays authoritative regardless of
                            // what the vendor push does; only the PUSH is retried by
                            // saving the template again, never this command.
                            //
                            // For a HeyGen template, `run()` can reach HeyGen up to
                            // TWICE in the same call — `ensureConfiguration()` calls
                            // `ensureSecret()` (POST /v1/secrets) then
                            // `createOrUpdateConfiguration()` (PATCH or POST
                            // /v1/llm-configurations). This is not a duplicate push:
                            // they are two DIFFERENT vendor resources, and the secret
                            // must exist before its id can be referenced in the
                            // configuration body. `ensureSecret()` also short-circuits
                            // with NO HTTP call whenever the credential already has a
                            // `heygen_secret_id` — the normal case for an
                            // already-bound credential like the one this repoint
                            // reuses — so a repoint of an already-configured template
                            // makes exactly ONE HeyGen call (the PATCH). No
                            // `AvatarTemplate` model hook (`booted()`'s `saving`
                            // handler above only validates I1-I5; no `saved` hook, no
                            // observer, no event listener) dispatches a vendor push
                            // itself, so `$template->save()` above never causes a
                            // second push either.
                            try {
                                $result = $resyncTemplateBinding->run($template);
                            } catch (Throwable $e) {
                                $syncThrew++;
                                Log::error('RepointGeminiFlashLiteBindingCommand: sync push threw', [
                                    'template_id' => $template->id,
                                    'organization_id' => $template->organization_id,
                                    ...SafeDbContext::for($e),
                                ]);

                                // llm_model_id is already saved to the NEW model, but
                                // the push that would confirm it on the vendor side
                                // never completed. Leaving llm_sync_status as-is would
                                // let a PRIOR 'synced' value — recorded when this row
                                // was still bound to the OLD model — survive unchanged
                                // and now misdescribe the vendor state of a binding it
                                // never confirmed. Stamped to the same "needs a retry"
                                // value `ResyncTemplateBinding::run()` itself uses for
                                // a non-throwing vendor failure on a bound template
                                // (never 'not_required': every row reaching here has a
                                // non-null llm_model_id/llm_credential_id). A plain
                                // column stamp, so `saveQuietly()` — not `save()` — for
                                // the same re-entrancy reason `ResyncTemplateBinding::
                                // run()` uses it: re-firing `saving` would re-run I1-I5
                                // on a binding that already passed them moments ago.
                                //
                                // This write is itself unguarded risk: it runs after
                                // the sync push already threw, so a second failure here
                                // (a DB connection blip between the earlier successful
                                // $template->save() and this one) must not propagate —
                                // that would abort the sweep mid-loop from inside a
                                // catch block, the exact bug class this command was
                                // hardened against one level up. Caught, logged, and
                                // folded into the SAME sync-push failure below: it is
                                // still one failed-sync event for this template, just
                                // with an extra detail that the status stamp itself
                                // also failed, so no separate counter is added.
                                $statusStampFailed = false;

                                try {
                                    $template->forceFill(['llm_sync_status' => 'failed'])->saveQuietly();
                                } catch (Throwable $inner) {
                                    $statusStampFailed = true;
                                    Log::error('RepointGeminiFlashLiteBindingCommand: failed-status stamp threw', [
                                        'template_id' => $template->id,
                                        'organization_id' => $template->organization_id,
                                        ...SafeDbContext::for($inner),
                                    ]);
                                }

                                $this->warn(sprintf(
                                    'repointed template %d (%s) to %s in the database, but the sync push threw (%s) — llm_model_id is saved; llm_sync_status is now "failed" to reflect the unconfirmed vendor state; saving the template again (or a credential rotation) retries the push%s',
                                    $template->id,
                                    $template->name,
                                    self::NEW_MODEL_KEY,
                                    $e::class,
                                    $statusStampFailed
                                        ? ' — the status stamp itself also failed; llm_sync_status may still read a stale value'
                                        : '',
                                ));

                                continue;
                            }

                            if ($result['status'] === 'synced') {
                                $synced++;
                                $this->info(sprintf('repointed template %d (%s) — synced', $template->id, $template->name));
                            } else {
                                $needsRetry++;
                                $this->warn(sprintf(
                                    'repointed template %d (%s) to %s in the database, but the vendor push %s (%s) — llm_sync_status is "failed"; saving the template again (or a credential rotation) retries the push',
                                    $template->id,
                                    $template->name,
                                    self::NEW_MODEL_KEY,
                                    $result['status'],
                                    $result['message'] ?? 'no message',
                                ));
                            }
                        }
                    });
            });
        }

        if ($count === 0) {
            $this->info(sprintf("No avatar_templates row is bound to '%s'. Nothing to repoint.", self::OLD_MODEL_KEY));

            return self::SUCCESS;
        }

        $this->newLine();

        if ($dryRun) {
            $this->info(sprintf('Would repoint %d template(s).', $count));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Repointed %d template(s): %d synced, %d need a retry, %d threw during the sync push, %d could not be saved and are still on %s.',
            $count,
            $synced,
            $needsRetry,
            $syncThrew,
            $saveThrew,
            self::OLD_MODEL_KEY,
        ));

        return ($needsRetry > 0 || $syncThrew > 0 || $saveThrew > 0) ? self::FAILURE : self::SUCCESS;
    }
}
