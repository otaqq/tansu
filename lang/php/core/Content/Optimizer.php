<?php

declare(strict_types=1);

namespace Otaqq\Tansu\Content;

use Closure;
use GdImage;
use Otaqq\Tansu\Optimization;

/**
 * Makes JPEG, PNG, GIF and WebP images smaller with GD: resizes them to fit a box (never enlarging) and re-encodes
 * JPEG and WebP at a quality. A JPEG is turned upright by its EXIF orientation. The result carries no metadata.
 *
 * Nothing is risked: animated images, missing GD support and images that may not fit in memory (MemoryGuard)
 * are left as they are, and the result says why.
 */
final class Optimizer
{
    /** Quality for JPEG and WebP when only resizing */
    public const DEFAULT_QUALITY = 90;

    private const LOSSY = ['image/jpeg', 'image/webp'];

    private const RASTER = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** Shared with the MetadataStripper, which decodes rotated JPEGs */
    public readonly MemoryGuard $memory;

    private readonly Closure $supports;

    private readonly bool $gd;

    /**
     * @param ?Closure(): ?int $freeMemory free bytes, null for no limit (default: from memory_limit)
     * @param ?Closure(string $type): bool $supports whether GD reads and writes a type (default: from imagetypes())
     * @param ?bool $gd whether GD is there (default: detected)
     * @param int $maxPixels images with more pixels are never decoded
     */
    public function __construct(
        float $memoryMargin = 2.0,
        ?Closure $freeMemory = null,
        ?Closure $supports = null,
        ?bool $gd = null,
        int $maxPixels = 50_000_000,
    ) {
        $this->memory = new MemoryGuard($memoryMargin, $freeMemory, $maxPixels);
        $this->supports = $supports ?? self::gdSupports(...);
        $this->gd = $gd ?? function_exists('imagecreatefromstring');
    }

    public function optimize(string $type, string $bytes, Optimization $optimization): OptimizeResult
    {
        if (!in_array($type, self::RASTER, true)) {
            return new OptimizeResult($bytes);
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return new OptimizeResult($bytes);
        }
        [$width, $height] = $info;
        $orientation = $type === 'image/jpeg' ? MetadataStripper::jpegOrientation($bytes) : 1;
        [$shownWidth, $shownHeight] = $orientation >= 5 ? [$height, $width] : [$width, $height];
        [$targetWidth, $targetHeight] = self::fit($shownWidth, $shownHeight, $optimization);
        $resize = $targetWidth !== $shownWidth || $targetHeight !== $shownHeight;
        $compress = $optimization->quality !== null && in_array($type, self::LOSSY, true);
        if (!$resize && !$compress) {
            return new OptimizeResult($bytes);
        }

        $context = ['width' => $width, 'height' => $height];
        if (self::animated($type, $bytes)) {
            return new OptimizeResult($bytes, skipped: 'animated', context: $context);
        }
        if (!$this->gd) {
            return new OptimizeResult($bytes, skipped: 'gd_missing', context: $context);
        }
        if (!($this->supports)($type)) {
            return new OptimizeResult($bytes, skipped: 'format_unsupported', context: $context);
        }
        // GD holds 4 bytes a pixel; its PNG reader also holds the raw rows. Turning upright needs a second copy
        $decode = $width * $height * ($type === 'image/png' ? 8 : 4);
        $needed = $decode + ($orientation === 1 ? 0 : $width * $height * 4) + ($resize ? $targetWidth * $targetHeight * 4 : 0);
        $refused = $this->memory->refuse($width, $height, $needed);
        if ($refused !== null) {
            return new OptimizeResult($bytes, skipped: 'memory', context: [...$context, ...$refused]);
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return new OptimizeResult($bytes, skipped: 'format_unsupported', context: $context);
        }
        if ($orientation !== 1) {
            $image = MetadataStripper::orient($image, $orientation);
            if ($image === false) {
                return new OptimizeResult($bytes, skipped: 'format_unsupported', context: $context);
            }
        }
        if ($resize) {
            $image = self::resize($image, $type, $targetWidth, $targetHeight);
        }
        $out = self::encode($image, $type, $optimization->quality ?? self::DEFAULT_QUALITY);
        if (!$resize && strlen($out) >= strlen($bytes)) {
            // Compressing did not help: keep the original (metadata is stripped separately)
            return new OptimizeResult($bytes);
        }

        return new OptimizeResult($out, true, null, $context);
    }

    /**
     * The size inside the box, keeping the aspect ratio; never larger than the image.
     *
     * @return array{int, int}
     */
    private static function fit(int $width, int $height, Optimization $optimization): array
    {
        $scale = min(
            1.0,
            $optimization->maxWidth === null ? 1.0 : $optimization->maxWidth / $width,
            $optimization->maxHeight === null ? 1.0 : $optimization->maxHeight / $height,
        );

        return $scale >= 1.0 ? [$width, $height] : [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];
    }

    private static function resize(GdImage $image, string $type, int $width, int $height): GdImage
    {
        $resized = imagecreatetruecolor($width, $height);
        if ($type === 'image/gif') {
            $transparent = imagecolortransparent($image);
            if ($transparent >= 0 && $transparent < imagecolorstotal($image)) {
                // GIF transparency is one key colour: scale without blending so edges do not mix with it
                $color = imagecolorsforindex($image, $transparent);
                $key = imagecolorallocate($resized, $color['red'], $color['green'], $color['blue']);
                imagefill($resized, 0, 0, $key);
                imagecolortransparent($resized, $key);
                imagecopyresized($resized, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));

                return $resized;
            }
        } elseif ($type !== 'image/jpeg') {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagefill($resized, 0, 0, imagecolorallocatealpha($resized, 0, 0, 0, 127));
        }
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));

        return $resized;
    }

    private static function encode(GdImage $image, string $type, int $quality): string
    {
        ob_start();
        match ($type) {
            'image/jpeg' => imagejpeg($image, null, $quality),
            'image/webp' => (imagesavealpha($image, true) && imagewebp($image, null, $quality)),
            'image/png' => (imagesavealpha($image, true) && imagepng($image, null, 6)),
            'image/gif' => imagegif($image),
        };

        return (string) ob_get_clean();
    }

    /**
     * GIF with more than one graphic control extension, PNG with an animation control chunk, WebP with the
     * animation flag. GD would keep only the first frame.
     */
    private static function animated(string $type, string $bytes): bool
    {
        return match ($type) {
            'image/gif' => substr_count($bytes, "\x21\xF9\x04") > 1,
            'image/png' => ($actl = strpos($bytes, 'acTL')) !== false && $actl < (strpos($bytes, 'IDAT') ?: PHP_INT_MAX),
            'image/webp' => substr($bytes, 12, 4) === 'VP8X' && (ord($bytes[20] ?? "\0") & 0x02) !== 0,
            default => false,
        };
    }

    private static function gdSupports(string $type): bool
    {
        $flags = function_exists('imagetypes') ? imagetypes() : 0;

        return match ($type) {
            'image/jpeg' => ($flags & IMG_JPG) !== 0 && function_exists('imagejpeg'),
            'image/png' => ($flags & IMG_PNG) !== 0 && function_exists('imagepng'),
            'image/gif' => ($flags & IMG_GIF) !== 0 && function_exists('imagegif'),
            'image/webp' => ($flags & IMG_WEBP) !== 0 && function_exists('imagewebp'),
            default => false,
        };
    }
}
