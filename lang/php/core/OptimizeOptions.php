<?php

declare(strict_types=1);

namespace Otaqq\Tansu;

use InvalidArgumentException;

/**
 * Image optimization offered to the person uploading: on or off, named presets, free input within ranges,
 * what the form selects first, the safety margin on the memory estimate and the largest image (in pixels) decoded.
 *
 * The client asks per upload (protocol: the `optimize`, `max_width`, `max_height` and `quality` fields);
 * resolve() turns that into an Optimization, or refuses what the configuration does not allow.
 */
final class OptimizeOptions
{
    public const NONE = 'none';

    public const CUSTOM = 'custom';

    private const FIELDS = ['max_width', 'max_height', 'quality'];

    /** @var array<string, array{min: int, max: int, default: int}> */
    public const CUSTOM_DEFAULT = [
        'max_width' => ['min' => 16, 'max' => 4096, 'default' => 1920],
        'max_height' => ['min' => 16, 'max' => 4096, 'default' => 1920],
        'quality' => ['min' => 40, 'max' => 100, 'default' => 85],
    ];

    /**
     * @param array<string, array{label: string|array<string, string>, optimization: Optimization}> $presets
     * @param ?array<string, array{min: int, max: int, default: int}> $custom ranges of free input; null hides it
     */
    public function __construct(
        public readonly bool $enabled = false,
        public readonly ?string $default = null,
        public readonly array $presets = [],
        public readonly ?array $custom = self::CUSTOM_DEFAULT,
        public readonly float $memoryMargin = 2.0,
        public readonly int $maxPixels = 50_000_000,
    ) {
        if ($maxPixels < 1) {
            throw new InvalidArgumentException('optimize.max_pixels must be at least 1');
        }
        if ($memoryMargin < 1.0) {
            throw new InvalidArgumentException('optimize.memory_margin must be at least 1.0');
        }
        if ($default !== null && $default !== self::NONE && !isset($presets[$default]) && !($default === self::CUSTOM && $custom !== null)) {
            throw new InvalidArgumentException("optimize.default \"$default\" is not a preset");
        }
    }

    /**
     * From configuration: enabled, default, presets (key => label, max_width, max_height, quality), custom, memory_margin,
     * max_pixels.
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $presets = [];
        foreach ($config['presets'] ?? [] as $key => $preset) {
            if (!is_string($key) || $key === self::NONE || $key === self::CUSTOM || !preg_match('/^[A-Za-z0-9_-]+$/', $key)) {
                throw new InvalidArgumentException("optimize.presets: \"$key\" cannot be a preset key");
            }
            $label = $preset['label'] ?? $key;
            if (!is_string($label) && !(is_array($label) && $label !== [] && array_filter($label, 'is_string') === $label)) {
                throw new InvalidArgumentException("optimize.presets.$key.label must be a string or a map by language");
            }
            $optimization = new Optimization(
                self::configInt($preset, 'max_width', 1, PHP_INT_MAX, "presets.$key"),
                self::configInt($preset, 'max_height', 1, PHP_INT_MAX, "presets.$key"),
                self::configInt($preset, 'quality', 1, 100, "presets.$key"),
            );
            if (!$optimization->resizes() && $optimization->quality === null) {
                throw new InvalidArgumentException("optimize.presets.$key must resize, compress or both");
            }
            $presets[$key] = ['label' => $label, 'optimization' => $optimization];
        }

        $custom = array_key_exists('custom', $config) ? $config['custom'] : self::CUSTOM_DEFAULT;
        if ($custom !== null) {
            $ranges = [];
            foreach (self::FIELDS as $field) {
                $range = array_merge(self::CUSTOM_DEFAULT[$field], $custom[$field] ?? []);
                $range = array_map('intval', $range);
                $ceiling = $field === 'quality' ? 100 : PHP_INT_MAX;
                if ($range['min'] < 1 || $range['max'] > $ceiling || $range['min'] > $range['max']
                    || $range['default'] < $range['min'] || $range['default'] > $range['max']) {
                    throw new InvalidArgumentException("optimize.custom.$field needs min <= default <= max".($field === 'quality' ? ' within 1..100' : ''));
                }
                $ranges[$field] = ['min' => $range['min'], 'max' => $range['max'], 'default' => $range['default']];
            }
            $custom = $ranges;
        }

        return new self(
            (bool) ($config['enabled'] ?? false),
            isset($config['default']) ? (string) $config['default'] : null,
            $presets,
            $custom,
            (float) ($config['memory_margin'] ?? 2.0),
            (int) ($config['max_pixels'] ?? 50_000_000),
        );
    }

    /**
     * What GET /info tells the UI.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if (!$this->enabled) {
            return ['enabled' => false];
        }
        $presets = [];
        foreach ($this->presets as $key => $preset) {
            $presets[] = [
                'key' => $key,
                'label' => $preset['label'],
                'max_width' => $preset['optimization']->maxWidth,
                'max_height' => $preset['optimization']->maxHeight,
                'quality' => $preset['optimization']->quality,
            ];
        }

        return ['enabled' => true, 'default' => $this->default, 'presets' => $presets, 'custom' => $this->custom];
    }

    /**
     * The optimization an upload asks for, or null for none.
     *
     * @param array<string, mixed> $request the upload's `optimize`, `max_width`, `max_height` and `quality` fields
     *
     * @throws TansuException invalid_request
     */
    public function resolve(array $request): ?Optimization
    {
        $choice = $request['optimize'] ?? null;
        if ($choice === null || $choice === '' || $choice === self::NONE) {
            return null;
        }
        if (!is_string($choice)) {
            throw TansuException::invalidRequest('optimize must be a string.');
        }
        if (!$this->enabled) {
            throw TansuException::invalidRequest('Optimization is not available.');
        }
        if ($choice !== self::CUSTOM) {
            return $this->presets[$choice]['optimization'] ?? throw TansuException::invalidRequest("Unknown optimization \"$choice\".");
        }
        if ($this->custom === null) {
            throw TansuException::invalidRequest('Custom optimization is not available.');
        }
        $values = [];
        foreach (self::FIELDS as $field) {
            $value = $request[$field] ?? null;
            if ($value === null || $value === '') {
                $values[$field] = null;
                continue;
            }
            $number = filter_var($value, FILTER_VALIDATE_INT);
            $range = $this->custom[$field];
            if ($number === false || $number < $range['min'] || $number > $range['max']) {
                throw TansuException::invalidRequest("$field must be a whole number from {$range['min']} to {$range['max']}.");
            }
            $values[$field] = $number;
        }
        $optimization = new Optimization($values['max_width'], $values['max_height'], $values['quality']);
        if (!$optimization->resizes() && $optimization->quality === null) {
            throw TansuException::invalidRequest('Custom optimization needs a size or a quality.');
        }

        return $optimization;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function configInt(array $config, string $field, int $min, int $max, string $where): ?int
    {
        if (!isset($config[$field])) {
            return null;
        }
        $value = filter_var($config[$field], FILTER_VALIDATE_INT);
        if ($value === false || $value < $min || $value > $max) {
            throw new InvalidArgumentException("optimize.$where.$field must be a whole number from $min".($max === PHP_INT_MAX ? '' : " to $max"));
        }

        return $value;
    }
}
