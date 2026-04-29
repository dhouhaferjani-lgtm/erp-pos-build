<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Enums;

/**
 * Voucher kind discriminator.
 *
 * MPV (Multi-Purpose Voucher) is the default — non-taxable liability per EU Directive 2016/1065.
 * SPV (Single-Purpose Voucher) is reserved for Phase 2+ where the applicable VAT is known
 * at issuance time (e.g. a voucher redeemable only for a specific VAT rate). Phase 1
 * issuance services MUST refuse SPV with an explicit error.
 */
enum VoucherKind: string
{
    case MPV = 'MPV';
    case SPV = 'SPV';
}
