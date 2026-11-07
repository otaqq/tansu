<?php

declare(strict_types=1);

namespace Otaqq\Tansu\Content;

/**
 * The result of inspecting an upload: its media type and the cleaned bytes to store.
 */
final class Inspection
{
    public function __construct(
        public readonly string $type,
        public readonly string $bytes,
    ) {
    }
}
