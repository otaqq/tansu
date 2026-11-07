<?php

declare(strict_types=1);

namespace Otaqq\Tansu\Laravel;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Otaqq\Tansu\Owner;

/**
 * The default OwnerResolver: each logged-in user owns "tansu/users/{id}". The `owner` parameter is refused.
 */
final class UserOwnerResolver implements OwnerResolver
{
    public function resolve(Request $request, ?string $requested): Owner
    {
        $user = $request->user();
        if ($user === null) {
            throw new AuthenticationException();
        }
        $id = (string) $user->getAuthIdentifier();
        if ($requested !== null && $requested !== $id) {
            throw new AuthorizationException();
        }

        return new Owner($id, 'tansu/users/'.$id);
    }
}
