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
        $types = new FileTypes();

        $this->assertSame('image', $types->groupOf('image/webp'));
        $this->assertSame('image', $types->groupOf(FileTypes::SVG));
        $this->assertSame('document', $types->groupOf('application/pdf'));
        $this->assertSame('video', $types->groupOf('video/webm'));
    }

    public function test_type_of_a_stored_name(): void
    {
        $types = new FileTypes();

        $this->assertSame('application/pdf', $types->typeOf('report.pdf'));
        $this->assertSame('video/mp4', $types->typeOf('clip.MP4'));
        $this->assertSame('video/webm', $types->typeOf('clip.webm'));
        $this->assertNull($types->typeOf('notes.txt'));
    }

    public function test_to_array_has_the_group_and_preview(): void
    {
        $this->assertSame(
            [
                ['type' => 'image/png', 'extensions' => ['png'], 'group' => 'image', 'preview' => 'image'],
                ['type' => 'application/pdf', 'extensions' => ['pdf'], 'group' => 'document', 'preview' => 'link'],
            ],
            (new FileTypes(['image/png', 'document']))->toArray(),
        );
    }

    public function test_a_custom_type_joins_a_new_group_and_is_accepted_by_type_or_group(): void
    {
        $custom = ['text/html' => ['extensions' => ['html', 'htm'], 'group' => 'page', 'preview' => 'link']];

        $byType = new FileTypes(['text/html'], $custom);
        $byGroup = new FileTypes(['page'], $custom);

        $this->assertSame(['text/html'], $byType->accepted);
        $this->assertSame(['text/html'], $byGroup->accepted);
        $this->assertTrue($byType->accepts('text/html'));
        $this->assertSame('page', $byType->groupOf('text/html'));
        $this->assertSame('html', $byType->extension('text/html'));
        $this->assertSame('text/html', $byType->typeOf('index.HTML'));
        $this->assertContains('page', $byType->groupNames());
        $this->assertSame(
            [['type' => 'text/html', 'extensions' => ['html', 'htm'], 'group' => 'page', 'preview' => 'link']],
            $byType->toArray(),
        );
    }

    public function test_the_preview_mode_defaults_to_link_and_is_validated(): void
    {
        $types = new FileTypes(['text/css'], ['text/css' => ['extensions' => ['css'], 'group' => 'page']]);
        $this->assertSame('link', $types->previewOf('text/css'));

        $this->assertSame('image', (new FileTypes())->previewOf('image/png'));
        $this->assertSame('video', (new FileTypes())->previewOf('video/mp4'));

        $this->expectException(InvalidArgumentException::class);
        new FileTypes(['text/css'], ['text/css' => ['extensions' => ['css'], 'group' => 'page', 'preview' => 'render']]);
    }

    public function test_a_custom_type_without_a_group_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FileTypes(['text/html'], ['text/html' => ['extensions' => ['html']]]);
    }
}
