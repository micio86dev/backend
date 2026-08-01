<?php

declare(strict_types=1);

namespace Tests\Fixtures\Queue;

use App\Support\Tenancy\TenantContextScope;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * Fixture for the CI Tier 2 real-worker verification
 * (tests/Feature/Queue/RealWorkerRedisDriverTest.php) — deliberately NOT a
 * production job (lives under tests/Fixtures/, not app/Jobs/, so it is
 * excluded from PR1's retry-ownership arch guard, PR1's config-invariant
 * discovery, and the production Docker image's autoloader, none of which
 * this fixture should be subject to).
 *
 * Records TWO cache entries to make TenantContextScope::runFor()'s effect
 * observable from the dispatching test process:
 *   - "before": TenantResolver's orgId BEFORE runFor() — proves
 *     Queue::before really did reset ambient tenant context to null for
 *     this queued job (documented behavior — see
 *     App\Jobs\ScoreEvaluationJob's class doc — re-verified here under the
 *     REAL redis driver, not sync).
 *   - "during": TenantResolver's orgId INSIDE the runFor() closure — proves
 *     TenantContextScope actually established it.
 */
final class TenancyProofJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        private readonly int $organizationId,
        private readonly string $cacheKeyPrefix,
    ) {}

    public function handle(): void
    {
        $orgIdBefore = app(TenantResolver::class)->getOrgId();
        Cache::put("{$this->cacheKeyPrefix}:before", $orgIdBefore ?? 'null', 60);

        TenantContextScope::runFor($this->organizationId, function (): void {
            $orgIdDuring = app(TenantResolver::class)->getOrgId();
            Cache::put("{$this->cacheKeyPrefix}:during", $orgIdDuring, 60);
        });
    }
}
