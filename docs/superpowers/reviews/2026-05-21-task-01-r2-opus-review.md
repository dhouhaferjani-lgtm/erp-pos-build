# Phase 2 Task 01 R2 — Opus-Equivalent Adversarial Review

**Date:** 2026-05-21  
**Original Task 1 commit reviewed:** `eef3946f3` (`Phase 2.1.1: Add account payment payload contract`)  
**R2 fix commit reviewed:** `266738f53` (`Phase 2.1.2: Reject premature account aliases`)  
**Prior reviews:** `docs/superpowers/reviews/2026-05-21-task-01-opus-review.md`, `docs/superpowers/reviews/2026-05-21-task-01-r2-codex-review.md`  
**Verdict:** APPROVE

## Findings

None.

The R1 request-changes items are closed. I did not find a fix-local regression in R2.

## R2 Verification

1. **Original `ACCOUNT_PAYMENT` now rejects non-null `references.server_customer_alias_id` at the PHP boundary.**

   `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:585-599` still requires an exact populated `references` object shape, still accepts `external_reference` and `related_sale_receipt_event_id` as nullable strings, and now throws `payload_account_payment_server_customer_alias_forbidden` whenever `server_customer_alias_id !== null`. This gates both direct validator use and `StrictCanonicalParser`, because the parser path delegates per-event constraints to the same validator.

   Coverage exists in:

   - `apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php:217-230`
   - `apps/api/tests/Unit/Fiscal/StrictCanonicalParserTest.php:123-137`

2. **The TS v1 payload type prevents normal authoring from setting a non-null alias on original `ACCOUNT_PAYMENT`.**

   `apps/pos/src/lib/fiscal/payloads/AccountPaymentPayload.ts:77-80` narrows `AccountPaymentReferences.server_customer_alias_id` from `string | null` to `null`. A normally typed `AccountPaymentPayload` can no longer assign a server alias in the original event without escaping the type system.

3. **Populated references positive coverage exists while alias remains null.**

   R2 added positive PHP validator and strict-parser coverage for populated `references` carrying both legal current fields and `server_customer_alias_id: null`:

   - `apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php:140-154`
   - `apps/api/tests/Unit/Fiscal/StrictCanonicalParserTest.php:107-121`

   The POS-side fixture/type test also covers a typed populated-reference authoring shape with null alias:

   - `apps/pos/src/lib/fiscal/__tests__/AccountPaymentPayload.test.ts:44-55`

4. **No new defect introduced by the R2 fix itself.**

   The implementation rejects only the future reconciliation alias. It does not silently downgrade populated references to `null`, does not remove the references object shape check, and does not weaken the legal current reference fields.

## Clean Checks

- **Contract drift:** Spec §6 still says `server_customer_alias_id` is populated only after server reconciliation in an `ACCOUNT_PAYMENT_RECONCILED` follow-up event, not mutated into the original event. PHP `PAYLOAD_KEYS` and TS `ACCOUNT_PAYMENT_PAYLOAD_KEYS` remain aligned on the 20 top-level `ACCOUNT_PAYMENT` keys.
- **Fail-loud:** Non-null alias now fails at validator/parser boundary with a dedicated forensic prefix instead of being accepted as a premature server fact.
- **Dead-path rebuild:** R2 exercises both direct validator and strict canonical parser paths; the parser path is live through the shared `FiscalEventPayloadRegistry` and `FiscalPayloadConstraintValidator`.
- **Discriminated-union/reference matrix:** Account-payment tests now cover synced and pending customers, stale and fresh snapshots, local and foreign currency, nullable references, populated legal references, and forbidden non-null alias.
- **D16 bounded modules:** R2 touched only fiscal validator/parser tests and POS fiscal payload typing/tests. It did not add Treasury, Partner, Customer, Contact, B2B, or Accounting operational dependencies.
- **Constructor/service locator discipline:** R2 did not add `app()`, `App::make()`, `resolve()`, or `Auth::user()`.
- **Phase 1.5 tax-number gate:** The universal tax-number validator remains explicitly documented as a Phase 1.5 deferral, and R2 does not present the universal regex as final TN/FR validation.

I reran the focused R2 checks:

- `APP_KEY=... ./vendor/bin/phpunit tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php --filter account_payment` — 12 tests, 25 assertions.
- `APP_KEY=... ./vendor/bin/phpunit tests/Unit/Fiscal/StrictCanonicalParserTest.php --filter account_payment` — 4 tests, 17 assertions.
- `pnpm test -- AccountPaymentPayload.test.ts FiscalEventPayloadRegistry.test.ts accountPaymentCanonicalParity.test.ts` — 3 files, 15 tests.
- `APP_KEY=... ./vendor/bin/phpstan analyse --level=8 <R2 touched PHP paths>` — no errors.
- `./vendor/bin/pint --test <R2 touched PHP paths>` — pass.
- `git diff --check` — pass.

The R2 self-review additionally records the broader post-fix matrix as green: focused PHP Task 1 set, full backend Fiscal/POS slice, full POS suite, chokepoint sentinel, and Pass2B sentinel.

## Residual Risks

- The later `ACCOUNT_PAYMENT_RECONCILED` contract still needs to define where `server_customer_alias_id` becomes legal and how reconciliation references the original event. R2 correctly forbids it only on the original sealed `ACCOUNT_PAYMENT`.
- The validator still does not assert balance-snapshot arithmetic equality between `payment.amount`, `local_balance_snapshot.payment_amount`, and projected balances. This remains outside the R2 alias fix and was already noted as a later projection/authoring risk.
- TS type narrowing blocks normal typed authoring, but runtime/`any` escape hatches remain possible by definition. The PHP parser/validator boundary is therefore the load-bearing enforcement, and R2 now covers it.

## Final Verdict

APPROVE. R2 resolves the R1 alias-forgery and populated-references coverage gaps without introducing a new contract, parser, dependency, or tax-number-gate regression.
