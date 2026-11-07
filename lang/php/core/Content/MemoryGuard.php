<?php

declare(strict_types=1);

namespace Otaqq\Tansu\Content;

use Closure;
use InvalidArgumentException;

/**
 * Decides whether decoding an image is safe. Running out of memory is a fatal error in PHP and cannot be caught,
 * so the memory a decode needs is estimated first, multiplied by a margin (GD's real use varies with the format
 * and the build), and compared with the free part of memory_limit. Images with more pixels than a cap are never
 * decoded, even without a memory limit: a small PNG can stand for gigabytes of pixels.
 */
final class MemoryGuard
{
    private readonly Closure $freeMemory;

    /**
     * @param ?Closure(): ?int $freeMemory free bytes, null for no limit (default: from memory_limit)
     */
    public function __construct(
        public readonly float $margin = 2.0,
        ?Closure $freeMemory = null,
        public readonly int $maxPixels = 50_000_000,
    ) {
        if ($margin < 1.0) {
            throw new InvalidArgumentException('The memory margin must be at least 1.0');
        }
        if ($maxPixels < 1) {
            throw new InvalidArgumentException('max_pixels must be at least 1');
        }
        $this->freeMemory = $freeMemory ?? self::freeMemory(...);
    }

    /**
     * Null when the work fits; otherwise details for the log.
     *
     * @param int $bytes estimated bytes the work needs (before the margin)
     * @return ?array<string, int|null>
     */
    public function refuse(int $width, int $height, int $bytes): ?array
    {
        if ($width * $height > $this->maxPixels) {
            return ['max_pixels' => $this->maxPixels];
        }
        $estimated = (int) ceil($bytes * $this->margin);
        $free = ($this->freeMemory)();
        if ($free !== null && $estimated > $free) {
            return ['estimated_memory' => $estimated, 'free_memory' => $free];
        }

        return null;
    }

    /**
     * Free bytes under memory_limit, or null when there is no limit.
     */
    public static function freeMemory(): ?int
    {
        $limit = self::memoryLimit();

        return $limit === null ? null : max(0, $limit - memory_get_usage(true));
    }

    /**
     * memory_limit in bytes, or null for no limit (-1).
     */
    public static function memoryLimit(): ?int
    {
        $value = trim((string) ini_get('memory_limit'));
        if ($value === '' || $value === '-1') {
            return null;
        }
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
