# Task 07 Opus-Equivalent Second-Pass Review - Device ACCOUNT_PAYMENT Authoring + Printable

Date: 2026-05-21
Commit reviewed: `27614ffc1` (`Phase 2.7.1: Author account payments on device`)
Reviewer: Opus-equivalent adversarial second pass
Verdict: REQUEST-CHANGES

## Findings

### P1 - ACCOUNT_PAYMENT printable omits required sealed metadata

The ACCOUNT_PAYMENT payload carries `business_date`, `terminal_id`, `shift_id`, `training_flag`, and customer phone in the sealed event, but the printable projection drops them. `buildEscPosAccountPaymentReceiptData()` only maps `receipt_number`, `date_time`, `terminal_name`, `operator_name`, `customer_name`, balances, payment amount, and stale flag into `ReceiptData`; it does not carry `business_date`, `terminal_id`, `shift_id`, `training_flag`, or customer phone/account identifier. The shared `ReceiptData` type also has no account-payment-specific fields for these values, and the Rust account-payment branch prints only title, balances, amount, stale marker, and payment method.

References:
- `apps/pos/src/lib/offline/accountPaymentService.ts:206` builds the required sealed fields, including `business_date`, `shift_id`, `terminal_id`, and `training_flag`.
- `apps/pos/src/lib/buildReceiptData.ts:291` maps the printable data but drops those fields.
- `apps/pos/src/lib/printing.ts:122` defines `ReceiptData` without `business_date`, `shift_id`, `training_flag`, or customer phone/account fields.
- `apps/pos/src-tauri/src/printing/receipt_template.rs:384` prints the account-payment layout without business date, shift id, terminal id, customer phone/account id, or a training marker.
- `apps/pos/src-tauri/src/printing/receipt_template.rs:923` tests only that sale/VAT lines are absent and does not cover these required fields.

Why this matters: the Phase 2 spec's `ACCOUNT_PAYMENT_RECEIPT` section requires event time and business date, terminal ID, cashier name, shift ID, customer name and optional phone/account identifier, and a training marker when `training_flag=true`. Business date is not safely inferable from device timestamp because the SoT treats fiscal business-day assignment as a separate session/timezone concept.

Minimal fix: extend the account-payment printable data model and Rust branch to print `payload.business_date`, `payload.terminal_id`, `payload.shift_id`, `payload.training_flag`, and available customer phone/account identifier from the sealed payload. Add a Rust formatter test and TS builder test asserting those fields are present, including a training-mode receipt.

### P2 - ACCOUNT_PAYMENT validator parity has unverified branches

The TS validator implementation includes ACCOUNT_PAYMENT checks for foreign-currency pairing and nullable-object `regime_extensions`, and these appear to mirror PHP structurally. The tests, however, do not exercise those ACCOUNT_PAYMENT-specific paths. The current ACCOUNT_PAYMENT engine tests cover happy path, extra top-level key, zero amount, staleness reason coupling, reserved `server_customer_alias_id`, and key drift, but not foreign currency pair validity or `regime_extensions` object/null rejection of arrays/scalars.

References:
- `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:1357` implements ACCOUNT_PAYMENT foreign-currency pair validation.
- `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:1170` validates nullable-object `regime_extensions`.
- `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:519` and `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:485` are the PHP parity targets.
- `apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts:1161` through `apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts:1269` omit those ACCOUNT_PAYMENT variants.

Minimal fix: add focused ACCOUNT_PAYMENT tests for `(foreign_currency_amount, foreign_currency_code)` both-null, both-present, one-sided invalid, unknown code, and `regime_extensions` as null/object versus array/scalar.

## Positive Checks

- Device authoring goes through `FiscalEventEngine.append()` with `event_type='ACCOUNT_PAYMENT'`, `source_event_class='account_payments'`, and the shared fiscal chain; I found no dual-chain or legacy receipt-sync/hash path in the account-payment service.
- The 20-key top-level contract is present in TS and PHP, and the drift gate reads PHP keys from the validator.
- Customer attach and account-payment authoring reject cross-tenant/company customer snapshots before sealing.
- Missing customer, missing cash method/register, missing operator/company/terminal/shift, alias conflict, and append failure all fail loud rather than downgrading to a sale receipt or non-fiscal payment.
- No touched PHP code in this commit, so CLAUDE rule 13 service-locator risk is not introduced here.
- D16 looks respected in this slice: device code consumes mirrored customer/payment reference data and does not hard-depend on Treasury/Accounting/B2B operational services.
- Balance projection uses decimal helpers (`bcformat`, `bcsub`, `bcadd`, `bccomp`) rather than JS float arithmetic.

## Test/Verification Note

I did not rerun the full suites for this second-pass review. This review is based on static inspection of commit `27614ffc1`, the authoritative Phase 2 Task 7/spec/research context, and the Codex self-review document.
