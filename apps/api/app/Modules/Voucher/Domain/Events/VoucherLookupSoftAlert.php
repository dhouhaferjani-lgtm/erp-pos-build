<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Events;

/**
 * Emitted when per-tenant/hour failed voucher lookups reach the soft-alert threshold (50).
 *
 * This event is informational only — it does NOT block the lookup.
 * Listeners may notify ops channels, update dashboards, or log for audit.
 *
 * At the hard-block threshold (200), a VoucherRateLimitedException is thrown instead.
 *
 * This event is immutable — never rename, restructure, or delete it.
 * Create a VoucherLookupSoftAlertV2 if the contract must change.
 */
final class VoucherLookupSoftAlert
{
    public function __construct(
        public readonly string $tenantId,
        public readonly int $count,
        public readonly string $occurredAt,
    ) {}
}
