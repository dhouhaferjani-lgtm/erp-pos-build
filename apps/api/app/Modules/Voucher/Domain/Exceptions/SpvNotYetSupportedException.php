<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when a caller attempts to issue a Single-Purpose Voucher (SPV).
 *
 * SPV issuance is deferred to Phase 2. All vouchers issued in Phase 1 are
 * Multi-Purpose Vouchers (MPV) per EU Directive 2016/1065 default.
 */
final class SpvNotYetSupportedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Single-Purpose Vouchers (SPV) are not yet supported in Phase 1. '
            .'All vouchers are issued as MPV (Multi-Purpose Voucher) by default. '
            .'SPV support, where VAT is known at issuance time, ships in Phase 2.'
        );
    }
}
