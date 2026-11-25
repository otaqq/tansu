<?php

declare(strict_types=1);

namespace Otaqq\Tansu\Tests\Core;

use InvalidArgumentException;
use Otaqq\Tansu\Limits;
use PHPUnit\Framework\TestCase;

final class LimitsTest extends TestCase
{
    public function test_one_file_size_for_every_type(): void
    {
        $limits = new Limits(100);

        $this->assertSame(100, $limits->fileSize);
        $this->assertSame(100, $limits->fileSizeFor('video'));
        $this->assertSame(['file_size' => 100, 'file_sizes' => null, 'total_size' => null, 'files' => null], $limits->toArray());
    }

    public function test_file_size_by_group(): void
    {
        $limits = new Limits(['image' => 100, 'video' => 500], 1000, 3);

        $this->assertSame(500, $limits->fileSize);
        $this->assertSame(100, $limits->fileSizeFor('image'));
        $this->assertSame(500, $limits->fileSizeFor('video'));
        // A group without its own limit gets the largest one
        $this->assertSame(500, $limits->fileSizeFor('document'));
        $this->assertSame(
            ['file_size' => 500, 'file_sizes' => ['image' => 100, 'video' => 500], 'total_size' => 1000, 'files' => 3],
            $limits->toArray(),
        );
    }

    public function test_unknown_groups_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Limits(['audio' => 100]);
    }

    public function test_a_custom_group_is_accepted_when_its_name_is_given(): void
    {
        $limits = new Limits(['page' => 100], groups: ['image', 'document', 'video', 'page']);

        $this->assertSame(100, $limits->fileSizeFor('page'));
    }
}
