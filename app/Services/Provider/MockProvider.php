<?php

declare(strict_types=1);

namespace App\Services\Provider;

use App\Models\InterviewSession;
use Illuminate\Support\Str;

/**
 * MockProvider — SPEC.md §3.7 "Test mode": the avatar provider a
 * `beai_test_…` interview always runs on (public-api step 9).
 *
 * Makes NO outbound HTTP call to any real avatar vendor (T-TEST-001) —
 * `issue()`/`reconcileTranscript()`/`teardown()` are pure, local operations,
 * unlike `HeygenProvider`/`TavusProvider`, which both call out to their own
 * vendor API for every one of these three methods.
 *
 * `reconcileTranscript()` always returns `[]`: a mock session's transcript
 * is written directly by `App\Jobs\PublicApi\RunMockInterviewJob` (scripted
 * `Utterance` rows, never harvested from a provider), so there is nothing
 * for this method to fetch — `InterviewController::replaceUtterances()`/
 * `harvestOutgoingTranscript()` both already treat an empty array as a
 * legitimate no-op.
 *
 * `provider_session_ref` is a locally-generated opaque identifier (never a
 * real vendor session id) — good enough for the teardown/resume bookkeeping
 * `InterviewController` already does uniformly across every provider, and
 * never read by anything provider-specific since `teardown()` here is a
 * no-op.
 *
 * Routing to this class is decided in TWO places, deliberately kept in
 * agreement (see both call sites' own docblocks for why there are two):
 *   1. `InterviewController::resolveProvider()` — the ACTUAL routing point
 *      for every live request (`start()`/`suspend()`/`end()`), keyed off
 *      the `provider` name string forced to `'mock'` in `start()` for a
 *      `ApiKeyMode::Test` participant.
 *   2. `InterviewServiceProvider`'s contextual `ProviderSessionService::class`
 *      binding — consulted by `App\Console\Commands\ProviderSmokeCheck` and
 *      any future direct container resolution, defense-in-depth so nothing
 *      that resolves the interface directly can hand a test-mode candidate
 *      a real vendor provider either.
 *
 * REQ: SPEC.md §3.7 "Test mode" mock avatar provider (public-api step 9)
 */
final class MockProvider implements ProviderSessionService
{
    public function issue(InterviewSession $session, QuestionContext $ctx): ProviderToken
    {
        return new ProviderToken(
            provider: 'mock',
            token: null,
            conversation_url: null,
            provider_session_ref: 'mock_'.(string) Str::ulid(),
        );
    }

    public function reconcileTranscript(InterviewSession $session): array
    {
        return [];
    }

    public function teardown(ProviderToken $token): void
    {
        // No real vendor session exists to tear down — see class docblock.
    }
}
