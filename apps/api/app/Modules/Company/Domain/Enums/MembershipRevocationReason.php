<?php

declare(strict_types=1);

namespace App\Modules\Company\Domain\Enums;

/**
 * Why a `user_company_memberships` row was moved to MembershipStatus::Revoked.
 *
 * This is the provenance the reactivation edge reads: `UserController::activate`
 * restores ONLY memberships stamped `UserDeactivated`, because those are the
 * ones the account-level deactivation cascade took. A membership revoked by any
 * other decision (or a legacy row revoked before this column existed, which
 * carries NULL) is an independent act and stays revoked — reactivating an
 * account must never silently undo a separate revocation.
 */
enum MembershipRevocationReason: string
{
    /** Revoked as a side effect of `users.status` going Inactive (deactivate/destroy). */
    case UserDeactivated = 'user_deactivated';
}
