<?php

declare(strict_types=1);

namespace Otaqq\Tansu\Content;

use Otaqq\Tansu\FileTypes;
use Otaqq\Tansu\Optimization;
use Otaqq\Tansu\TansuException;

/**
 * Finds out what an upload really is from its bytes, and returns a cleaned copy that is safe to store,
 * optimized when asked. Optimized images are re-encoded and so carry no metadata; everything else is stripped.
 */
final class Inspector
{
    private const RASTER = [
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_PNG => 'image/png',
        IMAGETYPE_GIF => 'image/gif',
        IMAGETYPE_WEBP => 'image/webp',
    ];

    private const MP4_BRANDS = ['isom', 'iso2', 'iso3', 'iso4', 'iso5', 'iso6', 'mp41', 'mp42', 'mp71', 'avc1', 'dash', 'M4V '];

    private readonly MetadataStripper $stripper;

    private readonly Optimizer $optimizer;

    /**
     * @param ?MetadataStripper $stripper default: one that shares the optimizer's memory guard
     */
    public function __construct(
        private readonly FileTypes $types,
        private readonly bool $stripMetadata = true,
        ?MetadataStripper $stripper = null,
        private readonly SvgSanitizer $svg = new SvgSanitizer(),
        ?Optimizer $optimizer = null,
    ) {
        $this->optimizer = $optimizer ?? new Optimizer();
        $this->stripper = $stripper ?? new MetadataStripper(memory: $this->optimizer->memory);
    }

    /**
     * @throws TansuException type_not_allowed
     */
    public function inspect(string $bytes, ?Optimization $optimization = null): Inspection
    {
        $type = $this->detect($bytes);
        if ($type === null || !$this->types->accepts($type)) {
            throw TansuException::typeNotAllowed();
        }
        if ($type === FileTypes::SVG) {
            return new Inspection($type, $this->svg->sanitize($bytes));
        }
        $result = $optimization === null ? null : $this->optimizer->optimize($type, $bytes, $optimization);
        if ($result?->optimized) {
            return new Inspection($type, $result->bytes, $result);
        }
        if ($this->stripMetadata) {
            $bytes = $this->stripper->strip($type, $bytes);
        }

        return new Inspection($type, $bytes, $result);
    }

    /**
     * Only the type, without cleaning: for checks that must come before the work (the size limit of the type's group).
     *
     * @throws TansuException type_not_allowed
     */
    public function type(string $bytes): string
    {
        $type = $this->detect($bytes);
        if ($type === null || !$this->types->accepts($type)) {
            throw TansuException::typeNotAllowed();
        }

        return $type;
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
        if (str_starts_with($bytes, '%PDF-')) {
            return 'application/pdf';
        }
        if (self::isMp4($bytes)) {
            return 'video/mp4';
        }
        if (self::isWebm($bytes)) {
            return 'video/webm';
        }

        return null;
    }

    /**
     * An ISO base media file (ftyp box first) whose major brand is an MP4 video brand. HEIC, AVIF and QuickTime
     * use the same box structure with other brands and are refused.
     */
    private static function isMp4(string $bytes): bool
    {
        if (strlen($bytes) < 16 || substr($bytes, 4, 4) !== 'ftyp') {
            return false;
        }
        $size = unpack('N', $bytes)[1];

        return $size >= 16 && in_array(substr($bytes, 8, 4), self::MP4_BRANDS, true);
    }

    /**
     * A Matroska file (EBML header) whose DocType is "webm".
     */
    private static function isWebm(string $bytes): bool
    {
        return str_starts_with($bytes, "\x1A\x45\xDF\xA3") && str_contains(substr($bytes, 4, 64), "\x42\x82\x84webm");
    }
}
