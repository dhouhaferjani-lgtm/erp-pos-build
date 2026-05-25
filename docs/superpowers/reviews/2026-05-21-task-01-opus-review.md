# Phase 2 Task 01 — Opus-Equivalent Adversarial Review

**Date:** 2026-05-21  
**Commit reviewed:** `eef3946f3` (`Phase 2.1.1: Add account payment payload contract`)  
**Review target:** Task 1 — `ACCOUNT_PAYMENT` Payload Contract And Drift Gates  
**Verdict:** REQUEST-CHANGES

## Findings By Severity

### P1 — `ACCOUNT_PAYMENT` accepts a server-reconciliation alias in the original sealed event

- `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:585-595`
- `apps/pos/src/lib/fiscal/payloads/AccountPaymentPayload.ts:77-80`
- Spec: `docs/superpowers/specs/2026-05-21-pos-customer-accounts-phase2-spec-v1.md:189-193`

The v1 spec explicitly says `references.server_customer_alias_id` is populated after server reconciliation only in an `ACCOUNT_PAYMENT_RECONCILED` follow-up event and is not mutated into the original `ACCOUNT_PAYMENT`. The PHP validator currently accepts any non-empty string for `references.server_customer_alias_id`, and the POS type exposes it as `string | null` for the original payload.

That lets a device-authored `ACCOUNT_PAYMENT` carry a value that claims a server-side alias resolution before the server has reconciled it. This is a contract drift and fail-loud gap: pending-create/customer-alias semantics are part of the immutable fiscal fact, and accepting the reconciled alias in the original event can make later projection/reconciliation code trust a forged or premature alias instead of waiting for `ACCOUNT_PAYMENT_RECONCILED`.

Required fix: for `FiscalEventType::ACCOUNT_PAYMENT`, require `references.server_customer_alias_id === null` whenever `references` is present. Narrow the TS v1 payload type accordingly if feasible, and add a negative validator/parser test proving non-null `server_customer_alias_id` is rejected. A populated-references positive fixture should populate `related_sale_receipt_event_id` and/or `external_reference`, while keeping `server_customer_alias_id` null.

### P2 — Populated references are not covered despite the locked matrix

- `apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php:64-138`
- `apps/api/tests/Unit/Fiscal/StrictCanonicalParserTest.php:82-104`
- Spec acceptance: `docs/superpowers/specs/2026-05-21-pos-customer-accounts-phase2-spec-v1.md:295-300`

The acceptance matrix requires nullable vs populated `references`. The added positives cover baseline nullable references, pending/stale/card, and foreign currency, but I did not find a positive fixture where `references` is a populated object. The strict parser also only accepts the baseline account-payment envelope plus an extra-key negative.

This matters because `references` is the subtle future-proof block with one field that is legal only later (`server_customer_alias_id`) and two fields that are legal now. Add at least one PHP validator positive and one parser positive for populated references with `server_customer_alias_id: null`; keep the non-null alias case as the P1 negative.

## Clean Checks

- PHP and TS top-level key sets match the spec's 20 required `ACCOUNT_PAYMENT` keys in the DTO, validator `PAYLOAD_KEYS`, and POS `ACCOUNT_PAYMENT_PAYLOAD_KEYS`.
- `ACCOUNT_PAYMENT` is implemented at event version 1 in PHP and TS registries, and the TS server-only set remains limited to `TERMINAL_REGISTRY_SNAPSHOT` and `COMPANY_DAY_CLOSURE_MANIFEST`.
- `StrictCanonicalParser` has a live path for `ACCOUNT_PAYMENT` through the registry DTO mapping plus shared `FiscalPayloadConstraintValidator`; no dead DTO-only path found.
- Required fail-loud cases are present for invalid customer sync status, zero payment outside training, stale flags without reason, foreign-currency half-pair, and invalid universal tax number.
- No new direct Treasury/Partner/Customer/Contact/B2B/Accounting operational dependency was introduced in the changed Fiscal/POS payload-contract files.
- No new `app()`, `App::make()`, `resolve()`, or `Auth::user()` call was introduced in the touched fiscal replay/parser/reader paths.
- The `FiscalEventType::isImplemented()` addition plus `isImplementedInPhase1()` alias is semantically current-implemented-set compatible; no live PHP call site still depends on the historical Phase 1-only meaning.
- The Phase 1.5 tax-number gate remains visible: the validator documents and uses the universal regex only, not final TN/FR validation.

## Residual Risks

- The validator does not assert that `local_balance_snapshot.payment_amount` equals `payment.amount`, nor that the projected balance fields arithmetically derive from the before-balance fields. I am not marking this as a Task 1 finding because the current plan focuses on shape/discriminants, but later authoring/projection tasks should pin this before receipts depend on the snapshot values.
- I did not rerun the implementer's full verification matrix; this review is based on the actual diff from `c0fbc3c6` to `eef3946f3`, the cited specs, and targeted static inspection.

## Final Verdict

REQUEST-CHANGES. The core contract is close, but the original `ACCOUNT_PAYMENT` payload must fail loud on non-null `references.server_customer_alias_id`, and the populated-references branch needs explicit positive coverage before Task 1 is approval-ready.
