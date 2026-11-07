<?php

declare(strict_types=1);

namespace Otaqq\Tansu;

/**
 * How one upload is to be made smaller: fit inside max_width × max_height (a null side does not limit) and/or
 * re-encode at a quality from 1 to 100 (JPEG and WebP only).
 */
final class Optimization
{
    public function __construct(
        public readonly ?int $maxWidth = null,
        public readonly ?int $maxHeight = null,
        public readonly ?int $quality = null,
    ) {
    }

    public function resizes(): bool
    {
        return $this->maxWidth !== null || $this->maxHeight !== null;
    }
}
