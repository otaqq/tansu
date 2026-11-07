<?php

declare(strict_types=1);

namespace Otaqq\Tansu;

use InvalidArgumentException;

/**
 * Upload limits: the largest single file (one size, or a size per group), and the owner's quota (null means no limit).
 */
final class Limits
{
    /** The largest single upload of any type */
    public readonly int $fileSize;

    /** @var ?array<string, int> group => largest single upload, when given per group */
    public readonly ?array $fileSizes;

    /**
     * @param int|array<string, int> $fileSize bytes, or bytes by group (see FileTypes::GROUPS). A group missing
     *                                         from the map gets the largest size in it
     */
    public function __construct(
        int|array $fileSize,
        public readonly ?int $totalSize = null,
        public readonly ?int $files = null,
    ) {
        if (is_int($fileSize)) {
            $this->fileSize = $fileSize;
            $this->fileSizes = null;

            return;
        }
        if ($fileSize === []) {
            throw new InvalidArgumentException('file_size by group needs at least one group');
        }
        foreach ($fileSize as $group => $size) {
            if (!isset(FileTypes::GROUPS[$group])) {
                throw new InvalidArgumentException("Unknown file group: \"$group\"");
            }
        }
        $this->fileSizes = array_map('intval', $fileSize);
        $this->fileSize = max($this->fileSizes);
    }

    /**
     * The largest upload for a type.
     */
    public function fileSizeFor(string $type): int
    {
        return $this->fileSizes[FileTypes::groupOf($type)] ?? $this->fileSize;
    }

    /**
     * @return array{file_size: int, file_sizes: ?array<string, int>, total_size: ?int, files: ?int}
     */
    public function toArray(): array
    {
        return ['file_size' => $this->fileSize, 'file_sizes' => $this->fileSizes, 'total_size' => $this->totalSize, 'files' => $this->files];
    }
}
