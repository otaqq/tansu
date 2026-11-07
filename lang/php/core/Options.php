<?php

declare(strict_types=1);

namespace Otaqq\Tansu;

/**
 * Settings that are the same for every owner: accepted types, default limits, whether metadata is stripped,
 * and the image optimization offered to the person uploading.
 */
final class Options
{
    public function __construct(
        public readonly FileTypes $types = new FileTypes(),
        public readonly Limits $limits = new Limits(5 * 1024 * 1024),
        public readonly bool $stripMetadata = true,
        public readonly OptimizeOptions $optimize = new OptimizeOptions(),
    ) {
    }
}
