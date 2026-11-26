<?php

declare(strict_types=1);

namespace Otaqq\Tansu\Laravel;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Otaqq\Tansu\Limits;
use Otaqq\Tansu\Owner;

/**
 * A base OwnerResolver that carries the boilerplate every app would otherwise repeat: it requires a
 * logged-in user, decides from a Gate ability whether that user may manage (write to) the library, and
 * builds the Owner.
 *
 * A host app extends it and supplies ownerId() — the only genuinely app-specific part (the current
 * tenant, team, user, theme, ...). Override root(), canManage() or limits() when the defaults do not fit;
 * nothing here is mandatory beyond ownerId().
 */
abstract class AbstractOwnerResolver implements OwnerResolver
{
    /**
     * The Gate ability that grants management (upload, rename, delete). null (the default) lets every
     * logged-in user manage; when set, a user the ability denies gets a read-only library. The owner id is
     * passed to the Gate as its argument, so the ability may authorize per owner.
     */
    protected ?string $manageAbility = null;

    public function resolve(Request $request, ?string $requested): Owner
    {
        $user = $request->user();
        if ($user === null) {
            throw new AuthenticationException();
        }
        $id = $this->ownerId($request, $requested);

        return new Owner($id, $this->root($id), $this->canManage($user, $id), $this->limits($id));
    }

    /**
     * The id of the owner whose files this request works on (one path segment, e.g. a tenant, user or
     * theme id). $requested is the `owner` query parameter if the UI sent one — ignore it, validate it, or
     * authorize it, and throw Illuminate\Auth\Access\AuthorizationException when it is not allowed.
     */
    abstract protected function ownerId(Request $request, ?string $requested): string;

    /** The owner's root on the disk, relative to the disk root. Defaults to the id itself. */
    protected function root(string $id): string
    {
        return $id;
    }

    /** Whether the user may manage (write to) the library. Defaults to the manageAbility Gate check. */
    protected function canManage(Authenticatable $user, string $id): bool
    {
        return $this->manageAbility === null || Gate::forUser($user)->allows($this->manageAbility, $id);
    }

    /** Limits for this owner, or null to use the configured defaults. */
    protected function limits(string $id): ?Limits
    {
        return null;
    }
}
