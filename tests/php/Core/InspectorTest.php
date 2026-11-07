<?php

declare(strict_types=1);

namespace Otaqq\Tansu\Tests\Core;

use Otaqq\Tansu\Content\Inspector;
use Otaqq\Tansu\FileTypes;
use Otaqq\Tansu\TansuException;
use Otaqq\Tansu\Tests\Images;
use PHPUnit\Framework\TestCase;

final class InspectorTest extends TestCase
{
    public function test_detects_raster_types_from_content(): void
    {
        $inspector = new Inspector(new FileTypes());
        $this->assertSame('image/jpeg', $inspector->inspect(Images::jpeg())->type);
        $this->assertSame('image/png', $inspector->inspect(Images::png())->type);
        $this->assertSame('image/gif', $inspector->inspect(Images::gif())->type);
        $this->assertSame('image/webp', $inspector->inspect(Images::webp())->type);
    }

    public function test_detects_documents_and_videos_from_content(): void
    {
        $inspector = new Inspector(new FileTypes());
        $this->assertSame('application/pdf', $inspector->inspect(Images::pdf())->type);
        $this->assertSame('video/mp4', $inspector->inspect(Images::isoMedia('mp42'))->type);
        $this->assertSame('video/mp4', $inspector->inspect(Images::isoMedia('isom'))->type);
        $this->assertSame('video/webm', $inspector->inspect(Images::ebml('webm'))->type);
        $this->assertSame(Images::pdf(), $inspector->inspect(Images::pdf())->bytes);
    }

    public function test_rejects_look_alikes_of_documents_and_videos(): void
    {
        $inspector = new Inspector(new FileTypes());
        // HEIC and AVIF images and QuickTime are ISO media too; Matroska that is not WebM; text mentioning %PDF-
        foreach ([Images::isoMedia('heic'), Images::isoMedia('avif'), Images::isoMedia('qt  '), Images::ebml('matroska'), "hello %PDF-1.4"] as $bytes) {
            try {
                $inspector->inspect($bytes);
                $this->fail('Accepted '.bin2hex(substr($bytes, 0, 16)));
            } catch (TansuException $e) {
                $this->assertSame('type_not_allowed', $e->errorCode);
            }
        }
    }

    public function test_rejects_types_that_are_not_accepted(): void
    {
        $this->expectExceptionObject(TansuException::typeNotAllowed());
        (new Inspector(new FileTypes(['image/jpeg'])))->inspect(Images::png());
    }

    public function test_rejects_content_that_is_not_an_image(): void
    {
        $this->expectExceptionObject(TansuException::typeNotAllowed());
        (new Inspector(new FileTypes()))->inspect('<?php echo "hi";');
    }

    public function test_rejects_svg_unless_accepted(): void
    {
        $this->expectExceptionObject(TansuException::typeNotAllowed());
        (new Inspector(new FileTypes()))->inspect('<svg xmlns="http://www.w3.org/2000/svg"/>');
    }

    public function test_sanitizes_svg_when_accepted(): void
    {
        $inspection = (new Inspector(new FileTypes([FileTypes::SVG])))
            ->inspect('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="1"/></svg>');
        $this->assertSame(FileTypes::SVG, $inspection->type);
        $this->assertStringNotContainsString('script', $inspection->bytes);
        $this->assertStringContainsString('<rect width="1"/>', $inspection->bytes);
    }

    public function test_strips_metadata_unless_turned_off(): void
    {
        $jpeg = Images::withExif(Images::jpeg());
        $this->assertStringNotContainsString('GPS-SECRET', (new Inspector(new FileTypes()))->inspect($jpeg)->bytes);
        $this->assertSame($jpeg, (new Inspector(new FileTypes(), stripMetadata: false))->inspect($jpeg)->bytes);
    }
}
