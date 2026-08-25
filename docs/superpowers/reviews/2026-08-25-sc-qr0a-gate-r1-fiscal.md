# Gate r1 — fiscal-pos-reviewer — C-QR0a fiscal-authority schema (SCHEMA ONLY)

| | |
|---|---|
| Lane | C-QR0a, Session C document-lifecycle-dimensions program (lane 3 of 16) |
| Branch / worktree | `feat/sc-qr0a-authority-schema` · `.worktrees/sc-qr0a-authority-schema` |
| Reviewed SHA | `f7488c02d` (code `ffd0e1b7a`) · base `351802aac` |
| Inputs | BRIEF-C-QR0a · SPEC r11 §1 fiscal row + §2.3 · r10 condition 1 + r11 F-147 · handback `2026-08-25-sc-qr0a-handback.md` |
| Gate | fiscal-pos-reviewer (r1). Tenancy gate runs AFTER this one. |
| Probes | PG 16.10 throwaway `autoerp_test_scqr0a_gate` (127.0.0.1:5433) — created and DROPPED. Worktree restored and verified `git status --porcelain` empty at `f7488c02d`. |

## VERDICT

**ACCEPT-WITH-CONDITIONS** — merge-blocking: **YES** (conditions 1–3 below).

The lane does exactly what r10 condition 1 and r11 F-147 asked for: nullable-only schema, no default, no NOT NULL,
no CHECK, no seed, no backfill, no reader but the two Eloquent casts, and a staged-deployment guard that goes red
if the activation lane ships its CHECK ahead of its backfill (tamper-proven, 4 of 6 red). Nothing in posting,
printing or allocation moved. The three blockers are mechanical/forward-looking, not design faults.

## Legs (all re-derived by execution, not read from the handback)

| Leg | Result |
|---|---|
| Column shape on real PG (`information_schema.columns`) | `country_document_settings.fiscal_authority_mode` varchar(32) NULL default NULL · `fiscal_authority_types` **json** NULL default NULL · `policy_expertise_status` varchar(32) NULL default NULL · `documents.fiscal_authority_status` varchar(32) NULL default NULL. **PASS** |
| No CHECK / no index / no seed | `pg_constraint` matching `fiscal_authority|policy_expertise` → 0 rows; `pg_index` → 0 rows; `SELECT count(*) FROM country_document_settings` → 0. **PASS** |
| Idempotent + rollback-neutral | `up()` ×2 → all 4 present; `down()` ×2 → all 4 absent, no error; `up()` again → all 4 present. **PASS** |
| pgsql-guarded PG-specific code | only `recordCensus()`; guarded at migration:156. Docblock census present at :60-81, executed at :171-198. **PASS** |
| Only readers are the two model casts | grep of the 4 column names + 3 enums + DTO over `apps/` and `packages/` (excluding vendor/node_modules) → migration, `Document.php:64,203`, `CountryDocumentSettings.php:25-27,49-51`, the DTO/cast pair, the 3 test files, `generated.d.ts`. **No scope breach.** |
| Posting / printing / allocation unchanged | `tests/Feature/Modules/Document/DocumentPdfRenderTest.php` + `tests/Feature/Document/PostingMarkerPrintTest.php` + `tests/Feature/Treasury/N6PaymentOnUnpostedInvoiceTest.php` (each `ls`-verified) → **32 passed (99 assertions)**, sqlite. |
| Lane tests, sqlite | 3 files → **4 skipped, 19 passed (58 assertions)** |
| Lane tests, PostgreSQL 16 | 3 files, `-c phpunit-pgsql.xml` → **23 passed (94 assertions)** |
| **Tamper proof** | scratch edit of the migration: `->nullable()` → `->default('not_required')` (Blueprint default = NOT NULL) plus an unguarded early `ALTER TABLE documents ADD CONSTRAINT chk_tamper_authority CHECK (...)`. `StagedDeploymentBootTest` on PG → **4 failed, 2 passed**: `no new column is not null` ⨯, `no new column carries a database default` ⨯, `no check constraint mentions a new column` ⨯, `the migration is idempotent` ⨯ (the unguarded DDL threw `42710` on the second `up()`). Migration restored; worktree clean. **The guard is real, not vacuous.** |
| PHPStan level 8 (touched paths, live-DB env) | `[OK] No errors` |
| Pint (touched paths) | `{"result":"pass"}` |
| deptrac ratchet | `TOTAL 183 / 183 — RESULT: PASS`. `deptrac.baseline.json` unchanged `351802aac..dev`. |
| feature-lane manifest checker (worktree) | OK — 1431 Feature classes, 74 groups; parked 1180 / ceiling 1180 |
| TypeScript | 3 lane lines only (`FiscalAuthorityMode`, `FiscalAuthorityStatus`, `PolicyExpertiseStatus`), no hand edits. R-3 attribution **CONFIRMED**: `PosVatRefusalReason`, `FiscalPeriodReopenRefusalCode`, `AllocationRefusalReason`, `AllocationTreatment`, `HistoricalOpeningSide`, `CategoryResolutionOutcome`, `batch_ledger_repair` are all **absent from `dev`'s own `generated.d.ts`** — authored by other lanes that never regenerated. Not this lane's. |
| Vocabulary vs SPEC §2.3 | mode `not_required·required` ✓ · status `not_required·pending·accepted·rejected` ✓ · expertise `approved·provisional` ✓ · types set `{invoice, credit_note}` representable ✓ · no `systemDefault()` (F-112) ✓ · not mass-assignable on either model ✓ (no `$guarded` on either) |

## Findings

### [IMPORTANT — MERGE-BLOCKING] F-1 · `apps/api/tests/feature-lane-manifest.json:9` — `gated_ceiling` is stale; the merge commit goes CI-red

Committed value `1180`. `dev` moved **twice during this gate** (`d255396c4` → `9f2aed21e`, Session A D-1 POS-VAT merge).
Measured on `dev` @ `9f2aed21e` via `git archive dev | tools/feature-lane-manifest-check.php`:
`⚠ PARKED BEHIND AN EXECUTION GATE: 70 group(s) / **1182** class(es)`, `gated_ceiling` **1182**, `groups.Document.classes` **86**.
The worktree measures 1180/1180 with its 2 new classes, so base was 1178.
Union at merge = **1182 + 2 = 1184**, `Document` **86 → 88**. The checker compares `$gatedClasses` (classes ON DISK) against
`gated_ceiling` (`tools/feature-lane-manifest-check.php:810`), so merging with 1180 — or with the handback's corrected 1182 —
fails as `GATED-LANE COVERAGE GREW`.
**Fix:** set `gated_ceiling` to dev's value +2 at the squash, and **re-derive again** if dev moves (it moved twice in one gate).

### [IMPORTANT — MERGE-BLOCKING] F-2 · migration:105 + `FiscalAuthorityTypes.php:23-28` — `json` (not `jsonb`) makes the stated byte-for-byte comparison impossible in SQL, and SQLite hides it

`$table->json('fiscal_authority_types')` produces PostgreSQL type `json`, which **has no equality operator**. Proven on the probe DB:

```
SELECT '["invoice"]'::json = '["invoice"]'::json;
ERROR:  operator does not exist: json = json
SELECT count(*) FROM country_document_settings WHERE fiscal_authority_types = '["invoice","credit_note"]';
ERROR:  operator does not exist: json = unknown
```
(`jsonb` on the same server returns `t`.)

The DTO docblock asserts canonicalisation makes two equivalent rows "equal byte-for-byte in the database — which is what lets
the country-parity test of C-QR0b be a **comparison** rather than a set intersection" (`FiscalAuthorityTypes.php:23-28`).
That comparison cannot be expressed as `WHERE fiscal_authority_types = ?` on this column, and any Eloquent `where()` on it
raises a 500 on PG. The suite would never catch it: the default driver is SQLite, where `json` is TEXT and `=` works —
the exact PostgreSQL-masked-by-SQLite trap. House convention in the tenant schema is `jsonb` (99 columns) over `json` (26).
Cost to change is **zero right now** (column is NULL on every row, no data, no index); after C-QR0b seeds ~200 country rows
it is another migration on a live table.
**Fix (pick one, explicitly):** (a) `$table->jsonb('fiscal_authority_types')->nullable()` — preferred; or (b) delete the
byte-for-byte/comparison claim from the docblock and pin C-QR0b to compare in PHP or via an explicit `::jsonb` cast.

### [IMPORTANT — MERGE-BLOCKING] F-3 · `.github/workflows/ci.yml:998` — both new Feature classes execute on NO CI event (R-1)

`feature-lane-documents/Document` is parked behind `vars.SELF_HOSTED_RUNNER_READY`, and neither class is named in the
`backend-test-pgsql --filter` allowlist. `StagedDeploymentBootTest`'s four shape assertions **skip on SQLite by construction**
(`skipUnlessPostgres()`, :56-61) — verified: sqlite run shows 4 skipped. So its entire guard value is PostgreSQL-only and it
currently runs on no PostgreSQL anywhere. It is the only mechanism that makes "C-QR0b shipped the CHECK/NOT NULL ahead of its
backfill" loud, a failure mode whose blast radius is the whole tenant fleet mid-roll.
Precedent is set by the immediately preceding lane in the **same group**: C-F0's `ProformaOutputTest` / `ProformaTemplateCensusTest`
were added to this allowlist by C-F0's own fiscal gate r1 F-3, joining `CorrectingEntryEndpointTest`,
`PurchaseOrderUnpricedLineConfirmTest`, `FiscalPeriodReopenEndpointTest`, `ExpensePostTest`,
`ExpensePaidFromRepositoryTest`, `OpeningCashFloatSeedsRepositoryTest`. See **R-1 ruling** below.

### [IMPORTANT — non-blocking, carry-forward] F-4 · migration:98-112,160-162 — silent half-application on a tenant lacking `country_document_settings`

Empirically proven on the probe DB (table dropped, `up()` re-run): `up()` completed **without throwing**, added only
`documents.fiscal_authority_status`, and `recordCensus()` returned early at :160-162 so **nothing was logged**. The migration is
then recorded as RUN and never retried. The census — the very instrument meant to record what happened — is silent in exactly
the case that needs recording. On a normal rolling migration the 2026-08-10 create runs first in the same batch, so probability
is low; but a restored snapshot, a tenant whose 2026-08-10 migration errored, or R-2's population would land here silently.
**Fix:** log a warning (or throw) when the settings table is absent, and require C-QR0b's preflight to assert
`new_columns_present = 4` per tenant rather than trusting the `migrations` table.

### [IMPORTANT — non-blocking, carry-forward to the LEDGER] F-5 · `FiscalAuthorityStatus.php:18` claims F-101 freezing that nothing enforces

The `documents` immutability trigger is a **blacklist**, not a whitelist:
`enforce_document_immutability()` (read from `pg_proc` on the probe DB) enumerates `document_number, document_date, partner_id,
subtotal, discount_amount, tax_amount, total, currency, fiscal_hash, previous_hash, chain_sequence, fiscal_category` and returns
NEW for anything else. `fiscal_authority_status` is not among them. Nor is it in the seal payload:
`DocumentPostingService.php:685-690` hashes only `document_number`, `posted_at`, `total`, `currency`.
So once the dimension is activated, flipping a sealed document from `rejected` to `accepted` breaks no chain and trips no
trigger — while the enum docblock states "FROZEN once the seal fact exists (F-101)".
SPEC §2.3 assigns the data-boundary triggers to **C-3a1a** (F-133), which is correct scoping for this lane — but C-QR0b
(which ships the writer) lands BEFORE C-3a1a in the normative critical path, and no residual in the handback carries this.
**Action:** record on the LEDGER against C-QR0b/C-3a1a; unlike the POS receipt trigger, `documents` has no column-allowlist
census test, so nothing will force a future column to be considered.

### [MINOR] F-6 · handback §4 — the red→green record is not reproducible

Handback records the two Feature files as `Tests: 4 skipped, 15 passed`; the two classes hold **15 test methods total**, so the
green is `4 skipped, 11 passed`. Re-derived: all three files together = 23 tests → sqlite `4 skipped, 19 passed`, PG `23 passed`.

### [MINOR] F-7 · `FiscalAuthorityTypesCast.php:69` — scalar input raises a raw `TypeError`

`set()` forwards `mixed` into `FiscalAuthorityTypes::fromArray(iterable $values)`. `'invoice'` (a scalar) throws `TypeError`,
not the DTO's typed `InvalidArgumentException` that the surrounding design promises. Add an `is_iterable()` guard with the
same message shape as :77-82.

### [MINOR] F-8 · migration:79 vs :122 — the executed census can never report the "0 before" half

`recordCensus()` runs AFTER the columns are added, so `new_columns_present` is always 4 in the log; the docblock's
"0 before this migration and 4 after — the idempotence check" (:79-81) describes the manual query, not the emitted one.

### [MINOR] F-9 · `StagedDeploymentBootTest` docblock overstates the simulation

`RefreshDatabase` runs the **full** tenant migration set (which now includes two migrations dated after this one), not
"this lane's migration only" as the brief and the class docblock say. Arguably a stronger test; the wording should match.

## Rulings

**R-1 — allowlist?** **YES, allowlist both classes** at `.github/workflows/ci.yml:998`
(`StagedDeploymentBootTest` mandatory, `AuthoritySchemaUnactivatedStateTest` alongside it), with the standard
"remove when the parked lane's gate flips" comment. Reasons, in order: (a) `StagedDeploymentBootTest` is PG-only by
construction — 4 of its 6 cases `markTestSkipped` on SQLite, so parking it means its guard runs nowhere at all;
(b) it is the sole mechanism that makes a premature CHECK/NOT NULL in C-QR0b loud, and that failure strands a partially
migrated fleet; (c) the precedent was set by the previous lane in the same group under the same gate (C-F0 r1 F-3), so
declining here would be inconsistent within one session. Caveat to record: `backend-test-pgsql` runs on
`workflow_dispatch || base_ref==main || base_ref==dev || push→main` (`ci.yml:589`) — **not** on `push→dev`, so the
allowlist arms the class on PRs and main pushes, not on the session's direct dev promotions.

**R-2 — 7/12 local tenant DBs lack `country_document_settings`.** **Stale-local-DB artefact, not a migration defect and not
a staging risk.** Proven: the 2026-08-10 create migration is unconditional (`...create_country_document_settings_table.php:38-53`,
no country/vertical gate), and the sampled DBs without the table stop at
`max(migration) = 2026_08_06_100000_backfill_ready_image_media_assets` with zero rows in `migrations` matching
`%country_document_settings%` — i.e. they were never migrated past 2026-08-06. A current DB
(`tenant01a035ba-…`) has the migration recorded and `to_regclass` non-null. Staging auto-migrates on push to dev.
**What C-QR0b's preflight must therefore do:** assert per tenant, from `information_schema`, that
`country_document_settings` exists AND that all four C-QR0a columns are present (`new_columns_present = 4`) BEFORE seeding,
and refuse the tenant loudly otherwise — because F-4 proves C-QR0a's `hasTable` guard can record itself as complete having
added only one of four columns, so "the migration ran" is not evidence that the schema is there.

## Manifest note

`dev` @ `9f2aed21e` (moved twice during this gate): `gated_ceiling` **1182**, measured parked classes **1182**,
`groups.Document.classes` **86**. Merge-time union for this lane = **`gated_ceiling` 1184**, **`Document` 86 → 88**.
The committed `1180` and the handback's ⚠-box `1182` are both stale. Re-derive at the squash — this is now the fourth
consecutive Session A/B/C lane for which dev moved under the union.

## Conditions (all must be satisfied before merge)

1. Re-derive and set `gated_ceiling` = dev's value + 2 (today: **1184**) and `groups.Document.classes` 86 → 88, at the squash commit (F-1).
2. Rule explicitly on `fiscal_authority_types` typing: switch to `jsonb`, or remove the byte-for-byte comparison claim and pin C-QR0b's parity test to a PHP/`::jsonb` comparison (F-2).
3. Add `StagedDeploymentBootTest` and `AuthoritySchemaUnactivatedStateTest` to the `backend-test-pgsql --filter` allowlist with the standard removal comment, and update the manifest `Document` note accordingly (F-3, R-1).
4. Record on the LEDGER, against C-QR0b: preflight must assert `new_columns_present = 4` per tenant (F-4, R-2); and against C-QR0b/C-3a1a: `fiscal_authority_status` is covered by neither `enforce_document_immutability()` nor the seal hash, so F-101 freezing is currently unenforced (F-5).
5. Correct the handback §4 green figures and the `StagedDeploymentBootTest` docblock wording (F-6, F-9); add the `is_iterable()` guard in the cast (F-7). Docs/one-liner round, no re-gate required.

**VERDICT: spec ✅ + quality CHANGES-REQUESTED (ACCEPT-WITH-CONDITIONS, merge-blocking 1–3)**
