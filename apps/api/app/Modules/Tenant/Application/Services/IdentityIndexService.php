<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Application\Services;

use App\Modules\Tenant\Domain\CentralIdentity;

/**
 * The SINGLE writer of the central identity index (`central_identities`,
 * topology contract §9.1). Every path that creates / changes / removes a
 * tenant user must route its index maintenance through this service:
 *
 *   - register (first owner)                  -> {@see record()}
 *   - admin-invited user (UserController@store) -> {@see record()}
 *   - email change (UserController@update)    -> {@see syncEmail()}
 *   - deactivation (UserController@destroy)   -> {@see remove()}
 *
 * If only register wrote the index, invited users would never enter it and
 * would lose email-first login / org recovery / tenant-qualified reset (r7 P1).
 *
 * The service is intentionally primitive-typed (email / tenantId / userId
 * strings) so the central Tenant module never depends on the tenant-scoped
 * Identity\Domain\User — the dependency arrow stays Identity -> Tenant.
 *
 * Cross-DB write ordering (topology §9.1): post-flip the central index row and
 * the tenant-DB users row live in different databases and cannot share a
 * transaction. Callers must write the central rows FIRST, then create the
 * tenant user, and compensate (remove central rows) on tenant-side failure;
 * the `tenant:reconcile-identities` command sweeps stragglers.
 */
class IdentityIndexService
{
    /**
     * Record (or refresh) a single (email, tenant) membership.
     *
     * Idempotent on (email, tenant_id): a second call updates the informational
     * user_id rather than creating a duplicate. A null email is a no-op
     * (PIN-only cashiers have no email and never enter the index).
     */
    public function record(?string $email, string $tenantId, ?string $userId = null): void
    {
        if ($email === null || $email === '') {
            return;
        }

        CentralIdentity::query()->updateOrCreate(
            ['email' => $email, 'tenant_id' => $tenantId],
            ['user_id' => $userId],
        );
    }

    /**
     * Move a membership when a user's email changes within a tenant.
     *
     * Removes the previous (previousEmail, tenant) row when the email actually
     * changed, then records the new email (no-op if the new email is null,
     * e.g. an email being cleared from a PIN-only cashier).
     */
    public function syncEmail(?string $previousEmail, ?string $newEmail, string $tenantId, ?string $userId = null): void
    {
        if ($previousEmail !== $newEmail) {
            $this->remove($previousEmail, $tenantId);
        }

        $this->record($newEmail, $tenantId, $userId);
    }

    /**
     * Remove a (email, tenant) membership (deactivation / destroy).
     * A null email is a no-op.
     */
    public function remove(?string $email, string $tenantId): void
    {
        if ($email === null || $email === '') {
            return;
        }

        CentralIdentity::query()
            ->where('email', $email)
            ->where('tenant_id', $tenantId)
            ->delete();
    }
}
