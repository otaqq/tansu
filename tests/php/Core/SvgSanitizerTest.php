<?php

declare(strict_types=1);

namespace Otaqq\Tansu\Tests\Core;

use Otaqq\Tansu\Content\SvgSanitizer;
use Otaqq\Tansu\TansuException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SvgSanitizerTest extends TestCase
{
    private const OPEN = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 10 10">';

    public function test_keeps_drawing_elements_and_attributes(): void
    {
        $svg = self::OPEN.'<g fill="red"><circle cx="5" cy="5" r="4" style="stroke: blue"/></g></svg>';
        $clean = (new SvgSanitizer())->sanitize($svg);

        $this->assertStringContainsString('<circle cx="5" cy="5" r="4" style="stroke: blue"/>', $clean);
        $this->assertStringContainsString('viewBox="0 0 10 10"', $clean);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function dangerous(): iterable
    {
        yield 'script element' => ['<script>alert(1)</script>', 'alert'];
        yield 'event handler' => ['<rect onload="alert(1)"/>', 'onload'];
        yield 'foreignObject' => ['<foreignObject><iframe src="https://example.com"/></foreignObject>', 'iframe'];
        yield 'style element' => ['<style>@import url(https://example.com/x.css)</style>', 'example.com'];
        yield 'external href' => ['<use href="https://example.com/x.svg#a"/>', 'example.com'];
        yield 'javascript xlink' => ['<a xlink:href="javascript:alert(1)"><rect/></a>', 'javascript'];
        yield 'external url in style' => ['<rect style="fill: url(https://example.com/x)"/>', 'example.com'];
        yield 'external url in attribute' => ['<rect fill="url(\'//example.com/x\')"/>', 'example.com'];
        yield 'image' => ['<image href="data:image/png;base64,AAAA"/>', 'image'];
        yield 'element in another namespace' => ['<h:p xmlns:h="http://www.w3.org/1999/xhtml">x</h:p>', 'h:p'];
        yield 'processing instruction' => ['<?php echo 1 ?>', 'php'];
    }

    #[DataProvider('dangerous')]
    public function test_removes_dangerous_content(string $inner, string $needle): void
    {
        $clean = (new SvgSanitizer())->sanitize(self::OPEN.$inner.'</svg>');

        $this->assertStringNotContainsString($needle, $clean);
    }

    public function test_keeps_internal_references(): void
    {
        $svg = self::OPEN.'<defs><linearGradient id="g"/></defs><rect fill="url(#g)"/><use href="#g"/></svg>';
        $clean = (new SvgSanitizer())->sanitize($svg);

        $this->assertStringContainsString('fill="url(#g)"', $clean);
        $this->assertStringContainsString('href="#g"', $clean);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        yield 'doctype with entity' => ['<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg">&x;</svg>'];
        yield 'not xml' => ['<svg xmlns="http://www.w3.org/2000/svg"><rect></svg>'];
        yield 'not svg' => ['<html xmlns="http://www.w3.org/1999/xhtml"/>'];
        yield 'svg without namespace' => ['<svg><rect/></svg>'];
    }

    #[DataProvider('refused')]
    public function test_refuses_what_cannot_be_trusted(string $svg): void
    {
        $this->expectExceptionObject(TansuException::typeNotAllowed());
        (new SvgSanitizer())->sanitize($svg);
    }

    public function test_recognizes_svg_with_declaration_and_comments(): void
    {
        $sanitizer = new SvgSanitizer();
        $this->assertTrue($sanitizer->looksLikeSvg("\xEF\xBB\xBF<?xml version=\"1.0\"?>\n<!-- logo -->\n<svg xmlns=\"http://www.w3.org/2000/svg\"/>"));
        $this->assertFalse($sanitizer->looksLikeSvg('<html><svg/></html>'));
    }
}
