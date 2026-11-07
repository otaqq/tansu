<?php

declare(strict_types=1);

namespace Otaqq\Tansu\Content;

/**
 * What the Optimizer did: the bytes to store, whether they were made smaller, and when an asked-for optimization
 * was not done, why (`animated`, `format_unsupported`, `gd_missing`, `memory`) with details for the log.
 */
final class OptimizeResult
{
    /**
     * @param array<string, int> $context width, height and, for `memory`, the estimated and the free memory in bytes
     */
    public function __construct(
        public readonly string $bytes,
        public readonly bool $optimized = false,
        public readonly ?string $skipped = null,
        public readonly array $context = [],
    ) {
    }
}
