# Task 5 R2 Codex Self-Adversarial Review — Device ACCOUNT_CHARGE Authoring And Printable

Commits reviewed:

- `a41e9ddf0 Phase 3.5.1: Author account charges on device`
- `cc6f8477f Phase 3.5.2: Harden account charge authoring`

Prior Opus review: `docs/superpowers/reviews/2026-05-21-task-5-opus-review.md` — REQUEST-CHANGES.

## Verdict

APPROVE

## R2 Findings Closure

### 1. POS/server ACCOUNT_CHARGE validation drift

PASS. R2 adds recursive `payments`-key rejection for ACCOUNT_CHARGE payloads after the top-level keyset check, so the existing top-level `payload_extra_field:payments` test remains stable while nested `regime_extensions.payments` now fails loud before sealing. R2 also rejects non-null `references.related_sale_receipt_event_id`, missing due date when `payment_terms_days` is non-null, non-training `credit_decision.limit_exceeded=true`, amount/balance arithmetic mismatches, and B2B facture classification for non-business customers.

Residual note: The POS arithmetic check preserves the Task 4 R2 credit-offset rule by clamping projected net at zero. That is the contract axis explicitly carried into Task 5 review.

### 2. Inactive-customer bypass

PASS. `AttachedCheckoutCustomer` now carries `is_active`; `CustomerAttachPanel.fromMirror()` preserves `row.is_active`; pending local customers are explicitly active for the pending-create path; and `buildCreditDecision()` passes the selected customer activity flag into `evaluateAccountChargeCreditDecision()` instead of hardcoding `true`. The new service test asserts inactive customers reject before transaction, engine resolution, or append.

### 3. B2B invoice classification

PASS. `buildAccountChargePayload()` emits `b2b_facture_draft_requested` only for `customer_category === 'business'`; non-business categories remain `b2c_charge_receipt`. The fiscal engine now rejects B2B classification when the customer category is not `business`.

### 4. Printable shape too thin

PASS. `AccountChargePrintable` now exposes sealed-payload fields needed for a standalone POS-core receipt: seller identity/address/tax number, terminal id, shift id, customer category/account identifier, charge amount, credit limit and available credit, VAT breakdown, staleness flags/reason, and training marker. The printable test asserts these fields.

## Standing-Pattern Checks

- Cross-tenant/company safety: PASS. Customer tenant/company checks still happen inside the credit-rules engine before approval; no new FK lookup or unscoped resolver was added.
- Fail-loud versus silent downgrade: PASS. R2 adds more pre-seal validation and does not introduce fallback to SALE_RECEIPT, ACCOUNT_PAYMENT, receipt APIs, or payment APIs.
- Dead-path rebuild: PASS for Task 5 scope. The authoring service and validator paths are directly tested. UI caller wiring remains later plan scope.
- Contract drift: PASS. POS validator now matches the server-side account-charge invariants called out by Opus R1 for recursive payments, references, terms, limit-exceeded, and classification, while preserving the Phase 3 credit-offset rule.
- D16 bounded modules: PASS. No Treasury, Accounting, B2B, Partner, or operational Customer module dependency was added to the fiscal engine or account-charge service.
- Constructor injection / service location: PASS. No production `app()`, `App::make()`, or `resolve()` usage was introduced.
- Skip hygiene: PASS. No `markTestSkipped`, `it.skip`, or class-level skip was added by Task 5 R2.
- R2-fix risk: PASS. R2 touched shared customer snapshot typing and account-payment tests; full POS typecheck and the full POS test suite caught and confirmed fixture updates.

## Verification Evidence

- `pnpm test -- accountChargeService.test.ts accountChargePrintable.test.ts FiscalEventEngine.test.ts accountChargeCanonicalParity.test.ts paymentStore.customerAttach.test.ts accountPaymentService.test.ts` — 102 tests passed.
- `pnpm typecheck` — passed.
- Focused `eslint` on touched POS files — passed.
- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/` — 1173 tests, 3999 assertions, 107 skipped, 2 incomplete, 16 deprecations.
- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpstan analyse --level=8 --memory-limit=1G` — no errors.
- `./vendor/bin/pint --test app/Modules/POS app/Modules/Fiscal tests/Feature/POS tests/Feature/Fiscal tests/Unit/Fiscal` — pass.
- `pnpm test` — 168 files, 1498 tests passed.
- `pnpm typecheck && pnpm lint` — typecheck passed; lint passed with 41 pre-existing warnings and 0 errors.
- `bash apps/api/scripts/check-saleReceipt-chokepoints.sh && bash apps/pos/scripts/check-pass-2b-pending.sh` — pass.
