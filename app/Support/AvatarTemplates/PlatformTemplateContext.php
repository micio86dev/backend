<?php

declare(strict_types=1);

namespace App\Support\AvatarTemplates;

use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The one door through which a platform avatar template can be written.
 *
 * Closure-scoped and reentrant, like TenantContextScope: the previous state is
 * restored in `finally`, so a throwing callback cannot leave the context open
 * for whatever runs next in the same worker. Registered as a scoped binding, so
 * it is also fresh per request and per queued job.
 *
 * It deliberately does NOT touch TenantResolver: a superadmin acting as an
 * organization and a bare superadmin are both served.
 */
final class PlatformTemplateContext
{
    private bool $active = false;

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     *
     * @throws AuthorizationException when the actor is not a superadmin
     */
    public function run(User $actor, Closure $callback): mixed
    {
        if ($actor->is_superadmin !== true) {
            throw new AuthorizationException('Only a superadmin may write platform avatar templates.');
        }

        $previous = $this->active;
        $this->active = true;

        try {
            return $callback();
        } finally {
            $this->active = $previous;
        }
    }

    public function active(): bool
    {
        return $this->active;
    }
}
