# Gate r1 — Session B2 lane B2-1 / LEDGER C-27 (JE + income numbering tenant scope)

**VERDICT: ACCEPT-WITH-CONDITIONS — merge-blocking NO.**
Lens: **stock↔GL interaction**. Branch `fix/sb2-c27-je-numbering-tenant-scope` @ `eaccb7323` (base dev `834c8c017`).
Reviewer ran everything below by execution on PostgreSQL `127.0.0.1:5433`, throwaway DB `autoerp_stockglgate_test`,
in an **isolated detached worktree copy** (`scratchpad/c27probe`, real `vendor/` copied — not symlinked) so the shared
lane worktree was never edited (a treasury reviewer was live on the same branch).

---

## 1. What the change is, in seam terms

Numbering only. It does not add, remove or re-order a single stock movement or journal LINE. Its seam relevance is a
**failure mode**: before the fix, in a tenant with two companies, the second company's first JE of the year minted
`JE-YYYY-000001` against the tenant-wide unique index and died on `SQLSTATE 23505` — and `23505` is **not** in
`ConcurrencyFault::RETRYABLE_SQL_STATES` (`apps/api/app/Shared/Domain/ConcurrencyFault.php:56`), so on the POS path
`PosCoreReceiptProjection.php:505-518` **contained** that failure (`flushIfOutermost(contained: true)`), logged
`inventory GL batch failed; every inventory entry for the receipt was discarded`, and committed the receipt **with the
stock movements written and no COGS entry at all**. That is the canonical stock-moved-without-value-booked class. The
fix removes it for multi-company tenants. Verified both sides:

* stock side untouched — `git diff 834c8c017...eaccb7323 --stat` touches 3 production files, none in `Modules/Inventory`,
  `Modules/POS`, or any stock writer;
* GL side — every one of the 43 mint sites now passes the tenant id that the adjacent `JournalEntry::create` already
  writes, so the number and the row can no longer disagree.

## 2. Scope match (gate item 1) — VERIFIED

* Unique index: `apps/api/database/migrations/tenant/2025_11_30_100000_create_journal_entries_table.php:41`
  `$table->unique(['tenant_id','entry_number'])`. `grep -rn "entry_number" apps/api/database/migrations/` returns only
  that line plus the non-unique `:16` index and an unrelated `OB-` predicate comment — **nothing narrower exists**, and
  no later migration adds a `(company_id, entry_number)` unique. The only company-scoped unique is
  `uniq_je_company_chain_sequence` on `(company_id, chain_sequence)`
  (`2026_07_08_100400_add_chain_sequence_unique_index_to_journal_entries.php:36-40`) — the chain axis, untouched.
* Documents twin: `documents_tenant_id_type_document_number_unique`
  (`2025_11_30_080000_create_documents_table.php:39`, re-created at `2025_12_30_085029_...:24,41`).
* Generator (`GeneralLedgerService.php:5628-5652`): scan is `->where('tenant_id', $tenantId)`, lock key is
  `"journal_entry_number:{$tenantId}"`, format `sprintf('JE-%s-%06d', $year, $nextNumber)` — prefix/width unchanged.
* `JournalEntry` (`apps/api/app/Modules/Accounting/Domain/JournalEntry.php:45-108`) has **no global scope** — the widened
  `where('tenant_id', …)` cannot be silently re-narrowed by a company scope in a request context.
* Tenant expressions spot-checked at **13** sites by reading the adjacent `JournalEntry::create`, all identical to the
  `tenant_id` written and all **entity-derived, never context-derived**:
  `:2121` `$company->tenant_id` (GR-IR accrual), `:2271` `$company->tenant_id` (GR-IR clearing),
  `:2488` / `:2684` `$company->tenant_id` (supplier credit notes ± bonus return), `:5194` `$company->tenant_id`
  (inventory movement), `:5272` `$locked->tenant_id` + `$locked->company_id` (inventory reversal — taken from the LOCKED
  original row, the only correct source), `:5406` / `:5526` `$company->tenant_id` (write-off / write-off reversal),
  `:2921` `$voucher->tenant_id`, `:3946` / `:4212` `$payment->tenant_id`, `:4336` / `:4506` `(string) $receipt->tenant_id`,
  `:4577` `$command->tenantId`, `:4699` `$expense->tenant_id`, `:4838` `$income->tenant_id`.
  The Horizon/projection sites (POS `:3946/:4212/:4336/:4506/:4577`, inventory `:5194/:5406`) all read the tenant off the
  aggregate/DTO — **no `CompanyContext`, no `tenant()`** anywhere in the diff (`grep` on the diff confirms).
* Transaction-less GL methods (`:2197` GR-IR clearing, `:2428`/`:2603` supplier credit notes, `:4408` refund
  compensation) still get an EFFECTIVE lock: every caller wraps them — `SupplierInvoicePostingService.php:279` inside
  `DB::transaction`, `SupplierCreditNotePostingService.php:130,356,365`, `RefundCompensationService.php:257,271`.
* Manual endpoint: `JournalEntryController.php:187-215` takes the byte-identical key inside `store()`'s
  `DB::transaction` (`:93-119`); its tenant comes from `companyContext->requireCompany()->tenant_id` (`:69-70`) — a
  request path by definition, and the same value the row writes.

## 3. Lock ORDER + deadlock reasoning (gate item 2)

**What is correct.** `sealAndPersistEntry` stays per-company (`:3781-3783`), chain reads stay per company
(`:3795-3796`), and inside `generateEntryNumber` the tenant key is taken first, company key second (`:5644-5651`). The
key string is namespaced (`journal_entry_number:{uuid}`) and cannot alias the bare-uuid company key. The repo's I-1
invariant ("the company GL advisory is TERMINAL to inventory locks") **survives** — proven by execution, not reading:
`tests/Feature/Inventory/InventoryGlVoucherLockOrderTraceTest.php` (production trace, asserts first company advisory >
last `stock_levels`/`stock_movements` statement) is **OK (2 tests, 30 assertions)** on this branch, and the new tenant
key sits immediately before the company key, i.e. equally terminal.

**What is NOT correct — see finding F-1.** The docblock's universal claim is false, and the resulting AB-BA pair really
deadlocks in PG (reproduced, §5).

Cross-lane lattice checked and clean: `TreasuryMovementService::transfer()` takes the bare company key first
(`:239-247`) but its only caller mints BEFORE it (`RepositoryTransferService.php:77-101`), so the order is
tenant→company→repo; `AccountingService::postCorrectingEntryGl` (`:1306`) takes the company key and never mints;
`ProductCostLock` (`wac:{tenant}:{company}:{product}`), `remittance_number:{companyId}`, `expense_number:{tenant}`,
`income_number:{tenant}` are disjoint namespaces, and Expense/Income both take their own key BEFORE the JE key
(`ExpenseService.php:1031-1037` then GL; `IncomeService.php:156` then `createFromIncome`) with no path in the other
order (only `ExpenseController.php:235` / `IncomeController.php:205` call them).

## 4. Verified by execution — PG 5433, DB `autoerp_stockglgate_test`

| Run | Result |
|---|---|
| `tests/Feature/Accounting/JournalEntryNumberingTenantScopeTest.php` (branch code) | **OK (5 tests, 24 assertions)** — pgsql, no skips |
| `tests/Feature/Income/IncomeNumberingTenantScopeTest.php` (branch code) | **OK (2 tests, 9 assertions)** |
| **Revert probe** — both files against `git checkout 834c8c017 --` of the 3 production files, in the isolated copy | **Tests: 7, Failures: 6** — `23505 … journal_entries_tenant_id_entry_number_unique` (probe log :18/:128/:238/:253/:363/:473), `23505 … documents_tenant_id_type_document_number_unique`, plus both lock-key assertions null. Red without the fix, green with it. |
| `tests/Feature/Inventory/InventoryGlPostingSeamTest.php` | **OK (27 tests, 112 assertions)** |
| `tests/Feature/Inventory/GoodsReceiptGlPostingOrderTest.php` | **OK (12 tests, 42 assertions)** |
| `tests/Feature/Inventory/InventoryGlVoucherLockOrderTraceTest.php` | **OK (2 tests, 30 assertions)** |
| `tests/Feature/Inventory/InventoryGlLockOrderContentionTest.php` (10 green pairs + 4 red sensitivity arms) | **OK (15 tests, 174 assertions)** |
| PHPStan level 8, 3 touched production files, live-PG env | **[OK] No errors** |
| `pint --test` on all 5 touched files | `{"result":"pass"}` |
| `php tools/feature-lane-manifest-check.php` | OK — 1423 Feature classes / 74 groups; parked 70 groups / **1174** classes (matches the manifest edit); coverage debt 1/1 |

The four inventory/GR/POS lock-order + seam files above were **not** run by the implementer; they are the ones most
exposed to a new advisory lock, and they are green.

## 5. Findings

### [IMPORTANT] F-1 — `GeneralLedgerService.php:5628-5643` states a global lock-order invariant that the code does not enforce; the resulting AB-BA pair deadlocks (reproduced)

The docblock asserts: *"the tenant-keyed numbering lock is ALWAYS taken BEFORE the company-keyed chain lock … so two
transactions can never grab the two keys in opposite orders and deadlock."* That holds only for transactions that mint.
`sealAndPersistEntry` (`:3781-3783`) takes the **company key alone** whenever it posts an entry that was numbered in an
EARLIER transaction — exactly the replay branches
`createInventoryMovementEntry` `:5157-5163` and `createInventoryWriteOffEntry` `:5380-5387`
(`if ($existing !== null) { if ($postSynchronously && … !== Posted) { $this->postEntryNow($existing, …); } }`), which by
contract run inside the caller's root transaction (`postEntryNow` throws otherwise, `:3701-3703`).
`InventoryGlPostingBuffer::flushIfOutermost()` (`apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingBuffer.php:70-90`)
posts a **batch** of movement contexts in one loop inside that same root transaction, every one with
`postSynchronously: true` (`InventoryGlPostingService.php:73-86,117-127,189-…`). So a flush whose first context finds a
pre-existing Draft entry and whose second context mints acquires **company → tenant**, while every ordinary mint
acquires **tenant → company**.

Reproduced on PG 5433 with the exact key expressions the service uses (two connections, one holding
`hashtextextended('<companyUuid>',0)` then requesting `hashtextextended('journal_entry_number:<tenantUuid>',0)`, the
other the reverse):

```
A holds company key
B holds tenant key
B ERROR: SQLSTATE[40P01]: Deadlock detected: 7 ERROR:  deadlock detected
DETAIL:  Process 5640 waits for ExclusiveLock on advisory lock [...]; blocked by process 5639.
         Process 5639 waits for ExclusiveLock on advisory lock [...]; blocked by process 5640.
```

Before this lane the same sequence was a harmless re-acquisition of one key, so the hazard is **newly introduced**.

**Both sides checked before grading it.** `40P01` IS in `ConcurrencyFault::RETRYABLE_SQL_STATES`
(`ConcurrencyFault.php:56`), and `PosCoreReceiptProjection.php:506-517` re-throws retryable faults instead of
swallowing them, so a deadlock aborts the whole unit and the job retries — stock movement and GL entry stay atomic, and
**no receipt can commit with stock moved and COGS silently dropped by this path**. Interactive writers
(`DeliveryNoteService.php:198`, `ReturnNoteService.php:580`, `InvoiceController.php:839,1041`) propagate it as a rolled-back
500. Hence Important, not Critical.

*Fix (small):* take `journal_entry_number:{$entry->tenant_id}` in `sealAndPersistEntry` immediately before the company
key (universal T-before-C, cost = posting also serialises tenant-wide), **or** take it in the two `$existing` branches
before `postEntryNow`. If neither is done in this lane, the docblock must be downgraded to what is true and a LEDGER row
opened — a false lock-order invariant in the file everyone copies from is worse than the deadlock itself.

### [IMPORTANT] F-2 — no test on the stock side of the seam; the reproduction is expense-only

`JournalEntryNumberingTenantScopeTest` drives `createFromExpense` through `/api/v1/expenses/{id}/post` (`:56-79`,
`:263-289`) and `IncomeNumberingTenantScopeTest` drives income. **None** of the eight inventory / GR-IR / supplier
credit-note mint sites (`:2121, :2271, :2488, :2684, :5194, :5272, :5406, :5526`) is exercised for the two-company case,
and no test runs a mint in a **queue/projection context with `CompanyContext` cleared** (rule 20) even though seven of
the 43 sites live there. The pre-fix damage that actually loses money is the POS/inventory one described in §1
(contained flush + non-retryable 23505 ⇒ stock without COGS), and that path has no regression test.
*Ask:* one PG test that posts an inventory movement entry for the SECOND company of a tenant with `CompanyContext`
cleared, asserting BOTH sides — the `stock_movements` row AND the `JournalEntry` with `…000002`.

### [IMPORTANT] F-3 — residual: the two INVENTORY sibling minters keep the exact defect this lane fixes

`OpeningBalancePostingService::generateOpeningEntryNumber()` (`apps/api/app/Modules/Inventory/Application/Services/OpeningBalancePostingService.php:290-306`)
scans `->where('company_id', $companyId)` for `INV-OB-{year}-%` against the **tenant-wide**
`journal_entries_tenant_id_entry_number_unique`, keyed `hashtext('inv-ob-seq:{companyId}:{year}')`; same shape in
`ResetOpeningBalanceService.php:263-272` (`INV-OBR-`), `AccountingOpeningService.php:474-492` (`OB-`),
`ArApOpeningService.php:448-472` (`HIST-`). In a two-company tenant, company #2's inventory opening-balance post 23505s
unconditionally — i.e. opening stock lands with no GL, or the whole batch rolls back. The implementer parks these on
Session W; the promoter must not read "C-27 merged" as "numbering fixed". Note also they use 32-bit `hashtext` while
GL/Expense/Income use 64-bit `hashtextextended` — two hash families guarding one table.

### [MINOR] F-4 — 999999 wrap now arrives N× sooner and fails permanently, silently

`sprintf('JE-%s-%06d')` + `substr($lastNumber, -6)` (`:5646-5652`): at 1,000,000 entries in one YEAR the formatted
number becomes 7 digits, `substr(-6)` reads `000000`, the next allocation restarts at `…000001` and every mint 23505s
forever. Widening to the tenant divides the ceiling by the number of companies. Same code shape in the income twin
(`IncomeService.php:238-245`). *Ask:* throw explicitly when `$nextNumber > 999999`.

### [MINOR] F-5 — the I-1 terminal-advisory guard does not cover the new key

`InventoryGlVoucherLockOrderTraceTest.php:159-180` matches an advisory statement only when
`($query['bindings'][0] ?? null) === $this->companyId`. The tenant key is strictly WIDER (it blocks every company of the
tenant), so holding it across inventory row locks would be worse than holding the company key — yet no guard would see
it. *Ask:* extend the trace assertion to `journal_entry_number:{tenantId}`.

### [CONDITION] F-6 — owner ack of the numbering contract (same shape as Q-11 condition 2)

JE and INC numbers now interleave across the companies of a tenant, so each company's own register shows gaps. Verified
this does not break the FEC export today: `apps/api/app/Modules/Taxation/Infrastructure/Exporters/FecExporter.php:62-90`
mints its own sequential `EcritureNum` from the VAT summary and never reads `journal_entries.entry_number`; the GL report
only ORDERs by it (`GeneralLedgerReportService.php:314,414`), so ordering stays monotonic. It remains user-visible and
needs the owner ack line.

### [CONDITION] F-7 — the census is local-only

Implementer report §8 is explicit: no `tenant_*` / `synerivia_central` DBs exist on this laptop, so `tenants:run` looped
over nothing and the fleet census (staging/production) has NOT been run. Must run before promotion.

## 6. Test honesty (gate item 5) — PASS

Real HTTP routes, `RefreshDatabase`, `RolesAndPermissionsSeeder`, real models, no mock of the unit under test. The lock
pins assert the actual BINDING TEXT from `DB::getQueryLog()` (`JournalEntryNumberingTenantScopeTest.php:224-238`), not a
mocked call. PG-only assertions `markTestSkipped` (`:109-111, :148-150, :183-185`) rather than pass vacuously. T3
(`:146-175`) is honestly labelled as a mutual-exclusion pin, not a race proof, and T2's query-log ordering carries the
ordering claim. Verified by re-running: 5/5 on PG with zero skips, and 6/7 failures on the reverted base.

## 7. Conditions for merge

1. **F-1**: either normalise T-before-C on the `postEntryNow`-of-an-existing-entry paths, or downgrade the docblock's
   universal claim and open a LEDGER row. (One or the other; do not ship the false invariant silently.)
2. **F-2**: add the second-company inventory-mint test (queue context, both sides asserted) — this lane, or a named
   follow-up row.
3. **F-6** owner ack line on the interleaved numbering contract; **F-7** fleet census before promotion.
4. **F-3** stays a named Session-W row that explicitly lists `INV-OB-` and `INV-OBR-` as stock↔GL exposures.
5. F-4 / F-5 are cheap; take them now or file them.

## 8. Machine notes

Throwaway DB `autoerp_stockglgate_test` (5433) and the probe worktree copy were removed after the run. The shared lane
worktree `.worktrees/sb2-c27-je-numbering` was never written to; nothing was pushed or merged.
