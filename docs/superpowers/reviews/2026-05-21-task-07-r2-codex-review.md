# Task 07 R2 Codex Self-Adversarial Review — Printable Metadata + Validator Branch Coverage

Date: 2026-05-21
Commits reviewed:
- `27614ffc1` (`Phase 2.7.1: Author account payments on device`)
- `e52367a5b` (`Phase 2.7.2: Complete account payment printable metadata`)
Reviewer: Codex
Verdict: APPROVE

## Review Trigger

The Opus-equivalent R1 review returned `REQUEST-CHANGES` on two points:

1. ACCOUNT_PAYMENT printable data dropped sealed metadata required by the Phase 2 spec.
2. ACCOUNT_PAYMENT TS validator had untested foreign-currency and `regime_extensions` branches.

## R2 Fix Verification

- Printable `ReceiptData` now carries `business_date`, `terminal_id`, `shift_id`, `training_flag`, `customer_account_id`, and `customer_phone`.
- `buildEscPosAccountPaymentReceiptData()` maps all six values directly from the sealed ACCOUNT_PAYMENT payload.
- Rust `ReceiptData` accepts the same fields with serde defaults for backward compatibility.
- Rust receipt formatter prints those metadata fields and prints a visible training marker when `training_flag=true`.
- Long UUID-bearing metadata uses full label/value text lines instead of `two_column()` so labels and values are not truncated by ESC/POS column width.
- TS builder test asserts the sealed metadata appears in printable data, including training mode and customer phone/account id.
- Rust formatter test asserts the account receipt includes business date, terminal ID, shift ID, account id, customer phone, training marker, balances, and excludes sale/VAT headings.
- Fiscal engine tests now cover ACCOUNT_PAYMENT foreign currency both-present, one-sided invalid, unknown code, `regime_extensions` object success, and scalar/list rejection.

## Standing-Pattern Attack Vectors

- Contract drift: R2 only extends printable data from the sealed payload; it does not mutate the canonical ACCOUNT_PAYMENT payload contract.
- Fail-loud: validator branches still throw before append on malformed foreign-currency or regime-extension inputs.
- Dead-path rebuild: metadata flows through `buildEscPosAccountPaymentReceiptData()` into the existing success-modal print path and Rust formatter branch.
- R2-introduced-defect check: focused TS, full POS, Rust, backend, chokepoint, and whitespace gates were rerun after the R2 changes.
- D16 bounded modules: no Treasury/Accounting/B2B imports added.
- Rule 13: no PHP code changed; service-locator pattern not introduced.
- Decimal money: R2 did not alter account balance projection arithmetic.

## Verification Evidence

- Focused TS tests: `107` passed across `FiscalEventEngine.test.ts`, `buildReceiptData.test.ts`, `accountPaymentService.test.ts`, and `CustomerAttachPanel`.
- Full POS tests: `162` files, `1445` tests passed.
- POS typecheck: passed.
- POS lint: passed with `41` existing warnings and `0` errors.
- Rust focused test: `account_payment_receipt_uses_account_layout_without_sale_lines_or_vat` passed.
- Backend PHPUnit: `1101` tests, `3689` assertions, `107` skipped, `2` incomplete, `16` deprecations.
- Backend PHPStan: level 8 on `app/Modules/POS app/Modules/Fiscal` passed.
- Backend Pint: `app/Modules/POS app/Modules/Fiscal` passed.
- §14.3 chokepoint gate: passed.
- `.PASS_2B_PENDING` absence check: passed.
- `git diff --check`: passed.

## Residual Risk

- The printable uses current plain text labels for long metadata rather than QR/barcode encoding. That is sufficient for Task 7 and avoids truncation, but later receipt design work may choose a denser account-payment layout.
