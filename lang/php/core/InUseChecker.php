<?php

declare(strict_types=1);

namespace Otaqq\Tansu;

/**
 * Lets the host veto renaming or deleting files it still uses (for example, when a record points at one).
 */
interface InUseChecker
{
    /**
     * @param list<string> $paths file paths relative to the owner's root
     * @return list<string> the paths among $paths that are in use
     */
    public function inUse(Owner $owner, array $paths): array;
}
