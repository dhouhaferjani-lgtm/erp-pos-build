# Task 07 R2 Opus-Equivalent Adversarial Review - Printable Metadata + Validator Branch Coverage

Date: 2026-05-21
Commits reviewed:
- `27614ffc1` (`Phase 2.7.1: Author account payments on device`)
- `e52367a5b` (`Phase 2.7.2: Complete account payment printable metadata`)
Reviewer: Opus-equivalent R2 adversarial reviewer
Verdict: APPROVE

## Findings

No blocking or request-changes findings.

## R1 Closure Verification

- R1 P1 is closed. `buildEscPosAccountPaymentReceiptData()` now maps the sealed ACCOUNT_PAYMENT payload metadata into `ReceiptData`: `business_date`, `terminal_id`, `shift_id`, `training_flag`, `customer_account_id`, and `customer_phone` at `apps/pos/src/lib/buildReceiptData.ts:336`. The TS transport type carries those fields at `apps/pos/src/lib/printing.ts:190`, and Rust carries matching serde-defaulted fields at `apps/pos/src-tauri/src/printing/receipt_template.rs:98`.
- The Rust account-payment printable now emits the metadata before the account-payment body at `apps/pos/src-tauri/src/printing/receipt_template.rs:340`, `apps/pos/src-tauri/src/printing/receipt_template.rs:349`, `apps/pos/src-tauri/src/printing/receipt_template.rs:358`, `apps/pos/src-tauri/src/printing/receipt_template.rs:372`, and `apps/pos/src-tauri/src/printing/receipt_template.rs:381`; the training marker prints at `apps/pos/src-tauri/src/printing/receipt_template.rs:392`.
- The long UUID-bearing metadata uses `text_line()` rather than `two_column()`, so the formatter no longer applies the two-column width split to terminal, shift, or account IDs. The buffer does not truncate these values in the focused Rust test.
- R1 P2 is closed. ACCOUNT_PAYMENT validator tests now cover paired foreign currency, one-sided invalid pair, unknown currency code, nullable-object `regime_extensions`, and scalar/list rejection at `apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts:1243` through `apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts:1304`. The happy path still covers both-null foreign currency fields and null `regime_extensions`.

## R2 Regression Checks

- Sale receipt builders do not populate the new account-payment printable fields: `buildEscPosReceiptData()` returns sale/refund data without them at `apps/pos/src/lib/buildReceiptData.ts:119`, and offline sale printing does the same at `apps/pos/src/lib/buildReceiptData.ts:214`. The shared Rust struct is backward-compatible through serde defaults.
- Account-payment sale/VAT leakage remains gated out by `receipt_kind == account_payment`; the account-payment branch starts at `apps/pos/src-tauri/src/printing/receipt_template.rs:457`, and the sale line/VAT branch is the `else` at `apps/pos/src-tauri/src/printing/receipt_template.rs:499`.
- Validator behavior matches the PHP parity targets for nullable associative `regime_extensions` and foreign-currency pair checks: TS at `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:1170` and `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:1357`, PHP at `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:485` and `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:519`.
- Standing patterns still hold in this slice: account payments append through the shared fiscal event chain at `apps/pos/src/lib/offline/accountPaymentService.ts:272`, reject cross-tenant/company customers before sealing at `apps/pos/src/lib/offline/accountPaymentService.ts:186` and `apps/pos/src/stores/paymentStore.ts:570`, use decimal helpers for balance projection at `apps/pos/src/lib/offline/accountPaymentService.ts:140`, and do not introduce PHP service locator usage or a Treasury/Accounting/B2B hard dependency in the device authoring path.

## Residual Minor Notes

- The new metadata fields are on the shared `ReceiptData` shape, and the Rust formatter prints them whenever present, before it computes `receipt_kind` at `apps/pos/src-tauri/src/printing/receipt_template.rs:420`. Current TS sale/Z builders do not set these fields, so there is no active sale receipt leak. If future sale-printing work decides to map sealed sale metadata into `ReceiptData`, it should either intentionally design those labels for sale receipts or gate the account/customer metadata to `receipt_kind == account_payment`.

## Verification Run

- `pnpm --filter @autoerp/pos test -- --run src/lib/__tests__/buildReceiptData.test.ts src/lib/fiscal/__tests__/FiscalEventEngine.test.ts` passed: 2 files, 95 tests.
- `cargo test account_payment_receipt_uses_account_layout_without_sale_lines_or_vat` passed: 1 focused Rust test.
- `pnpm --filter @autoerp/pos typecheck` passed.
