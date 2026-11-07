<?php

declare(strict_types=1);

namespace Otaqq\Tansu\Laravel;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Otaqq\Tansu\Owner;

/**
 * Supplied by the host app: whose files a request works on.
 */
interface OwnerResolver
{
    /**
     * @param ?string $requested the `owner` query parameter, if the UI sent one. Only a request: check that the user may access it
     *
     * @throws AuthorizationException when the user may not access the owner
     */
    public function resolve(Request $request, ?string $requested): Owner;
}
