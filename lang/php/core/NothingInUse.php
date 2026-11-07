<?php

declare(strict_types=1);

namespace Otaqq\Tansu;

/**
 * The default InUseChecker: the host uses none of the files.
 */
final class NothingInUse implements InUseChecker
{
    public function inUse(Owner $owner, array $paths): array
    {
        return [];
    }
}
