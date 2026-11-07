<?php

declare(strict_types=1);

namespace Otaqq\Tansu\Tests\Core;

use Otaqq\Tansu\Name;
use Otaqq\Tansu\TansuException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NameTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function folders(): iterable
    {
        yield 'plain' => ['photos', 'photos'];
        yield 'spaces' => ['my  shop photos', 'my-shop-photos'];
        yield 'reserved characters' => ['a/b\\c:d*e?f"g<h>i|j', 'a-b-c-d-e-f-g-h-i-j'];
        yield 'leading dots and dashes' => ['..-.hidden', 'hidden'];
        yield 'control characters' => ["a\x01b\x7Fc", 'abc'];
        yield 'NFD to NFC' => ["\u{30AB}\u{3099}", "\u{30AC}"];
        yield 'dots inside' => ['v1.2', 'v1.2'];
    }

    #[DataProvider('folders')]
    public function test_normalizes_folder_names(string $given, string $expected): void
    {
        $this->assertSame($expected, Name::folder($given));
    }

    public function test_replaces_the_extension_of_file_names(): void
    {
        $this->assertSame('photo.jpg', Name::file('photo.PNG', 'jpg'));
        $this->assertSame('photo.jpg', Name::file('photo', 'JPG'));
        $this->assertSame('my.photo.jpg', Name::file('my.photo.png', 'jpg'));
        $this->assertSame('shell.jpg', Name::file('shell.php', 'jpg'));
    }

    public function test_truncates_to_255_bytes_keeping_the_extension_and_whole_characters(): void
    {
        $name = Name::file(str_repeat('あ', 200).'.png', 'png');
        $this->assertLessThanOrEqual(255, strlen($name));
        $this->assertStringEndsWith('あ.png', $name);
        $this->assertTrue(mb_check_encoding($name, 'UTF-8'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function empty(): iterable
    {
        yield 'empty' => [''];
        yield 'only dots' => ['...'];
        yield 'only spaces' => ['   '];
        yield 'not a string' => [null];
        yield 'invalid utf-8' => ["\xFF"];
    }

    #[DataProvider('empty')]
    public function test_rejects_names_that_are_empty_after_normalizing(mixed $name): void
    {
        $this->expectExceptionObject(TansuException::invalidName());
        Name::folder($name);
    }

    public function test_split(): void
    {
        $this->assertSame(['photo', '.jpg'], Name::split('photo.jpg'));
        $this->assertSame(['photo', ''], Name::split('photo'));
        $this->assertSame(['a.b', '.c'], Name::split('a.b.c'));
    }
}
