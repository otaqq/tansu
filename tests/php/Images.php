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

    public static function gif(int $width = 4, int $height = 4): string
    {
        return self::capture(fn (GdImage $image) => imagegif($image), self::halves($width, $height));
    }

    public static function webp(int $width = 4, int $height = 4): string
    {
        return self::capture(fn (GdImage $image) => imagewebp($image), self::halves($width, $height));
    }

    /**
     * A PNG with a fully transparent left half and an opaque blue right half.
     */
    public static function transparentPng(int $width = 40, int $height = 20): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, $height - 1, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, intdiv($width, 2), 0, $width - 1, $height - 1, imagecolorallocate($image, 0, 0, 255));

        return self::capture(fn (GdImage $image) => imagepng($image), $image);
    }

    /**
     * A 4 × 4 GIF with two 1 × 1 frames, each a graphic control extension and an image.
     */
    public static function animatedGif(): string
    {
        $frame = "\x21\xF9\x04\x00\x0A\x00\x00\x00"."\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00"."\x02\x02\x44\x01\x00";

        return "GIF89a\x04\x00\x04\x00\x80\x00\x00\xFF\xFF\xFF\x00\x00\x00".$frame.$frame.';';
    }

    /**
     * A WebP whose VP8X header says it is animated.
     */
    public static function animatedWebp(): string
    {
        $webp = self::webp();
        $chunk = fn (string $type, string $data) => $type.pack('V', strlen($data)).$data.(strlen($data) % 2 ? "\0" : '');
        $vp8x = $chunk('VP8X', chr(0x02)."\0\0\0".substr(pack('V', 3), 0, 3).substr(pack('V', 3), 0, 3));
        $body = $vp8x.substr($webp, 12);

        return 'RIFF'.pack('V', 4 + strlen($body)).'WEBP'.$body;
    }

    /**
     * Width and height of an image.
     *
     * @return array{int, int}
     */
    public static function size(string $bytes): array
    {
        $info = getimagesizefromstring($bytes);

        return [$info[0], $info[1]];
    }

    /**
     * A minimal PDF: the header, one empty page and the trailer.
     */
    public static function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
            ."2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
            ."3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 10 10] >> endobj\n"
            ."trailer << /Root 1 0 R >>\n%%EOF\n";
    }

    /**
     * The start of an ISO base media file: an ftyp box with the given major brand, then an empty mdat box.
     */
    public static function isoMedia(string $brand = 'mp42'): string
    {
        $ftyp = $brand.pack('N', 0).$brand.'isom';

        return pack('N', 8 + strlen($ftyp)).'ftyp'.$ftyp.pack('N', 8).'mdat';
    }

    /**
     * The start of a Matroska file: an EBML header with the given doc type.
     */
    public static function ebml(string $docType = 'webm'): string
    {
        $body = "\x42\x86\x81\x01" // EBMLVersion 1
            ."\x42\x82".chr(0x80 | strlen($docType)).$docType
            ."\x42\x87\x81\x04\x42\x85\x81\x02";

        return "\x1A\x45\xDF\xA3".chr(0x80 | strlen($body)).$body."\x18\x53\x80\x67\x80";
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
