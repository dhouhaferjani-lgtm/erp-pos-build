# Handback — C-QR0a, fiscal-authority schema (SCHEMA ONLY)

Session C · document-lifecycle-dimensions program · lane 3 of 16
Brief: `docs/sessions/session-C-lifecycle-2026-08-24/BRIEF-C-QR0a-authority-schema.md`
Spec: `docs/sessions/session-C-lifecycle-2026-08-24/SPEC-document-lifecycle-dimensions.md` r11 §1 (fiscal row) · §2.3
Gates owed: `fiscal-pos-reviewer` + `tenancy-authz-reviewer`. **NOT merged.**

## 1. Branch and SHAs

| | |
|---|---|
| Branch | `feat/sc-qr0a-authority-schema` |
| Worktree | `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/sc-qr0a-authority-schema` |
| Base (local `dev`) | `351802aac` (≥ `3910538da`, the C-F0 CI repair — brief precondition satisfied) |
| Final SHA | `ffd0e1b7a` (single commit) |

Vendor is a REAL copy, not a symlink; class resolution verified inside the worktree
(`ReflectionClass(Document::class)->getFileName()` → the worktree path).

## 2. What shipped

### Migration (ONE, tenant)
`apps/api/database/migrations/tenant/2026_08_25_000100_add_fiscal_authority_columns_unactivated.php`

| Table | Column | Type | Null | Default | CHECK |
|---|---|---|---|---|---|
| `country_document_settings` | `fiscal_authority_mode` | `varchar(32)` | YES | none | none |
| `country_document_settings` | `fiscal_authority_types` | `json` | YES | none | none |
| `country_document_settings` | `policy_expertise_status` | `varchar(32)` | YES | none | none |
| `documents` | `fiscal_authority_status` | `varchar(32)` | YES | none | none |

No seed row, no backfill, no index, no initialiser, no resolver, no guard, no refusal —
all C-QR0b. `pre_delivery_invoicing_policy` and its DDL default are UNTOUCHED (its removal
belongs to C-QR0b's complete-rows work, F-134).

### PHP
- `app/Modules/Document/Domain/Enums/FiscalAuthorityMode.php` — `not_required · required`. Deliberately NO `systemDefault()`: F-112 forbids a code default deciding the mode.
- `app/Modules/Document/Domain/Enums/FiscalAuthorityStatus.php` — `not_required · pending · accepted · rejected`; `satisfiesFiscalCompletion()` answers only its own axis of F-61.
- `app/Modules/Document/Domain/Enums/PolicyExpertiseStatus.php` — `approved · provisional`.
- `app/Modules/Document/Domain/DTOs/FiscalAuthorityTypes.php` — value object for the JSON column (rule 3); canonicalises to `DocumentType::cases()` order and deduplicates, so the stored JSON is a SET that compares byte-for-byte; refuses a non-`DocumentType` string.
- `app/Modules/Document/Domain/DTOs/FiscalAuthorityTypesCast.php` — the Eloquent cast. In **Domain**, not `Infrastructure/Casts`: the models that declare it are Domain classes and deptrac forbids Domain → Infrastructure. NULL is preserved in both directions (NULL ≠ the empty set, which would itself be a policy).
- `app/Modules/Document/Domain/CountryDocumentSettings.php` — three casts + `@property` docs.
- `app/Modules/Document/Domain/Document.php` — `fiscal_authority_status` cast + `@property`.

**Deliberately NOT in `$fillable` on either model.** The casts make the columns readable and
typed; mass assignment arrives in C-QR0b with the writer that owns it. Until then a payload
naming them is dropped rather than honoured. Pinned by
`AuthoritySchemaUnactivatedStateTest::test_the_new_columns_are_not_mass_assignable_yet`.

### CI manifest
`apps/api/tests/feature-lane-manifest.json`: group `Document` `86 → 88`, `gated_ceiling`
`1178 → 1180`, with the reason recorded in the group note. Checker green afterwards
("tests/Feature lane manifest OK — 1431 Feature classes in 74 groups"). **Neither new
Feature class is added to any live `--filter` allowlist** — `feature-lane-documents/Document`
stays parked behind `vars.SELF_HOSTED_RUNNER_READY`, so both execute nowhere until that gate
flips. See §8 residual R-1.

> **⚠ RE-DERIVE THE UNION AT MERGE.** Local `dev` moved from the base `351802aac` to
> `42f7f8bad` (Session B, Slice D batch 1) while this lane was in flight, and dev's own
> `gated_ceiling` is now **1180** with `Document` still at 86. So the merge-time values are
> `Document 86 → 88` (unchanged — Session B's +2 landed in other groups) and
> **`gated_ceiling` 1180 → 1182**, not the 1178 → 1180 recorded in the committed file.
> Re-derive again if `dev` moves before the squash. Session B's batch adds a CHECK on
> `documents.type`; it does not mention any column of this lane, so
> `StagedDeploymentBootTest::test_no_check_constraint_mentions_a_new_column` is unaffected
> (verified by reading the constraint's target column, not by assumption).

## 3. MIGRATION-BEARING

**MIGRATION-BEARING: additive nullable, safe on any row count.**

Every statement is `ADD COLUMN … NULL` with no default: on PostgreSQL 16 each is a
catalog-only change (no table rewrite) and completes in constant time whether a tenant holds
0 or 10^8 documents. There is **no constraint any existing row could violate, so there is no
abort path** — the docblock census is INFORMATIONAL (it sizes the C-QR0b backfill), and the
migration never throws on its result. Fleet-abort risk: **NONE**.

Idempotent (`Schema::hasTable` / `hasColumn` guards per object; proven by
`StagedDeploymentBootTest::test_the_migration_is_idempotent`, which calls `up()` twice) and
rollback-neutral (`down()` drops only what `up()` added; nothing reads the columns).

The CHECK, the NOT NULL and the seeded rows arrive **atomically with the backfill** in
C-QR0b. That ordering is not stylistic: tenant migrations roll one tenant at a time
(`RollingTenantMigrationCommand`), so for the whole window the fleet is MIXED and the running
release still inserts documents without ever naming `fiscal_authority_status`. A NOT NULL or
a CHECK shipped here would break exactly the tenants that migrated first.
`StagedDeploymentBootTest` is what makes that regression loud.

### Exact per-tenant census query (read-only)

```sql
SELECT
  (SELECT COUNT(*) FROM country_document_settings)                     AS settings_rows,
  (SELECT COUNT(*) FROM documents)                                     AS document_rows,
  (SELECT COUNT(*) FROM documents WHERE status = 'posted')             AS posted_documents,
  (SELECT COUNT(*) FROM documents
    WHERE type IN ('invoice','credit_note') AND status <> 'cancelled') AS authority_scope_documents,
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = current_schema()
      AND (table_name, column_name) IN (
            ('country_document_settings','fiscal_authority_mode'),
            ('country_document_settings','fiscal_authority_types'),
            ('country_document_settings','policy_expertise_status'),
            ('documents','fiscal_authority_status')))                  AS new_columns_present;
```

`new_columns_present` is 0 before and 4 after — the idempotence check.
`authority_scope_documents` is the C-QR0b initialiser's population and the OQ-49 quarantine's
upper bound; it gates nothing here. The migration also runs this census at `up()` time
(pgsql only) and emits it to the log as `C-QR0a fiscal-authority schema census`, so the
per-tenant numbers land in the rolling migration's output instead of having to be
reconstructed afterwards. State literals in the executed copy come from `DocumentStatus` /
`DocumentType` (rule 9), never from string literals.

### Census output — every local tenant database, read-only, BEFORE this migration

PostgreSQL 16 on `127.0.0.1:5433`. No tenant database was written to.

| tenant database | settings_rows | document_rows | posted | authority_scope | new_columns_present |
|---|---|---|---|---|---|
| tenant019fbe86-944a-7252-8a3b-8c341dfa9de9 | **NO TABLE** | 1456 | 442 | 845 | 0 |
| tenant019fcf48-49a3-7230-aaf5-1c5daa44b3b3 | **NO TABLE** | 22 | 0 | 22 | 0 |
| tenant019fe276-750a-709d-8968-d1364e3459b6 | **NO TABLE** | 91 | 57 | 77 | 0 |
| tenant01a01b77-21a0-73f7-a893-96f689fbe815 | 0 | 59 | 24 | 39 | 0 |
| tenant01a03028-9470-70e6-83ca-cdc354f17cf1 | 2 | 15 | 1 | 3 | 0 |
| tenant01a033c6-3f61-73ce-b7f4-026b75656b71 | 2 | 0 | 0 | 0 | 0 |
| tenant01a034af-94ea-713d-8ce0-462216bf6ab5 | 2 | 21 | 2 | 1 | 0 |
| tenant01a035ba-592b-72aa-a12a-1e6b2f7e1d06 | 2 | 9 | 2 | 2 | 0 |
| tenant3f16ac36-1cc6-4a5d-82bd-4f14831be040 | **NO TABLE** | 16 | 16 | 16 | 0 |
| tenant4c3a1260-ed30-4ee6-8755-a9d823d61403 | **NO TABLE** | 0 | 0 | 0 | 0 |
| tenantbe3cd47a-e4a1-4941-8b77-dde33f6ca4ce | **NO TABLE** | 0 | 0 | 0 | 0 |
| tenantf6c592ac-2199-4095-96b4-e4244442dd80 | **NO TABLE** | 32 | 19 | 20 | 0 |

**Finding (residual R-2):** 7 of 12 local tenant databases have **no `country_document_settings`
table at all** — the 2026-08-10 `create_country_document_settings_table` migration never ran
there — and one of the five that do have it holds **zero rows**. This migration is unharmed
(its `Schema::hasTable` guard skips the settings half and still adds
`documents.fiscal_authority_status`), but it is a real precondition for C-QR0b, whose seeder
and `COUNTRY_DOCUMENT_SETTINGS_NOT_SEEDED` refusal both assume the table exists.

## 4. Red → green, by path

Machine: one test process at a time; every path `ls`-verified before running; the full suite
was never invoked. PG throwaway `autoerp_test_scqr0a` on `127.0.0.1:5433`, created and
dropped by this lane.

### sqlite (`php artisan test <path>`)

| file | RED | GREEN |
|---|---|---|
| `tests/Unit/Document/FiscalAuthorityEnumsTest.php` | `Tests: 8 failed (1 assertions)` — `Class "App\Modules\Document\Domain\Enums\FiscalAuthorityMode" not found` | `Tests: 8 passed (16 assertions)` |
| `tests/Feature/Document/AuthoritySchemaUnactivatedStateTest.php` + `tests/Feature/Document/StagedDeploymentBootTest.php` | `Tests: 9 failed, 4 skipped, 1 passed` — `country_document_settings.fiscal_authority_mode must exist after this lane's migration. Failed asserting that false is true.` | `Tests: 4 skipped, 15 passed` |

The 4 sqlite skips are `StagedDeploymentBootTest`'s `information_schema` / `pg_constraint`
assertions, which are PostgreSQL-only by construction and are proven below.

### PostgreSQL 16 (`php artisan test -c phpunit-pgsql.xml <path>`)

| file | RED | GREEN |
|---|---|---|
| `AuthoritySchemaUnactivatedStateTest` + `StagedDeploymentBootTest` | `Tests: 13 failed, 1 passed (14 assertions)` | `Tests: 15 passed` |
| all three files together | — | `Tests: 22 passed (90 assertions)` (before the idempotence case was added; `StagedDeploymentBootTest` alone then re-ran `6 passed, 42 assertions`) |

The single test green at RED on PG is
`StagedDeploymentBootTest::test_the_container_boots_and_posting_still_succeeds` — the
unchanged-behaviour control, which must be green on both sides of this lane by design.

Two guards that would have passed vacuously at RED (`no check constraint mentions a new
column`, `the new columns are not mass assignable yet`) were **strengthened with a
`Schema::hasColumn` precondition** so that they genuinely go red before the migration; the
RED numbers above are from after that change.

### Regression, by path (sqlite) — the two models this lane edits

`tests/Unit/Document/DocumentFillableRegressionTest.php`,
`tests/Unit/Document/DocumentEntityTest.php`,
`tests/Feature/Document/PreDeliveryInvoicingGateTest.php`,
`tests/Feature/Document/PreDeliveryInvoicingPolicyResolverTest.php`
→ `Tests: 2 skipped, 33 passed (80 assertions)` (the 2 skips are pre-existing PG-only CHECK cases).

## 5. Static analysis

- **PHPStan level 8** on every touched production path (`app/Modules/Document/Domain/DTOs/`, the three enums, `Document.php`, `CountryDocumentSettings.php`, the migration): `[OK] No errors`. Three findings were fixed at source, none suppressed: a non-list variadic (`array_values` — PHP 8 named arguments can give a variadic string keys), a missing `CastsAttributes<TGet, TSet>` generic on `castUsing()`, and an already-narrowed `is_iterable()`.
- **Pint**: `{"result":"pass"}` on every touched path.
- **deptrac ratchet**: `RESULT: PASS — no boundary regression against baseline` (TOTAL 183/183 held). The cast living in Domain is what keeps this at parity.
- **feature-lane manifest checker**: OK after the ceiling raise.

## 6. TypeScript

`CACHE_STORE=array php artisan typescript:transform` in the worktree; the generated diff is
committed and was never hand-edited (rule 7). `Transformed 529 PHP types to TypeScript`;
`packages/shared/types/generated.d.ts` +12 −1.

**This lane's three lines**, all in `App.Modules.Document.Domain.Enums`:

```ts
export type FiscalAuthorityMode = 'not_required' | 'required';
export type FiscalAuthorityStatus = 'not_required' | 'pending' | 'accepted' | 'rejected';
export type PolicyExpertiseStatus = 'approved' | 'provisional';
```

**Inherited drift carried in the same regeneration** (residual R-3) — six entries authored by
OTHER lanes that landed on `dev` without regenerating the file, so the first lane to run the
transform picks them up: `PosVatRefusalReason`, `FiscalPeriodReopenRefusalCode`,
`AllocationRefusalReason`, `AllocationTreatment`, `HistoricalOpeningSide`,
`CategoryResolutionOutcome`, plus `batch_ledger_repair` added to
`StockMovementReferenceType`. All are additive; none is this lane's. Regenerating is the only
correct action available (hand-trimming the file would violate rule 7), but a reviewer should
attribute them, not to this lane.

`FiscalAuthorityTypes` is deliberately NOT emitted to TypeScript: it carries no `#[TypeScript]`
attribute because it is a Domain value object, not a wire DTO, and its PHP shape
(`{types: [...]}`) is not the JSON wire shape (a bare list). The frontend contract for the
column, if one is ever needed, is `Array<DocumentType>` and belongs to the C-QR0b lane that
first exposes it.

## 7. Scope discipline

Files changed: 4 new production classes, 1 new migration, 2 model edits (casts + docblocks
only), 3 new test files, 1 manifest ceiling raise, 1 regenerated TS file. Nothing else was
touched. No seed, no initialiser, no backfill, no resolver, no guard, no refusal code, no
`fiscal_authority_attempts` table, no country parity test — every one of those is C-QR0b or
C-3a1a per the brief's out-of-scope list.

## 8. Residuals (seen, NOT touched)

- **R-1 — the two new Feature classes execute on no CI event.** `feature-lane-documents/Document` is parked behind `vars.SELF_HOSTED_RUNNER_READY`, and this lane did NOT add them to the `backend-test-pgsql --filter` allowlist. Precedent cuts both ways: C-F0 and W2-6 DID name their classes there precisely because a parked lane arms nothing. `StagedDeploymentBootTest` is the only guard that C-QR0b has not shipped its CHECK ahead of its backfill — a staged-deployment failure mode with fleet-wide blast radius. **Recommend the gates rule on whether it should be allowlisted**; the union arithmetic needs re-deriving at merge — see the ⚠ box in §2 (`dev` is already at `42f7f8bad` / `gated_ceiling` 1180, so the merge value is 1182).
- **R-2 — 7 of 12 local tenant databases lack `country_document_settings` entirely** and a further one has it empty (§3 census). Harmless here; a hard precondition for C-QR0b's seeder and its `COUNTRY_DOCUMENT_SETTINGS_NOT_SEEDED` refusal. Whether the staging/production fleet shows the same gap is unverified — the census above is LOCAL only.
- **R-3 — inherited TypeScript drift** from six other lanes, carried in this lane's regeneration (§6).
- **R-4 — `CountryDocumentDefaults` records FR's provisional status in a code COMMENT**, which is exactly what `policy_expertise_status` exists to make a column. Migrating that comment into the seeded column is C-QR0b's job; this lane deliberately left `CountryDocumentDefaults` and `CountryDocumentSettingsSeeder` untouched.
- **R-5 — `documents.status` still carries the retired `paid` / `received` values** (visible in the regenerated `DocumentStatus` TS line). Out of scope here; owned by C-RET1a/b/c per SPEC §1 F-88.
- **R-6 — the DDL default on `pre_delivery_invoicing_policy` is still present.** F-134 requires it dropped before C-QR0b's mass insert of complete rows. Not touched by instruction.

## 9. Verification commands (re-runnable)

```bash
cd .worktrees/sc-qr0a-authority-schema/apps/api

# sqlite
php artisan test tests/Unit/Document/FiscalAuthorityEnumsTest.php \
                 tests/Feature/Document/AuthoritySchemaUnactivatedStateTest.php \
                 tests/Feature/Document/StagedDeploymentBootTest.php

# PostgreSQL 16 (throwaway; .env.testing points DB_DATABASE/DB_CENTRAL_DATABASE at it)
psql -h 127.0.0.1 -p 5433 -U autoerp -d postgres -c 'CREATE DATABASE autoerp_test_scqr0a OWNER autoerp'
php artisan test -c phpunit-pgsql.xml tests/Feature/Document/AuthoritySchemaUnactivatedStateTest.php \
                                      tests/Feature/Document/StagedDeploymentBootTest.php
psql -h 127.0.0.1 -p 5433 -U autoerp -d postgres -c 'DROP DATABASE autoerp_test_scqr0a'

./vendor/bin/phpstan analyse --memory-limit=2G app/Modules/Document/Domain/DTOs/ \
  app/Modules/Document/Domain/Enums/FiscalAuthority*.php \
  app/Modules/Document/Domain/Enums/PolicyExpertiseStatus.php \
  app/Modules/Document/Domain/Document.php app/Modules/Document/Domain/CountryDocumentSettings.php \
  database/migrations/tenant/2026_08_25_000100_add_fiscal_authority_columns_unactivated.php
php tools/deptrac-ratchet.php --config=deptrac.yaml --baseline=deptrac.baseline.json
php tools/feature-lane-manifest-check.php
CACHE_STORE=array php artisan typescript:transform
```

**Do NOT merge.** Gates: `fiscal-pos-reviewer` + `tenancy-authz-reviewer`.
