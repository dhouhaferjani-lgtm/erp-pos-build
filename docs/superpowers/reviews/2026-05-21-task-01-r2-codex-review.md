# Phase 2 Task 01 R2 — Codex Self-Review

**Date:** 2026-05-21  
**R1 commit reviewed:** `eef3946f3`  
**R2 commit reviewed:** `266738f53` (`Phase 2.1.2: Reject premature account aliases`)  
**Prior Opus-equivalent review:** `docs/superpowers/reviews/2026-05-21-task-01-opus-review.md`  
**Verdict:** APPROVE.

## R1 Finding Rechecked

1. **Non-null `references.server_customer_alias_id` is now rejected on original ACCOUNT_PAYMENT.**

   The PHP validator now throws `payload_account_payment_server_customer_alias_forbidden` when an original `ACCOUNT_PAYMENT` payload carries a non-null `references.server_customer_alias_id`. This matches the Phase 2 spec rule that the alias field is populated only by a later `ACCOUNT_PAYMENT_RECONCILED` follow-up event.

2. **TS v1 payload type now narrows the alias field to null.**

   `AccountPaymentReferences.server_customer_alias_id` is now typed as `null`, preventing normal TS authoring code from populating a premature server alias.

3. **Populated references are covered.**

   PHP validator and strict-parser tests now accept populated `references` with `external_reference` and `related_sale_receipt_event_id` while keeping `server_customer_alias_id` null. Negative validator and parser tests cover non-null alias rejection.

## Standing Pattern Review

- **Contract drift:** PHP validator, parser tests, TS payload type, and spec are aligned on alias semantics.
- **Fail-loud:** Premature alias now fails at parser/validator boundary, before projection or reconciliation code can trust it.
- **Dead-path rebuild:** R2 tests exercise both `validatePerEventConstraints()` and `StrictCanonicalParser::parse()` paths.
- **Discriminated-union matrix:** R2 closes the missing populated-reference branch without weakening nullable-reference coverage.
- **D16 / bounded modules:** No Treasury/Partner/Customer/Contact/B2B/Accounting dependency was introduced.
- **Constructor injection / helpers:** No `app()`, `App::make()`, `resolve()`, or `Auth::user()` was introduced.
- **R2 regression pattern:** R2 was reviewed as a fresh change. The TS type is narrowed rather than widened; PHP accepts legal current references and rejects only the future reconciliation alias.
- **Phase 1.5 gate:** R2 does not alter the unresolved per-country tax-number deployment gate.

## Verification

- `APP_KEY=... ./vendor/bin/phpunit tests/Unit/Fiscal/FiscalEventTypeTest.php tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php tests/Unit/Fiscal/StrictCanonicalParserTest.php tests/Unit/Fiscal/CanonicalPayloadReaderTest.php` — 193 tests, 813 assertions.
- `APP_KEY=... ./vendor/bin/phpstan analyse --level=8 <R2 touched PHP paths>` — no errors.
- `./vendor/bin/pint --test <R2 touched PHP paths>` — pass.
- `pnpm test -- FiscalEventPayloadRegistry.test.ts AccountPaymentPayload.test.ts accountPaymentCanonicalParity.test.ts` — 15 tests.
- `APP_KEY=... ./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/` — 1087 tests, 3625 assertions; existing 16 deprecations, 107 skipped, 2 incomplete.
- `pnpm test` — 153 files, 1392 tests.
- `bash apps/api/scripts/check-saleReceipt-chokepoints.sh` — PASS.
- `bash apps/pos/scripts/check-pass-2b-pending.sh` — PASS.
- `git diff --check` — pass.

## Residual Risks

- Later `ACCOUNT_PAYMENT_RECONCILED` work must decide where `server_customer_alias_id` is legal. This R2 fix only enforces the original sealed `ACCOUNT_PAYMENT` contract.

## Verdict

APPROVE. The Opus-equivalent R1 request-changes items are resolved; send R2 to Opus-equivalent review before committing review files or pushing.
