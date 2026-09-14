<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ConversationLlm\ResyncCredentialBindings;
use App\Models\LlmCredential;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Re-push every template bound to a rotated credential, off the request.
 *
 * A JOB, NOT A CONTROLLER BRANCH, and the reason is the change that put it
 * here. Credentials became PLATFORM rows (RATIFIED 2026-09-14), so `N` stopped
 * meaning "one organization's templates" and started meaning "every tenant's".
 * Each HeyGen template can cost two 10-second vendor calls (`PATCH` → 404 →
 * `POST`), so a rotation with enough bound templates and one slow vendor runs
 * a PATCH request into `max_execution_time` or the gateway timeout.
 *
 * And a timeout there is not merely slow: the templates the sweep never
 * reached are never stamped `failed`, so they go on reading
 * `llm_sync_status = 'synced'` — which `LlmBindingResolver::resolveStatus()`
 * reports as `Applied`, the one state its docblock calls BILLABLE — against a
 * key the vendor no longer honours. That is the exact defect this whole change
 * exists to end, arriving through the tail of a list instead of through a
 * provider filter.
 *
 * NO TenantContextScope, DELIBERATELY. The sweep is cross-tenant by
 * construction: one platform credential serves every organization, so a
 * rotation must reach every organization's bound templates. There is no single
 * org id this job could open a scope for, and opening one would reproduce the
 * stranding it exists to prevent. Named in
 * `tests/Arch/Tenancy/QueuedJobTenantContextArchTest.php` with that reasoning.
 *
 * Takes the ID, not the model. `SerializesModels` would re-resolve an
 * `LlmCredential` through Eloquent on unserialize, and a credential deleted
 * between dispatch and execution would make the job fail rather than simply
 * find nothing to do — a rotation is exactly the moment someone might also be
 * tidying up.
 */
final class ResyncCredentialBindingsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $credentialId) {}

    /**
     * Declared explicitly, never inherited: a job that declares nothing takes
     * whatever `--tries` the worker was started with
     * (QueuedJobRetryOwnershipArchTest).
     *
     * Retrying is SAFE because every push is idempotent — `TavusPalSync`
     * re-sends the whole `/layers` node and `ensureConfiguration()` PATCHes an
     * existing configuration or creates one — so a second attempt re-pushes
     * templates that already landed rather than duplicating anything.
     *
     * Three, matching the mail jobs, because what is being retried is the same
     * shape of failure: a vendor having a bad minute. Beyond that the rows are
     * already stamped `failed` and an operator can see them, which is a better
     * outcome than a worker retrying for an hour against an account that is
     * genuinely down.
     */
    public function tries(): int
    {
        return 3;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    /**
     * Generous, and deliberately so — this is the one job here whose duration
     * scales with the ESTATE rather than with one message.
     *
     * A single HeyGen template can cost two 10-second vendor calls (`PATCH` →
     * 404 → `POST`), and the sweep now spans every tenant bound to the
     * credential. 300s covers a realistic estate against a slow vendor; past
     * that, the run is not slow but broken, and failing lets the retry above
     * start from a clean attempt. Whatever it did reach is already stamped,
     * so a timeout never leaves a row claiming `synced` falsely — which is the
     * property that made moving this off the request necessary.
     *
     * A PROPERTY, never a `timeout()` method, and that is a framework fact
     * rather than a style choice.
     *
     * `Queue::createObjectPayload()` builds `'timeout'` from
     * `getAttributeValue($job, Timeout::class, 'timeout')`, and
     * `ReadsClassAttributes::getAttributeValue()` reads a PROPERTY or a
     * `#[Timeout]` attribute — it has no `method_exists` branch. `tries` and
     * `backoff` DO have one (`Queue.php:234` and `:251`), which is exactly what
     * makes this easy to get wrong: three neighbours, two different contracts.
     *
     * As a method it was dead code. The worker fell through to
     * `config('queue.runtime.worker_timeout')`, so the budget argued below
     * never executed and `config/queue.php`'s
     * `max(declared job timeout) < worker_timeout` invariant was vacuous.
     */
    public int $timeout = 300;

    public function handle(): void
    {
        $credential = LlmCredential::find($this->credentialId);

        if ($credential === null) {
            // Deleted between dispatch and execution. Nothing to re-push, and
            // not an error: the bindings went with it.
            return;
        }

        $result = app(ResyncCredentialBindings::class)->run($credential);

        // The action never throws — both provider paths document a
        // NEVER-THROWS contract — so a warning is the only signal there is,
        // and every row that did not land is already stamped `failed` by
        // `ResyncTemplateBinding`. This line is what makes the aggregate
        // visible to an operator reading logs rather than templates.
        if ($result['status'] === 'warning') {
            Log::warning('Credential rotation left bound templates unsynced', [
                'credential_id' => $this->credentialId,
                'message' => $result['message'] ?? 'llm_config_failed',
            ]);
        }
    }
}
