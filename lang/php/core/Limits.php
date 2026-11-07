<?php

declare(strict_types=1);

namespace Otaqq\Tansu;

/**
 * Upload limits: the largest single file, and the owner's quota (null means no limit).
 */
final class Limits
{
    public function __construct(
        public readonly int $fileSize,
        public readonly ?int $totalSize = null,
        public readonly ?int $files = null,
    ) {
    }

    /**
     * @return array{file_size: int, total_size: ?int, files: ?int}
     */
    public function toArray(): array
    {
        return ['file_size' => $this->fileSize, 'total_size' => $this->totalSize, 'files' => $this->files];
    }
}
