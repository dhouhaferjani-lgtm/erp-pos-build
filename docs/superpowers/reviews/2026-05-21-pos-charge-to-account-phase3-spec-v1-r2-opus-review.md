# Phase 3 Stage A Spec v1 R2 Opus Review

Reviewed artifact: `docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md`

Prior review: `docs/superpowers/reviews/2026-05-21-pos-charge-to-account-phase3-spec-v1-opus-review.md`

R2 self-review: `docs/superpowers/reviews/2026-05-21-pos-charge-to-account-phase3-spec-v1-r2-codex-review.md`

Verdict: APPROVE-WITH-MINOR-EDITS

## Findings

### Minor: Discounted ACCOUNT_CHARGE AR posting needs an explicit expected-shape test

The R2 AR section now fixes the prior material omission by requiring `CustomerReceivable` debit, `ProductRevenue` credit, `VatCollected` credit, partner attribution, idempotency by `fiscal_event_id`, no Treasury Payment/POS payment/payment line, and no `createPOSPaymentEntry()` reuse (`docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:379`-`385`). It also requires currency-scale balance (`:383`) and the general parser matrix covers discount-present and discount-absent cases (`:430`).

The remaining ambiguity is only in the Treasury bridge test matrix: the exact journal assertion line covers total/revenue/VAT/partner/no-payment rows, but does not explicitly require a nonzero `transaction_discount_amount` case (`:434`). Because the spec says ProductRevenue is normally `totals.subtotal` under the locked AutoERP discount convention (`:381`) while the canonical invariant is `subtotal + vat_total == total + transaction_discount_amount` (`:268`), an implementation could satisfy the happy path but still get discounted account charges wrong unless one bridge test pins the discounted shape.

Suggested minor edit: extend the Treasury bridge test matrix at `:434` with a discounted charge case that proves the entry still balances at `currency_scale` and applies the locked discount convention, either by reducing revenue or by using the established discount account/line semantics.

## R2 Fix Verification

The two prior request-changes findings are materially fixed:

- Payload completeness is now aligned with the sale-evidence requirements. The top-level exact key set adds `buyer` (`:139`), the buyer block mirrors SALE_RECEIPT and includes `buyer.codice_fiscale` with the locked failure prefix (`:180`-`:185`), and line items include required `product_id` plus nullable `non_collected_subtype` (`:187`-`:202`).
- Payload invariants and tests now cover the R2 additions: exact top-level/nested keys, no `payments` block, SALE_RECEIPT-compatible buyer keys, Italy `buyer.codice_fiscale`, line-item `product_id`, and `non_collected_subtype` (`:256`-`:285`, `:423`-`:438`).
- Italy analysis now calls out RT sale evidence, non-collection subtype, and `codice_fiscale` without moving those fields into `regime_extensions` (`:311`-`:314`).
- AR GL semantics now forbid the previous fake-payment path: no Treasury Payment row, no POS ReceiptPayment row, no payment line, and no `createPOSPaymentEntry()` (`:376`, `:385`, `:434`).
- D16 remains intact. Accounting-readiness is explicitly Treasury-owned and does not create a Fiscal/POS hard dependency on Accounting (`:371`-`:388`).
- D8 remains intact. POS does not author a Tax Invoice; the B2B/web aggregate bridge is still the recommended lock for B2B facture drafting (`:301`-`:304`, `:389`-`:397`).
- Contract drift controls are explicit: exact top-level keys, exact nested buyer/line keys, no payments block, and dedicated contract-drift tests (`:256`-`:264`, `:423`-`:438`, `:459`-`:462`).

## Verification Commands

Commands run from `/Users/houssamr/Projects/syneriva/apps/erp.phase-3`:

- `git status --short`
- `nl -ba docs/superpowers/reviews/2026-05-21-pos-charge-to-account-phase3-spec-v1-opus-review.md`
- `nl -ba docs/superpowers/reviews/2026-05-21-pos-charge-to-account-phase3-spec-v1-r2-codex-review.md`
- `nl -ba docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md | sed -n '120,220p'`
- `nl -ba docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md | sed -n '250,325p'`
- `nl -ba docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md | sed -n '350,410p'`
- `nl -ba docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md | sed -n '420,470p'`
- `rg -n "buyer|codice_fiscale|product_id|non_collected_subtype|CustomerReceivable|ProductRevenue|VatCollected|createPOSPaymentEntry|Tax Invoice|Accounting-readiness|payments block|exact" docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md`
- `rg -n "case CustomerReceivable|case VatCollected|case ProductRevenue|createPOSPaymentEntry|class Treasury.*Bridge|FiscalEventProjector" apps/api/app/Modules apps/api/app/Domain`

No test suite was run; this was a spec/document review.
