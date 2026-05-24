# Task 07 Codex Self-Adversarial Review — Device ACCOUNT_PAYMENT Authoring + Printable

Date: 2026-05-21
Commit reviewed: `27614ffc1` (`Phase 2.7.1: Author account payments on device`)
Reviewer: Codex
Verdict: APPROVE

## Scope Reviewed

- Device ACCOUNT_PAYMENT authoring service:
  - `apps/pos/src/lib/offline/accountPaymentService.ts`
  - `apps/pos/src/lib/offline/__tests__/accountPaymentService.test.ts`
- Fiscal engine ACCOUNT_PAYMENT append validator:
  - `apps/pos/src/lib/fiscal/FiscalEventEngine.ts`
  - `apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts`
- Checkout/customer attach UI and store wiring:
  - `apps/pos/src/components/customers/CustomerAttachPanel.tsx`
  - `apps/pos/src/components/customers/CustomerAttachPanel.test.tsx`
  - `apps/pos/src/stores/paymentStore.ts`
  - `apps/pos/src/pages/HomePage.tsx`
- Printable receipt path:
  - `apps/pos/src/lib/buildReceiptData.ts`
  - `apps/pos/src/lib/printing.ts`
  - `apps/pos/src/locales/en/pos.json`
  - `apps/pos/src/locales/fr/pos.json`
  - `apps/pos/src-tauri/src/printing/receipt_template.rs`

## Contract Checks

- ACCOUNT_PAYMENT rides `FiscalEventEngine.append()` with `event_type='ACCOUNT_PAYMENT'`, event_version resolved by the registry, and `source_event_class='account_payments'`; no second chain, no legacy v3 hash path, and no `/pos/receipts/sync` path.
- Payload shape mirrors the locked Phase 2 spec: 20 top-level keys, `receipt_type_code='ACCOUNT_PAYMENT'`, `treasury_allocation_policy='FIFO'`, seller/customer/payment/staleness/local balance/reference blocks present.
- TS append validation now mirrors PHP `FiscalPayloadConstraintValidator::validateAccountPaymentPayload()` for structural checks: exact key set, UUID/date/datetime/money/string/enum checks, zero amount rejected unless training, stale reason coupling, foreign-currency pair, forbidden `server_customer_alias_id`, nullable object regime extensions.
- Cross-language drift gate extended to assert `ACCOUNT_PAYMENT_PAYLOAD_KEYS` equals PHP `PAYLOAD_KEYS['ACCOUNT_PAYMENT']`.
- Printable receipt path uses `receipt_kind='account_payment'` and omits sale line/VAT semantics in the Rust ESC/POS template.

## Standing-Pattern Attack Vectors

- Cross-tenant/company safety: `createAccountPayment()` rejects selected customer tenant/company mismatch before payload construction; store-level wrapper repeats the same check before entering the terminal lock.
- Fail-loud over silent downgrade: missing customer, missing auth tenant/company, missing terminal/shift, missing cash method/repository, alias mismatch, invalid amount, malformed payload, and append failure all throw or surface as checkout errors; no fallback to sale receipt or non-fiscal payment recording.
- Dead-path rebuild: the new service is called by `paymentStore.processAccountPayment()`, surfaced through `CustomerAttachPanel`, and printed through HomePage success-modal data. The Rust account-payment receipt branch is covered by a focused test.
- Discriminated matrix: tests cover happy path, zero non-training reject, zero training allow, stale reason required/mismatch, alias conflict, cross-company customer reject, pending customer alias guard, UI success/error path, and printable sale-semantics exclusion.
- Contract drift: TS key-set drift gates now cover both SALE_RECEIPT and ACCOUNT_PAYMENT; AccountPaymentPayload golden parity remains in place.
- Rule 13/service locator: no PHP code was introduced; grep over touched task files found no `app()`, `App::make`, or `resolve()`.
- D16 bounded modules: device code only consumes customer mirror data and payment repositories; there are no Treasury/Accounting/B2B imports or server-side Treasury calls in the device authoring path.
- Decimal money: balance projection uses decimal helpers (`bcadd`, `bcsub`, `bccomp`, `bcformat`) rather than JS float arithmetic.
- R2 defect pattern: no R2 fix was required in this review round.

## Verification Evidence

- POS focused tests: `72` passed across `FiscalEventEngine.test.ts`, `AccountPaymentPayload`, and `accountPaymentService.test.ts`.
- Full POS tests: `162` files, `1439` tests passed.
- POS typecheck: `pnpm typecheck` passed.
- POS lint: `pnpm lint` passed with `41` pre-existing warnings and `0` errors.
- Rust focused test: `account_payment_receipt_uses_account_layout_without_sale_lines_or_vat` passed.
- Backend PHPUnit: `1101` tests, `3689` assertions, `107` skipped, `2` incomplete, `16` deprecations.
- Backend PHPStan: level 8 on `app/Modules/POS app/Modules/Fiscal` passed.
- Backend Pint: `app/Modules/POS app/Modules/Fiscal` passed.
- §14.3 chokepoint gate: passed.
- `.PASS_2B_PENDING` absence check: passed.

## Residual Notes

- Full Rust `cargo fmt --check` remains unsuitable as a task gate because unrelated pre-existing Rust files are not formatted. The touched Rust template was formatted with `rustfmt`.
- POS lint still reports repository-wide pre-existing warnings. The new HomePage dependency warning introduced during this task was removed before commit.
