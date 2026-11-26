<?php

declare(strict_types=1);

namespace Otaqq\Tansu;

use InvalidArgumentException;

/**
 * The file types tansu knows, their groups, the extension each is stored with, and how each is previewed.
 *
 * The built-in types are media (images, PDF, video). A host may register more through the constructor's
 * $custom argument, for example to accept the raw HTML, CSS and JavaScript of a static site.
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

    /** How a type is previewed in the explorer: inline image or video, an open-in-new-tab link, an in-place text editor, or nothing */
    public const PREVIEWS = ['image', 'video', 'link', 'text', 'none'];

    /** @var list<string> media types uploads may have */
    public readonly array $accepted;

    /** @var array<string, list<string>> every known type (built-in and custom) => its extensions */
    private readonly array $known;

    /** @var array<string, list<string>> every group (built-in and custom) => its types */
    private readonly array $groups;

    /** @var array<string, string> every known type => its preview mode */
    private readonly array $previews;

    /**
     * @param list<string>                                                                    $accepted group names and media types
     * @param array<string, array{extensions: list<string>, group: string, preview?: string}> $custom   extra types: media type =>
     *                                                                                                  its extensions, the group it joins
     *                                                                                                  (a new name is allowed) and its
     *                                                                                                  preview mode (default "link")
     */
    public function __construct(array $accepted = self::DEFAULT, array $custom = [])
    {
        [$this->known, $this->groups, $this->previews] = self::register($custom);

        $types = [];
        foreach ($accepted as $entry) {
            if (isset($this->groups[$entry])) {
                array_push($types, ...$this->groups[$entry]);
            } elseif (isset($this->known[$entry])) {
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

    /**
     * Whether a type is a built-in media type, told from its bytes. Custom types (text) have no signature and are
     * told from the upload's name instead.
     */
    public static function isSignatureType(string $type): bool
    {
        return isset(self::KNOWN[$type]);
    }

    /**
     * The extension a file of this type is stored with.
     */
    public function extension(string $type): string
    {
        return $this->known[$type][0];
    }

    /**
     * The group of a known type; a type in no explicit group belongs to the group of its top-level type.
     */
    public function groupOf(string $type): string
    {
        foreach ($this->groups as $group => $types) {
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
     * The preview mode of a known type (one of PREVIEWS); an unknown type is previewed by the mode of its group.
     */
    public function previewOf(string $type): string
    {
        return $this->previews[$type] ?? self::previewForGroup($this->groupOf($type));
    }

    /**
     * The media type of a stored file, from its extension (stored files always have the extension of their content).
     */
    public function typeOf(string $name): ?string
    {
        [, $extension] = Name::split($name);
        $extension = strtolower(ltrim($extension, '.'));
        foreach ($this->known as $type => $extensions) {
            if (in_array($extension, $extensions, true)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Every group name, built-in and custom (for validating per-group limits).
     *
     * @return list<string>
     */
    public function groupNames(): array
    {
        return array_keys($this->groups);
    }

    /**
     * @return list<array{type: string, extensions: list<string>, group: string, preview: string}>
     */
    public function toArray(): array
    {
        return array_map(
            fn (string $type) => [
                'type' => $type,
                'extensions' => $this->known[$type],
                'group' => $this->groupOf($type),
                'preview' => $this->previewOf($type),
            ],
            $this->accepted,
        );
    }

    /**
     * Merges the custom types into the built-in registries.
     *
     * @param array<string, array{extensions: list<string>, group: string, preview?: string}> $custom
     * @return array{0: array<string, list<string>>, 1: array<string, list<string>>, 2: array<string, string>}
     */
    private static function register(array $custom): array
    {
        $known = self::KNOWN;
        $groups = self::GROUPS;
        $previews = [];
        foreach (array_keys(self::KNOWN) as $type) {
            $previews[$type] = self::previewForGroup(self::builtinGroupOf($type));
        }

        foreach ($custom as $type => $spec) {
            if (!is_string($type) || $type === '' || !isset($spec['extensions'], $spec['group'])) {
                throw new InvalidArgumentException("Custom file type \"$type\" needs extensions and a group");
            }
            $preview = $spec['preview'] ?? 'link';
            if (!in_array($preview, self::PREVIEWS, true)) {
                throw new InvalidArgumentException("Unknown preview mode \"$preview\" for \"$type\"");
            }
            $known[$type] = array_values($spec['extensions']);
            $groups[$spec['group']] = [...($groups[$spec['group']] ?? []), $type];
            $previews[$type] = $preview;
        }

        return [$known, $groups, $previews];
    }

    /**
     * The group of a built-in type, by its explicit group then its top-level type (SVG is image).
     */
    private static function builtinGroupOf(string $type): string
    {
        foreach (self::GROUPS as $group => $types) {
            if (in_array($type, $types, true)) {
                return $group;
            }
        }

        return str_starts_with($type, 'video/') ? 'video' : 'image';
    }

    private static function previewForGroup(string $group): string
    {
        return match ($group) {
            'image' => 'image',
            'video' => 'video',
            default => 'link',
        };
    }
}
