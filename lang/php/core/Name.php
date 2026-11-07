<?php

declare(strict_types=1);

namespace Otaqq\Tansu;

use Normalizer;

/**
 * Normalizes folder and file names sent by clients (see protocol/README.md, "Names").
 */
final class Name
{
    private const MAX_BYTES = 255;

    /**
     * @throws TansuException invalid_name
     */
    public static function folder(mixed $name): string
    {
        $name = self::clean($name);

        return self::finish(self::truncate($name, ''));
    }

    /**
     * A file name with the given extension. Any extension in $name is replaced.
     *
     * @throws TansuException invalid_name
     */
    public static function file(mixed $name, string $extension): string
    {
        $base = self::clean($name);
        $dot = strrpos($base, '.');
        if ($dot !== false && $dot > 0) {
            $base = rtrim(substr($base, 0, $dot), '.- ');
        }
        $suffix = '.'.strtolower($extension);

        return self::finish(self::truncate($base, $suffix)).$suffix;
    }

    /**
     * Splits "photo.jpg" into ["photo", ".jpg"]; a name without a dot has an empty extension.
     *
     * @return array{0: string, 1: string}
     */
    public static function split(string $name): array
    {
        $dot = strrpos($name, '.');
        if ($dot === false || $dot === 0) {
            return [$name, ''];
        }

        return [substr($name, 0, $dot), substr($name, $dot)];
    }

    private static function clean(mixed $name): string
    {
        if (!is_string($name) || !mb_check_encoding($name, 'UTF-8')) {
            throw TansuException::invalidName();
        }
        $name = Normalizer::normalize($name, Normalizer::FORM_C);
        if ($name === false) {
            throw TansuException::invalidName();
        }
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $name = preg_replace('#[/\\\\:*?"<>|\s]+#u', '-', (string) $name);
        $name = preg_replace('/-{2,}/', '-', (string) $name);

        return trim((string) $name, '.- ');
    }

    private static function truncate(string $base, string $suffix): string
    {
        $room = self::MAX_BYTES - strlen($suffix);
        if (strlen($base) > $room) {
            $base = mb_strcut($base, 0, $room, 'UTF-8');
        }

        return trim($base, '.- ');
    }

    private static function finish(string $name): string
    {
        if ($name === '' || !Path::isValidSegment($name)) {
            throw TansuException::invalidName();
        }

        return $name;
    }
}
