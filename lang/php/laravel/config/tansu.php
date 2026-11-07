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
     * Media types uploads may have. SVG ('image/svg+xml') is off by default; when added, it is sanitized.
     */
    'accept' => FileTypes::DEFAULT,

    'strip_metadata' => true,

    /*
     * Default limits; an OwnerResolver can give an owner its own. Sizes in bytes, null for no limit.
     * PHP's upload_max_filesize and post_max_size must be at least file_size.
     */
    'limits' => [
        'file_size' => 5 * 1024 * 1024,
        'total_size' => null,
        'files' => null,
    ],

];
