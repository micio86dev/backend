<?php

namespace App\Providers;

use App\Contracts\LLMProvider;
use App\Testing\FakeLLMProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // D36: Bind FakeLLMProvider for APP_ENV=testing.
        // All standard tests use this fake — zero HTTP requests to external AI APIs.
        // Real provider implementations are bound in C8 for non-test environments.
        // @ai-group tests (ai-integration.yml) override this binding with a real provider.
        if ($this->app->environment('testing')) {
            $this->app->bind(LLMProvider::class, FakeLLMProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
