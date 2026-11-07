<?php

declare(strict_types=1);

namespace Otaqq\Tansu\Laravel;

use Illuminate\Support\ServiceProvider;

/**
 * Registers the configuration, the routes and the publishable UI files.
 */
final class TansuServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/tansu.php', 'tansu');
        $this->app->singleton(Tansu::class, fn ($app) => new Tansu($app, $app['filesystem'], $app['config']['tansu']));
    }

    public function boot(): void
    {
        if ($this->app['config']['tansu.routes.enabled'] && !$this->app->routesAreCached()) {
            $this->loadRoutesFrom(__DIR__.'/routes.php');
        }
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/config/tansu.php' => config_path('tansu.php')], 'tansu-config');
            $this->publishes([__DIR__.'/../../../ui/src' => public_path('vendor/tansu')], ['tansu-assets', 'laravel-assets']);
        }
    }
}
