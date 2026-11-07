<?php

declare(strict_types=1);

namespace Otaqq\Tansu\Tests\Core;

use Otaqq\Tansu\Content\MemoryGuard;
use Otaqq\Tansu\Content\MetadataStripper;
use Otaqq\Tansu\Tests\Images;
use PHPUnit\Framework\TestCase;

final class MetadataStripperTest extends TestCase
{
    public function test_removes_exif_xmp_and_comments_from_jpeg_without_re_encoding(): void
    {
        $original = Images::jpeg();
        $stripped = (new MetadataStripper())->strip('image/jpeg', Images::withExif($original));

        foreach (['GPS-SECRET', 'XMP-SECRET', 'COMMENT-SECRET', 'Exif', 'CREATOR: gd-jpeg'] as $secret) {
            $this->assertStringNotContainsString($secret, $stripped);
        }
        // Only segments were dropped: the image data after the last header segment is unchanged
        $this->assertStringEndsWith(substr($original, strpos($original, "\xFF\xDA")), $stripped);    }

    public function test_turns_a_rotated_jpeg_upright(): void
    {
        // Stored 40x20, red left and blue right; orientation 6 means "rotate 90° clockwise to show"
        $stripped = (new MetadataStripper())->strip('image/jpeg', Images::withExif(Images::jpeg(), 6));

        $this->assertStringNotContainsString('GPS-SECRET', $stripped);
        [$width, $height] = getimagesizefromstring($stripped);
        $this->assertSame([20, 40], [$width, $height]);
        $this->assertGreaterThan(200, Images::colorAt($stripped, 10, 5)[0], 'red on top');
        $this->assertGreaterThan(200, Images::colorAt($stripped, 10, 35)[2], 'blue at the bottom');
    }

    public function test_keeps_only_the_orientation_when_turning_upright_would_not_fit_in_memory(): void
    {
        $jpeg = Images::withExif(Images::jpeg(400, 200), 6);
        $stripped = (new MetadataStripper(memory: new MemoryGuard(freeMemory: fn () => 1000)))->strip('image/jpeg', $jpeg);

        $this->assertStringNotContainsString('GPS-SECRET', $stripped);
        $this->assertStringNotContainsString('XMP-SECRET', $stripped);
        $this->assertSame([400, 200], Images::size($stripped), 'not decoded');
        $this->assertSame(6, MetadataStripper::jpegOrientation($stripped));
        $this->assertNotFalse(imagecreatefromstring($stripped));
    }

    public function test_orientation_8_rotates_counter_clockwise(): void
    {
        $stripped = (new MetadataStripper())->strip('image/jpeg', Images::withExif(Images::jpeg(), 8));

        $this->assertGreaterThan(200, Images::colorAt($stripped, 10, 5)[2], 'blue on top');
        $this->assertGreaterThan(200, Images::colorAt($stripped, 10, 35)[0], 'red at the bottom');
    }

    public function test_orientation_2_mirrors(): void
    {
        $stripped = (new MetadataStripper())->strip('image/jpeg', Images::withExif(Images::jpeg(), 2));

        $this->assertSame([40, 20], array_slice(getimagesizefromstring($stripped), 0, 2));
        $this->assertGreaterThan(200, Images::colorAt($stripped, 5, 10)[2], 'blue on the left');
    }

    public function test_removes_text_and_exif_chunks_from_png(): void
    {
        $original = Images::png();
        $stripped = (new MetadataStripper())->strip('image/png', Images::pngWithText($original));

        $this->assertStringNotContainsString('TEXT-SECRET', $stripped);
        $this->assertStringNotContainsString('EXIF-SECRET', $stripped);
        $this->assertSame($original, $stripped);
    }

    public function test_removes_exif_and_xmp_chunks_from_webp_and_clears_their_flags(): void
    {
        $stripped = (new MetadataStripper())->strip('image/webp', Images::webpWithExif(Images::webp()));

        $this->assertStringNotContainsString('EXIF-SECRET', $stripped);
        $this->assertStringNotContainsString('XMP-SECRET', $stripped);
        $this->assertSame(0, ord($stripped[20]) & 0x0C, 'VP8X flags');
        $this->assertSame(strlen($stripped) - 8, unpack('V', $stripped, 4)[1], 'RIFF size');
        $this->assertNotFalse(imagecreatefromstring($stripped));
    }

    public function test_leaves_other_types_and_broken_data_alone(): void
    {
        $stripper = new MetadataStripper();
        $this->assertSame('GIF89a...', $stripper->strip('image/gif', 'GIF89a...'));
        $this->assertSame('not a jpeg', $stripper->strip('image/jpeg', 'not a jpeg'));
    }
}
