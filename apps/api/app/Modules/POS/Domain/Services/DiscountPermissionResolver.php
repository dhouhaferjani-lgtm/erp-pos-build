<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Services;

use App\Modules\Identity\Domain\User;

/**
 * Single source of truth for "who may apply a POS discount, and up to what
 * personal percent".
 *
 * Both the read path (DiscountController / PosAuthController — what the UI is
 * told) and the authoritative write path (DiscountCalculationService — enforced
 * at receipt/fiscal-event creation) MUST resolve permission through this class
 * so the two can never disagree.
 *
 * The divergence this closes: the read path granted an admin/super_admin bypass
 * (can_discount || isAdmin, max 100) while the validator read the raw
 * User::can_discount flag with no bypass and rejected the same admin at
 * checkout — the operator was told "yes" then blocked mid-sale.
 *
 * Percent values are percent-rate strings at scale 2 (matching the decimal:2
 * column). Never floats.
 */
final class DiscountPermissionResolver
{
    /** Admin bypass ceiling — full 100% personal limit, percent-rate string at scale 2. */
    public const string ADMIN_MAX_PERCENT = '100.00';

    /** @var list<string> Roles that bypass the per-user can_discount flag. */
    private const array ADMIN_ROLES = ['super_admin', 'admin'];

    /**
     * Whether the user's role grants an unconditional discount bypass.
     */
    public function isAdmin(User $user): bool
    {
        return $user->hasRole(self::ADMIN_ROLES);
    }

    /**
     * Whether the user may apply discounts at all (before terminal gating).
     *
     * Admins bypass the per-user can_discount flag; the short-circuit keeps the
     * cheap flag check first so a role lookup only happens when it can matter.
     */
    public function canDiscount(User $user): bool
    {
        return (bool) $user->can_discount || $this->isAdmin($user);
    }

    /**
     * The user's effective personal max discount percent (percent-rate string,
     * scale 2), or null when the user has no personal cap (the terminal limit
     * then applies). Admins are pinned to {@see ADMIN_MAX_PERCENT}.
     *
     * @return numeric-string|null
     */
    public function effectiveMaxPercent(User $user): ?string
    {
        if ($this->isAdmin($user)) {
            return self::ADMIN_MAX_PERCENT;
        }

        $max = $user->max_discount_percent;

        /** @var numeric-string|null $result */
        $result = $max === null ? null : (string) $max;

        return $result;
    }
}
