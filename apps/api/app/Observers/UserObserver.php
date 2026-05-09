<?php

declare(strict_types=1);

namespace App\Observers;

use App\Modules\Identity\Domain\User;

/**
 * Observer for User model — Sanctum token revocation on tenant_id change
 * (api.auth-permissions cluster, master plan §15 Invariant A.3).
 *
 * Forward-compat defense: no production endpoint mutates users.tenant_id
 * today (UserController CRUD always pins tenant_id to the auth user's own
 * tenant). The hook is in place so a future super-admin "move user between
 * tenants" feature is automatically protected — moving a user wipes their
 * old tokens; the user must re-authenticate from the new tenant context.
 *
 * Spatie team-id consistency (Invariant C) is automatic: SetPermissionsTeam
 * middleware reads auth()->user()->tenant_id directly each request, so the
 * new tenant_id flows through after the user re-logs in. No additional
 * cache invalidation needed at this layer.
 */
class UserObserver
{
    /**
     * Handle the User "updated" event.
     *
     * Revokes all personal access tokens when tenant_id changes.
     */
    public function updated(User $user): void
    {
        if ($user->wasChanged('tenant_id')) {
            $user->tokens()->delete();
        }
    }
}
