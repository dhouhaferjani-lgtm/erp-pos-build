# Codex Self-Adversarial Review — Phase 3 Spec v1 R2

**Date:** 2026-05-21  
**Reviewed artifact:** `docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md`  
**Reviewed prior findings:** `docs/superpowers/reviews/2026-05-21-pos-charge-to-account-phase3-spec-v1-opus-review.md`  
**Reviewer:** Codex first-pass adversarial review after R2 fixes  
**Verdict:** APPROVE

## Prior Opus Findings

### Finding 1 — Missing SALE_RECEIPT sale-evidence fields

**Status:** RESOLVED.

R2 changes:

- Added top-level `buyer` as a nullable SALE_RECEIPT-compatible fiscal buyer block.
- Added first-class `buyer.codice_fiscale` and retained the landed Phase 1.5.2 forensic prefix.
- Added required `line_items[].product_id`.
- Added nullable `line_items[].non_collected_subtype`.
- Updated payload invariants, Italy analysis, and test matrix for these fields.

Why this resolves the issue:

- `ACCOUNT_CHARGE` now preserves the SALE_RECEIPT sale-evidence keys Opus identified as load-bearing for projection and multi-country readiness.
- `customer` remains the account/credit snapshot; `buyer` is the fiscal identity snapshot. That avoids conflating account state with country-specific buyer identity while retaining the existing validator precedent.

### Finding 2 — AR GL posting line shape under-specified

**Status:** RESOLVED.

R2 changes:

- Locked the journal shape:
  - Debit `SystemAccountPurpose::CustomerReceivable` for `totals.total` / `totals.amount_charged_to_account`, with `partner_id`.
  - Credit `SystemAccountPurpose::ProductRevenue` for net revenue.
  - Credit `SystemAccountPurpose::VatCollected` for `totals.vat_total` when VAT is greater than zero.
  - Require balancing at `currency_scale`.
  - Require idempotent source identifier derived from `fiscal_event_id`.
  - Forbid creating Treasury `Payment`, POS `ReceiptPayment`, payment lines, or calling `createPOSPaymentEntry()`.
- Tightened the deliverable wording so Accounting readiness stays behind a Treasury-owned boundary instead of a Fiscal/POS dependency.
- Updated the test matrix to assert debit/credit amounts, VAT line presence, partner attribution, and absence of payment-row side effects.

Why this resolves the issue:

- A new method name alone can no longer hide the old cash-path bug. The spec now pins the accounting semantics the implementation and tests must prove.

## R2 Standing-Pattern Sweep

- **R2 introduces new defects:** PASS. The R2 additions were rechecked for D16. The new Accounting-readiness sentence explicitly keeps readiness checks Treasury-owned and forbids a Fiscal/POS dependency.
- **Contract drift:** PASS. Payload changes are reflected in the invariants and test matrix, not only in prose.
- **D16:** PASS. `buyer` and `line_items[].product_id` are sealed payload facts; they do not require live Partner/Product/B2B calls at projection time. Future FK resolution remains tenant/company-scoped and bridge-owned.
- **Fail-loud:** PASS. Missing AR/revenue/VAT accounts and projection conflicts remain typed failures.
- **No service locator:** PASS. Spec still forbids production `app()`, `App::make()`, and `resolve()`.
- **D8:** PASS. The Tax Invoice boundary is unchanged: POS authors `ACCOUNT_CHARGE`; web B2B aggregates when active.

## Verdict

APPROVE. The two Opus REQUEST-CHANGES findings are resolved without opening a new D16, D8, or accounting ambiguity.

