<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Events;

/**
 * Dispatched when a voucher accumulates 5 consecutive failed lookup attempts (spec §4.7).
 *
 * The voucher is automatically Voided upon dispatch (auto-fraud-detection path).
 *
 * This event is immutable — never rename, restructure, or delete it.
 * Create a VoucherFraudAlertV2 if the contract must change.
 */
final class VoucherFraudAlert
{
    public function __construct(
        public readonly string $voucherId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $terminalId,
        public readonly string $cashierId,
        public readonly int $attemptsCount,
        public readonly string $occurredAt,
    ) {}
}
