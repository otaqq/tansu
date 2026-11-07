<?php

use Otaqq\Tansu\FileTypes;

return [

    /*
     * The filesystem disk files are stored on, and how the browser loads them:
     * "disk" uses the disk's public URL (Storage::url, for example the "public" disk with `php artisan storage:link`),
     * "route" serves them through GET {prefix}/raw after the same authorization as the API (for private disks).
     */
    'disk' => env('TANSU_DISK', 'public'),
    'url' => env('TANSU_URL', 'disk'),

    /*
     * Decides whose files a request works on and whether the user may change them.
     * A class implementing Otaqq\Tansu\Laravel\OwnerResolver. The default gives each logged-in user
     * their own root, "tansu/users/{id}".
     */
    'owner_resolver' => Otaqq\Tansu\Laravel\UserOwnerResolver::class,

    /*
     * Vetoes renaming or deleting files the app still uses. A class implementing Otaqq\Tansu\InUseChecker, or null.
     */
    'in_use_checker' => null,

    'routes' => [
        'enabled' => true,
        'prefix' => 'tansu',
        'middleware' => ['web', 'auth'],
        'name' => 'tansu.',
    ],

    /*
     * What uploads may be: groups ("image": JPEG, PNG, GIF, WebP; "document": PDF; "video": MP4, WebM)
     * and media types. SVG ('image/svg+xml') is in no group and off by default; when added, it is sanitized.
     */
    'accept' => FileTypes::DEFAULT_GROUPS,

    /*
     * Removes EXIF, XMP and similar metadata from images. PDF and video metadata is not removed.
     */
    'strip_metadata' => true,

    /*
     * Default limits; an OwnerResolver can give an owner its own. Sizes in bytes, null for no limit.
     * file_size is one size for every type, or a size per group. A map is merged over the one below, so a group the
     * app leaves out keeps its default here; write one number to use the same size for all. It applies to the
     * uploaded file, before optimization. PHP's upload_max_filesize and post_max_size must be at least the largest.
     */
    'limits' => [
        'file_size' => [
            'image' => 5 * 1024 * 1024,
            'document' => 10 * 1024 * 1024,
            'video' => 50 * 1024 * 1024,
        ],
        'total_size' => null,
        'files' => null,
    ],

    /*
     * Image optimization (JPEG, PNG, GIF, WebP, with GD), chosen per upload by the person uploading:
     * keep the original, a preset, or custom values within the ranges below.
     */
    'optimize' => [
        'enabled' => false,

        // What the upload form selects first: null (keep the original), a preset key or 'custom'
        'default' => null,

        // key => label (a string or a map by language), max_width, max_height (fit inside, never enlarge), quality (1-100)
        // 'large' => ['label' => ['en' => 'Large', 'ja' => '大'], 'max_width' => 1920, 'max_height' => 1920, 'quality' => 85],
        'presets' => [],

        // Free input next to the presets; null hides it
        'custom' => [
            'max_width' => ['min' => 16, 'max' => 4096, 'default' => 1920],
            'max_height' => ['min' => 16, 'max' => 4096, 'default' => 1920],
            'quality' => ['min' => 40, 'max' => 100, 'default' => 85],
        ],

        // The estimated decode memory times this must fit in the free part of memory_limit, else the original is kept
        'memory_margin' => 2.0,

        // Images with more pixels are never decoded, even without a memory limit (a small PNG can be huge decoded)
        'max_pixels' => 50_000_000,

        // Log channel for optimizations that were skipped; null uses the default channel
        'log_channel' => null,
    ],

];
