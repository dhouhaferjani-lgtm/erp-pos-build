<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\DTOs;

use App\Modules\Voucher\Domain\Voucher;
use App\Modules\Voucher\Domain\VoucherLedger;

/**
 * Immutable result returned by VoucherRedemptionService::redeem().
 *
 * Contains the refreshed voucher projection, the primary redemption ledger row,
 * an optional rounding-adjustment ledger row (non-null when residual < min currency unit),
 * and the final applied amount + balance at currency scale.
 */
final readonly class VoucherRedemptionResult
{
    /**
     * @param  Voucher  $voucher  Refreshed projection after the redemption
     * @param  VoucherLedger  $redemptionEntry  The ledger row for the Redeemed event
     * @param  VoucherLedger|null  $roundingEntry  Non-null when a RoundingAdjustment fired
     * @param  numeric-string  $appliedAmount  Amount actually applied at currency scale
     * @param  numeric-string  $newBalance  Remaining balance at internal precision (currency_scale + 2)
     * @param  bool  $fullyRedeemed  True when status transitioned to FullyRedeemed
     */
    public function __construct(
        public readonly Voucher $voucher,
        public readonly VoucherLedger $redemptionEntry,
        public readonly ?VoucherLedger $roundingEntry,
        public readonly string $appliedAmount,
        public readonly string $newBalance,
        public readonly bool $fullyRedeemed,
    ) {}
}
