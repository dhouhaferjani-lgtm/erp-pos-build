# enforcement-P3 · M1 — `journal_entries` balance-guard census (package 3(a))

**Branch** `codex/enforcement-p3-money-lanes` · **base** `0ca7bbb09` · **milestone** `p3-M1`
**Binding scope** `docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md` §4 "3(a)" (gate-r1 C-2 ruling, acceptance rule gate-r2 R2-H-7).

---

## 0. Executive result

| quantity | value |
|---|---|
| `journal_entries` creation sites found (all patterns) | **55** raw matches → **53** real (2 are comment/docblock false positives) |
| distinct files containing a creator | **9** (7 production + 2 seeders) |
| class **(a)** draft-only creators (return Draft, do not post themselves) | **21** |
| class **(b)** posts through the chokepoint (self-post or caller-post) | **24** |
| class **(c)** writes `Posted` WITHOUT `sealAndPersistEntry` — structural | **8** (6 production + 2 seeders) |
| class **(c)** rows that are **genuine balance gaps** (unbalanced posted row reachable) | **0** |
| new balance validators added | **0** (correct — see §4) |
| red-first unbalanced-post tests presented as class-(c) evidence | **0** (correct — every candidate is green-at-base ⇒ DISQUALIFIED per C-2) |
| deliverable **D** (chokepoint failure-mode normalization) | **1 genuine defect found and fixed**, red-first proven (§5) |
| reported findings handed to the parent (out of 3(a) balance scope) | **6** (§6) |

**The headline:** the GL posting chokepoint is genuinely complete for the balance
invariant. Every path that can write a `posted`/sealed row is already guarded —
either by `sealAndPersistEntry`'s own `bcadd`/`bccomp` check, or (for the three
`AccountingService` document paths) by the constructor-injected
`DoubleEntryValidator` via `assertLegsBalance()`. **No new validator was warranted
and none was added.** The one real defect the census surfaced is not a missing
guard but a *defeated* one: the chokepoint's refusal is thrown as a bare
`\InvalidArgumentException` and is swallowed by an over-broad `catch` on a live
production path. That is deliverable D, and it is fixed and red-first proven.

---

## 1. Census method + completeness proof

The P1 baseline (`apps/api/tests/Architecture/baselines/document-per-action-baseline.json`)
carries only **4** `journal_entries` keys (indices 1, 2, 10, 33) — it is the
*unlinked-write violation* set, **not** a creator inventory. It was used as a seed
only; the behavioural census below was built independently and its completeness
proven three ways.

**(i) Positive sweep — two creation patterns.**

```
grep -rn "JournalEntry::create"          --include='*.php' app/ database/   → 47 sites
grep -rn "JournalEntry::query()->create" --include='*.php' app/ database/   →  6 sites
```

> ⚠️ **Census blind spot worth propagating.** The `JournalEntry::query()->create`
> form is **invisible** to a `JournalEntry::create` grep and hid **6 creators**
> (all the `createInstrument*Entry` family, `GeneralLedgerService.php:2884/2993/3079/3134/3201`,
> plus `createOutboundInstrumentEntry:1084`). Any inventory built from the naive
> pattern alone is incomplete by 6 rows. **`DocumentPerActionWriteScanner`
> should be checked for the same blind spot** — see finding R-6 (§6).

**(ii) Negative sweep — no other write vector.** All returned empty:

```
JournalEntry::insert | ::upsert | ::forceCreate | ::firstOrCreate | ::updateOrCreate   → none
->journalEntries()->create / ->save(...)  (relation-create)                            → none
DB::table('journal_entries')->insert / ->update                                        → none
```

A full enumeration of every static call on the model confirms the closed set:
`create(` ×49, `query(` ×43, `where(` ×7, `getNextChainSequence(` ×4,
`getLastChainHash(` ×4, `observe(` ×1, `find(` ×1. Nothing else constructs a row.

**(iii) The decisive structural proof — what can write `Posted`.**

```
grep -rn "JournalEntryStatus::Posted" --include='*.php' app/   (assignments only, reads excluded)
```

yields **exactly six** write sites plus the chokepoint:

| # | site | kind |
|---|---|---|
| 0 | `GeneralLedgerService.php:3440` | **the chokepoint** (`sealAndPersistEntry` `$entry->update([...])`) |
| 1 | `AccountingOpeningService.php:268` | direct create |
| 2 | `AccountingService.php:404` | direct create |
| 3 | `AccountingService.php:556` | direct create |
| 4 | `AccountingService.php:903` | direct create |
| 5 | `OpeningBalancePostingService.php:238` | direct create |
| 6 | `ResetOpeningBalanceService.php:163` | direct create |

Reinforced by two independent facts:

- **DB default is fail-safe.** `journal_entries.status` is
  `->default('draft')` (`database/migrations/tenant/2025_11_30_100000_create_journal_entries_table.php:19`),
  never altered by any later migration. The `JournalEntry` model declares **no**
  `$attributes` default. An INSERT omitting `status` therefore lands **draft**.
- **Lines freeze at seal.** `JournalLineObserver` (`app/Modules/Accounting/Domain/Observers/JournalLineObserver.php`)
  throws `ImmutableJournalEntryException` on `creating` (when the entry `isChained()`
  and already has lines), `updating`, and `deleting`. There are **no** `JournalLine`
  update/delete call sites in `app/` at all. So an entry cannot be unbalanced
  *after* it was balanced at seal time.

> **Conclusion (a)-question answered:** *can a draft reach `posted`/sealed state
> without passing `sealAndPersistEntry`?* **No.** The only Posted-writers are the
> chokepoint and the six direct creators enumerated above; there is no raw-SQL
> status flip, no relation-create, and the column default is `draft`.

---

## 2. Full census table

**Legend — classification**
`(a)` draft-only creator · `(b)` posted through the chokepoint · `(c)` writes Posted without `sealAndPersistEntry`

**Legend — posting route.** `postEntry` (`:2772`) → `postEntryWithOptionalActor` (`:2777`) → `sealAndPersistEntry` (`:3378`);
`postEntryNow` (`:3348`) → `sealAndPersistEntry`. Two wrapper helpers also funnel there and are easy to miss:
`postEntryAndDispatchPostedEvent(AfterCommit)` (`:76`/`:95`) and
`postSystemGeneratedEntryAndDispatchPostedEvent(AfterCommit)` (`:86`/`:112`).

### 2.1 `GeneralLedgerService.php` — self-posting creators → class (b)

All rows below create a Draft **and post it themselves** through the chokepoint.
Already guarded. **No new validator, no new test presented as evidence.**

| # | site | method | posting route (in-body) | disposition |
|---|---|---|---|---|
| 1 | `:434` | `createCustomerAdvanceJournalEntry` | `postEntryNow` / `…AfterCommit` | (b) chokepoint-guarded |
| 2 | `:535` | `reverseSupplierAdvanceJournalEntry` | `postEntryNow` / `…AfterCommit` | (b) chokepoint-guarded |
| 3 | `:647` | `reverseCustomerAdvanceJournalEntry` | `postEntryNow` / `…AfterCommit` | (b) chokepoint-guarded |
| 4 | `:745` | `createPaymentRefundJournalEntry` | `postEntryNow` / `…AfterCommit` | (b) chokepoint-guarded |
| 5 | `:822` | `createSupplierInvoiceJournalEntry` | `postEntryAndDispatchPostedEventAfterCommit` (`:875`) | (b) chokepoint-guarded |
| 6 | `:910` | `createSupplierPaymentJournalEntry` | `postEntryNow` / `…AfterCommit` | (b) chokepoint-guarded |
| 7 | `:1156` | `createExpenseSettlementJournalEntry` | `postEntryNow` / `…AfterCommit` | (b) chokepoint-guarded |
| 8 | `:1249` | `createRepositoryAdjustmentJournalEntry` | `postEntryNow` | (b) chokepoint-guarded |
| 9 | `:1343` | `createAcquirerFeeJournalEntry` | `postEntryNow` | (b) chokepoint-guarded |
| 10 | `:1482` | `createPaymentReceivedJournalEntry` | `postEntryNow` / `…AfterCommit` | (b) chokepoint-guarded |
| 11 | `:1916` | `createGoodsReceiptGrIrEntry` | `postSystemGenerated…AfterCommit` (`:1957`) | (b) chokepoint-guarded |
| 12 | `:2061` | `createSupplierInvoiceGrIrClearingEntry` | `postSystemGenerated…` (`:2190`) | (b) chokepoint-guarded |
| 13 | `:2278` | `createSupplierCreditNoteEntry` | `postSystemGenerated…` (`:2357`) | (b) chokepoint-guarded |
| 14 | `:2442` | `createSupplierCreditNoteEntryWithBonusReturn` | `postSystemGenerated…` (`:2541`) | (b) chokepoint-guarded |
| 15 | `:2619` | `createVoucherLedgerEntry` | `…AfterCommit` (`:2662`/`:2664`) | (b) chokepoint-guarded |
| 16 | `:4127` | `createFromExpense` | `postEntryNow` / `…AfterCommit` | (b) chokepoint-guarded |
| 17 | `:4266` | `createFromIncome` | `postEntryNow` / `…AfterCommit` | (b) chokepoint-guarded |
| 18 | `:4346` | `createLinkedCostCapitalizationEntry` | `postEntryNow` / `…AfterCommit` | (b) chokepoint-guarded |
| 19 | `:4434` | `createLinkedCostCapitalizationReversalEntry` | `postEntryNow` / `…AfterCommit` | (b) chokepoint-guarded |
| 20 | `:4616` | `createInventoryMovementEntry` | `postEntryNow` / `…AfterCommit` | (b) chokepoint-guarded |
| 21 | `:4694` | `reverseInventoryMovementEntry` | `postEntryNow` | (b) chokepoint-guarded |
| 22 | `:4833` | `createInventoryWriteOffEntry` | `postEntryNow` / `…AfterCommit` | (b) chokepoint-guarded |
| 23 | `:4953` | `reverseInventoryWriteOffEntry` | `postEntry` (`:4984`) | (b) chokepoint-guarded — **stock↔GL seam**, see §3 |

Two of these self-post **conditionally** — recorded here, escalated as finding R-2:

| site | method | condition | consequence when false |
|---|---|---|---|
| `:1583` | `createPaymentToleranceJournalEntry` | posts at `:1645` **only if `$user !== null`** | `payment_tolerance` draft never posted |
| `:1711` | `clearCustomerAdvanceToReceivable` | posts at `:1749` **only if `$user !== null`** | `prepayment_application` draft never posted |

### 2.2 `GeneralLedgerService.php` — draft-only creators → class (a)

These return a Draft. Per §1(iii) a draft can only ever reach Posted via the
chokepoint, so **none of them can seal outside it**. Classified by who posts.

| # | site | method | who posts the draft | disposition |
|---|---|---|---|---|
| 24 | `:3563` | `createPOSPaymentEntry` | `ReceiptPaymentService.php:303` → `postEntry` `:313`; `TreasuryReceiptBridge.php:1377` → `postEntryNow` `:1387` | (a)→(b) caller posts via chokepoint |
| 25 | `:3646` | `createPOSRefundReversalEntry` | `TreasuryReceiptBridge.php:1372` → `postEntryNow` `:1387` | (a)→(b) caller posts via chokepoint |
| 26 | `:3493` | `createPOSPaymentToleranceEntry` | `ReceiptPaymentService.php:400` → `postEntry` `:406` | (a)→(b) caller posts via chokepoint |
| 27 | `:3758` | `createPosCashRoundingEntry` | `TreasuryReceiptBridge.php:465` → `postEntryNow` `:473` | (a)→(b) caller posts via chokepoint |
| 28 | `:3928` | `createPosToleranceWriteoffEntry` | `TreasuryReceiptBridge.php:546` → `postEntryNow` `:547` | (a)→(b) caller posts via chokepoint |
| 29 | `:3862` | `createRefundCompensationEntry` | `RefundCompensationService.php:271` → `postEntryNow` `:287` | (a)→(b) caller posts via chokepoint |
| 30 | `:1084` | `createOutboundInstrumentEntry` (private) | `InstrumentLifecycleService` posts via `postEntryNow` | (a)→(b) chokepoint-guarded |
| 31 | `:2884` | `createInstrumentClearingEntry` | caller posts via `postEntryNow`; **also** guards balance itself at `:2865-2867` (`\LogicException` if `net+fee+feeVat != nominal`) | (a)→(b) doubly guarded |
| 32 | `:2993` | `createInstrumentDishonorEntry` | caller posts via `postEntryNow` | (a)→(b) chokepoint-guarded |
| 33 | `:3079` | `createInstrumentToleranceReversalEntry` | caller posts via `postEntryNow` | (a)→(b) chokepoint-guarded |
| 34 | `:3134` | `createInstrumentTransitEntry` (private) | caller posts via `postEntryNow` | (a)→(b) chokepoint-guarded |
| 35 | `:3201` | `createInstrumentCancellationEntry` | caller posts via `postEntryNow` | (a)→(b) chokepoint-guarded |
| 36 | `:1413` | `createRepositoryTransferJournalEntry` | **nobody** — `RepositoryTransferService.php:77` discards; no `postEntry` in that file | (a) **orphan draft** → finding R-2 |
| 37 | `:3999` | `createPOSChargeEntry` | **nobody** — `TreasuryAccountChargeBridge.php:80` discards; the bridge's replay check *asserts* Draft at `:189` | (a) **orphan draft by design** → finding R-2 |
| 38 | `:146` | `createFromInvoice` | **no production call site** (seeder + tests only) | (a) N/A — dead in production → finding R-3 |
| 39 | `:230` | `createFromCreditNote` | **no production call site** (tests only) | (a) N/A — dead in production → finding R-3 |
| 40 | `:353` | `createPaymentEntry` | **no production call site** (tests only) | (a) N/A — dead in production → finding R-3 |

### 2.3 Non-`GeneralLedgerService` creators

| # | site | method | class | posting route | disposition |
|---|---|---|---|---|---|
| 41 | `JournalEntryController.php:97` | `store` | (a) | separate operator action `POST /journal-entries/{id}/post` → `postEntry` (`JournalEntryController.php:177`) | (a)→(b); **additionally** pre-validated by the injected `DoubleEntryValidator->isBalanced()` at `:76` before the Draft is even created |
| 42 | `UninvoicedDeliveryNoteService.php:530` | `generateYearEndAdjustment` | (a) | **no production call site** | N/A — dead code, corroborated by `ProvisioningRequiredPurposesV1.php:78` → finding R-3 |
| 43 | `UninvoicedDeliveryNoteService.php:591` | `generateReversalEntry` | (a) | **no production call site** | N/A — dead code → finding R-3 |
| 44 | `AccountingService.php:398` | `createInvoiceGLEntries` | **(c)** | direct `Posted` create, hash set at `:507` | **already guarded** — `assertLegsBalance()` at `:503` → `DoubleEntryValidator::isSumBalanced($lines,$scale)` (`:333`). Green-at-base ⇒ **DISQUALIFIED as a target** |
| 45 | `AccountingService.php:550` | `createCreditNoteGLEntries` | **(c)** | direct `Posted` create | **already guarded** — `assertLegsBalance()` at `:716`. Green-at-base ⇒ **DISQUALIFIED** |
| 46 | `AccountingService.php:894` | `reverseDocumentGl` | **(c)** | direct `Posted` create | **already guarded** — pre-flight balance refusal at `:881` (`UnreversibleDocumentGlException`) **and** `assertLegsBalance()` at `:929`. Green-at-base ⇒ **DISQUALIFIED**. Documented lineless carve-out → finding R-4 |
| 47 | `AccountingOpeningService.php:262` | `postBatch` | **(c)** | direct `Posted` create, `is_historical=true` (off-chain) | **balanced by construction** — the OBE plug at `:316-334` computes `difference = ΣDr − ΣCr` and writes the offsetting leg, so Σ always nets. Additionally scale-clamped upstream (see §4.1). Green-at-base ⇒ **DISQUALIFIED** |
| 48 | `OpeningBalancePostingService.php:232` | `post` | **(c)** | direct `Posted` create, `is_historical` | **balanced by construction** — exactly two legs, both the *same* variable `$totalInventoryValue` (`:250`/`:260`), each written through `CurrencyScale::bcformatStrict`. Green-at-base ⇒ **DISQUALIFIED** |
| 49 | `ResetOpeningBalanceService.php:157` | `reset` | **(c)** | direct `Posted` create, `is_historical` | **balanced by construction** — two legs, same `$totalValue` (`:172`/`:182`), both `bcformatStrict`. Green-at-base ⇒ **DISQUALIFIED** |
| 50 | `CoffeeShopSeeder.php:1132` | `seedPartnerTransactions` | (c) | direct `Posted` create | **N/A — seeder, not production code.** Out of the enforcement contract |
| 51 | `DemoPharmacySeeder.php:872` | `seedTunisiaBalances` | (c) | direct `Posted` create | **N/A — seeder, not production code** |

**False positives excluded:** `JournalCode.php:12` and `VatPeriodCancellationGuard.php:66`
are docblock prose mentioning `JournalEntry::create()`, not call sites.

---

## 3. Lens notes

**stock↔GL seam (`ReverseWriteOffService`) — no gap.** `reverse()`
(`app/Modules/BatchExpiry/Domain/Services/ReverseWriteOffService.php`) creates no
journal entry itself; its only GL call is `reverseInventoryWriteOffEntry()` at
`:203-209`, which creates a Draft (`GeneralLedgerService.php:4959`) and posts it
**only** through `postEntry` (`:4984`) → `sealAndPersistEntry`. Lines are a strict
debit/credit mirror of the original (`:4966-4977`), so balance is inherited and
then re-asserted by the chokepoint. **Not queued** — the sole invoker is the
synchronous HTTP route `POST /stock-movements/{movementId}/reverse-write-off`
(`app/Modules/BatchExpiry/Presentation/routes.php:34` →
`BatchController.php:485`); no `ShouldQueue` class exists under `app/Modules/BatchExpiry`.
Rule 19 is satisfied explicitly: the company currency is resolved and passed down
at `ReverseWriteOffService.php:202-208`, and the chokepoint resolves scale as
`getScale($currencyCode ?? $companyCurrencyCode)` (`GeneralLedgerService.php:3392-3395`)
— never a bare no-arg `getScale()`. **Class (b), no work.**

**Rule 19/20 in touched queued paths.** The only file touched by an implementation
change in this milestone is `SalesOrderToInvoiceConverter` (§5), which runs in the
synchronous HTTP conversion path, not a worker. No scale resolution was added,
changed, or removed; no `getScale()` call was introduced. No projection `apply()`
is touched, so the `app(CompanyContext::class)->clear()` obligation does not arise.

---

## 4. Why zero class-(c) guards were added (and two killed leads)

The C-2 ruling is explicit: *a green-at-base test DISQUALIFIES the target.* Both
promising leads were pursued to the point of proof and then **abandoned as false
positives** rather than dressed up as coverage.

### 4.1 Killed lead #1 — `AccountingOpeningService::postBatch` PG rounding divergence

**Hypothesis.** `postBatch` writes the raw imported `$debit`/`$credit` into the
line (`:299-307`) **without** `CurrencyScale::bcformatStrict`, while accumulating
`$totalDebit`/`$totalCredit` with `bcadd(…, $scale)`. `bcadd` **truncates**;
PostgreSQL `numeric(N,3)` **rounds half-away-from-zero**. A 4-dp input would
therefore persist a line value different from the total the OBE plug was computed
against → an unbalanced, `Posted`, hash-free opening-balance entry in production.

**The rounding asymmetry is real** (verified against the live PG 15 instance):

```
$ psql -tAc "SELECT '10.0005'::numeric(15,3), '10.0004'::numeric(15,3);"
10.001|10.000                       ← PG rounds up; bcadd('10.0005',…,3) truncates to 10.000
```

**Why it is nevertheless NOT a reachable gap.** `postBatch` consumes only rows with
`status = Valid` (`:247`). The **only** writer that can set that status together with
`mapped_data` is `OpeningBalanceBatchService.php:494-497`, which stores
`$result['mapped_data']` produced by `AccountingOpeningService::validateRow()` — and
`validateRow` **normalises both amounts to the currency scale before storing them**:

```php
// AccountingOpeningService.php:190 / :196
$mappedData['debit']  = bcadd('0', (string) $debit,  $scale);
$mappedData['credit'] = bcadd('0', (string) $credit, $scale);
```

So every value `postBatch` ever sees is already at `$scale`, and the write/total
divergence cannot arise. Constructing the unbalanced state would require writing
`mapped_data` directly from a test — **fabricating a state production cannot reach**.
That is precisely the C-2 false positive. **Disposition: class (c) structural,
already-safe; no guard, no test.** (A defence-in-depth `bcformatStrict` at the line
write is still *stylistically* correct per rule 19 and is raised as finding R-5,
not implemented here — implementing it would be unjustified scope creep with no
demonstrable failing behaviour.)

### 4.2 Killed lead #2 — SQLite vs PG for the opening-balance pair

`OpeningBalancePostingService::post` and `ResetOpeningBalanceService::reset` each
write exactly two legs from a **single** variable, both already passed through
`CurrencyScale::bcformatStrict`. Any rounding the DB applies is applied *identically*
to both legs, so it cancels. No input can unbalance them. **Green at base on both
SQLite and PG ⇒ disqualified.**

### 4.3 `AccountingService` trio

`assertLegsBalance()` (`:313`) delegates to the **constructor-injected**
`DoubleEntryValidator::isSumBalanced($lines, $scale)` (`:333`) — the house
validator, injected per rule 13 at `AccountingService.php:54`. It is invoked on the
freshly re-read persisted lines *before* the hash is computed, on all three paths
(`:503`, `:716`, `:929`). A deliberately-unbalanced-post test on any of them is
green at base. **Disqualified; characterised as already-guarded.**

---

## 5. Deliverable D — chokepoint failure-mode normalization (the one real defect)

Brief §4 3(a) item 4 authorises this as *"its own recorded deliverable — not a
duplicate validator"*, when the census shows the chokepoint's exception posture
diverges from the house throw+alert pattern and an unbalanced post could be
**silently swallowed**. The census shows exactly that.

### 5.1 The divergence

- **House pattern.** `UnbalancedJournalEntryException`
  (`app/Modules/Accounting/Domain/Exceptions/UnbalancedJournalEntryException.php`)
  already exists and is the type `AccountingService::assertLegsBalance()` throws
  (`:350`). Its own docblock states the contract: *"the auto-posting paths fail
  CLOSED on imbalance."*
- **The chokepoint diverges.** `sealAndPersistEntry` instead throws a **bare
  `\InvalidArgumentException`** (`GeneralLedgerService.php:3406-3409`) —
  indistinguishable from ordinary argument-validation noise.

### 5.2 The consequence — a live swallowed unbalanced post

`SalesOrderToInvoiceConverter::transferPrepayments()` posts a real GL entry through
the chokepoint (`clearCustomerAdvanceToReceivable`, which posts at
`GeneralLedgerService.php:1749`) and wraps the call in:

```php
// SalesOrderToInvoiceConverter.php:589  (BEFORE)
} catch (\InvalidArgumentException|\RuntimeException $e) {
    // If GL clearing cannot be created, log warning but don't fail the conversion.
    Log::warning('Could not create GL entry for prepayment transfer: '.$e->getMessage(), [...]);
    $invoicePayload['prepayments_transferred']['gl_entry_skipped'] = true;
    $invoicePayload['prepayments_transferred']['gl_skip_reason']  = $e->getMessage();
    $invoice->update(['payload' => $invoicePayload]);
}
```

That `catch` matches **both** the chokepoint's `\InvalidArgumentException` *and* the
house `UnbalancedJournalEntryException` (currently a `\RuntimeException`). An
unbalanced GL post is therefore **downgraded to a `Log::warning`**, the conversion
reports success, and the customer advance is silently never cleared — the advance
and receivable accounts diverge permanently with no loud failure. This is the exact
incident class deliverable D names.

### 5.3 The fix (no second balance algorithm)

1. `UnbalancedJournalEntryException` now extends **`\InvalidArgumentException`**
   (was `\RuntimeException`). This is a strictly **backward-compatible narrowing**
   of what the chokepoint already throws: every existing
   `catch (\InvalidArgumentException)` keeps working unchanged.
2. `sealAndPersistEntry` throws `UnbalancedJournalEntryException::forChokepoint(...)`
   — **the identical message string**, so both existing message assertions still
   pass. The balance algorithm itself is **untouched**; only the thrown type changes.
3. `SalesOrderToInvoiceConverter` re-throws `UnbalancedJournalEntryException` before
   the graceful `catch`, so a balance failure fails loudly while the genuine
   "cannot create" cases (non-positive amount, advance-balance ceiling) stay graceful.

Blast radius verified: no `catch (\RuntimeException)` exists on any
`createInvoiceGLEntries` / `createCreditNoteGLEntries` / `reverseDocumentGl` caller,
and the only two tests referencing the chokepoint's unbalanced failure assert on the
**message**, which is preserved.

### 5.4 Red-first evidence

<!-- RED_FIRST_EVIDENCE -->

---

## 6. Reported findings (out of 3(a) balance scope — handed to the parent)

| id | finding | evidence | why not fixed here |
|---|---|---|---|
| **R-1** | **Live `journal_entries` DELETE.** `RepositoryTransferService.php:105` performs an unconditional `$draft->delete()` on a `journal_entries` row. It is safe *today* only because the row is an unchained Draft inside the caller's own transaction and the replay path rolls back to a savepoint first. Two residual weaknesses: (i) `JournalEntryObserver::deleting()`'s `isChained()` guard reads the **stale in-memory** `$draft`, not the instance `postEntryNow` mutated, so the guard is not load-bearing; (ii) there is **no DB-level append-only trigger** on `journal_entries`. | `RepositoryTransferService.php:103-106`; `TreasuryMovementService.php:296-298,341-348,394-401`; `JournalEntryObserver.php:43-48`; `JournalEntry.php:127-130` | Append-only violation, **not a balance-guard gap** — the deleted row is a Draft and never passed the chokepoint. Fixing the DELETE is outside 3(a). Recorded as instructed by the parent ledger lead. |
| **R-2** | **Orphan-draft surface.** Drafts reachable from production that are **never posted**: `treasury_transfer` (`GLS:1413`, unconditional), `pos_account_charge` (`GLS:3999`, unconditional — the bridge *asserts* Draft at `TreasuryAccountChargeBridge.php:189`), `payment_tolerance` (`GLS:1583` when `PaymentAllocationService.php:228` supplies a non-`User` actor), `prepayment_application` (`GLS:1711` when `SalesOrderToInvoiceConverter.php:152-153` has no `actor_user_id`), `manual` (until an operator hits the post endpoint). | as cited | Unposted drafts never seal, so they are not a balance-guard gap. But an AR charge that never posts means revenue/AR is never recognised — a real fiscal-completeness issue for the parent. |
| **R-3** | **Dead GL creators.** No production call site: `createFromInvoice` (`:146`), `createFromCreditNote` (`:230`), `createPaymentEntry` (`:353`), and both `UninvoicedDeliveryNoteService` creators (`:530`, `:591`). The last is independently corroborated by `ProvisioningRequiredPurposesV1.php:78`. Note `createPaymentEntry` is P1 baseline index 1 and `UninvoicedDeliveryNoteService` is index 10 — i.e. **two of P1's four `journal_entries` violation rows sit on dead code.** | as cited | Dead-code removal is out of scope; but it materially changes how P1's violation partition should be read. |
| **R-4** | **Knowingly-sealed unbalanced entry.** `AccountingService::reverseDocumentGl` deliberately skips the balance assertion for **lineless** documents, sealing a one-legged (unbalanced) entry — accepted in-code as "the lesser evil" so a lineless document remains cancellable. | `AccountingService.php:860-881` (GL gate finding M-1) | A pre-existing, documented, deliberate acceptance from an earlier gate. Re-litigating it is not 3(a)'s call. |
| **R-5** | **Rule-19 boundary drift (cosmetic, no reachable defect).** `AccountingOpeningService::postBatch` writes line amounts without `CurrencyScale::bcformatStrict`, relying on upstream `validateRow` normalisation. Safe today, but the invariant lives in a different class from the write. | `AccountingOpeningService.php:299-307` vs `:190,:196` | No demonstrable failing behaviour (§4.1) — implementing it would be scope creep and would ship a green-at-base test. |
| **R-6** | **Census blind spot for the P1 scanner.** `JournalEntry::query()->create` is invisible to a `JournalEntry::create` grep and hides **6** creators. `DocumentPerActionWriteScanner` should be re-checked for the same pattern, and P1's 116-site census re-derived if it shares the blind spot. | §1(i); `GeneralLedgerService.php:1084,2884,2993,3079,3134,3201` | P1 is landed and closed; changing its baseline is the parent's call, not P3's. |

---

## 7. Deviations from the brief

| id | deviation |
|---|---|
| **M1-D1** | **Line numbers in the brief have drifted** from the landed base. Actual: `sealAndPersistEntry` `:3378` (balance check `:3396-3410`), not `:3480-3510`; `postEntry` `:2772`, not `:2874`; `postEntryNow` `:3348`, not `:3450`; `createPOSChargeEntry` `:3999`, not `:4072`. Every cited fact was verified at the **actual** location. |
| **M1-D2** | **The P1 baseline was insufficient as a census seed** — it holds 4 `journal_entries` keys (a violation set), not a creator inventory. The census was rebuilt independently and its completeness proven (§1). |
| **M1-D3** | **Zero class-(c) guards added, zero class-(c) red-first tests.** This is the *correct* outcome under the C-2 ruling, not an omission: every structural bypass is green-at-base and therefore disqualified. Two leads were pursued to proof and killed (§4). The milestone's implementation content is deliverable D. |
| **M1-D4** | **Worktree had no `vendor/`.** `composer install` was run in the worktree (a symlink to the main repo's `vendor` would autoload **stale main-repo** `App\` classes and invalidate every test result). Autoloader confirmed worktree-local. |
| **M1-D5** | **PG verification instance.** The `numeric` rounding fact in §4.1 was verified against the **port 5432 Homebrew PostgreSQL 15** instance (`TimeZone = Africa/Tunis`), using a dedicated scratch database `p3m1_test`. `autoerp_test` was **not** touched. |
