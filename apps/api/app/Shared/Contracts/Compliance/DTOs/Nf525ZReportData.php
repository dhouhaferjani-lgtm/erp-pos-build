<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Compliance\DTOs;

/**
 * Snapshot of a Z-report for NF525 export.
 *
 * `$reportData` is intentionally a free-form associative array because the
 * underlying column is JSONB and is already versioned by `schema_version`.
 * The XML builder reads `sales_count`, `gross_sales`, `net_sales`, `tax_amount`,
 * `voided_count` (v1/v2). Refund-flow's v3 schema adds `refunds_count`,
 * `refunds_amount`, `vouchers_issued_count`, `vouchers_issued_amount`,
 * `vouchers_redeemed_count`, `vouchers_redeemed_amount` (refund-flow §5.3),
 * which the JSON pass-through accommodates without any DTO shape change.
 *
 * @see docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md §5.3
 */
final readonly class Nf525ZReportData
{
    /**
     * @param  array<string, mixed>  $reportData
     */
    public function __construct(
        public string $id,
        public string $terminalId,
        public int $zNumber,
        public string $fiscalHash,
        public ?string $previousZHash,
        public string $generatedAtIso8601,
        public array $reportData,
    ) {}
}
