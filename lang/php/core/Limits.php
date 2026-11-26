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
     * @param int|array<string, int> $fileSize bytes, or bytes by group. A group missing from the map gets the
     *                                         largest size in it
     * @param list<string>           $groups   the group names a per-group map may use (default: the built-in groups;
     *                                         pass a FileTypes' groupNames() when custom groups are configured)
     */
    public function __construct(
        int|array $fileSize,
        public readonly ?int $totalSize = null,
        public readonly ?int $files = null,
        array $groups = FileTypes::DEFAULT_GROUPS,
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
            if (!in_array($group, $groups, true)) {
                throw new InvalidArgumentException("Unknown file group: \"$group\"");
            }
        }
        $this->fileSizes = array_map('intval', $fileSize);
        $this->fileSize = max($this->fileSizes);
    }

    /**
     * The largest upload for a group.
     */
    public function fileSizeFor(string $group): int
    {
        return $this->fileSizes[$group] ?? $this->fileSize;
    }

    /**
     * @return array{file_size: int, file_sizes: ?array<string, int>, total_size: ?int, files: ?int}
     */
    public function toArray(): array
    {
        return ['file_size' => $this->fileSize, 'file_sizes' => $this->fileSizes, 'total_size' => $this->totalSize, 'files' => $this->files];
    }
}
