# Adversarial merge gate — round 1 — `fix/opening-batch-lifecycle-hardening`

- **Reviewer:** treasury-reviewer (adversarial, code-grounded)
- **Date:** 2026-08-23
- **Lane commit:** `7e08ab419` on base `30001a187`
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/ob-hardening`
- **Branch name note:** the brief calls the lane `fix/opening-balance-lifecycle-hardening`; the actual ref is **`fix/opening-batch-lifecycle-hardening`** (`git branch --contains 7e08ab419`). Use the real name in the promotion ledger.
- **Diff:** 6 files, +1140 / −78. No `.github/`, no migration outside `database/migrations/tenant/`, nothing outside the opening-balance surfaces.

## 0. Class-resolution proof (worktree, not main)

`ReflectionClass::getFileName()` under the worktree's own `vendor/` (a **real directory**, not a symlink — `ls -ld vendor` → `drwxr-xr-x 86`):

```
OpeningBalanceBatchService  => .../.worktrees/ob-hardening/apps/api/app/Modules/Accounting/Application/Services/OpeningBalanceBatchService.php
AccountingOpeningService    => .../.worktrees/ob-hardening/apps/api/app/Modules/Accounting/Application/Services/AccountingOpeningService.php
ArApOpeningService          => .../.worktrees/ob-hardening/apps/api/app/Modules/Document/Application/Services/ArApOpeningService.php
InventoryOpeningService     => .../.worktrees/ob-hardening/apps/api/app/Modules/Inventory/Application/Services/InventoryOpeningService.php
```

All four resolve to the worktree. Every claim below was executed against that tree.

---

## 1. The deviation — the narrowed index predicate

### 1a. Blanket-predicate impossibility, re-derived independently

Two independent writers of `source_type='opening_balance'` exist, and they do **not** share the batch's `source_id` semantics:

- `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:540` and `:659` — `sourceType: 'opening_balance'`, `sourceId: $product->id` / `$model->id`. **Per-product** opening, not per-batch.
- `apps/api/app/Modules/Accounting/Application/Services/AccountingOpeningService.php:292` — `sourceId: $batch->id`.
- `apps/api/app/Modules/Inventory/Application/Services/InventoryOpeningService.php:269` — `sourceId: $batch->id` (inventory batch arm).

Reset-then-re-enter for the SAME product is a pinned, supported flow:
`apps/api/tests/Feature/Inventory/ResetOpeningBalanceServiceTest.php:149` — *"Re-entry: posting a NEW opening after reset succeeds"* — posts a second `OpeningBalancePosting` with `sourceType: 'opening_balance', sourceId: $product->id`. Reversal itself (`ResetOpeningBalanceService.php:281`) mints a third row on the same pair.

Empirically re-derived on a throwaway PG 16.10 (I did not take the lane's word for it). Planting exactly the legitimate triple (`INV-OB`, `INV-OBR`, `INV-OB` on one product id):

```
CREATE UNIQUE INDEX blanket_idx ON journal_entries (source_type, source_id)
  WHERE source_type = 'opening_balance';
ERROR:  could not create unique index "blanket_idx"
DETAIL:  Key (source_type, source_id)=(opening_balance, 1111...1111) is duplicated.
```

The shipped predicate created successfully on the same data. **The deviation is proven necessary, not a convenience.**

### 1b. `OB-%` exclusivity — is it really minted only by the batch arm?

Grepped every `entry_number` write and every entry-number `sprintf`/prefix literal in `app/`. The complete set of minted prefixes:

| Prefix | Site | Matches `'OB-%'` (left-anchored)? |
|---|---|---|
| `OB-{year}-{seq}` | `AccountingOpeningService.php:487` | **yes — the only one** |
| `INV-OB-{year}-{seq}` | `Inventory/.../OpeningBalancePostingService.php:306` | no |
| `INV-OBR-{year}-{seq}` | `Inventory/.../ResetOpeningBalanceService.php:281` | no |
| `JE-{year}-{seq}` | `GeneralLedgerService.php:5155`, `JournalEntryController.php:203` | no |
| `REVCAN-…` | `AccountingService.php:1140` | no |
| `CORR-…` | `AccountingService.php:1343` | no |

Non-match verified in PG, not just by eye:

```
    entry_number     | matches
---------------------+---------
 INV-OB-2026-000001  | f
 INV-OB-2026-000002  | f
 INV-OBR-2026-000001 | f
```

The one third-party caller that also produces a batch-sourced `OB-` entry — `apps/api/app/Modules/Import/Services/AccountingBalancesPhase.php` — routes **through** `AccountingOpeningService::postBatch` (`:250`), creates a fresh batch per import job (`:203`), and short-circuits on an already-`Locked` batch (`:148-150`). One JE per batch id. It does not break exclusivity.

### 1c. Can a batch's JE be legitimately re-created after a reversal?

**No.** There is no batch-level reset or reversal:
- `apps/api/app/Modules/Accounting/Presentation/routes.php:108-154` — the only batch routes are index/status/store/show/destroy/rows/lock/import/validate/preview/post. No reset, no unlock.
- `destroy` → `deleteBatch` requires `isDeletable()` = `Draft` (`OpeningBalanceBatchService.php:171`); a posted batch is `Locked` (accounting arm) or `Validated` (other arms), so the id is never recycled.
- No reversal path copies `source_type`/`source_id` onto a new entry (grepped `reverse*` in `Accounting/`; all five reversal methods are advance/inventory-movement/write-off shaped).

The only reset that exists is the **per-product** one (`Product/routes.php:82`), which mints `INV-OB`/`INV-OBR` and therefore falls outside the predicate.

### 1d. The AR/AP arm's claim-based backstop — do both layers compose?

Traced. In `ArApOpeningService::postBatch` (`:280-361`) the whole document loop lives inside one `DB::transaction`, opened with `lockBatchForPosting()` (`:283`) and closed with `markBatchValidated()` (`:350`) + `markRowsPosted()` (`:352`).

- **Layer 1 (serialization):** `lockBatchForPosting` (`OpeningBalanceBatchService.php:133-151`) is `whereKey(...)->lockForUpdate()->first()` + guard on the fresh row. Under READ COMMITTED, T2 blocks on T1's row lock and, on release, re-reads the *committed* status — which is no longer `Draft`. T2 therefore never writes a document.
- **Layer 2 (claim):** if layer 1 were ever a no-op (sqlite), `markBatchValidated` (`:398-412`) is a single-statement `UPDATE … WHERE id=? AND status='DRAFT'` asserting exactly one affected row, and `markRowsPosted` (`:642-655`) claims each row against the editable statuses. The claim runs **after** the document writes but **inside the same transaction**, so a losing claim throws → the whole document set rolls back. Verified by `test_arap_post_refuses_when_the_batch_row_advanced_behind_a_stale_model`, which asserts `Document::where('is_historical', true)->count() === 0` after refusal.
- **Refusal shape:** all refusals are `RuntimeException`, caught at `OpeningBalanceBatchController.php:621` → **422**, not a 500. I also confirmed `Illuminate\Database\QueryException` inherits `PDOException → RuntimeException` (`class_parents()` executed), so even a 23505 from the new index lands in the same 422 catch rather than a 500.

**Conclusion on item 1: the deviation is correct, minimal, and honestly documented. I could not construct a legitimate flow it breaks.**

---

## 2. H-1 lock correctness

Verified per arm that the fresh-row lock is the FIRST statement inside the transaction and that no read escapes it:

| Arm | Txn opens | Lock re-read | Rows read under lock | Lines built under lock |
|---|---|---|---|---|
| Accounting | `AccountingOpeningService.php:266` | `:269` | `:271-274` | `:305-352` |
| AR/AP | `ArApOpeningService.php:280` | `:283` | `:285-288` | `:299-346` |
| Inventory | `InventoryOpeningService.php:222` | `:225` | `:228-231` | `:244-274` (moved inside — confirmed against the base diff) |

Only `Company::findOrFail()` and `getScale($company->currency)` remain outside the transaction; both are read-only and neither feeds the guard. `getScale()` is called **with the entity currency** (`InventoryOpeningService.php:219`, `AccountingOpeningService.php:262`) — rule 19 satisfied, no bare no-arg `getScale()`.

The stale-caller regression is closed at the boundary: the closure's `$batch` is a fresh local (not in the `use` list), and `OpeningBalanceBatchController.php:590` re-`refresh()`es before serialising the response, so the removal of the in-closure `$batch->refresh()` does not leak a `Draft` status to the client.

**Stale-model simulation — read and judged.** `simulateConcurrentPost()` (test `:503-514`) advances the batch ROW to `Locked` + hash via a raw query while `$stale` (test `:185`) retains `Draft` — asserted at `:189`. That is exactly the T2-reads-superseded-snapshot interleaving: guard passes on the in-memory model, must fail on the locked re-read. Post-refusal assertions are real and specific: zero JE for the batch (`:199-206`), zero historical documents (`:227-231`), zero stock movements (`:252-256`), and a **byte-identical batch-row snapshot** including `hash`/`previous_hash` (`:207`, `:232`, `:261`).

Limitation (recorded as F-3 below): the test models the *committed* interleaving only. The blocking behaviour of `lockForUpdate` itself is not exercised.

---

## 3. H-2 — validate on a sealed batch

- Editability guard present in **all three** services: `AccountingOpeningService.php:97-102`, `InventoryOpeningService.php:68-73`, `ArApOpeningService.php:61-66`. All throw `RuntimeException` → `OpeningBalanceBatchController.php:491` → **422**.
- Row selection excludes `Posted` alongside `Skipped` in all three: `AccountingOpeningService.php:106-108`, `InventoryOpeningService.php:76-78`, `ArApOpeningService.php:71-73`.
- Write-path enforcement: `applyValidationResults` (`OpeningBalanceBatchService.php:606-610`) refuses any non-editable row before the update.
- **The editable list genuinely derives from the enum**, it is not a hardcoded array:
  `OpeningBalanceBatchService.php:666-672` filters `OpeningImportRowStatus::cases()` by `isEditable()`; the enum declares `Pending|Valid|Invalid` at `OpeningImportRowStatus.php:29-32`. Add a case and the list follows automatically.
- `markRowsPosted` claim semantics: `whereIn('status', $editableStatuses)` + `$claimed !== 1` throw (`:642-655`). Verified by `test_mark_rows_posted_refuses_a_row_that_is_already_posted`, which additionally asserts the row was **not re-pointed** at a second entity id (`:305-309`).
- **The hash-byte-identical assertion was RUN, not read.** `test_validate_endpoint_on_a_locked_batch_returns_422_and_leaves_the_seal_intact` posts for real, asserts the seal verifies (`:325`), POSTs `/validate`, asserts **422** (`:333`), then asserts the full import-row snapshot is byte-identical (`:335`) and `calculateBatchHash()` still equals the stored hash (`:336-340`). **Green on PostgreSQL.**

---

## 4. Migration proof — executed, not reasoned

`apps/api/database/migrations/tenant/2026_08_23_000100_unique_journal_entries_source_opening_balance_batch.php`.

Structure vs the named sibling `2026_08_11_000100_unique_journal_entries_source_inventory_movement.php`: same pgsql-only early return, same fail-closed pre-flight `GROUP BY … HAVING COUNT(*) > 1` scan, same `CREATE UNIQUE INDEX IF NOT EXISTS`, same `DROP INDEX IF EXISTS` down. Claim of verbatim mirroring holds.

I executed the **actual migration class** (via a bootstrapped Laravel container pointed at throwaway PG databases), not a hand-written approximation:

| Step | Result |
|---|---|
| Duplicate planted (two `OB-` entries, same `source_id`) | `UP THREW: RuntimeException: Cannot create uniq_je_source_opening_balance_batch: duplicate GL opening-balance batch posting (opening_balance, 4444…4444).` and **`INDEX PRESENT: NO`** — fail-closed with no partial artefact |
| Clean DB containing the legitimate reset/re-entry triple + one `OB-` row | `UP: OK`, `INDEX PRESENT: YES` |
| Idempotent re-run of `up()` | `UP: OK`, `INDEX PRESENT: YES` (NOTICE: relation already exists, skipping) |
| `down()` run twice | `AFTER DOWN x2 INDEX PRESENT: NO` — re-runnable |
| Second `OB-` JE for the same batch id | `ERROR: duplicate key value violates unique constraint "uniq_je_source_opening_balance_batch"` (**23505**) |
| Second `OB-` JE for a *different* batch id | accepted |
| Further `INV-OB-` re-entry on the same product | accepted |

Realised index definition:
```
CREATE UNIQUE INDEX uniq_je_source_opening_balance_batch ON public.journal_entries
  USING btree (source_type, source_id)
  WHERE ((source_type = 'opening_balance'::text) AND (entry_number ~~ 'OB-%'::text))
```
`~~` is IMMUTABLE — the docblock's legality claim (`:34`) is correct as realised by the planner.

**Census obligation docblock:** present at `:46-62`, correctly S-16-framed — names the auto-migrate-on-push exposure, the fail-closed behaviour, the exact census SQL, "run against EVERY tenant database", "record the result in the promotion ledger", and forbids weakening the index as the remedy. Adequate.

**Migration ordering.** Scanned all 291 refs. Ground truth:

- This lane: `2026_08_23_000100_…` — sorts **before** everything else dated 2026-08-23. No collision.
- On `dev`: `2026_08_23_100000_add_source_document_id_index_to_documents.php` (F4) and `2026_08_23_120000_backfill_location_pos_enabled_b3.php`.
- **Real collision, not this lane's:** branch `fix/membership-offboarding-pin-revocation` carries `2026_08_23_100000_add_revocation_tracking_to_user_company_memberships.php` — the **same `2026_08_23_100000` prefix** as the already-merged F4 migration. Legal in Laravel (ties broken by filename, so `add_revocation…` would sort before `add_source_document_id…`), but any ordering assumption in that lane must be re-checked at ITS merge. Flagged for the sequencer; **not a defect here** — this lane's migration touches a different table and sorts first regardless.

---

## 5. L-1 — advisory-lock preambles

Byte-comparable to the precedent `OpeningBalancePostingService::generateOpeningEntryNumber()` (`Inventory/.../OpeningBalancePostingService.php:285-290`):

```php
// precedent                      "inv-ob-seq:{$companyId}:{$year}"
// AccountingOpeningService:467   "gl-ob-seq:{$companyId}:{$year}"
// ArApOpeningService:450         "hist-doc-seq:{$companyId}:{$prefix}:{$year}"
```
Identical statement text (`SELECT pg_advisory_xact_lock(hashtext(?))`), identical `DB::getDriverName() === 'pgsql'` guard, identical placement immediately before the read-max. Both keys are **company-scoped**; the AR/AP key correctly adds the `HIST-INV`/`HIST-CN` prefix segment because the sequence is per prefix (`ArApOpeningService.php:445-449`).

**Named second-line unique indexes verified to exist as described** (docblock claims checked against migrations, not assumed):
- `journal_entries (tenant_id, entry_number)` — `2025_11_30_100000_create_journal_entries_table.php:41`.
- `documents (tenant_id, type, document_number)` — `2025_11_30_080000_create_documents_table.php:39`, re-created at `2025_12_30_085029_…:24`.

Key capture is asserted, not assumed: `test_accounting_entry_numbering_takes_a_company_scoped_advisory_lock` and `test_historical_document_numbering_takes_a_company_scoped_advisory_lock` sniff `DB::listen` bindings and assert the exact key strings (`:442`, `:457`). Both green on PG.

---

## 6. Regressions — everything below was RUN

| Gate | Result |
|---|---|
| `OpeningBalanceBatchLifecycleHardeningTest` (sqlite) | **OK** — 12 tests, 32 assertions, 3 skipped (PG-only) |
| `OpeningBalanceBatchLifecycleHardeningTest` (**PostgreSQL**, `phpunit-pgsql.xml`) | **OK (12 tests, 35 assertions)** — the 12/12 PG claim is TRUE |
| `OpeningBalanceBatchTest`, `OpeningBalanceStagingPrecisionTest`, `ArApOpeningPostLifecycleTest`, `InventoryOpeningEventsTest`, `InventoryOpeningMovementReasonTest`, `InventoryOpeningPreviewPrecisionTest` (sqlite) | **OK (38 tests, 124 assertions)** |
| `OpeningBalancesImportBatchTest`, `PartiesImportBalancesTest`, `ImportTypesTest`, `ProcessImportJobStatusTest`, `ResetOpeningBalanceServiceTest` (sqlite) | **OK (35 tests, 236 assertions)** |
| Same import/opening set on **PostgreSQL** | 46 tests, **1 failure** — the disclosed inherited red |
| `DocumentPerActionWriteGuardTest`, `EnumBackedStatusLiteralTest`, `TenantScopedFindCallsTest` | **OK (12 tests)** |
| Pint (all 6 lane files) | `{"result":"pass"}` |
| PHPStan L8 (all 6 lane files) | `[OK] No errors` |
| Deptrac | 182 violations, **zero of which cite any lane file** (grepped the four service paths in the full report — no hits). Absolute count is the inherited red to reconcile at promotion; the lane adds none. |

**Inherited reds — attributed, not accepted on faith:**

1. `PartiesImportBalancesTest::test_parties_import_posts_ar_and_ap_opening_balance_batches` (PG only) — `assertSame(['import_job_id'=>…,'source'=>…], $arBatch->import_file_reference)` at `:129`. The model is loaded fresh from the DB at `:123` (`OpeningBalanceBatch::where(...)->firstOrFail()`), so the key order is PG's jsonb ordering (`source` before `import_job_id` — shorter key first), entirely independent of this lane. **Genuinely inherited.** (Note the irony: the lane's own `syncClaimedAttributes` docblock, `OpeningBalanceBatchService.php:480-487`, cites exactly this PG jsonb behaviour as its reason for avoiding `refresh()` — the reasoning is sound and independently confirmed by this failure.)
2. `FeatureLaneManifestCheckerTest` — 7 failures in the worktree. **Stale-base artefact, disappears on merge.** Worktree manifest: `gated_ceiling 1134 / POS 143 / Document 75`. Current `dev` manifest (post-`c106fb809`): `gated_ceiling 1141 / POS 147 / Document 77`. `c106fb809` is NOT an ancestor of the lane base (verified with `git merge-base --is-ancestor`). The checker is **green on `dev`** (76 tests, 431 assertions, OK), and the lane touches the manifest in **zero** files.
   The lane's new class lands in group `Accounting`, whose lane is `treasury-spine-pgsql/feature-accounting` with `runs_on_pr_dev: true` and **no `execution_gate`** — a LIVE lane, informational ceiling, **no raise required**. The brief's expectation is confirmed.
3. `DocumentPerActionBaselineRatchetTest` — fails closed because `DPA_BASELINE_PROTECTED_BLOB` is unset locally. Environmental, not lane-attributable.

**Merge check:** `git merge-tree --write-tree dev 7e08ab419` → exit 0, clean tree `560f61c61…`. **No manifest conflict, no conflict of any kind.**

---

## 7. Scope

`git diff --name-status 30001a187 7e08ab419` — exactly 6 paths, all inside the opening-balance surface:

```
M apps/api/app/Modules/Accounting/Application/Services/AccountingOpeningService.php
M apps/api/app/Modules/Accounting/Application/Services/OpeningBalanceBatchService.php
M apps/api/app/Modules/Document/Application/Services/ArApOpeningService.php
M apps/api/app/Modules/Inventory/Application/Services/InventoryOpeningService.php
A apps/api/database/migrations/tenant/2026_08_23_000100_unique_journal_entries_source_opening_balance_batch.php
A apps/api/tests/Feature/Accounting/OpeningBalanceBatchLifecycleHardeningTest.php
```

No `.github/`, no config, no frontend, no shared types. Diff-stat matches the brief (+1140/−78). **Scope clean.**

Module boundaries (rule 6): `ArApOpeningService` (Document) and `InventoryOpeningService` (Inventory) call the new `lockBatchForPosting()` on `OpeningBalanceBatchService` — Accounting's **public Application service**, already injected on both sides before this lane. Deptrac reports zero violations citing these files. No new boundary crossing.

---

## Findings

No Critical. No Important. Five recorded items, all Minor or informational; none blocks merge.

**[Minor] F-1 — `apps/api/database/migrations/tenant/2026_08_23_000100_unique_journal_entries_source_opening_balance_batch.php:103-106`**
Plain `CREATE UNIQUE INDEX` inside Laravel's wrapping transaction takes an **ACCESS EXCLUSIVE** lock on `journal_entries` for the build. The house has an established alternative idiom — `CREATE INDEX CONCURRENTLY` + `public $withinTransaction = false`, used by 13 tenant migrations including the same-day F4 lane (`2026_08_23_100000_add_source_document_id_index_to_documents.php:66,74`, whose docblock explicitly cites lock avoidance "while tenants are live").
*Why it matters:* `origin/dev` auto-migrates every tenant DB on push; on a tenant with a large `journal_entries` the write lock is user-visible.
*Judgement:* defensible as shipped — it mirrors the `2026_08_11` sibling verbatim, and `CONCURRENTLY` cannot be combined with the in-transaction fail-closed pre-flight without risking an INVALID index on failure. The index is highly selective and the target is a first-act tenant.
*Fix (optional, or record instead):* keep as is, but record the expected lock window per tenant in the promotion ledger alongside the S-16 census result.

**[Minor / inherited] F-2 — `apps/api/app/Modules/Accounting/Application/Services/AccountingOpeningService.php:474-476` (and the identical sibling `Inventory/.../OpeningBalancePostingService.php:292-294`)**
The read-max is scoped `where('company_id', $companyId)` and the advisory lock is keyed on `companyId`, but the backstop unique the new docblock advertises (`:455`) is `journal_entries (tenant_id, entry_number)`. For a tenant with **two companies**, company B's read-max sees nothing and mints `OB-2026-000001` a second time → deterministic 23505 on the tenant-wide unique, with no concurrency involved.
*Why it matters:* the lane's new docblock calls that index "the second line of defence"; for multi-company tenants it is a hard failure surface, not a backstop.
*Not lane-introduced* — the `company_id` scoping predates the lane (only the advisory lock was inserted), and `GeneralLedgerService.php:5144` has the same shape for `JE-`.
*Fix:* open a follow-up ticket to make the sequence tenant-scoped (or the unique company-scoped) across all four generators. Do not widen this lane.

**[Minor] F-3 — `apps/api/tests/Feature/Accounting/OpeningBalanceBatchLifecycleHardeningTest.php:503-514`**
`simulateConcurrentPost()` models only the *committed*-superseded interleaving. The claim writes are therefore proven, but `lockForUpdate()`'s **blocking** behaviour — the actual layer-1 guarantee on PostgreSQL — is asserted by no test; on sqlite it compiles to nothing, so a future refactor that dropped `->lockForUpdate()` would keep all 12 tests green.
*Fix:* follow-up PG-only test using two live connections (second connection attempts `postBatch` while the first holds the row lock, asserting it blocks then refuses). Not required for this merge — the claim layer already fails closed.

**[Minor] F-4 — `apps/api/app/Modules/Accounting/Presentation/Controllers/OpeningBalanceBatchController.php:621-628`**
`catch (RuntimeException $e)` also catches `Illuminate\Database\QueryException` (confirmed: `QueryException → PDOException → RuntimeException`). A 23505 from the new index therefore returns **422 with the raw SQL error text** in `error.message`.
*Why it matters:* leaks schema internals to the client and is unreadable to an accountant. It is the correct *status code* (not a 500), so the brief's "clean refusal" requirement is met.
*Fix (follow-up):* add a `catch (QueryException $e)` ahead of the RuntimeException catch mapping SQLSTATE 23505 on `uniq_je_source_opening_balance_batch` to a domain message.

**[Info] F-5 — AR/AP and Inventory arms never seal the batch.**
`ArApOpeningService::postBatch` (`:350`) and `InventoryOpeningService::postBatch` (`:291`) call `markBatchValidated` but never `lockBatch`, so those batches terminate at `VALIDATED`, are never hash-sealed, and keep `hasUnlockedBatch()` / `isInventoryOpeningReady()` (`OpeningBalanceBatchService.php:519,531`) reporting them unlocked forever. **Pre-existing and unchanged by this lane**; the new guards make a second post refuse regardless (`canPost()` requires `Draft`). Worth a ticket, not a change here.

**[Info] F-6 — migration-timestamp collision to sequence elsewhere.**
`fix/membership-offboarding-pin-revocation` holds `2026_08_23_100000_add_revocation_tracking_to_user_company_memberships.php`, the same prefix as the already-merged F4 `2026_08_23_100000_add_source_document_id_index_to_documents.php`. Legal but order-fragile; hand it to the merge sequencer. Unrelated to this lane, whose `000100` sorts first and touches a different table.

---

## What to fix before merge

Nothing. F-1/F-4 are optional polish and F-2/F-3/F-5/F-6 are follow-up tickets; carry the S-16 per-tenant census result and the real branch name (`fix/opening-batch-lifecycle-hardening`) into the promotion ledger before pushing to `origin/dev`.

VERDICT: ACCEPT
