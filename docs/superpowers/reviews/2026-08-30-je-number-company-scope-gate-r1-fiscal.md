# Adversarial gate r1 — journal-entry number company scope (fiscal/POS + tenancy)

Reviewed commit: `ea8a38f15` (`fix/je-number-company-scope`), parent/base `50c103712`. The branch was committed during this read-only review; the final worktree was clean before this review artifact was added.

## Findings

### BLOCKER 1 — the required legacy-constraint-name census is not clean

The change removes the tenant unique, but tracked live sources still describe `journal_entries_tenant_id_entry_number_unique` as the active database contract:

- `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:5689-5701` says the only unique index is `(tenant_id, entry_number)` and is “deliberately left untouched.”
- `apps/api/app/Modules/Accounting/Presentation/Controllers/JournalEntryController.php:187-197` says its tenant-wide scan matches that old unique.
- `apps/api/tests/Feature/Accounting/JournalEntryNumberingTenantScopeTest.php:33-44` and `:68-73` assert/document the same obsolete index contract.

`git grep -n journal_entries_tenant_id_entry_number_unique HEAD -- apps/api ':!apps/api/backup_before_phase0.sql'` returned those three references in addition to `JournalEntryIndexNames.php:9`. This fails the requested “no live code references except constants/migration” condition and leaves load-bearing allocator/locking documentation factually wrong after the migration.

Required fix: update the live service/controller/test documentation to say JE allocation remains tenant-wide by owner ruling and advisory-lock contract even though persistence uniqueness is now company-scoped. Then repeat the grep; only the legacy-name constant and migration references should remain.

### BLOCKER 2 — the named end-to-end service pair is not covered

`OpeningBatchNumberingCompanyScopeTest` does use real services for two sibling companies, but its OB leg imports and calls `ArApOpeningService` (`apps/api/tests/Feature/Accounting/OpeningBatchNumberingCompanyScopeTest.php:17`, `:111-134`), which delegates to `ArApOpeningLedgerService::postOpeningEntry()` (`apps/api/app/Modules/Document/Application/Services/ArApOpeningService.php:378-385`). Its stock leg correctly calls `OpeningBalancePostingService` (`OpeningBatchNumberingCompanyScopeTest.php:22`, `:154-165`). It never calls the specifically required `AccountingOpeningService`, whose independent OB allocator is at `apps/api/app/Modules/Accounting/Application/Services/AccountingOpeningService.php:897-936`.

The existing test is valuable and reproduces the staging-class failure, but it does not satisfy the stated `AccountingOpeningService / OpeningBalancePostingService` end-to-end requirement. Add a real `AccountingOpeningService` post for both companies (or replace the AR leg if that is the owner-intended surface) and retain the inventory leg.

## Migration and tenancy assessment

- Census-before-drop is correctly ordered in `up()` (`2026_08_30_100900_enforce_company_scoped_journal_entry_numbers.php:36-49`), and company uniqueness is then installed at `:51-58`.
- The census groups every `entry_number` across distinct companies with no state/date/deletion predicate (`:112-128`), emits `journal_entries.number_scope_census collisions=N` (`:130-152`), and throws before DDL when any collision group exists (`:142-149`). PostgreSQL uses `array_agg(DISTINCT company_id)` and SQLite uses `group_concat(DISTINCT company_id)` (`:114-116`).
- `journal_entries` is not soft-deletable: `JournalEntry` uses `HasUuids` only (`apps/api/app/Modules/Accounting/Domain/JournalEntry.php:45-48`), and the table definition has no `deleted_at` (`apps/api/database/migrations/tenant/2025_11_30_100000_create_journal_entries_table.php:13-45`). Therefore “lifetime rows” means all rows, and the unfiltered census covers the complete table; there are no soft-deleted rows to omit.
- Healthy fresh databases census zero rows and proceed. Already-company-scoped re-entry returns safely while preserving the non-unique tenant lookup (`2026_08_30_100900_enforce_company_scoped_journal_entry_numbers.php:36-45`, `:155-166`). Unsupported drivers, absent table, and any absent required column return without throwing (`:87-109`). These absent-schema paths are clear in code but are not directly pinned by the new migration test.
- `down()` refuses before dropping company uniqueness when sibling-company duplicates would violate the restored tenant unique (`:61-84`). The migration test exercises this refusal and verifies the company unique survives (`apps/api/tests/Feature/Migrations/JournalEntryNumberCompanyScopeMigrationTest.php:117-125`). Same-company duplication is rejected at `:106-115`.
- Named constants are centralized at `apps/api/app/Modules/Accounting/Domain/JournalEntryIndexNames.php:7-12`; the non-unique `(tenant_id, entry_number)` lookup is preserved by `ensureLookupIndex()` (`migration :155-166`).

## Fiscal assessment

- The change does not edit sealing code, chain allocation, or the separate partial unique. `uniq_je_company_chain_sequence` remains `(company_id, chain_sequence) WHERE chain_sequence IS NOT NULL` at `apps/api/database/migrations/tenant/2026_07_08_100400_add_chain_sequence_unique_index_to_journal_entries.php:33-44`; a targeted `git diff HEAD^ HEAD` over that migration, `GeneralLedgerHashService`, and `GeneralLedgerService` was empty.
- Contrary to the proposed safe assumption, `entry_number` **is part of the sealed GL hash input**. `GeneralLedgerHashService` declares `entry_number|entry_date|company_id|total_debit|total_credit` and serializes `$entry->entry_number` at `apps/api/app/Modules/Accounting/Application/Services/GeneralLedgerHashService.php:62-90`. This migration performs index-only DDL and never rewrites an entry number, so existing seals remain byte-stable. Operational risk: if a non-zero census is “resolved” by renumbering a sealed row, its stored fiscal hash and every subsequent link become invalid. A collision tenant must remain refused until a fiscal-safe remediation/reseal decision is approved; operators must not directly edit sealed `entry_number` values.
- Ordinary `JE-*` allocation is still tenant-wide: it takes `journal_entry_number:{tenantId}` and scans by `tenant_id` (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:5671-5680`, `:5727-5747`); the manual controller does the same (`apps/api/app/Modules/Accounting/Presentation/Controllers/JournalEntryController.php:203-226`). Company-scoped persistence therefore accepts those globally unique values. Single-company tenants see no allocation behavior change.
- Opening allocators remain company-scoped: `AccountingOpeningService` scans `company_id` for `OB-*` (`AccountingOpeningService.php:912-936`), `ArApOpeningLedgerService` does likewise (`apps/api/app/Modules/Accounting/Application/Services/ArApOpeningLedgerService.php:253-287`), and inventory scans `company_id` for `INV-OB-*` (`apps/api/app/Modules/Inventory/Application/Services/OpeningBalancePostingService.php:380-405`). No allocator was edited by this commit.

## Regression evidence

- PostgreSQL parent/base replay (`50c103712`) of the new end-to-end class: **RED**, 1 failed, `SQLSTATE[23505]`, duplicate `(tenant_id, entry_number) ... OB-2026-000001`, at `ArApOpeningLedgerService.php:148` via `ArApOpeningService.php:381`.
- Current commit SQLite, both new paths: **4 passed, 20 assertions**.
- Current commit PostgreSQL j4, both new paths using the required environment/config: **4 passed, 20 assertions**.
- Parent/base SQLite replay passed because the old tenant unique is not retained in that fully migrated SQLite schema; the production-relevant PostgreSQL replay is the actual red proof. The explicit migration test restores the old unique before exercising both drivers (`JournalEntryNumberCompanyScopeMigrationTest.php:141-149`).
- Current same-company duplicate refusal is covered by the migration test (`:92-115`).

## Manifest and requested suite results

- Manifest checker: **PASS** — 1,486 Feature classes / 74 groups; 1,224 classes remain behind the existing execution gate and 1 class remains existing coverage debt.
- Raise notes explicitly cite SQLite and PostgreSQL j4 at `apps/api/tests/feature-lane-manifest.json:672-676` and `:864-868`.
- `tests/Feature/Migrations` on SQLite: **59 passed, 6 skipped, 194 assertions**. All six skips were declared PostgreSQL-only/`CONCURRENTLY` paths.
- `tests/Unit/Migrations`: **2 passed, 630 assertions**. This is `NoBareEchoInMigrationsTest`; census output uses `MigrationOutput` (`migration :222-229`).
- The two new paths were rerun after commit on both databases and remained green with the counts above.

## Deployment/readout for the nine-tenant promotion

For each of the 9 tenant databases, the operator must see:

1. `journal_entries.number_scope_census collisions=0`
2. `journal_entries.number_scope unique=(company_id,entry_number)`

`MigrationOutput` logs and writes those messages to the console outside tests (`apps/api/app/Shared/Database/MigrationOutput.php:13-29`). On a collision the readout becomes `journal_entries.number_scope_census collisions=N number=... companies=... company_ids=...`, then a `RuntimeException` refuses that tenant before the old unique is dropped (`migration :141-149`). Direct fleet `tenants:migrate` is fail-fast at the first tenant exception; earlier tenants may already be migrated and later tenants remain pending. The repository's rolling wrapper instead isolates and aggregates failures (`apps/api/app/Modules/Tenant/Application/Commands/RollingTenantMigrationCommand.php:18-35`, `:80-103`). In either mode, do not override the refusal or renumber a sealed entry ad hoc.

GATEVERDICT: CHANGES
BLOCKER: Remove/update the stale live references that still claim `journal_entries_tenant_id_entry_number_unique` is the active uniqueness contract, then prove the requested grep is clean.
BLOCKER: Exercise the real `AccountingOpeningService` plus `OpeningBalancePostingService` for both sibling companies; the current OB leg uses `ArApOpeningService`/`ArApOpeningLedgerService` instead.
