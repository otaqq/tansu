<?php

declare(strict_types=1);

namespace Otaqq\Tansu\Content;

/**
 * Removes EXIF, XMP, IPTC and text metadata from JPEG, PNG and WebP without re-encoding.
 *
 * A JPEG whose EXIF orientation says it is rotated or mirrored is re-encoded upright with GD instead
 * (when GD is available), because dropping the orientation alone would show it the wrong way round.
 * When decoding it might not fit in memory (MemoryGuard), or GD is missing, it is not decoded: a minimal EXIF
 * segment with only the orientation is kept instead.
 */
final class MetadataStripper
{
    /** JPEG markers dropped: APP1 (EXIF, XMP), APP13 (IPTC), COM */
    private const JPEG_DROP = [0xE1, 0xED, 0xFE];

    private const PNG_DROP = ['eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME'];

    private const WEBP_DROP = ['EXIF', 'XMP '];

    public function __construct(
        private readonly int $jpegQuality = 90,
        private readonly MemoryGuard $memory = new MemoryGuard(),
    ) {
    }

    public function strip(string $type, string $bytes): string
    {
        return match ($type) {
            'image/jpeg' => $this->jpeg($bytes),
            'image/png' => $this->png($bytes),
            'image/webp' => $this->webp($bytes),
            default => $bytes,
        };
    }

    private function jpeg(string $bytes): string
    {
        if (!str_starts_with($bytes, "\xFF\xD8")) {
            return $bytes;
        }
        $out = "\xFF\xD8";
        $orientation = 1;
        $pos = 2;
        $length = strlen($bytes);
        while ($pos + 4 <= $length && $bytes[$pos] === "\xFF") {
            $marker = ord($bytes[$pos + 1]);
            if ($marker === 0xFF) { // fill byte
                $pos++;
                continue;
            }
            if ($marker === 0xDA || $marker === 0xD9) { // start of scan / end of image: the rest is image data
                break;
            }
            $size = unpack('n', $bytes, $pos + 2)[1];
            $segment = substr($bytes, $pos, 2 + $size);
            if ($marker === 0xE1 && str_starts_with(substr($segment, 4), "Exif\0\0")) {
                $orientation = self::orientation(substr($segment, 10));
            }
            if (!in_array($marker, self::JPEG_DROP, true)) {
                $out .= $segment;
            }
            $pos += 2 + $size;
        }
        $out .= substr($bytes, $pos);

        if ($orientation === 1) {
            return $out;
        }
        $size = @getimagesizefromstring($out);
        // Decoding needs the image and the turned copy
        if ($size !== false && function_exists('imagecreatefromstring')
            && $this->memory->refuse($size[0], $size[1], $size[0] * $size[1] * 8) === null) {
            $upright = $this->upright($out, $orientation);
            if ($upright !== null) {
                return $upright;
            }
        }

        return "\xFF\xD8".self::orientationSegment($orientation).substr($out, 2);
    }

    /**
     * Reads the orientation tag (0x0112) of IFD0 from a TIFF structure; 1 when absent or unreadable.
     */
    private static function orientation(string $tiff): int
    {
        $order = substr($tiff, 0, 2);
        if ($order !== 'II' && $order !== 'MM' || strlen($tiff) < 8) {
            return 1;
        }
        $short = $order === 'II' ? 'v' : 'n';
        $long = $order === 'II' ? 'V' : 'N';
        $ifd = unpack($long, $tiff, 4)[1];
        if ($ifd + 2 > strlen($tiff)) {
            return 1;
        }
        $count = unpack($short, $tiff, $ifd)[1];
        for ($i = 0; $i < $count; $i++) {
            $entry = $ifd + 2 + $i * 12;
            if ($entry + 12 > strlen($tiff)) {
                break;
            }
            if (unpack($short, $tiff, $entry)[1] === 0x0112) {
                $value = unpack($short, $tiff, $entry + 8)[1];

                return $value >= 1 && $value <= 8 ? $value : 1;
            }
        }

        return 1;
    }

    /**
     * The EXIF orientation of a JPEG (1 to 8); 1 when it has none or it cannot be read.
     */
    public static function jpegOrientation(string $bytes): int
    {
        if (!str_starts_with($bytes, "\xFF\xD8")) {
            return 1;
        }
        $pos = 2;
        $length = strlen($bytes);
        while ($pos + 4 <= $length && $bytes[$pos] === "\xFF") {
            $marker = ord($bytes[$pos + 1]);
            if ($marker === 0xFF) {
                $pos++;
                continue;
            }
            if ($marker === 0xDA || $marker === 0xD9) {
                break;
            }
            $size = unpack('n', $bytes, $pos + 2)[1];
            if ($marker === 0xE1 && substr($bytes, $pos + 4, 6) === "Exif\0\0") {
                return self::orientation(substr($bytes, $pos + 10, $size - 8));
            }
            $pos += 2 + $size;
        }

        return 1;
    }

    /**
     * An APP1 EXIF segment with only the orientation tag.
     */
    private static function orientationSegment(int $orientation): string
    {
        $tiff = 'MM'.pack('n', 42).pack('N', 8)
            .pack('n', 1).pack('n', 0x0112).pack('n', 3).pack('N', 1).pack('n', $orientation).pack('n', 0)
            .pack('N', 0);
        $exif = "Exif\0\0".$tiff;

        return "\xFF\xE1".pack('n', 2 + strlen($exif)).$exif;
    }

    /**
     * Turns a decoded image upright for an EXIF orientation.
     */
    public static function orient(\GdImage $image, int $orientation): \GdImage|false
    {
        return match ($orientation) {
            2 => self::flip($image, IMG_FLIP_HORIZONTAL),
            3 => imagerotate($image, 180, 0),
            4 => self::flip($image, IMG_FLIP_VERTICAL),
            5 => self::flip(imagerotate($image, 90, 0), IMG_FLIP_VERTICAL),
            6 => imagerotate($image, -90, 0),
            7 => self::flip(imagerotate($image, -90, 0), IMG_FLIP_VERTICAL),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };
    }

    private function upright(string $jpeg, int $orientation): ?string
    {
        $image = @imagecreatefromstring($jpeg);
        if ($image === false) {
            return null;
        }
        $image = self::orient($image, $orientation);
        if ($image === false) {
            return null;
        }
        ob_start();
        imagejpeg($image, null, $this->jpegQuality);

        return (string) ob_get_clean();
    }

    private static function flip(\GdImage|false $image, int $mode): \GdImage|false
    {
        if ($image !== false) {
            imageflip($image, $mode);
        }

        return $image;
    }

    private function png(string $bytes): string
    {
        $signature = "\x89PNG\r\n\x1A\n";
        if (!str_starts_with($bytes, $signature)) {
            return $bytes;
        }
        $out = $signature;
        $pos = 8;
        $length = strlen($bytes);
        while ($pos + 12 <= $length) {
            $size = unpack('N', $bytes, $pos)[1];
            $type = substr($bytes, $pos + 4, 4);
            $chunk = substr($bytes, $pos, 12 + $size);
            if (!in_array($type, self::PNG_DROP, true)) {
                $out .= $chunk;
            }
            $pos += 12 + $size;
            if ($type === 'IEND') {
                break;
            }
        }

        return $out;
    }

    private function webp(string $bytes): string
    {
        if (substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WEBP') {
            return $bytes;
        }
        $body = '';
        $pos = 12;
        $length = strlen($bytes);
        while ($pos + 8 <= $length) {
            $type = substr($bytes, $pos, 4);
            $size = unpack('V', $bytes, $pos + 4)[1];
            $chunk = substr($bytes, $pos, 8 + $size + ($size & 1));
            if ($type === 'VP8X') {
                // Clear the EXIF (0x08) and XMP (0x04) flags
                $chunk[8] = chr(ord($chunk[8]) & ~0x0C);
            }
            if (!in_array($type, self::WEBP_DROP, true)) {
                $body .= $chunk;
            }
            $pos += 8 + $size + ($size & 1);
        }

        return 'RIFF'.pack('V', 4 + strlen($body)).'WEBP'.$body;
    }
}
