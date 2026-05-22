# Codex Self-Adversarial Review — Phase 3 Spec v1 R3

**Date:** 2026-05-21  
**Reviewed artifact:** `docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md`  
**Reviewed prior finding:** `docs/superpowers/reviews/2026-05-21-pos-charge-to-account-phase3-spec-v1-r2-opus-review.md`  
**Reviewer:** Codex first-pass adversarial review after R3 minor edit  
**Verdict:** APPROVE

## Prior Opus Minor Finding

### Discounted `ACCOUNT_CHARGE` AR posting needs an explicit expected-shape test

**Status:** RESOLVED.

R3 changes:

- Added `SystemAccountPurpose::SalesDiscount` debit when `transaction_discount_amount > 0`.
- Explained that this line balances the locked canonical invariant `subtotal + vat_total == total + transaction_discount_amount`.
- Required fail-loud behavior when a discounted charge lacks a SalesDiscount account.
- Added a dedicated Treasury bridge discounted-charge test matrix row asserting:
  - Dr AR for `total`.
  - Dr SalesDiscount for `transaction_discount_amount`.
  - Cr ProductRevenue for `subtotal`.
  - Cr VatCollected for `vat_total`.
  - Journal balances at `currency_scale`.
  - No Treasury `Payment`, POS `ReceiptPayment`, payment row, or payment line is created.

## R3 Standing-Pattern Sweep

- **Accounting correctness:** PASS. The discounted case now has a deterministic journal shape instead of leaving implementers to choose between reducing revenue and using a discount account.
- **Codebase trace:** PASS. `SystemAccountPurpose::SalesDiscount` exists in the accounting enum and seeded chart-of-account surfaces.
- **No new D16 issue:** PASS. The discount account lookup is inside the Treasury/accounting-owned bridge path. Fiscal/POS-core still do not depend on Accounting.
- **Contract drift:** PASS. The journal-shape prose and test matrix both mention the discounted case.
- **R2/R3 hazard:** PASS. The R3 fix was checked for its own accounting side effect: it balances the canonical invariant rather than changing payload arithmetic.

## Verdict

APPROVE. The remaining Opus minor edit is resolved without reopening the AR GL ambiguity.

