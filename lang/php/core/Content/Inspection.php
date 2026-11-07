<?php

declare(strict_types=1);

namespace Otaqq\Tansu\Content;

/**
 * The result of inspecting an upload: its media type, the cleaned bytes to store and what optimizing did
 * (see OptimizeResult).
 */
final class Inspection
{
    public function __construct(
        public readonly string $type,
        public readonly string $bytes,
        public readonly ?OptimizeResult $optimization = null,
    ) {
    }
}
