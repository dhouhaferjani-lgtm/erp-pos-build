# Adversarial gate r2 — journal-entry number company scope (fiscal/POS + tenancy)

Reviewed `fix/je-number-company-scope` through `100ff5741`, including `git log -4`, the clean working tree, the r1 review at `2026-08-30-je-number-company-scope-gate-r1-fiscal.md`, and the complete diff from `ea8a38f15`. This was a scoped, read-only re-check except for this review artifact.

## Findings

No blockers remain in the requested scope.

### B1 — resolved: live constraint documentation and allocator immutability

- `rg` over `app/` and `tests/` finds `journal_entries_tenant_id_entry_number_unique` only at `app/Modules/Accounting/Domain/JournalEntryIndexNames.php:9`, where it is the legacy-name constant needed by the migration. No service, controller, or test claims that it remains the active uniqueness contract.
- `GeneralLedgerService.php:5689-5696` now accurately records `(company_id, entry_number)` persistence uniqueness and the deliberate tenant-wide JE-* sequence/lock pending LEDGER D-J0-2. `JournalEntryController.php:190-198` records the same contract for manual allocation; its implementation still scans `tenant_id` and takes `journal_entry_number:{tenantId}` (`:204-217`).
- `git diff ea8a38f15 -- app/Modules/Accounting/Domain/Services app/Modules/Accounting/Application app/Modules/Inventory/Application` contains only docblock changes in three files. A PHP token comparison against `ea8a38f15`, excluding whitespace/comments/docblocks, reported `checked=3 non_comment_changes=0`. No allocator behavior changed.

### B2 — resolved: real two-company opening paths

- `OpeningBatchNumberingCompanyScopeTest.php:54-68` creates two sibling companies in one tenant, runs the same real posting helper for both, and requires identical arrays: `OB-<year>-000001`, `OB-<year>-000002`, and `INV-OB-<year>-000001`.
- The first element comes from a real Accounting opening batch (`OpeningBatchType::Accounting`) validated and posted through `AccountingOpeningService` (`:112-139`). The third comes from a real stock opening posted through `OpeningBalancePostingService` (`:178-212`). Thus both required services run for both companies, and their first numbers are identical across the sibling-company boundary. The retained AR opening is an additional same-prefix check and correctly consumes `OB-...000002` within each company.
- This test is RED under the former `(tenant_id, entry_number)` unique: company A's company-scoped Accounting allocator persists `OB-...000001`; company B scans only company B, independently mints the same `OB-...000001`, and the old tenant unique rejects the second insert with PostgreSQL `23505` before the equality assertions. The stock allocator has the same causal shape for `INV-OB-...000001` if reached. The current company unique admits those sibling-company duplicates while still rejecting duplicates inside one company.

## r1 regression checks

- Census-before-drop holds: `up()` calls `assertNoCrossCompanyNumberCollisions()` before dropping the tenant unique (`migration :48-49`). The census covers all journal-entry rows, grouping unfiltered `entry_number` values across distinct companies (`:112-152`).
- Refusal holds: a non-zero census emits details and throws before DDL (`:141-149`); the migration test verifies that the tenant unique survives (`JournalEntryNumberCompanyScopeMigrationTest.php:67-90`).
- `down()` mirrors the safety order: it runs the same census before dropping company uniqueness and recreating tenant uniqueness (`migration :61-84`). The test verifies rollback refusal and preservation of the company unique (`migration test :117-125`).
- The non-unique `(tenant_id, entry_number)` lookup remains ensured (`migration :155-165`) and is asserted by the idempotency test (`migration test :51-65`).
- Fiscal hash and chain allocation are untouched from `ea8a38f15`: the targeted diff over `GeneralLedgerHashService` and `2026_07_08_100400_add_chain_sequence_unique_index_to_journal_entries.php` is empty. `entry_number` remains sealed hash input (`GeneralLedgerHashService.php:62-90`), while `uniq_je_company_chain_sequence` remains the partial company/chain unique (`chain migration :33-44`). The lane performs index DDL only and does not rewrite fiscal numbers or hashes.
- No POS path appears in the lane delta from `ea8a38f15`; the fix-round application edits are documentation plus the scoped opening regression test.
- Manifest checker passes: 1,486 Feature classes / 74 groups, 1,224 classes behind the existing execution gate, and 1 existing coverage-debt class. The lane raise notes remain present for Accounting and Migrations.
- `tests/Unit/Migrations` passes, including `NoBareEchoInMigrationsTest`; census output remains routed through `MigrationOutput`.

## Fresh test evidence

- SQLite, both lane paths: **4 passed, 22 assertions**.
- PostgreSQL j4, both lane paths using `DB_DATABASE=autoerp_test_j4 DB_CENTRAL_DATABASE=autoerp_test_j4 php artisan test -c phpunit-pgsql.xml ...`: **4 passed, 22 assertions**.
- `tests/Unit/Migrations`: **2 passed, 630 assertions**.

GATEVERDICT: APPROVED
