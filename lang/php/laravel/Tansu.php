<?php

declare(strict_types=1);

namespace Otaqq\Tansu\Laravel;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Otaqq\Tansu\Explorer;
use Otaqq\Tansu\FileTypes;
use Otaqq\Tansu\InUseChecker;
use Otaqq\Tansu\Limits;
use Otaqq\Tansu\NothingInUse;
use Otaqq\Tansu\OptimizeOptions;
use Otaqq\Tansu\Options;
use Otaqq\Tansu\Owner;
use Otaqq\Tansu\Path;
use Psr\Log\LoggerInterface;

/**
 * Builds Explorers from the configuration. Also for host code that needs tansu's view of storage,
 * for example to turn a path the picker sent into a disk location.
 */
final class Tansu
{
    /**
     * @param array<string, mixed> $config the "tansu" configuration
     */
    public function __construct(
        private readonly Container $container,
        private readonly Filesystems $filesystems,
        private readonly array $config,
    ) {
    }

    /**
     * The owner of a request, from the configured OwnerResolver.
     */
    public function owner(Request $request): Owner
    {
        $requested = $request->query('owner');

        return $this->resolver()->resolve($request, is_string($requested) && $requested !== '' ? $requested : null);
    }

    public function explorer(Owner $owner, ?string $requestedOwner = null): Explorer
    {
        return new Explorer(
            $this->disk()->getDriver(),
            $owner,
            fn (string $location, string $path) => $this->url($location, $path, $requestedOwner),
            $this->options(),
            $this->inUseChecker(),
            $this->logger(),
        );
    }

    public function disk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter */
        return $this->filesystems->disk($this->config['disk']);
    }

    /**
     * The location on the disk of a path relative to the owner's root, or null if the path is not valid.
     */
    public function location(Owner $owner, string $path): ?string
    {
        try {
            $path = Path::parse($path);
        } catch (\Otaqq\Tansu\TansuException) {
            return null;
        }

        return $path === Path::ROOT ? null : $owner->location($path);
    }

    public function options(): Options
    {
        $limits = $this->config['limits'];

        $fileSize = $limits['file_size'];

        return new Options(
            new FileTypes(array_values($this->config['accept'])),
            new Limits(is_array($fileSize) ? $fileSize : (int) $fileSize, $limits['total_size'] ?? null, $limits['files'] ?? null),
            (bool) $this->config['strip_metadata'],
            OptimizeOptions::fromArray($this->config['optimize'] ?? []),
        );
    }

    private function url(string $location, string $path, ?string $requestedOwner): string
    {
        if ($this->config['url'] === 'route') {
            return route($this->config['routes']['name'].'raw', array_filter(['path' => $path, 'owner' => $requestedOwner]));
        }

        return $this->disk()->url($location);
    }

    private function logger(): ?LoggerInterface
    {
        if (!$this->container->bound('log')) {
            return null;
        }

        return $this->container->make('log')->channel($this->config['optimize']['log_channel'] ?? null);
    }

    private function resolver(): OwnerResolver
    {
        return $this->container->make($this->config['owner_resolver']);
    }

    private function inUseChecker(): InUseChecker
    {
        $class = $this->config['in_use_checker'];

        return $class === null ? new NothingInUse() : $this->container->make($class);
    }
}
