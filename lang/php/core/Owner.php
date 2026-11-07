<?php

declare(strict_types=1);

namespace Otaqq\Tansu;

use InvalidArgumentException;

/**
 * Whose files a request works on, decided by the host: where the owner's root is in storage,
 * whether the user may change anything, and optionally limits that differ from the defaults.
 */
final class Owner
{
    /**
     * @param string $root storage prefix of the owner's files, for example "tansu/42" (no leading or trailing "/")
     */
    public function __construct(
        public readonly string $id,
        public readonly string $root,
        public readonly bool $canManage = true,
        public readonly ?Limits $limits = null,
    ) {
        if ($root === '' || $root !== trim($root, '/') || str_contains("/$root/", '/../') || str_contains("/$root/", '/./')) {
            throw new InvalidArgumentException("Invalid owner root: \"$root\"");
        }
    }

    /**
     * The storage location of a path relative to the owner's root.
     */
    public function location(string $path): string
    {
        return $path === Path::ROOT ? $this->root : $this->root.$path;
    }
}
