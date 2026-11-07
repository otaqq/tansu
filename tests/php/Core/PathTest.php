<?php

declare(strict_types=1);

namespace Otaqq\Tansu\Tests\Core;

use Otaqq\Tansu\Path;
use Otaqq\Tansu\TansuException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PathTest extends TestCase
{
    public function test_accepts_root_and_nested_paths(): void
    {
        $this->assertSame('/', Path::parse('/'));
        $this->assertSame('/a/b c/写真', Path::parse('/a/b c/写真'));
        $this->assertSame('/a', Path::parse('/a/'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalid(): iterable
    {
        yield 'not a string' => [['/a']];
        yield 'empty' => [''];
        yield 'relative' => ['a/b'];
        yield 'parent' => ['/a/../b'];
        yield 'current' => ['/a/./b'];
        yield 'empty segment' => ['/a//b'];
        yield 'hidden' => ['/.env'];
        yield 'backslash' => ['/a\\b'];
        yield 'control' => ["/a\nb"];
        yield 'nul' => ["/a\0b"];
        yield 'invalid utf-8' => ["/\xFF"];
        yield 'long segment' => ['/'.str_repeat('a', 256)];
    }

    #[DataProvider('invalid')]
    public function test_rejects_invalid_paths(mixed $path): void
    {
        $this->expectExceptionObject(TansuException::invalidPath());
        Path::parse($path);
    }

    public function test_join_parent_name_within(): void
    {
        $this->assertSame('/a', Path::join('/', 'a'));
        $this->assertSame('/a/b', Path::join('/a', 'b'));
        $this->assertSame('/', Path::parent('/a'));
        $this->assertSame('/a', Path::parent('/a/b'));
        $this->assertSame('b', Path::name('/a/b'));
        $this->assertTrue(Path::isWithin('/a/b', '/a'));
        $this->assertTrue(Path::isWithin('/a', '/a'));
        $this->assertFalse(Path::isWithin('/ab', '/a'));
        $this->assertTrue(Path::isWithin('/ab', '/'));
    }
}
