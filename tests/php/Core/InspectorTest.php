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

    public function test_classifies_a_text_type_by_its_name_and_stores_it_unchanged(): void
    {
        $inspector = new Inspector($this->pageTypes());
        $html = "<!doctype html><html><body>こんにちは</body></html>";

        $inspection = $inspector->inspect($html, name: 'index.html');

        $this->assertSame('text/html', $inspection->type);
        $this->assertSame($html, $inspection->bytes);
        $this->assertSame('text/html', $inspector->type($html, 'index.html'));
    }

    public function test_a_text_type_needs_its_name_to_be_told_apart(): void
    {
        $this->expectExceptionObject(TansuException::typeNotAllowed());
        (new Inspector($this->pageTypes()))->inspect('body { color: red }');
    }

    public function test_bytes_that_are_not_text_are_refused_even_with_a_text_name(): void
    {
        $this->expectExceptionObject(TansuException::typeNotAllowed());
        (new Inspector($this->pageTypes()))->inspect("x\0y binary", name: 'evil.html');
    }

    private function pageTypes(): FileTypes
    {
        return new FileTypes(['page'], [
            'text/html' => ['extensions' => ['html', 'htm'], 'group' => 'page', 'preview' => 'link'],
            'text/css' => ['extensions' => ['css'], 'group' => 'page', 'preview' => 'text'],
        ]);
    }
}
