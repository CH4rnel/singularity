<?php

namespace App\Providers;

use App\Services\Ai\Providers\AiProviderRegistry;
use App\Services\TonApiService;
use App\Support\Environment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use URL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // TonApiService takes scalar config (endpoint + key), so the container
        // can't autowire it — build it from the bridge chain config.
        $this->app->singleton(TonApiService::class, fn () => new TonApiService(
            (string) (config('bridge.chains.ton.api_url') ?: 'https://tonapi.io'),
            config('bridge.chains.ton.api_key') ?: null,
        ));

        // One registry per request, so the catalogue and the gateway ask the
        // same instances about the same keys. Scoped rather than a singleton:
        // providers read config when they are built, and a test that rewrites
        // `ai.providers.*` must be able to see its own change.
        $this->app->scoped(AiProviderRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (Environment::isProduction()) {
            URL::forceHttps();
        }

        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
