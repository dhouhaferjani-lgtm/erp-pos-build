<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Enums;

use App\Modules\Treasury\Application\Projections\TreasuryDepositBridge;
use App\Modules\Treasury\Application\Services\DepositReferenceResolutionService;

/**
 * Why a back-office deposit's Treasury references cannot be projected.
 *
 * One case per invariant `TreasuryDepositBridge` enforces AFTER the
 * `DEPOSIT_RECEIPT` is sealed. Resolving these BEFORE the seal is the whole
 * point of W-5c D1 — a sealed receipt is immutable and has no delete route, so
 * every invariant that can only be discovered post-seal mints a permanent
 * orphan that over-states the customer's deposit history.
 *
 * @see DepositReferenceResolutionService
 * @see TreasuryDepositBridge
 */
enum DepositReferenceRefusal: string
{
    /** No active payment method with this code — bridge `resolvePaymentMethod()`. */
    case PaymentMethodNotFound = 'payment_method_not_found';

    /** No active payment repository with this id — bridge `resolveRepository()`. */
    case RepositoryNotFound = 'payment_repository_not_found';

    /** Repository carries neither `gl_account_id` nor the legacy `account_id`. */
    case RepositoryMissingGlAccount = 'payment_repository_missing_gl_account';

    /** The repository's cash GL account is absent or deactivated. */
    case RepositoryGlAccountInactive = 'payment_repository_gl_account_inactive';

    /**
     * The tender currency is not the repository's currency — the movement port's
     * `CurrencyMismatchException` guard, which fires post-seal.
     */
    case RepositoryCurrencyMismatch = 'payment_repository_currency_mismatch';

    public function message(): string
    {
        return match ($this) {
            self::PaymentMethodNotFound => 'The selected payment method does not exist or is no longer active.',
            self::RepositoryNotFound => 'The selected payment repository does not exist or is no longer active.',
            self::RepositoryMissingGlAccount => 'The selected payment repository has no cash GL account configured, so the deposit cannot be posted to the ledger.',
            self::RepositoryGlAccountInactive => "The selected payment repository's cash GL account is missing or inactive, so the deposit cannot be posted to the ledger.",
            self::RepositoryCurrencyMismatch => 'The deposit currency does not match the currency held by the selected payment repository.',
        };
    }
}
