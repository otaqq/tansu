<?php

declare(strict_types=1);

namespace Otaqq\Tansu\Tests;

use GdImage;

/**
 * Builds small test images with GD, and adds metadata to them byte by byte.
 */
final class Images
{
    /**
     * A JPEG whose left half is red and right half is blue.
     */
    public static function jpeg(int $width = 40, int $height = 20): string
    {
        return self::capture(fn (GdImage $image) => imagejpeg($image, null, 95), self::halves($width, $height));
    }

    public static function png(int $width = 40, int $height = 20): string
    {
        return self::capture(fn (GdImage $image) => imagepng($image), self::halves($width, $height));
    }

    public static function gif(): string
    {
        return self::capture(fn (GdImage $image) => imagegif($image), self::halves(4, 4));
    }

    public static function webp(): string
    {
        return self::capture(fn (GdImage $image) => imagewebp($image), self::halves(4, 4));
    }

    /**
     * Inserts an EXIF APP1 segment (orientation and a GPS marker string) and an XMP APP1 segment after SOI.
     */
    public static function withExif(string $jpeg, int $orientation = 1): string
    {
        $tiff = 'MM'.pack('n', 42).pack('N', 8)
            .pack('n', 1) // one entry in IFD0
            .pack('n', 0x0112).pack('n', 3).pack('N', 1).pack('n', $orientation).pack('n', 0)
            .pack('N', 0)
            .'GPS-SECRET-35.6812N';
        $exif = "Exif\0\0".$tiff;
        $xmp = "http://ns.adobe.com/xap/1.0/\0<x:xmpmeta>XMP-SECRET</x:xmpmeta>";

        return "\xFF\xD8"
            ."\xFF\xE1".pack('n', 2 + strlen($exif)).$exif
            ."\xFF\xE1".pack('n', 2 + strlen($xmp)).$xmp
            ."\xFF\xFE".pack('n', 2 + 14).'COMMENT-SECRET'
            .substr($jpeg, 2);
    }

    /**
     * Inserts tEXt and eXIf chunks after IHDR.
     */
    public static function pngWithText(string $png): string
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $ihdrEnd = 8 + 12 + 13;

        return substr($png, 0, $ihdrEnd)
            .$chunk('tEXt', "Comment\0TEXT-SECRET")
            .$chunk('eXIf', 'MM'.pack('n', 42).pack('N', 8).pack('n', 0).pack('N', 0).'EXIF-SECRET')
            .substr($png, $ihdrEnd);
    }

    /**
     * Turns a simple WebP into an extended one (VP8X) with EXIF and XMP chunks.
     */
    public static function webpWithExif(string $webp): string
    {
        $chunk = fn (string $type, string $data) => $type.pack('V', strlen($data)).$data.(strlen($data) % 2 ? "\0" : '');
        $vp8x = $chunk('VP8X', chr(0x0C)."\0\0\0".substr(pack('V', 3), 0, 3).substr(pack('V', 3), 0, 3));
        $body = $vp8x.substr($webp, 12).$chunk('EXIF', 'EXIF-SECRET').$chunk('XMP ', 'XMP-SECRET');

        return 'RIFF'.pack('V', 4 + strlen($body)).'WEBP'.$body;
    }

    /**
     * The colour at a point: [r, g, b].
     *
     * @return array{int, int, int}
     */
    public static function colorAt(string $bytes, int $x, int $y): array
    {
        $image = imagecreatefromstring($bytes);
        $rgb = imagecolorat($image, $x, $y);

        return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
    }

    private static function halves(int $width, int $height): GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, $height - 1, imagecolorallocate($image, 255, 0, 0));
        imagefilledrectangle($image, intdiv($width, 2), 0, $width - 1, $height - 1, imagecolorallocate($image, 0, 0, 255));

        return $image;
    }

    private static function capture(callable $write, GdImage $image): string
    {
        ob_start();
        $write($image);

        return (string) ob_get_clean();
    }
}
