<?php

declare(strict_types=1);

namespace Otaqq\Tansu\Tests\Core;

use Otaqq\Tansu\Content\Optimizer;
use Otaqq\Tansu\Optimization;
use Otaqq\Tansu\Tests\Images;
use PHPUnit\Framework\TestCase;

final class OptimizerTest extends TestCase
{
    public function test_resizes_to_fit_the_box_keeping_the_aspect_ratio(): void
    {
        $result = (new Optimizer())->optimize('image/jpeg', Images::jpeg(400, 200), new Optimization(100, 100));

        $this->assertTrue($result->optimized);
        $this->assertNull($result->skipped);
        $this->assertSame([100, 50], Images::size($result->bytes));
        // Still red on the left and blue on the right
        $this->assertGreaterThan(200, Images::colorAt($result->bytes, 10, 25)[0]);
        $this->assertGreaterThan(200, Images::colorAt($result->bytes, 90, 25)[2]);
    }

    public function test_a_missing_side_does_not_limit(): void
    {
        $result = (new Optimizer())->optimize('image/png', Images::png(400, 200), new Optimization(maxHeight: 50));

        $this->assertSame([100, 50], Images::size($result->bytes));
    }

    public function test_never_enlarges(): void
    {
        $jpeg = Images::jpeg(40, 20);
        $result = (new Optimizer())->optimize('image/jpeg', $jpeg, new Optimization(1000, 1000));

        $this->assertFalse($result->optimized);
        $this->assertNull($result->skipped);
        $this->assertSame($jpeg, $result->bytes);
    }

    public function test_compresses_jpeg_and_webp_by_quality(): void
    {
        $noisy = self::noise(200, 200);
        $result = (new Optimizer())->optimize('image/jpeg', $noisy, new Optimization(quality: 30));

        $this->assertTrue($result->optimized);
        $this->assertLessThan(strlen($noisy), strlen($result->bytes));
        $this->assertSame([200, 200], Images::size($result->bytes));

        $webp = (new Optimizer())->optimize('image/webp', Images::webp(200, 200), new Optimization(100, 100, 50));
        $this->assertTrue($webp->optimized);
        $this->assertSame(IMAGETYPE_WEBP, getimagesizefromstring($webp->bytes)[2]);
        $this->assertSame([100, 100], Images::size($webp->bytes));
    }

    public function test_keeps_the_original_when_compressing_does_not_make_it_smaller(): void
    {
        $jpeg = Images::jpeg(40, 20);
        $result = (new Optimizer())->optimize('image/jpeg', $jpeg, new Optimization(quality: 100));

        $this->assertFalse($result->optimized);
        $this->assertSame($jpeg, $result->bytes);
    }

    public function test_quality_alone_does_nothing_to_png_and_gif(): void
    {
        foreach (['image/png' => Images::png(), 'image/gif' => Images::gif()] as $type => $bytes) {
            $result = (new Optimizer())->optimize($type, $bytes, new Optimization(quality: 50));
            $this->assertFalse($result->optimized, $type);
            $this->assertNull($result->skipped, $type);
            $this->assertSame($bytes, $result->bytes, $type);
        }
    }

    public function test_png_keeps_transparency_and_gif_stays_gif(): void
    {
        $png = (new Optimizer())->optimize('image/png', Images::transparentPng(400, 200), new Optimization(100, 100));
        $image = imagecreatefromstring($png->bytes);
        $this->assertSame(127, (imagecolorat($image, 5, 25) >> 24) & 0x7F);
        $this->assertSame(0, (imagecolorat($image, 95, 25) >> 24) & 0x7F);

        $gif = (new Optimizer())->optimize('image/gif', Images::gif(400, 200), new Optimization(100, 100));
        $this->assertSame(IMAGETYPE_GIF, getimagesizefromstring($gif->bytes)[2]);
        $this->assertSame([100, 50], Images::size($gif->bytes));
    }

    public function test_jpeg_is_turned_upright_by_its_exif_orientation(): void
    {
        // Orientation 6: stored landscape, shown rotated 90° clockwise (portrait)
        $result = (new Optimizer())->optimize('image/jpeg', Images::withExif(Images::jpeg(400, 200), 6), new Optimization(100, 100));

        $this->assertSame([50, 100], Images::size($result->bytes));
        $this->assertStringNotContainsString('GPS-SECRET', $result->bytes);
    }

    public function test_skips_animated_images(): void
    {
        foreach (['image/gif' => Images::animatedGif(), 'image/webp' => Images::animatedWebp()] as $type => $bytes) {
            $result = (new Optimizer())->optimize($type, $bytes, new Optimization(2, 2));
            $this->assertFalse($result->optimized, $type);
            $this->assertSame('animated', $result->skipped, $type);
            $this->assertSame($bytes, $result->bytes, $type);
        }
    }

    public function test_skips_what_would_not_fit_in_memory_with_the_margin(): void
    {
        $jpeg = Images::jpeg(400, 200);
        // Decoding needs about 400 × 200 × 4 = 320 000 bytes, plus the resized copy
        $needed = 400 * 200 * 4 + 100 * 50 * 4;

        $tight = (new Optimizer(memoryMargin: 2.0, freeMemory: fn () => (int) ($needed * 1.5)))->optimize('image/jpeg', $jpeg, new Optimization(100, 100));
        $this->assertFalse($tight->optimized);
        $this->assertSame('memory', $tight->skipped);
        $this->assertSame($jpeg, $tight->bytes);
        $this->assertSame(['width' => 400, 'height' => 200, 'estimated_memory' => $needed * 2, 'free_memory' => (int) ($needed * 1.5)], $tight->context);

        $enough = (new Optimizer(memoryMargin: 2.0, freeMemory: fn () => $needed * 2))->optimize('image/jpeg', $jpeg, new Optimization(100, 100));
        $this->assertTrue($enough->optimized);

        $unlimited = (new Optimizer(freeMemory: fn () => null))->optimize('image/jpeg', $jpeg, new Optimization(100, 100));
        $this->assertTrue($unlimited->optimized);
    }

    public function test_counts_png_at_eight_bytes_a_pixel(): void
    {
        // libgd's PNG reader holds the raw rows as well as the image
        $asJpeg = 400 * 200 * 4 + 100 * 50 * 4;
        $result = (new Optimizer(memoryMargin: 1.0, freeMemory: fn () => $asJpeg))->optimize('image/png', Images::png(400, 200), new Optimization(100, 100));

        $this->assertSame('memory', $result->skipped);
        $this->assertSame(400 * 200 * 8 + 100 * 50 * 4, $result->context['estimated_memory']);
    }

    public function test_skips_images_with_more_pixels_than_the_cap_even_without_a_memory_limit(): void
    {
        $result = (new Optimizer(freeMemory: fn () => null, maxPixels: 400 * 200 - 1))->optimize('image/jpeg', Images::jpeg(400, 200), new Optimization(100, 100));

        $this->assertSame('memory', $result->skipped);
        $this->assertSame(400 * 200 - 1, $result->context['max_pixels']);
    }

    public function test_skips_formats_gd_cannot_write(): void
    {
        $result = (new Optimizer(supports: fn (string $type) => $type !== 'image/webp'))
            ->optimize('image/webp', Images::webp(200, 200), new Optimization(100, 100));

        $this->assertSame('format_unsupported', $result->skipped);
    }

    public function test_skips_without_gd(): void
    {
        $result = (new Optimizer(gd: false))->optimize('image/png', Images::png(400, 200), new Optimization(100, 100));

        $this->assertSame('gd_missing', $result->skipped);
    }

    public function test_leaves_types_it_does_not_optimize_alone(): void
    {
        $result = (new Optimizer())->optimize('application/pdf', Images::pdf(), new Optimization(100, 100, 50));

        $this->assertFalse($result->optimized);
        $this->assertNull($result->skipped);
        $this->assertSame(Images::pdf(), $result->bytes);
    }

    public function test_the_memory_margin_is_at_least_one(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Optimizer(memoryMargin: 0.5);
    }

    private static function noise(int $width, int $height): string
    {
        mt_srand(1);
        $image = imagecreatetruecolor($width, $height);
        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                imagesetpixel($image, $x, $y, mt_rand(0, 0xFFFFFF));
            }
        }
        ob_start();
        imagejpeg($image, null, 95);

        return (string) ob_get_clean();
    }
}
