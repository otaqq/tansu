<?php

declare(strict_types=1);

namespace Otaqq\Tansu\Content;

use Otaqq\Tansu\FileTypes;
use Otaqq\Tansu\TansuException;

/**
 * Finds out what an upload really is from its bytes, and returns a cleaned copy that is safe to store.
 */
final class Inspector
{
    private const RASTER = [
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_PNG => 'image/png',
        IMAGETYPE_GIF => 'image/gif',
        IMAGETYPE_WEBP => 'image/webp',
    ];

    public function __construct(
        private readonly FileTypes $types,
        private readonly bool $stripMetadata = true,
        private readonly MetadataStripper $stripper = new MetadataStripper(),
        private readonly SvgSanitizer $svg = new SvgSanitizer(),
    ) {
    }

    /**
     * @throws TansuException type_not_allowed
     */
    public function inspect(string $bytes): Inspection
    {
        $type = $this->detect($bytes);
        if ($type === null || !$this->types->accepts($type)) {
            throw TansuException::typeNotAllowed();
        }
        if ($type === FileTypes::SVG) {
            return new Inspection($type, $this->svg->sanitize($bytes));
        }
        if ($this->stripMetadata) {
            $bytes = $this->stripper->strip($type, $bytes);
        }

        return new Inspection($type, $bytes);
    }

    private function detect(string $bytes): ?string
    {
        $info = @getimagesizefromstring($bytes);
        if ($info !== false && isset(self::RASTER[$info[2]]) && $info[0] > 0 && $info[1] > 0) {
            return self::RASTER[$info[2]];
        }
        if ($this->types->accepts(FileTypes::SVG) && $this->svg->looksLikeSvg($bytes)) {
            return FileTypes::SVG;
        }

        return null;
    }
}
