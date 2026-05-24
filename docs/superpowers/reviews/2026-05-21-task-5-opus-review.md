# Task 5 Opus Second-Pass Adversarial Review — Device ACCOUNT_CHARGE Authoring And Printable

Commit reviewed: `a41e9ddf0 Phase 3.5.1: Author account charges on device`

Scope reviewed: Task 5 diff only, plus surrounding payload contract, credit rules, customer attach types, POS fiscal-engine validation, and server validator precedent.

## Findings

### REQUEST-CHANGES: POS fiscal-engine validation still accepts ACCOUNT_CHARGE payloads the locked server contract rejects

`apps/pos/src/lib/fiscal/FiscalEventEngine.ts:1234-1235` validates references and then accepts any object-shaped `regime_extensions`; `validateAccountChargeReferences()` at `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:1539-1545` permits a non-null `references.related_sale_receipt_event_id`. The locked server validator rejects both a recursive `payments` key anywhere and non-null split-sale references: `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:879-887` and `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:1006-1019`.

This creates a device/server drift path: the POS can seal an `ACCOUNT_CHARGE` locally, increment sync state, and only fail later at ingest. That violates the Task 5 fail-loud requirement and the locked "no payments anywhere" contract.

Required fixes/tests:

- Add recursive `payments`-key rejection to the POS `ACCOUNT_CHARGE` validator, including inside `regime_extensions`.
- Reject non-null `references.related_sale_receipt_event_id` in v1, matching the server validator.
- Add `FiscalEventEngine.test.ts` cases that fail before append for `regime_extensions: { payments: [...] }` and for non-null `references.related_sale_receipt_event_id`.
- While in this branch, add parity tests for other server-only contract checks currently absent from the POS validator: `charge_terms.payment_terms_days` requires `due_date`, non-training `credit_decision.limit_exceeded=true` is forbidden, and `totals.amount_charged_to_account` / balance arithmetic mismatches are rejected before sealing.

### REQUEST-CHANGES: Authoring bypasses the inactive-customer credit rule

`buildCreditDecision()` hardcodes `is_active: true` at `apps/pos/src/lib/accountCharge/accountChargeService.ts:213-223`. The credit rules engine has an explicit inactive-customer rejection at `apps/pos/src/lib/accountCharge/creditRulesEngine.ts:90-91`, but Task 5 never passes the selected customer's real activity flag. The attached customer type also omits `is_active` (`apps/pos/src/stores/paymentStore.ts:129-145`), and `CustomerAttachPanel.fromMirror()` drops `row.is_active` when building the checkout customer (`apps/pos/src/components/customers/CustomerAttachPanel.tsx:21-39`).

That means any production caller with an already-attached stale/inactive customer object, restored checkout state, or future non-search attach path can seal a charge even though the device credit policy should reject it before append.

Required fixes/tests:

- Add `is_active` to `AttachedCheckoutCustomer`.
- Preserve `row.is_active` in `CustomerAttachPanel.fromMirror()` and set pending-created local customers explicitly active or otherwise policy-blocked according to the intended pending-customer rule.
- Pass `input.customer.is_active` into `evaluateAccountChargeCreditDecision()` instead of hardcoding `true`.
- Add an `accountChargeService.test.ts` case asserting an inactive selected customer throws `account_charge_credit_rejected:customer_inactive` and does not call `getFiscalEventEngine()` or `append()`.

### IMPORTANT: Business-customer authoring never emits the B2B invoice classification

`buildAccountChargePayload()` always sets `invoice_classification: 'b2c_charge_receipt'` at `apps/pos/src/lib/accountCharge/accountChargeService.ts:328`. The Phase 3 spec/locked D8 boundary requires business customer account charges to be distinguishable as `b2b_facture_draft_requested` so the later Document/B2B bridge has a live sealed input. With the current authoring path, a business customer can be charged but the future bridge will have no signal to draft a Facture; this is a dead-path risk for the planned B2B route.

Required fixes/tests:

- Set `invoice_classification` from the sealed customer category: exact `business` should produce `b2b_facture_draft_requested`; all other categories should remain `b2c_charge_receipt`.
- Add `accountChargeService.test.ts` coverage for business and non-business categories.
- Add POS engine validation coverage that rejects `b2b_facture_draft_requested` for non-business customers, matching server-side `payload_account_charge_invoice_classification_mismatch`.

### IMPORTANT: Printable mapping is too thin for the locked ACCOUNT_CHARGE_RECEIPT shape

`buildAccountChargePrintable()` exposes only a minimal subset (`apps/pos/src/lib/accountCharge/accountChargePrintable.ts:14-32`, `apps/pos/src/lib/accountCharge/accountChargePrintable.ts:71-95`). It omits seller name/address/tax number, terminal id, shift id, account identifier/category, VAT breakdown rows, credit limit, explicit charge amount, staleness warning fields, and training marker. The spec's printable is supposed to be POS-core usable without live Partner/Treasury enrichment; this shape cannot render the required receipt from sealed data alone.

Required fixes/tests:

- Extend `AccountChargePrintable` to carry the required sealed-payload display fields from spec §10.
- Add mapper assertions for seller identity, terminal id, shift id, VAT breakdown, credit limit/available credit, staleness warning, and training marker.

## Passing Checks

- `authorAccountCharge()` uses `FiscalEventEngine.append()` with `event_type: 'ACCOUNT_CHARGE'`, `source_event_class: 'account_charge'`, and `source_event_id: accountChargeUuid` (`apps/pos/src/lib/accountCharge/accountChargeService.ts:397-412`).
- Payment lines are rejected before engine resolution/append in the Task 5 authoring service (`apps/pos/src/lib/accountCharge/accountChargeService.ts:187-193`, covered by `apps/pos/src/lib/accountCharge/__tests__/accountChargeService.test.ts:172-181`).
- I found no `receiptApi.createReceipt()` or POS payment API calls in the new account-charge authoring layer.
- Credit-balance offset math in the authoring service preserves the Task 4 R2 rule: receivable increases by charge, credit remains unchanged, and projected net is clamped at zero (`apps/pos/src/lib/accountCharge/accountChargeService.ts:167-173`), with focused test coverage in `apps/pos/src/lib/accountCharge/__tests__/accountChargeService.test.ts:194-222`.
- No new production Laravel `app()`, `App::make()`, or `resolve()` usage was introduced in this Task 5 POS-only commit.
- No class-level `markTestSkipped()` was added. The existing `describe.skip` gate in `FiscalEventEngine.test.ts` predates this commit and is not a Task 5 regression.

## Verification

Static adversarial review plus targeted `rg`, `git show`, and `git diff --check` inspection. I did not rerun the full POS/API suites for this second-pass review.

## Verdict

REQUEST-CHANGES

R2 fixes must include the tests above and should receive a fresh Codex self-review plus Opus second-pass review.
