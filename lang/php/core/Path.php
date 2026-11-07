<?php

declare(strict_types=1);

namespace Otaqq\Tansu;

/**
 * Paths relative to an owner's root, as the protocol defines them: "/" or "/a/b", no empty, "." or ".." segments,
 * no hidden segments, no backslashes or control characters.
 */
final class Path
{
    public const ROOT = '/';

    private const MAX_SEGMENT_BYTES = 255;

    /**
     * Checks a path from a request and returns it in canonical form (a trailing "/" is dropped).
     *
     * @throws TansuException invalid_path
     */
    public static function parse(mixed $path): string
    {
        if (!is_string($path) || $path === '' || $path[0] !== '/' || !mb_check_encoding($path, 'UTF-8')) {
            throw TansuException::invalidPath();
        }
        if ($path === self::ROOT) {
            return self::ROOT;
        }
        $path = rtrim($path, '/');
        foreach (explode('/', substr($path, 1)) as $segment) {
            if (!self::isValidSegment($segment)) {
                throw TansuException::invalidPath();
            }
        }

        return $path;
    }

    public static function isValidSegment(string $segment): bool
    {
        return $segment !== ''
            && $segment[0] !== '.'
            && strlen($segment) <= self::MAX_SEGMENT_BYTES
            && !str_contains($segment, '\\')
            && !str_contains($segment, '/')
            && preg_match('/[\x00-\x1F\x7F]/', $segment) === 0;
    }

    public static function join(string $folder, string $name): string
    {
        return ($folder === self::ROOT ? '' : $folder).'/'.$name;
    }

    public static function parent(string $path): string
    {
        $parent = substr($path, 0, (int) strrpos($path, '/'));

        return $parent === '' ? self::ROOT : $parent;
    }

    public static function name(string $path): string
    {
        return substr($path, (int) strrpos($path, '/') + 1);
    }

    /**
     * True when $path is $folder itself or inside it.
     */
    public static function isWithin(string $path, string $folder): bool
    {
        return $folder === self::ROOT || $path === $folder || str_starts_with($path, $folder.'/');
    }
}
