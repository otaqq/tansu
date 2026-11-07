<?php

declare(strict_types=1);

namespace Otaqq\Tansu;

use InvalidArgumentException;

/**
 * The file types tansu knows, their groups, and the extension each is stored with.
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
        'application/pdf' => ['pdf'],
        'video/mp4' => ['mp4'],
        'video/webm' => ['webm'],
    ];

    /** @var array<string, list<string>> group => the types it stands for in `accept`. SVG is in none: it must be named */
    public const GROUPS = [
        'image' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        'document' => ['application/pdf'],
        'video' => ['video/mp4', 'video/webm'],
    ];

    /** @var list<string> groups accepted by default */
    public const DEFAULT_GROUPS = ['image', 'document', 'video'];

    /** @var list<string> media types accepted by default: every group, not SVG */
    public const DEFAULT = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'video/mp4', 'video/webm'];

    /** @var list<string> media types uploads may have */
    public readonly array $accepted;

    /**
     * @param list<string> $accepted group names and media types
     */
    public function __construct(array $accepted = self::DEFAULT)
    {
        $types = [];
        foreach ($accepted as $entry) {
            if (isset(self::GROUPS[$entry])) {
                array_push($types, ...self::GROUPS[$entry]);
            } elseif (isset(self::KNOWN[$entry])) {
                $types[] = $entry;
            } else {
                throw new InvalidArgumentException("Unknown file type or group: \"$entry\"");
            }
        }
        $this->accepted = array_values(array_unique($types));
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
     * The group of a known type; a type in no group (SVG) belongs to the group of its top-level type.
     */
    public static function groupOf(string $type): string
    {
        foreach (self::GROUPS as $group => $types) {
            if (in_array($type, $types, true)) {
                return $group;
            }
        }

        return match (strstr($type, '/', true)) {
            'video' => 'video',
            'image' => 'image',
            default => 'document',
        };
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
     * @return list<array{type: string, extensions: list<string>, group: string}>
     */
    public function toArray(): array
    {
        return array_map(
            fn (string $type) => ['type' => $type, 'extensions' => self::KNOWN[$type], 'group' => self::groupOf($type)],
            $this->accepted,
        );
    }
}
