<?php

declare(strict_types=1);

namespace Otaqq\Tansu\Tests\Core;

use InvalidArgumentException;
use Otaqq\Tansu\FileTypes;
use PHPUnit\Framework\TestCase;

final class FileTypesTest extends TestCase
{
    public function test_the_default_accepts_every_group_but_not_svg(): void
    {
        $types = new FileTypes();

        $this->assertSame(
            ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'video/mp4', 'video/webm'],
            $types->accepted,
        );
        $this->assertFalse($types->accepts(FileTypes::SVG));
    }

    public function test_groups_and_types_can_be_mixed_without_duplicates(): void
    {
        $types = new FileTypes(['image', 'image/png', FileTypes::SVG, 'document']);

        $this->assertSame(
            ['image/jpeg', 'image/png', 'image/gif', 'image/webp', FileTypes::SVG, 'application/pdf'],
            $types->accepted,
        );
    }

    public function test_stays_compatible_with_v0_1_callers(): void
    {
        // DEFAULT is still a list of media types, and the argument keeps its name
        $this->assertSame((new FileTypes())->accepted, FileTypes::DEFAULT);
        $this->assertSame(['image/png'], (new FileTypes(accepted: ['image/png']))->accepted);
    }

    public function test_unknown_groups_and_types_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FileTypes(['audio']);
    }

    public function test_group_of_a_type(): void
    {
        $this->assertSame('image', FileTypes::groupOf('image/webp'));
        $this->assertSame('image', FileTypes::groupOf(FileTypes::SVG));
        $this->assertSame('document', FileTypes::groupOf('application/pdf'));
        $this->assertSame('video', FileTypes::groupOf('video/webm'));
    }

    public function test_type_of_a_stored_name(): void
    {
        $this->assertSame('application/pdf', FileTypes::typeOf('report.pdf'));
        $this->assertSame('video/mp4', FileTypes::typeOf('clip.MP4'));
        $this->assertSame('video/webm', FileTypes::typeOf('clip.webm'));
        $this->assertNull(FileTypes::typeOf('notes.txt'));
    }

    public function test_to_array_has_the_group(): void
    {
        $this->assertSame(
            [['type' => 'image/png', 'extensions' => ['png'], 'group' => 'image'], ['type' => 'application/pdf', 'extensions' => ['pdf'], 'group' => 'document']],
            (new FileTypes(['image/png', 'document']))->toArray(),
        );
    }
}
