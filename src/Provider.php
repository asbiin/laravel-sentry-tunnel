<?php

namespace SentryTunnel;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * @psalm-suppress UnusedClass
 */
class Provider extends ServiceProvider
{
    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        $this->registerRoutes();
        $this->registerPublishing();
    }

    /**
     * Register the package routes.
     */
    private function registerRoutes(): void
    {
        Route::group($this->routeConfiguration(), function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        });
    }

    /**
     * Get the route group configuration array.
     */
    private function routeConfiguration(): array
    {
        return [
            'middleware' => config('sentry-tunnel.middleware', []),
            'domain' => config('sentry-tunnel.domain', null),
            'prefix' => config('sentry-tunnel.tunnel-url'),
        ];
    }

    /**
     * Register the package's publishable resources.
     */
    private function registerPublishing(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/sentry-tunnel.php' => config_path('sentry-tunnel.php'),
            ], 'sentry-tunnel-config');
        }
    }

    /**
     * Register any package services.
     */
    #[\Override]
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/sentry-tunnel.php', 'sentry-tunnel'
        );
    }
}
