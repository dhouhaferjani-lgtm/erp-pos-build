Lane complete. Report follows.

---

# LANE B2-1 / C-27 — DELIVERABLE

## 1. Branch + worktree + commit

- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/sb2-c27-je-numbering`
- **Branch:** `fix/sb2-c27-je-numbering-tenant-scope` (base = dev `834c8c017`)
- **Commit:** `eaccb7323` — `fix(sb2-c27): tenant-scope + advisory-lock journal-entry and income numbering` (6 files, +777/−83)
- **NOT merged, NOT pushed.**
- Throwaway PG DB `autoerp_c27_test` on 127.0.0.1:5433 left in place for the reviewer gate (drop with `dropdb`).

## 2. Pre-existing red counts (established on the UNTOUCHED base, before any edit)

| File (sqlite) | Baseline |
|---|---|
| `tests/Feature/Accounting/CreateJournalEntryTest.php` | OK 11 tests / 29 assertions |
| `tests/Feature/Accounting/ChainSequenceUniqueIndexTest.php` | OK 2 / 3 |
| `tests/Feature/Accounting/PostEntryNowAtomicityTest.php` | OK 5 / 15 |
| `tests/Feature/Expense/ExpensePostTest.php` | OK 5 tests, 13 assertions, 1 skipped (PG-only) |

**Zero pre-existing reds on the four regression files.**

## 3. Red → green, per file, by path

### `tests/Feature/Accounting/JournalEntryNumberingTenantScopeTest.php` (new)

RED, sqlite (`Tests: 5, Assertions: 6, Failures: 2, Skipped: 3`):
```
SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed:
journal_entries.tenant_id, journal_entries.entry_number
(... insert into "journal_entries" (... 'JE-2026-000001' ...) )
Expected response status code [200] but received 500.
```
RED, pgsql:5433 (`Tests: 5, Assertions: 13, Failures: 4`):
```
1) ...do_not_collide_across_two_companies_in_the_same_tenant
   SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique
   constraint "journal_entries_tenant_id_entry_number_unique"
2) ...chain_sequence_stays_per_company...  (same 23505)
3) ...takes_the_tenant_lock_before_the_company_lock
   Expected a pg_advisory_xact_lock keyed journal_entry_number:{tenantId} ... null is not null
4) ...manual_journal_entry_creation_takes_the_same_tenant_lock_key  (null is not null)
```
GREEN: sqlite `OK, Tests: 5, Assertions: 15, Skipped: 3` · pgsql `OK (5 tests, 24 assertions)` (re-run after the PHPStan docblock fix — still `OK (5 tests, 24 assertions)`).

### `tests/Feature/Income/IncomeNumberingTenantScopeTest.php` (new)

RED, sqlite (`Tests: 2, Failures: 1, Skipped: 1`):
```
UNIQUE constraint failed: documents.tenant_id, documents.type, documents.document_number
(update "documents" set "status" = posted, "document_number" = INC-2026-000001 ...)
```
RED, pgsql (`Tests: 2, Assertions: 6, Failures: 2`):
```
1) 23505 duplicate key ... "documents_tenant_id_type_document_number_unique"
2) Expected a pg_advisory_xact_lock keyed income_number:{tenantId} ... two arrays are not identical
```
GREEN: sqlite `OK, 2 tests, 6 assertions, 1 skipped` · pgsql `OK (2 tests, 9 assertions)`.

### Regressions after the change

| File | sqlite | pgsql:5433 |
|---|---|---|
| `tests/Feature/Accounting/CreateJournalEntryTest.php` | OK 11/29 | not run (sqlite-only assertions) |
| `tests/Feature/Accounting/ChainSequenceUniqueIndexTest.php` | OK 2/3 | not run |
| `tests/Feature/Accounting/PostEntryNowAtomicityTest.php` | OK 5/15 | **OK 5/15** |
| `tests/Feature/Expense/ExpensePostTest.php` | OK 5/13 (1 skip) | **OK 5/16** (Q-11 lock test now executes) |
| `tests/Feature/Income/IncomeSpineTest.php` (added, IncomeService is touched) | OK 2/19 | not run |

Gates: `./vendor/bin/pint --dirty` → `{"result":"pass"}`; PHPStan level 8 on the three production files **and** both new test files → `[OK] No errors` (run with a live PG env).

## 4. Production changes (file:line, post-commit)

**`app/Modules/Accounting/Domain/Services/GeneralLedgerService.php`**
- `:5585-5643` — `generateEntryNumber(string $tenantId, string $companyId)`: tenant-scoped scan (`->where('tenant_id', …)`, `->value('entry_number')`), format `JE-%s-%06d` unchanged; takes `journal_entry_number:{tenantId}` **first**, then the pre-existing bare-`$companyId` key; docblock records both defects and the ordering rule.
- `:3779-3780` — one sentence added to the `sealAndPersistEntry` lock comment ("the TENANT-keyed numbering lock … is always taken BEFORE this one"). **`sealAndPersistEntry` is otherwise untouched — no reorder, no lock removed.**
- 43 call sites rewritten (table below).
- 8 sites had their existing `Company::findOrFail($companyId)` (and its comment) hoisted a few lines above the call so `$company->tenant_id` is in scope: **542, 642, 753, 850, 1586, 1686, 1835, 5406**. No new query is introduced at any site.

**`app/Modules/Income/Application/Services/IncomeService.php`**
- `:156` — `generateIncomeNumber($income->tenant_id)`.
- `:203-246` — tenant-scoped scan + new `income_number:{tenantId}` pgsql-guarded xact lock; `INC-%s-%06d` unchanged.

**`app/Modules/Accounting/Presentation/Controllers/JournalEntryController.php`**
- `:187-215` — `journal_entry_number:{tenantId}` lock added at the top of `generateEntryNumber` (key byte-identical to the service's). **Caller already runs inside `DB::transaction`** (`store()`, `:93`) — no wrapping needed.

**`tests/feature-lane-manifest.json`** — see §7.

## 5. The 43 call sites and the tenant-id expression used at each

Every expression is the one the adjacent `JournalEntry::create([... 'tenant_id' => X ...])` already writes. **`CompanyContext`/`tenant()` was not used anywhere. Zero sites needed a new `Company::query()->value('tenant_id')` lookup.**

| # | line | method | args passed |
|---|---|---|---|
| 1 | 157 | createFromInvoice | `$invoice->tenant_id, $companyId` |
| 2 | 241 | createFromCreditNote | `$creditNote->tenant_id, $companyId` |
| 3 | 364 | createPaymentEntry | `$user->tenant_id, $companyId` |
| 4 | 463 | reclassifyCustomerPaymentToAdvance | `$company->tenant_id, $companyId` |
| 5 | 542 | createCustomerAdvanceJournalEntry | `$company->tenant_id, $companyId` ᴴ |
| 6 | 642 | reverseSupplierAdvanceJournalEntry | `$company->tenant_id, $companyId` ᴴ |
| 7 | 753 | reverseCustomerAdvanceJournalEntry | `$company->tenant_id, $companyId` ᴴ |
| 8 | 850 | createPaymentRefundJournalEntry | `$company->tenant_id, $companyId` ᴴ |
| 9 | 927 | createSupplierInvoiceJournalEntry | `$user->tenant_id, $companyId` |
| 10 | 1015 | createSupplierPaymentJournalEntry | `$user->tenant_id, $companyId` |
| 11 | 1194 | createOutboundInstrumentEntry | `$tenantId, $companyId` |
| 12 | 1261 | createExpenseSettlementJournalEntry | `$user->tenant_id, $companyId` |
| 13 | 1354 | createRepositoryAdjustmentJournalEntry | `$tenantId, $companyId` |
| 14 | 1453 | createAcquirerFeeJournalEntry | `$tenantId, $companyId` |
| 15 | 1523 | createRepositoryTransferJournalEntry | `$tenantId, $companyId` |
| 16 | 1586 | createPaymentReceivedJournalEntry | `$company->tenant_id, $companyId` ᴴ |
| 17 | 1686 | createPaymentToleranceJournalEntry | `$company->tenant_id, $companyId` ᴴ |
| 18 | 1835 | clearCustomerAdvanceToReceivable | `$company->tenant_id, $companyId` ᴴ |
| 19 | 2121 | createGoodsReceiptGrIrEntry | `$company->tenant_id, $companyId` |
| 20 | 2271 | createSupplierInvoiceGrIrClearingEntry | `$company->tenant_id, $companyId` |
| 21 | 2488 | createSupplierCreditNoteEntry | `$company->tenant_id, $companyId` |
| 22 | 2684 | createSupplierCreditNoteEntryWithBonusReturn | `$company->tenant_id, $companyId` |
| 23 | 2921 | createVoucherLedgerEntry | `$voucher->tenant_id, $companyId` |
| 24 | 3196 | createInstrumentClearingEntry | `$tenantId, $companyId` |
| 25 | 3305 | createInstrumentDishonorEntry | `$tenantId, $companyId` |
| 26 | 3391 | createInstrumentToleranceReversalEntry | `$tenantId, $companyId` |
| 27 | 3446 | createInstrumentTransitEntry | `$tenantId, $companyId` |
| 28 | 3531 | createInstrumentCancellationEntry | `$tenantId, $companyId` |
| 29 | 3853 | createPOSPaymentToleranceEntry | `$company->tenant_id, $companyId` |
| 30 | 3946 | createPOSPaymentEntry | `$payment->tenant_id, $companyId` |
| 31 | 4212 | createPOSRefundReversalEntry | `$payment->tenant_id, $companyId` |
| 32 | 4336 | createPosCashRoundingEntry | `(string) $receipt->tenant_id, $companyId` |
| 33 | 4440 | createRefundCompensationEntry | `$tenantId, $companyId` |
| 34 | 4506 | createPosToleranceWriteoffEntry | `(string) $receipt->tenant_id, $companyId` |
| 35 | 4577 | createPOSChargeEntry | `$command->tenantId, $command->companyId` |
| 36 | 4699 | createFromExpense | `$expense->tenant_id, $companyId` |
| 37 | 4838 | createFromIncome | `$income->tenant_id, $companyId` |
| 38 | 4924 | createLinkedCostCapitalizationEntry | `$expense->tenant_id, $companyId` |
| 39 | 5012 | createLinkedCostCapitalizationReversalEntry | `$expense->tenant_id, $companyId` |
| 40 | 5194 | createInventoryMovementEntry | `$company->tenant_id, $companyId` |
| 41 | 5272 | reverseInventoryMovementEntry | `$locked->tenant_id, $locked->company_id` |
| 42 | 5406 | createInventoryWriteOffEntry | `$company->tenant_id, $companyId` ᴴ |
| 43 | 5526 | reverseInventoryWriteOffEntry | `$company->tenant_id, $companyId` |

ᴴ = the site's existing `Company::findOrFail($companyId)` was hoisted above the call.

`IncomeService::generateIncomeNumber` has **exactly one** call site (`post()`, `:156`), not three — the brief's `:76/:117/:153` are the three `DB::transaction` openers in the file. That one call site is inside `DB::transaction`, so the xact lock is held to commit.

## 6. Migration

**No migration. No index change.** (Widening `journal_entries_tenant_id_entry_number_unique` to include `company_id` was explicitly rejected — `entry_number` is an externally quoted FEC/export identifier and that is an owner ruling.) Not MIGRATION-BEARING.

## 7. Feature-lane manifest

`php tools/feature-lane-manifest-check.php` failed on the two new classes; resolved as instructed:

- `groups.Income.classes` **3 → 4** (parked lane `feature-lane-fiscal-finance/Income` — ceiling is enforced while parked).
- `gated_ceiling` **1173 → 1174**.
- `groups.Accounting.classes` **86 → 87** (informational only — `treasury-spine-pgsql/feature-accounting` is a live lane; updated so the enumerating note stays accurate).

Post-edit: `tests/Feature lane manifest OK — 1423 Feature classes in 74 groups`; parked 70 groups / **1174** classes; coverage debt 1 group / 1 class (unchanged). The parent should re-resolve these three numbers against live dev at merge.

## 8. Census

**Local census found ZERO exposure, but it is NOT a fleet census — flag this to the promoter.** Port 5433 (`autoerp_postgres`) hosts **no `tenant_*` and no `synerivia_central` database** — the db-per-tenant local stack is not provisioned on this machine right now, so `tenants:run` had nothing to loop over. I ran the Q-11 §C queries directly against every candidate DB instead.

Populated-DB scan (`companies` / `journal_entries` row counts): `autoerp` 0/0, `autoerp_test` 0/0, `autoerp_test2` **1/6**, `autoerp_lane_test` 0/0, `autoerp_wave4_test` 0/0, `autoerp_o27_test` 0/0, `autoerp_treasury_test` 0/0.

Against `autoerp_test2` (the only DB with data):

| Query | Result |
|---|---|
| #1 tenants with >1 company | **0 rows** |
| #2 already-interleaved `JE-` years (`count(DISTINCT company_id) > 1`) | **0 rows** |
| #3 companies whose next `JE-` allocation collides today | 1 company (`Kessler Inc`, tenant `01a014d1-…c663`): `own_max = tenant_max = JE-2026-000004` → **not blocked** |
| #4a interleaved `INC-` years | **0 rows** |
| #4b next `INC-` allocation collision | 1 company, `own_max = tenant_max = NULL` → **not blocked** |
| #4c row counts | `inc_docs 0`, `exp_docs 0`, `je_rows 4` |

Against `autoerp`: `companies_total 0`, `tenants_total 0`, all three queries 0 rows.

**Conclusion: no single-tenant-two-company data exists locally, so there is no data-repair work implied by this change on this machine. The real fleet census (staging / production tenant DBs) has NOT been run and must be run before promotion** — the queries above are ready to paste into a `tenants:run` loop.

## 9. Owner ack owed (same shape as Q-11 condition 2)

This is a **numbering-contract change**. JE numbers and income document numbers now **interleave across the companies of a tenant** (company A gets `…0001` and `…0003`, company B `…0002`), so each company's own register shows gaps. That is the only allocation consistent with the tenant-wide unique index, but it is owner-visible and is stated in the commit message, not only in a docblock.

## 10. Residuals / adjacent defects seen and NOT touched

1. **`OB-` opening entries** — `AccountingOpeningService.php:479-492`: company-scoped scan, and its lock is `hashtext('gl-ob-seq:{companyId}:{year}')`. Same class of defect. **Session W owns openings.**
2. **`HIST-INV`/`HIST-CN`/`HIST-DOC`** — `Document/Application/Services/ArApOpeningService.php:448-472`: company-scoped scan against the same tenant-wide `documents` unique index; its own docblock already concedes the index is only "the second line of defence". Session W.
3. **`INV-OB-`** — `Inventory/Application/Services/OpeningBalancePostingService.php:296-306` and **`INV-OBR-`** — `Inventory/Application/Services/ResetOpeningBalanceService.php:263-272`: both company-scoped, both keyed `hashtext('inv-ob(r)-seq:{companyId}:{year}')`. Session W.
4. **Lock-primitive drift:** the four openings minters use 32-bit `hashtext(?)` while GL/Expense/Income use 64-bit `hashtextextended(?, 0)`. Disjoint key spaces today, but two different hash families guarding one table is a hazard worth unifying when Session W lands.
5. **`Document/Domain/Services/DocumentNumberingService.php:47,84`** — the canonical `DocumentSequence` is keyed on `company_id` against the same tenant-wide `documents` unique index, so `INV-YYYY-0001` from a second company collides too; its one-shot retry re-reads the same company sequence and cannot help. Flagged by the Q-11 gate as well; out of this lane's scope.
6. **T3 honesty note:** the two-connection test proves the tenant key is mutually exclusive across PG sessions and that the key string is the one under test; it does **not** by itself prove the service holds it — that is pinned by T2's query-log ordering assertion (tenant lock index < company lock index). A true racing two-connection post was not attempted inside the 90-second tool budget, exactly as the brief permits.

---

Fix round committed on top of `eaccb7323`. Not merged, not pushed.

# C-27 FIX ROUND r1 — REPORT

**Branch** `fix/sb2-c27-je-numbering-tenant-scope` · **worktree** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/sb2-c27-je-numbering` · **new commit `47bf340c4`** (on `eaccb7323`).

## 1. F-1 — universal tenant-before-company order (done, in code)

`GeneralLedgerService.php`:
- **`:5618-5628`** new `private function takeTenantNumberingLock(string $tenantId): void` — the `journal_entry_number:{tenantId}` literal now exists exactly once; pgsql-guarded, early-return on other drivers.
- **`:3784`** `sealAndPersistEntry` calls it immediately before the company chain lock at **`:3794-3796`**, with a comment (`:3771-3783`) naming the replay branches and the reproduced `40P01`. Nothing else in `sealAndPersistEntry` changed — no reorder, no lock removed, hash-chain sequence byte-identical.
- **`:5674`** `generateEntryNumber` calls the same helper, then the company key at `:5676-5678`.
- **`:5651-5663`** docblock rewritten to the truthful invariant: *"every path that takes the company chain key takes the tenant numbering key first"*, with the reason `sealAndPersistEntry` must participate (entries numbered in an earlier transaction mint nothing). Cost stated explicitly: posting now also serialises tenant-wide.

**Red → green (PG 5433, `autoerp_c27_test`), new file `tests/Feature/Accounting/InventoryGlNumberingTenantScopeTest.php::test_posting_a_pre_numbered_entry_takes_the_tenant_key_before_the_company_key`** — txn 1 mints the Draft via `createInventoryMovementEntry(postSynchronously: false)`, txn 2 replays with `postSynchronously: true` (hits `$existing` → `postEntryNow` → `sealAndPersistEntry`, mints nothing), query log of txn 2 asserted.

RED on `eaccb7323`:
```
A transaction that posts a PRE-NUMBERED entry takes the company chain key; it must take the
tenant numbering key first, or it can AB-BA against a concurrent mint (reviewer reproduced
SQLSTATE 40P01). Failed asserting that null is not null.
Tests: 1, Assertions: 3, Failures: 1.
```
(The `chain_sequence === 1` assertion passed first, proving the replay really did post — so the log inspected is the sealing transaction.) GREEN after: file `OK (2 tests, 12 assertions)`.

## 2. F-2 — stock side of the seam (done)

Second test in the same new file: `test_inventory_gl_mints_tenant_unique_numbers_for_a_second_company_with_no_company_context`. PG-only, `connectionsToTransact()` empty (buffer sees production transaction levels, same contract as `InventoryGlPostingSeamTest`). Two companies of one tenant, real `StockMovement` rows (real `Product` + `Location`), `app(CompanyContext::class)->clear()` before flushing, minted through the production `InventoryGlPostingBuffer::enqueue()/flushIfOutermost()` inside a root transaction. Asserts **both sides**: both `stock_movements` rows survive, `journal_entries.source_id` links to each, company A `JE-YYYY-000001` / company B `JE-YYYY-000002`, `chain_sequence` 1 and 1.

RED via the restore-file technique (`git checkout 834c8c017 -- GeneralLedgerService.php`, run, `git checkout HEAD --`; **no stash**, worktree verified clean after):
```
Illuminate\Database\UniqueConstraintViolationException: SQLSTATE[23505]: Unique violation: 7
ERROR: duplicate key value violates unique constraint "journal_entries_tenant_id_entry_number_unique"
Tests: 1, Assertions: 0, Errors: 1.
```
GREEN on the branch before the F-1 change (`OK (1 test, 8 assertions)`) and after it (`OK (2 tests, 12 assertions)`).

## 3. Treasury F-6 — T3 replaced with a test that can fail (done)

`JournalEntryNumberingTenantScopeTest.php`: `test_the_tenant_numbering_key_is_mutually_exclusive_across_connections` → **`test_the_posting_transaction_holds_the_tenant_numbering_key`**. It now drives a real expense post inside an explicit `DB::transaction` and, while that transaction is open, proves from a second PDO connection that `pg_try_advisory_xact_lock` on `journal_entry_number:{tenant}` returns **false** — the service is holding it. A pre-check asserts the key is free beforehand, so the assertion is not vacuous.

RED against `834c8c017`'s generator:
```
journal_entry_number:{tenantId} must be HELD for the life of the posting transaction —
otherwise two concurrent minters read the same maximum.
Failed asserting that true is false.
Tests: 1, Assertions: 4, Failures: 1.
```
GREEN after: file `OK (5 tests, 26 assertions)` on PG.

## 4. Full re-run by path (one file per invocation)

| File | sqlite | pgsql:5433 | gate baseline |
|---|---|---|---|
| `Accounting/InventoryGlNumberingTenantScopeTest.php` (new) | 2 skipped | **OK 2 / 12** | n/a |
| `Accounting/JournalEntryNumberingTenantScopeTest.php` | OK 5 / 15 (3 skip) | **OK 5 / 26** | n/a |
| `Income/IncomeNumberingTenantScopeTest.php` | OK 2 / 6 (1 skip) | **OK 2 / 9** | n/a |
| `Accounting/CreateJournalEntryTest.php` | **OK 11 / 29** | — | 11 / 29 ✓ |
| `Accounting/ChainSequenceUniqueIndexTest.php` | **OK 2 / 3** | — | 2 / 3 ✓ |
| `Accounting/PostEntryNowAtomicityTest.php` | — | **OK 5 / 15** | 5 / 15 ✓ |
| `Expense/ExpensePostTest.php` | — | **OK 5 / 16** | 5 / 16 ✓ |
| `Inventory/InventoryGlVoucherLockOrderTraceTest.php` | — | **OK 2 / 30** | 2 / 30 ✓ |
| `Inventory/InventoryGlLockOrderContentionTest.php` | — | **OK 15 / 174** | 15 / 174 ✓ |
| `Inventory/InventoryGlPostingSeamTest.php` | — | **OK 27 / 112** | 27 / 112 ✓ |
| `Inventory/GoodsReceiptGlPostingOrderTest.php` | — | **OK 12 / 42** | 12 / 42 ✓ |

Every inventory/GR baseline matches exactly — notably the I-1 terminal-advisory trace (2/30), so the new tenant key sits after the inventory row locks, equally terminal.

**Gates:** PHPStan level 8 on `GeneralLedgerService.php` + both touched test files → `[OK] No errors`. `pint --dirty` → `{"result":"pass"}`. `feature-lane-manifest-check.php` → OK, **1424** Feature classes / 74 groups; parked 70 groups / **1174** classes; debt 1/1. `groups.Accounting.classes` bumped 87 → 88 (informational only — live lane `treasury-spine-pgsql/feature-accounting`; the ceiling is not enforced there). No other manifest change this round; `gated_ceiling` stays 1174 and `Income` stays 4.

## 5. Exact production changes this round

Only one production file touched: `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php` — `:3771-3784` (comment + tenant lock call), `:5618-5628` (new helper), `:5651-5663` (docblock), `:5671-5678` (generator uses the helper). +70/−… on that file; `IncomeService.php` and `JournalEntryController.php` were **not** touched this round.

## 6. Residuals — not addressed, deliberately

- **F-3** (Session W): `OpeningBalancePostingService.php:290-306` (`INV-OB-`), `ResetOpeningBalanceService.php:263-272` (`INV-OBR-`), `AccountingOpeningService.php:474-492` (`OB-`), `ArApOpeningService.php:448-472` (`HIST-`) still carry the exact defect, and use 32-bit `hashtext` vs the 64-bit `hashtextextended` used here. The two `INV-` ones are stock↔GL exposures.
- **F-4** (999999 wrap): not taken — the coordinator's list did not include it. `sprintf('JE-%s-%06d')` + `substr(-6)` still restarts silently at 1,000,000 entries/year, and widening to the tenant divides the ceiling by the company count. Same shape in `IncomeService.php`. Needs an explicit throw; file it.
- **F-5** (I-1 guard blind to the new key): not taken — not in the coordinator's list. `InventoryGlVoucherLockOrderTraceTest.php:159-180` matches an advisory statement only when `bindings[0] === $this->companyId`, so the strictly-wider tenant key is invisible to that guard. The test is green today (2/30) because the new key is taken adjacent to the company key, but nothing would catch a future regression that hoists it above the inventory row locks.
- **F-6 (owner ack)** and **F-7 (fleet census)** are unchanged and still owed before promotion — the local census remains zero-exposure and non-fleet (no `tenant_*` / `synerivia_central` DBs on this laptop).
- **`DocumentNumberingService.php:47,84`** — per-company `DocumentSequence` against the tenant-wide `documents` unique index, with a one-shot retry that re-reads the same company sequence. Out of lane.
- Throwaway DB `autoerp_c27_test` (5433) left in place for the re-gate; drop with `dropdb` when done.

**Not merged.**