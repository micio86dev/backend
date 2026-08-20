<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\InterviewSession;
use App\Services\Provider\HeygenProvider;
use App\Services\Provider\ProviderSessionService;
use App\Services\Provider\QuestionContext;
use App\Services\Provider\TavusProvider;
use Illuminate\Console\Command;
use Throwable;

/**
 * Gated live smoke check against the REAL HeyGen LiveAvatar / Tavus API
 * (design D10, layer L3 — the ONLY layer that can prove acceptance).
 *
 * L1 (`ProviderContractFixtureTest`) proves our PARSING is correct against a
 * docs-verified fixture. L2 (the golden-body test in the same file) proves our
 * OUTBOUND body has not drifted since a human verified it. NEITHER can prove
 * the real provider actually ACCEPTS the request — only a live call with a real
 * API key can. Per design D11's stated risk: if LiveAvatar REQUIRES
 * `opening_text`, only this check (run manually, by a human, with a real key)
 * can confirm the HeyGen wire correction (PR2/PR3) actually ends the production
 * outage — do not guess.
 *
 * Deliberately NOT wired into any Pest test and NEVER run in CI: it costs real
 * provider credits/quota on every invocation and needs a real API key, neither
 * of which belongs in `php artisan test --parallel`. Refuses to run unless BOTH
 * `INTERVIEW_SMOKE_ENABLED=true` AND a real API key are present — mirrors
 * `StorageSelfTestCommand`'s gating pattern (object-storage-fix D4).
 *
 * Usage (never in CI, run where the credentials actually live):
 *   INTERVIEW_SMOKE_ENABLED=true php artisan interview:smoke-check --provider=heygen
 *   INTERVIEW_SMOKE_ENABLED=true php artisan interview:smoke-check --provider=tavus
 *
 * REQ: ProviderSmokeCheck (PR4 task 4.10 — design D10 L3)
 */
final class ProviderSmokeCheck extends Command
{
    protected $signature = 'interview:smoke-check {--provider=heygen : heygen|tavus}';

    protected $description = 'Gated live smoke check: issue() + teardown() against the REAL provider API — never run in CI, costs real credits';

    public function handle(): int
    {
        if (! (bool) config('interview.smoke_enabled', false)) {
            $this->error('Refusing to run: INTERVIEW_SMOKE_ENABLED is not true. This check costs real provider credits/quota and must be run deliberately, never in CI.');

            return self::FAILURE;
        }

        $providerName = (string) $this->option('provider');

        if (! in_array($providerName, ['heygen', 'tavus'], true)) {
            $this->error("Unknown --provider=[{$providerName}]. Expected heygen|tavus.");

            return self::FAILURE;
        }

        $apiKey = (string) config("interview.{$providerName}.api_key", '');

        if ($apiKey === '') {
            $this->error("Refusing to run: no API key configured for provider [{$providerName}] (config('interview.{$providerName}.api_key') is empty).");

            return self::FAILURE;
        }

        /** @var ProviderSessionService $service */
        $service = match ($providerName) {
            'heygen' => app(HeygenProvider::class),
            'tavus' => app(TavusProvider::class),
        };

        $this->line("interview:smoke-check — provider=[{$providerName}] — issuing a REAL session against the live API...");

        $session = $this->fakeSession($providerName);
        $ctx = new QuestionContext(
            competencyCode: 'SMOKE',
            questionIndex: 0,
            systemPrompt: 'You are an automated smoke-check session. Say hello and nothing else.',
            promptVersion: 'smoke-check',
            openingText: 'This is an automated smoke check. Please disregard.',
        );

        try {
            $token = $service->issue($session, $ctx);
            $this->info("issue(): OK — provider_session_ref=[{$token->provider_session_ref}]");
        } catch (Throwable $e) {
            $this->error('issue(): FAILED — '.$e->getMessage());

            return self::FAILURE;
        }

        try {
            $service->teardown($token);
            $this->info('teardown(): OK');
        } catch (Throwable $e) {
            // Non-fatal — the acceptance claim already stands on issue() succeeding.
            $this->error('teardown(): FAILED (non-fatal, best-effort) — '.$e->getMessage());
        }

        $this->info("interview:smoke-check PASSED — the [{$providerName}] outbound request shape was ACCEPTED by the real API.");

        return self::SUCCESS;
    }

    /**
     * An unsaved, in-memory session — never persisted (no DB row needed;
     * `issue()` only makes HTTP calls, it never queries the session itself).
     */
    private function fakeSession(string $providerName): InterviewSession
    {
        $session = new InterviewSession;
        $session->forceFill([
            'id' => 999_999_999,
            'organization_id' => 0,
            'participant_id' => 0,
            'project_id' => 0,
            'question_index' => 0,
            'competency_code' => 'SMOKE',
            'framework_version_id' => 0,
            'provider' => $providerName,
            'provider_session_ref' => null,
            'status' => 'pending',
        ]);

        return $session;
    }
}
