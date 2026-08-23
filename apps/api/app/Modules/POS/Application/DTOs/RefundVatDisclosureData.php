<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The three VAT figures an X/Z report must show together so it stops
 * contradicting itself (owner ruling B-6(ii), 2026-08-23 — Option A1/A2).
 *
 * The defect this closes: a Z from a shift that took a return printed a headline
 * `tax_amount` that is GROSS of refunds beside a per-rate `vat_breakdown` table
 * that is NET of them — two numbers on one signed document disagreeing by
 * exactly the refund VAT, with no field anywhere disclosing the bridge. It is
 * also why `FiscalPayloadConstraintValidator::validateZFamilyVatBreakdownConsistency()`
 * has to stop asserting `SUM(vat_amount) == tax_amount` the moment
 * `refunds_totals.count != 0` (that gate STAYS — this closes the contradiction
 * it hides, it does not remove the gate).
 *
 * NOTHING here is signed. It is derived at render time from projections; no
 * payload key, no canonical byte, no Z hash changes. `rows` come from
 * `pos_receipt_vat_details` joined to return receipts over the Z's window, using
 * the IDENTICAL per-row normalisation the VAT declaration uses, so the Z and the
 * declaration reconcile by construction rather than by coincidence.
 *
 * Identity: `sales_vat - refund_vat == net_vat`, where `net_vat` is
 * `SUM(vat_breakdown[].vat_amount)` — the SIGNED, authoritative figure.
 */
#[TypeScript]
final class RefundVatDisclosureData extends Data
{
    /**
     * @param  list<RefundVatDisclosureRowData>  $rows  Per-rate refund VAT, ascending by rate. Empty when the shift took no refunds.
     * @param  string  $sales_vat  The sale-only headline (`report_data.tax_amount`). NEVER a declaration input.
     * @param  string  $refund_vat  Positive magnitude of VAT reversed by refunds in the window.
     * @param  string  $net_vat  `SUM(vat_breakdown[].vat_amount)` — the declaration-facing figure.
     * @param  bool  $has_refund_vat  Whether the three-line disclosure is worth rendering at all.
     * @param  bool  $is_reconciled  Whether `sales_vat - refund_vat == net_vat` holds exactly. False means the projected refund rows and the signed table disagree (an incompletely projected window, or a legacy corpus wedge) — surfaced rather than hidden.
     */
    public function __construct(
        public readonly array $rows,
        public readonly string $sales_vat,
        public readonly string $refund_vat,
        public readonly string $net_vat,
        public readonly bool $has_refund_vat,
        public readonly bool $is_reconciled,
    ) {}
}
