<?php

declare(strict_types=1);

namespace Otaqq\Tansu\Laravel;

use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the configuration, the routes and the publishable UI files.
 *
 * The app's config/tansu.php is merged over the package defaults at every depth, so an app writes only what it
 * changes (Laravel's mergeConfigFrom would replace nested arrays whole).
 */
final class TansuServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (!($this->app instanceof CachesConfiguration && $this->app->configurationIsCached())) {
            $config = $this->app['config'];
            $config->set('tansu', self::mergeConfig(require __DIR__.'/config/tansu.php', (array) $config->get('tansu', [])));
        }
        $this->app->singleton(Tansu::class, fn ($app) => new Tansu($app, $app['filesystem'], $app['config']['tansu']));
    }

    /**
     * The app's values over the defaults: maps are merged key by key at every depth; lists and other values replace.
     *
     * @param array<mixed> $defaults
     * @param array<mixed> $app
     * @return array<mixed>
     */
    public static function mergeConfig(array $defaults, array $app): array
    {
        foreach ($app as $key => $value) {
            $default = $defaults[$key] ?? null;
            $defaults[$key] = is_array($value) && is_array($default) && !array_is_list($value) && !array_is_list($default)
                ? self::mergeConfig($default, $value)
                : $value;
        }

        return $defaults;
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
