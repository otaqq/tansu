<?php

declare(strict_types=1);

namespace Otaqq\Tansu;

use InvalidArgumentException;

/**
 * The file types tansu knows, and the extension each is stored with.
 */
final class FileTypes
{
    public const SVG = 'image/svg+xml';

    /** @var array<string, list<string>> media type => extensions, the first is the one files are stored with */
    private const KNOWN = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/gif' => ['gif'],
        'image/webp' => ['webp'],
        self::SVG => ['svg'],
    ];

    /** @var list<string> */
    public const DEFAULT = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /**
     * @param list<string> $accepted media types uploads may have
     */
    public function __construct(public readonly array $accepted = self::DEFAULT)
    {
        foreach ($accepted as $type) {
            if (!isset(self::KNOWN[$type])) {
                throw new InvalidArgumentException("Unknown file type: \"$type\"");
            }
        }
    }

    public function accepts(string $type): bool
    {
        return in_array($type, $this->accepted, true);
    }

    public static function extension(string $type): string
    {
        return self::KNOWN[$type][0];
    }

    /**
     * The media type of a stored file, from its extension (stored files always have the extension of their content).
     */
    public static function typeOf(string $name): ?string
    {
        [, $extension] = Name::split($name);
        $extension = strtolower(ltrim($extension, '.'));
        foreach (self::KNOWN as $type => $extensions) {
            if (in_array($extension, $extensions, true)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * @return list<array{type: string, extensions: list<string>}>
     */
    public function toArray(): array
    {
        return array_map(fn (string $type) => ['type' => $type, 'extensions' => self::KNOWN[$type]], $this->accepted);
    }
}
