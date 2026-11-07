<?php

declare(strict_types=1);

namespace Otaqq\Tansu\Tests\Core;

use InvalidArgumentException;
use Otaqq\Tansu\Optimization;
use Otaqq\Tansu\OptimizeOptions;
use Otaqq\Tansu\TansuException;
use PHPUnit\Framework\TestCase;

final class OptimizeOptionsTest extends TestCase
{
    private static function options(): OptimizeOptions
    {
        return OptimizeOptions::fromArray([
            'enabled' => true,
            'default' => 'logo',
            'presets' => [
                'logo' => ['label' => ['en' => 'Logo', 'ja' => 'ロゴ'], 'max_width' => 600, 'max_height' => 600, 'quality' => 80],
                'small' => ['label' => 'Small file', 'quality' => 60],
            ],
            'custom' => [
                'max_width' => ['min' => 16, 'max' => 4096, 'default' => 1200],
                'max_height' => ['min' => 16, 'max' => 4096, 'default' => 1200],
                'quality' => ['min' => 40, 'max' => 100, 'default' => 80],
            ],
            'memory_margin' => 1.5,
            'max_pixels' => 1_000_000,
        ]);
    }

    public function test_off_by_default(): void
    {
        $options = new OptimizeOptions();

        $this->assertFalse($options->enabled);
        $this->assertSame(['enabled' => false], $options->toArray());
        $this->assertNull($options->resolve([]));
        $this->assertNull($options->resolve(['optimize' => 'none']));
    }

    public function test_to_array_for_info(): void
    {
        $this->assertSame([
            'enabled' => true,
            'default' => 'logo',
            'presets' => [
                ['key' => 'logo', 'label' => ['en' => 'Logo', 'ja' => 'ロゴ'], 'max_width' => 600, 'max_height' => 600, 'quality' => 80],
                ['key' => 'small', 'label' => 'Small file', 'max_width' => null, 'max_height' => null, 'quality' => 60],
            ],
            'custom' => [
                'max_width' => ['min' => 16, 'max' => 4096, 'default' => 1200],
                'max_height' => ['min' => 16, 'max' => 4096, 'default' => 1200],
                'quality' => ['min' => 40, 'max' => 100, 'default' => 80],
            ],
        ], self::options()->toArray());
        $this->assertSame(1.5, self::options()->memoryMargin);
        $this->assertSame(1_000_000, self::options()->maxPixels);
        $this->assertSame(50_000_000, (new OptimizeOptions())->maxPixels);
    }

    public function test_resolves_presets_and_custom_values(): void
    {
        $options = self::options();

        $this->assertNull($options->resolve([]));
        $this->assertEquals(new Optimization(600, 600, 80), $options->resolve(['optimize' => 'logo']));
        $this->assertEquals(new Optimization(null, null, 60), $options->resolve(['optimize' => 'small']));
        // Form fields arrive as strings; a side left out does not limit
        $this->assertEquals(new Optimization(800, null, null), $options->resolve(['optimize' => 'custom', 'max_width' => '800', 'max_height' => '']));
        $this->assertEquals(new Optimization(null, null, 40), $options->resolve(['optimize' => 'custom', 'quality' => 40]));
    }

    public function test_refuses_what_the_config_does_not_allow(): void
    {
        $options = self::options();
        $requests = [
            ['optimize' => 'huge'],
            ['optimize' => 'custom'],
            ['optimize' => 'custom', 'max_width' => '5000'],
            ['optimize' => 'custom', 'quality' => '39'],
            ['optimize' => 'custom', 'quality' => 'high'],
            ['optimize' => ['logo']],
        ];
        foreach ($requests as $request) {
            try {
                $options->resolve($request);
                $this->fail('Accepted '.json_encode($request));
            } catch (TansuException $e) {
                $this->assertSame('invalid_request', $e->errorCode);
                $this->assertSame(422, $e->status);
            }
        }
    }

    public function test_refuses_optimizing_when_off_or_custom_when_hidden(): void
    {
        $this->expectExceptionObject(TansuException::invalidRequest('Optimization is not available.'));
        (new OptimizeOptions())->resolve(['optimize' => 'custom', 'quality' => 50]);
    }

    public function test_custom_can_be_hidden(): void
    {
        $options = OptimizeOptions::fromArray(['enabled' => true, 'custom' => null, 'presets' => ['a' => ['label' => 'A', 'quality' => 50]]]);

        $this->assertNull($options->toArray()['custom']);
        $this->expectExceptionObject(TansuException::invalidRequest('Custom optimization is not available.'));
        $options->resolve(['optimize' => 'custom', 'quality' => 50]);
    }

    public function test_refuses_broken_config(): void
    {
        $broken = [
            ['enabled' => true, 'presets' => ['a' => ['label' => 'A']]],                                // does nothing
            ['enabled' => true, 'presets' => ['custom' => ['label' => 'C', 'quality' => 50]]],          // reserved key
            ['enabled' => true, 'presets' => ['a' => ['label' => 'A', 'quality' => 0]]],                // out of 1..100
            ['enabled' => true, 'default' => 'missing'],
            ['enabled' => true, 'memory_margin' => 0.9],
            ['enabled' => true, 'custom' => ['quality' => ['min' => 50, 'max' => 40, 'default' => 45]]],
        ];
        foreach ($broken as $config) {
            try {
                OptimizeOptions::fromArray($config);
                $this->fail('Accepted '.json_encode($config));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
