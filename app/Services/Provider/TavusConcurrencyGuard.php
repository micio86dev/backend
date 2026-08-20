<?php

declare(strict_types=1);

namespace App\Services\Provider;

use App\Support\Provider\ProviderErrorMessage;
use Illuminate\Http\Client\Response;

/**
 * Retries a Tavus conversation-create call before it surfaces as a 429 to the
 * candidate, but ONLY when the rejection is specifically a concurrency-limit
 * error (design D8, PR6).
 *
 * PORTED FROM legacy-demo (`@wire-source
 * legacy-demo/src/pages/api/interview/start.ts:270-361`): retry up to
 * `interview.tavus.concurrency_retries` times, `interview.tavus.
 * concurrency_backoff_ms` apart, ONLY when the provider's own diagnostic
 * message matches the concurrency-limit pattern. Config-driven so it is
 * settable to zero (disables retrying entirely) without a deploy.
 *
 * DELIBERATELY NOT PORTED — DO NOT RESTORE THIS: legacy-demo's
 * `endActiveTavusConversations()` (`start.ts:280-298`) best-effort reaped
 * EVERY active conversation on the Tavus account via `GET
 * /v2/conversations?status=active` + `POST .../end` on each, to make room for
 * the new one. legacy-demo was single-tenant, so that was safe there. BEAI is
 * multi-tenant (CLAUDE.md tenant-isolation constraint): the same "self-heal"
 * would terminate another tenant's in-flight interview to make room for this
 * one — cross-tenant data destruction wearing a resilience feature's
 * clothes. There is no "list active conversations" call anywhere in this
 * class, and there must never be one. See
 * `tests/Unit/C7a/TavusConcurrencyGuardTest.php`'s grep-based negative test.
 */
final class TavusConcurrencyGuard
{
    private const CONCURRENCY_PATTERN = '/maximum concurrent conversations/i';

    /**
     * Invoke `$createCall` once, then retry ONLY on a concurrency-limit
     * rejection, up to the configured retry count.
     *
     * @param  callable(): Response  $createCall  Performs ONE `POST /v2/conversations` attempt.
     *                                            Must be idempotent-safe to call repeatedly.
     * @return Response the last response received (successful, or the final
     *                  still-rejected response once retries are exhausted)
     */
    public function attempt(callable $createCall, string $apiKey): Response
    {
        $retries = max(0, (int) config('interview.tavus.concurrency_retries', 3));
        $backoffMs = max(0, (int) config('interview.tavus.concurrency_backoff_ms', 2000));

        $response = $createCall();

        for ($attempt = 0; $attempt < $retries && $this->isConcurrencyLimited($response, $apiKey); $attempt++) {
            if ($backoffMs > 0) {
                usleep($backoffMs * 1000);
            }

            $response = $createCall();
        }

        return $response;
    }

    /**
     * Whether `$response` is specifically a Tavus concurrency-limit rejection
     * — a 4xx (never 5xx, never a success) whose provider message matches the
     * demo's own pattern. Any other failure (malformed body, auth, 5xx) is
     * NOT a concurrency case and must not be retried by this guard.
     */
    public function isConcurrencyLimited(Response $response, string $apiKey): bool
    {
        if ($response->successful() || $response->status() >= 500) {
            return false;
        }

        $message = ProviderErrorMessage::extract($response->json(), $apiKey);

        return $message !== null && preg_match(self::CONCURRENCY_PATTERN, $message) === 1;
    }
}
