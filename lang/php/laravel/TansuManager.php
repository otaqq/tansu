<?php

declare(strict_types=1);

namespace Otaqq\Tansu\Laravel;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use InvalidArgumentException;

/**
 * Builds a Tansu per configured area.
 *
 * An app may run more than one library from the same package: config `tansu.areas` is a map of area name to a config
 * that overrides the top-level one (merged at every depth), so each area has its own disk, accepted types, owner
 * resolver, limits and routes. Without `tansu.areas` there is only the default area, which is the top-level config
 * itself; nothing changes for apps that configure a single library.
 */
final class TansuManager
{
    /** Name of the area that is the top-level config (the only area when `tansu.areas` is unset). */
    public const DEFAULT = 'default';

    /** @var array<string, Tansu> built areas, by name */
    private array $built = [];

    /**
     * @param  array<string, mixed>  $config  the "tansu" configuration
     */
    public function __construct(
        private readonly Container $container,
        private readonly Filesystems $filesystems,
        private readonly array $config,
    ) {
    }

    /**
     * The Tansu for an area. null or "default" is the top-level config; any other name must be a key of `tansu.areas`.
     */
    public function area(?string $name = null): Tansu
    {
        $name ??= self::DEFAULT;

        return $this->built[$name] ??= new Tansu($this->container, $this->filesystems, $this->configFor($name));
    }

    /**
     * Area names in order: "default" first, then the keys of `tansu.areas`.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return [self::DEFAULT, ...array_keys($this->areas())];
    }

    /**
     * The routes of each area, by name (for registering the HTTP endpoints once per area).
     *
     * @return array<string, array<string, mixed>>
     */
    public function routes(): array
    {
        $routes = [];
        foreach ($this->names() as $name) {
            $routes[$name] = $this->configFor($name)['routes'];
        }

        return $routes;
    }

    /**
     * @return array<string, mixed>
     */
    private function configFor(string $name): array
    {
        $base = $this->config;
        unset($base['areas']);
        if ($name === self::DEFAULT) {
            return $base;
        }

        $override = $this->areas()[$name] ?? null;
        if (! is_array($override)) {
            throw new InvalidArgumentException("Unknown tansu area: {$name}");
        }

        return TansuServiceProvider::mergeConfig($base, $override);
    }

    /**
     * @return array<string, mixed>
     */
    private function areas(): array
    {
        $areas = $this->config['areas'] ?? [];

        return is_array($areas) ? $areas : [];
    }
}
