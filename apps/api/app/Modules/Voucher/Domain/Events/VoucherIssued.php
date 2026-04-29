<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Events;

use App\Modules\Voucher\Domain\Enums\VoucherSource;

/**
 * Dispatched after a voucher is successfully issued (any source).
 *
 * This event is immutable — never rename, restructure, or delete it.
 * Create a VoucherIssuedV2 if the contract must change.
 */
final class VoucherIssued
{
    /**
     * @param  numeric-string  $amount
     */
    public function __construct(
        public readonly string $voucherId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $code,
        public readonly VoucherSource $source,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $issuedByUserId,
        public readonly string $glJournalEntryId,
        public readonly string $occurredAt,
    ) {}
}
