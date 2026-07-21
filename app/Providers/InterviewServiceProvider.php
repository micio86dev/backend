<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Provider\HeygenProvider;
use App\Services\Provider\ProviderSessionService;
use App\Services\Provider\TavusProvider;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the concrete ProviderSessionService implementation.
 *
 * Resolution order (per design FIX-6):
 *   1. project->provider_override (per-project override, if set in the current request context)
 *   2. config('interview.provider') (env INTERVIEW_PROVIDER, default 'heygen')
 *
 * The binding is contextual — resolved fresh per-request via a closure so that
 * per-project overrides (set on the Eloquent model) can be read at the point of injection.
 *
 * Currently supported providers: 'heygen', 'tavus'.
 *
 * REQ: ProviderSessionService binding (C7a Phase 7.7)
 */
class InterviewServiceProvider extends ServiceProvider
{
    /**
     * Register ProviderSessionService binding.
     *
     * The closure is deferred to resolution time so `request()` and `auth()` are
     * available when the service is first requested (not during boot).
     */
    public function register(): void
    {
        $this->app->bind(ProviderSessionService::class, function (): ProviderSessionService {
            // Per-project override (FIX-6: column is 'provider_override', not 'provider')
            $projectOverride = null;

            // Attempt to read provider_override from the authenticated candidate's project.
            // This is a best-effort lookup; if not available, fall back to global config.
            try {
                $participant = auth('api-candidate')->user();
                if ($participant !== null) {
                    $project         = $participant->project;
                    $projectOverride = $project?->provider_override;
                }
            } catch (\Throwable) {
                // Not in a candidate request context — fall back to global config.
            }

            $providerName = $projectOverride ?? config('interview.provider', 'heygen');

            return match ($providerName) {
                'tavus'  => new TavusProvider,
                default  => new HeygenProvider, // 'heygen' is the default
            };
        });
    }

    public function boot(): void {}
}
