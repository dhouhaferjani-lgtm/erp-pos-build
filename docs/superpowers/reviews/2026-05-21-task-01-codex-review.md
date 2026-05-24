# Phase 2 Task 01 — Codex Self-Review

**Date:** 2026-05-21  
**Commit reviewed:** `eef3946f3` (`Phase 2.1.1: Add account payment payload contract`)  
**Plan task:** Task 1 — ACCOUNT_PAYMENT Payload Contract And Drift Gates  
**Verdict:** APPROVE.

## Scope Reviewed

Task 1 adds the ACCOUNT_PAYMENT v1 canonical payload contract on PHP and TS:

- PHP payload DTO and canonical reader view DTOs.
- PHP registry support and strict parser/validator support.
- TS registry support and payload fixture/types.
- TS/PHP canonical byte parity fixture for the golden ACCOUNT_PAYMENT payload.
- Tests for registry, exact key set, strict parser, canonical reader, discriminants, and negative validator cases.

## Standing Pattern Review

- **Cross-tenant FK safety:** No DB lookups or FK writes are introduced in Task 1. Customer, seller, payment method, repository, and alias identifiers remain sealed payload data only; tenant/company lookup enforcement is deferred to later projection/sync tasks per plan.
- **Fail-loud vs silent downgrade:** Parser and validator reject unimplemented types, missing keys, extra keys, invalid customer sync status, zero payment outside training, stale flags without reason, foreign-currency pair mismatch, and invalid seller tax number. No fallback coercion was added.
- **Dead-path rebuild:** The PHP payload DTO is reached by `FiscalEventPayloadRegistry` and `StrictCanonicalParser`; canonical view DTOs are reached by `CanonicalPayloadReader::forAccountPayment()` and dedicated tests; TS payload fixture is reached by parity tests.
- **Discriminated-union matrix:** Tests cover `synced` and `pending_create`, stale and fresh snapshots, local and foreign currency, nullable references, fixed `receipt_type_code=ACCOUNT_PAYMENT`, and fixed `treasury_allocation_policy=FIFO`.
- **Contract drift:** ACCOUNT_PAYMENT top-level keys are in PHP `PAYLOAD_KEYS`, TS `ACCOUNT_PAYMENT_PAYLOAD_KEYS`, PHP DTO `toArray()`, and TS golden canonical bytes. The TS canonical encoder test pins the exact byte output.
- **Per-method skips:** No new skips were added.
- **Skip citation accuracy:** No skip citations were introduced.
- **Constructor injection / container helpers:** No `app()`, `App::make()`, `resolve()`, or `Auth::user()` was added. New services have no dependencies.
- **D16 bounded modules:** Task 1 stays in Fiscal/POS payload-contract code. It does not import Treasury, Partner, Customer, Contact, B2B, or Accounting from POS-core projection paths.
- **R2-fix regression pattern:** A self-review naming drift on `FiscalEventType::isImplementedInPhase1()` was fixed before this review by adding `isImplemented()` and keeping the old helper as a compatibility alias.
- **Phase 1.5 gate:** This commit does not change the roadmap or deployment status. Phase 2 remains not customer-facing deployment-ready until Phase 1.5 per-country tax-number validation is implemented, reviewed, and pushed.

## Verification

- `APP_KEY=... ./vendor/bin/phpunit tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php tests/Unit/Fiscal/StrictCanonicalParserTest.php tests/Unit/Fiscal/CanonicalPayloadReaderTest.php` — 184 tests, 733 assertions.
- `APP_KEY=... ./vendor/bin/phpunit tests/Unit/Fiscal/FiscalEventTypeTest.php tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php tests/Unit/Fiscal/StrictCanonicalParserTest.php tests/Unit/Fiscal/CanonicalPayloadReaderTest.php` — 188 tests, 774 assertions.
- `APP_KEY=... ./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/` — 1083 tests, 3614 assertions; existing 16 deprecations, 107 skipped, 2 incomplete.
- `APP_KEY=... ./vendor/bin/phpstan analyse --level=8 <touched PHP paths>` — no errors.
- `./vendor/bin/pint --test <touched PHP paths>` — pass.
- `pnpm test -- FiscalEventPayloadRegistry.test.ts AccountPaymentPayload.test.ts accountPaymentCanonicalParity.test.ts` — 14 tests.
- `pnpm test` — 153 files, 1391 tests.
- `pnpm lint` — exit 0; existing warnings only.
- `bash apps/api/scripts/check-saleReceipt-chokepoints.sh` — PASS, 8 call sites reconciled.
- `bash apps/pos/scripts/check-pass-2b-pending.sh` — PASS.
- `git diff --check` — pass.

## Residual Risks

- The ACCOUNT_PAYMENT validator intentionally keeps the Phase 1 universal seller/customer tax-number regex. This is acceptable only because Phase 1.5 per-country strict tax-number validation remains a deployment gate.
- The exact business semantics for balance arithmetic are not enforced in Task 1 beyond shape, scale, and nonzero payment. Later device authoring/projection tasks must preserve the sealed snapshot and test cross-company/customer alias behavior.

## Verdict

APPROVE. Ready for Opus-equivalent second-pass adversarial review.
