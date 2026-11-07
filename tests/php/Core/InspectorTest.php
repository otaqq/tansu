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
