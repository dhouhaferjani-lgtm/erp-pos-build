# Executive Summary

Approve with minor edits. v5 materially closes the round-4 blockers: the discount field is now non-negative, VAT/total arithmetic is explicit, NF525 canonical-vs-legacy mapping is implementable, and the Pass 2A marker removes reliance on commit-message discipline. I found no new blocker or structural P1. Two edits should land before Pass 2A dispatch: compare zero discount amounts numerically rather than with the literal string `"0"`, and broaden the Pass 2B sentinel so it cannot be bypassed by indirect engine wiring.

# Round-4 Closure Verification

| Finding | Verdict | Evidence |
|---|---|---|
| N-16 invoice-discount + scale invariant | PARTIALLY-CLOSED | v5 rewrites §6: `transaction_discount_amount` is a non-negative bcformat string with a regex that has no leading minus (`docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md:36-39`), all money fields are non-negative (`:46`), the scale table enumerates the decimal fields (`:48-68`), the VAT partition algorithm uses BCMath grouping/comparison (`:78-113`), the total arithmetic invariant is unconditional (`:115-131`), and the old FR/TN/DE regime claim is replaced with AutoERP-convention wording (`:129-133`). The remaining inconsistency is the literal zero-discount invariant in §6.A (`:41-43`) against scale-formatted zero values permitted by §6.B (`:52-55`, `:70-74`); see N-27. |
| N-19 P1 invoice-discount semantics | CLOSED | v5 states the amount is non-negative and never a surcharge (`docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md:36-39`), requires reason null-iff-zero (`:41-43`), adds negative tests for both negative amount and reason mismatch (`:144-145`), and attributes VAT allocation to AutoERP convention rather than a multi-country regime rule (`:129-133`). The zero-comparison wording needs the N-27 edit, but the semantics requested by N-19 are now present. |
| N-12 NF525 sub-method bifurcation | CLOSED | §8.B provides implementable pseudo-code: `mapSaleReceipt(Receipt $receipt)` branches on `$receipt->fiscal_event_id !== null`, reads `FiscalEvent`, creates `$this->canonicalReader->forSaleReceipt($event)`, maps buyer/seller/lines/payments/VAT/original reference from the reader, and otherwise falls back to `mapSaleReceiptLegacy($receipt)` (`docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md:201-221`). The line, payment, and VAT canonical mappers have explicit DTO signatures and field mappings (`:223-260`), legacy methods are retained for the null-fiscal-event path (`:262-267`), void/return follow the same pattern (`:269`), and the reader API is defined as `forSaleReceipt(FiscalEvent $event): SaleReceiptCanonicalView` with typed accessors and DTO classes (`:271`). |
| N-23 P2 Pass 2A safety | CLOSED | v5 adds an actual marker file in Pass 2A (`apps/pos/src/lib/offline/.PASS_2B_PENDING`) with required content (`docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md:161-168`) and a CI script that fails when the marker exists and `receiptService.ts` contains `FiscalEventEngine` (`:170-185`). The check is wired into `chokepoint-gate` on every PR (`:187`) and Pass 2B atomically deletes the marker while adding the engine wiring (`:286-296`). That closes the specific round-4 issue: enforcement no longer depends on commit-message discipline. N-25 below notes the sentinel is still underbroad. |
| N-24 P2 scale invariant field list | CLOSED | The §6.B table covers every bcformat decimal field in Candidate C-v3: top-level `subtotal`, `vat_total`, `total`, `transaction_discount_amount` (`docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md:52-55`); line `unit_price`, `line_subtotal`, `line_vat`, `line_discount_amount`, `quantity`, `vat_rate` (`:56-61`); VAT breakdown `net_amount`, `vat_amount`, `gross_amount`, `rate` (`:62-65`); payment `amount` and nullable `foreign_currency_amount` (`:66-67`); and voucher `redeemed_amount` (`:68`). These align with Candidate C-v3 decimal fields in v3 (`docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v3.md:53-67`, `:76-79`, `:92-106`, `:107-109`). |

# Phase 2 New Defects

## N-25 CI sentinel coverage

Severity: P2.

§8.A catches the concrete example of a third-party PR that imports or references `FiscalEventEngine` in `apps/pos/src/lib/offline/receiptService.ts`, because the script greps that file for the literal string (`docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md:170-185`). It does not catch all failure modes: receiptService could be wired indirectly through a singleton/helper import whose symbol/path does not contain `FiscalEventEngine`, or a PR could touch the offline/stores checkout path while the marker exists without adding that literal. Tighten the check to fail any non-Pass-2B receiptService/offline checkout wiring change while `.PASS_2B_PENDING` exists, or grep for the actual forbidden call surface as well as the class name.

## N-26 CanonicalPayloadReader API shape

Severity: None.

The typed view API is reasonable. §8.B defines `CanonicalPayloadReader::forSaleReceipt(FiscalEvent $event): SaleReceiptCanonicalView`, with accessors such as `buyer()`, `seller()`, `lineItems()`, `payments()`, `vatBreakdown()`, and `originalReceiptReference()` (`docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md:207-217`, `:271`). Given the mapper wants DTOs for NF525 export (`:223-260`), a typed view is safer than returning flat arrays and keeps legacy fallback isolated. No defect.

## N-27 Zero-discount comparison uses literal `"0"`

Severity: P2.

§6.A states `(transaction_discount_amount == "0") <=> (transaction_discount_reason == null)` (`docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md:41-43`), but §6.B validates the field at `currency_scale` (`:52-55`) and `moneyRegex(2)` permits `"0.00"` (`:70-74`). A bcformatted zero at scale 2 will usually be `"0.00"`, so literal string equality can reject a valid no-discount payload with `reason=null`. The negative test only covers amount `"0"` (`:145`), so v5 does not catch this. Fix by specifying `bccomp(transaction_discount_amount, "0", $currency_scale) == 0` for the zero side of the invariant and add a `"0.00" + null` positive test.

## N-28 Quantity scale schema compatibility

Severity: None.

§6.B fixes `line_items[].quantity` at `quantity_scale` 3 for Phase 1 (`docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md:60`). The server receipt-line schema accepts three decimal places with `pos_receipt_lines.quantity DECIMAL(10,3)` (`apps/api/database/migrations/2026_01_08_190638_create_pos_receipt_lines_table.php:43-45`). The device `offline_receipts` table stores `lines` as `TEXT`, so it does not impose a narrower decimal scale (`apps/pos/src/lib/db/migrations.ts:91-100`). No defect.

## N-29 VAT rate scale compatibility

Severity: None.

§6.B fixes VAT rates at scale 2 and gives examples `"20.00"` and `"5.50"` (`docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md:61`, `:65`). Candidate C-v3 already defines VAT rates as percentage bcformat strings like `"20.00"`, `"5.50"`, and `"0.00"` (`docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v3.md:62-67`, `:99-104`). Existing POS schema uses `tax_rate DECIMAL(5,2)` (`apps/api/database/migrations/2026_01_08_190638_create_pos_receipt_lines_table.php:49-51`), and fixtures include both `"20.00"` and `"5.50"` (`apps/api/tests/Feature/POS/ReceiptFinalizationServiceTest.php:85-91`, `apps/api/tests/Feature/POS/ComboReceiptTest.php:68-84`). No defect.

# Verdict

v5 is dispatchable after minor text/test hardening. The core closures are now specified well enough for implementation; the remaining edits are guardrail precision, not a reason to block or request structural rework.

VERDICT: APPROVE-WITH-MINOR-EDITS
