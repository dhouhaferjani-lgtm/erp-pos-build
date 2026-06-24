# R-2 Codex Review — GL Hash Chain Unification

Date: 2026-06-24
Reviewer: Codex (adversarial self-review)
Scope:
- `apps/api/app/Modules/Accounting/Application/Services/GeneralLedgerHashService.php`
- `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php`
- `apps/api/tests/Feature/Accounting/GLIntegrationTest.php`
- `apps/api/tests/Feature/Accounting/POSAccountChargeJournalEntryTest.php`
- `apps/api/tests/Feature/Fiscal/TreasuryAccountChargeBridgeTest.php`

## Verdict

APPROVE.

No blocking issues found in the R-2 diff. `GeneralLedgerService::postEntryWithOptionalActor()` now uses `GeneralLedgerHashService::calculateHash()` instead of the old local JSON hashing path, assigns `chain_sequence`, persists nullable genesis `previous_hash`, and hashes with the company currency so `verifyChain()` recomputes the same serialized totals. `GeneralLedgerHashService::verifyChain()` resolves company currency directly, so verification no longer depends on `CompanyContext`.

## Review Notes

- The manual-only genesis case asserts sequence `1`, null `previous_hash`, non-null `fiscal_hash`, and a valid chain.
- The mixed Chain-A/Chain-B case creates an invoice GL entry via `AccountingService`, posts a manual draft via `GeneralLedgerService`, and asserts sequence `2`, previous-hash continuity, and `verifyChain()`.
- The COGS regression clears `CompanyContext` before posting and verifies the chain while context remains cleared.
- Two Opus residual regressions are covered: no explicit currency/no context, and caller currency differing from company currency.
- Invoice media attachment wiring was not touched; it remains deferred for the unified media management transition.

## Residual Risks

- Existing rows previously hashed by the old Chain-B JSON algorithm still require migration/backfill policy if present in a target environment. This change makes new postings verifiable but does not rewrite historical rows.
- Chain sequence assembly still uses separate reads for previous hash and next sequence. Concurrency hardening is pre-existing and remains outside R-2.
- `verifyChain()` now throws if company currency cannot be resolved. That is fail-loud and preferable to masking an invalid company id, but callers expecting only `true`/`false` should be audited later.

## Verification

- RED before implementation: focused R-2 tests failed for null `chain_sequence` and no-context COGS scale resolution.
- RED after Opus residual review: no-currency/no-context posting threw `UnboundCompanyContextException`; mismatched caller currency produced `verifyChain() === false`.
- GREEN focused R-2 filter: 5 tests, 18 assertions.
- GREEN scoped hash/accounting files: 76 tests, 344 assertions.
- PHPStan level 8:
  - Passed for production changed files and the smaller changed test files.
  - `GLIntegrationTest.php` full-file analysis still fails on 43 pre-existing nullable-line / iterable-type issues; the new COGS `product_id` fixture error was fixed.
- Pint scoped check passed.
