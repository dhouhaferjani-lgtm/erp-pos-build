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
| deliverable **D** (chokepoint failure-mode normalization) | **type normalization + 1 genuinely swallowed queued-context refusal fixed**, both red-first proven (§5.6, §5.7) |
| catchers of a balance refusal censused (round-1 finding 1) | **7 broad + 8 narrow-excluded** (§5.5) |
| reported findings handed to the parent (out of 3(a) balance scope) | **9** (§6) |
| round-0 changes WITHDRAWN at review (unreachable / wrong) | **2** — the converter re-throw (§5.2) and the exception re-parenting (§5.3) |

**The headline:** the GL posting chokepoint is genuinely complete for the balance
invariant. Every path that can write a `posted`/sealed row is already guarded —
either by `sealAndPersistEntry`'s own `bcadd`/`bccomp` check, or (for the three
`AccountingService` document paths) by the constructor-injected
`DoubleEntryValidator` via `assertLegsBalance()`. **No new validator was warranted
and none was added.** The real defects are not missing guards but *defeated* ones:
the chokepoint's refusal was thrown as a bare `\InvalidArgumentException` that no
caller could single out, and one production listener genuinely swallowed it. Both
are fixed and red-first proven (§5.6, §5.7).

**Round 1 changed this section's conclusions and the corrections are recorded in
place, not smoothed over.** Round 0 surveyed only *creators*, never *catchers* — so
it fixed the wrong site. Two round-0 changes were **withdrawn**: a re-throw in
`SalesOrderToInvoiceConverter` that was proven unreachable with a non-discriminating
test (§5.2), and a re-parenting of `UnbalancedJournalEntryException` that silently
changed an API error envelope and invalidated two explicit in-tree design contracts
(§5.3). The genuinely swallowed unbalanced post — in the queued-listener context the
brief actually names — was found by the reviewer, not by round 0, and is now closed
with its residual reported (§5.6, R-8). §5.5 adds the catcher census that was
missing.

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

## 5. Deliverable D — chokepoint failure-mode normalization

Brief §4 3(a) item 4 authorises this as *"its own recorded deliverable — not a
duplicate validator"*, when the census shows the chokepoint's exception posture
diverges from the house throw+alert pattern and an unbalanced post could be
**silently swallowed** in a **queued context**.

> **Round-1 scope correction.** Round 1 found this section's original target was the
> wrong one. The census had surveyed *creators* and never *catchers*, so it picked a
> synchronous HTTP path (`SalesOrderToInvoiceConverter`) whose swallow it then had to
> withdraw as non-occurring — while a genuinely swallowed unbalanced post existed in
> the listener context the brief actually names. §5.7 adds the missing **catcher
> census**; §5.2 records the withdrawal; §5.8 records the real fix.

### 5.1 The divergence

- **House pattern.** `UnbalancedJournalEntryException`
  (`app/Modules/Accounting/Domain/Exceptions/UnbalancedJournalEntryException.php`)
  already existed and is the type `AccountingService::assertLegsBalance()` throws
  (`:350`). Its own docblock states the contract: *"the auto-posting paths fail
  CLOSED on imbalance."*
- **The chokepoint diverged.** `sealAndPersistEntry` instead threw a **bare
  `\InvalidArgumentException`** (`GeneralLedgerService.php:3406-3409`) —
  indistinguishable from ordinary argument-validation noise, so no caller could
  single it out.

**The delivered change is exactly this and nothing more:** the chokepoint now raises
`UnbalancedJournalEntryException::forChokepoint(...)` with a **byte-identical
message**. The balance algorithm is untouched. No second validator exists anywhere.

### 5.2 WITHDRAWN — the `SalesOrderToInvoiceConverter` re-throw (round-1 finding 2)

Round 0 added `catch (UnbalancedJournalEntryException) { throw $e; }` to
`transferPrepayments()`. **It has been withdrawn — it was unreachable, and its test
did not discriminate for it.**

`transferPrepayments()` always runs inside `billingConcurrencyRetrier->run()`'s
transaction (`SalesOrderToInvoiceConverter.php:165` →
`DeliveryNoteBillingConcurrencyRetrier.php:40`), so `DB::transactionLevel() > 0`
always holds when `clearCustomerAdvanceToReceivable` reaches its post. That post goes
through `postEntryAndDispatchPostedEventAfterCommit`, which **defers via
`DB::afterCommit` whenever the level is > 0** and posts synchronously only at level 0
(`GeneralLedgerService.php:96-110`). The refusal is therefore always raised after the
`try` frame has returned.

**Proof of non-discrimination, run for this round:** with the new catch block deleted
from the source, its test still passed —

```
$ python3 -  # delete the `catch (UnbalancedJournalEntryException)` block (1010 bytes)
catch block removed, bytes: 1010
$ ./vendor/bin/phpunit tests/Feature/Document/DocumentConversionScenarioTest.php \
    --filter '^…::it_does_not_swallow_an_unbalanced_prepayment_gl_post$'
OK (1 test, 1 assertion)
```

**Disposition.** The catch is withdrawn; `SalesOrderToInvoiceConverter` now carries
**zero behavioural change** (only a comment recording the deferral, and the removal of
the now-unused import). The test was **kept and re-scoped**, because it does
discriminate on something real — the deferral itself. Renamed to
`it_pins_the_deferral_that_keeps_an_unbalanced_prepayment_post_loud`: if the enclosing
transaction is ever removed, the post becomes synchronous, the surviving graceful
`catch (\InvalidArgumentException|\RuntimeException)` swallows the refusal, and the
test goes red — which is precisely when the catch must be narrowed. Reported as R-7.

**Round-1 finding 5 (the comment) is resolved by deletion:** the withdrawn comment
claimed the re-throw prevented the advance and receivable "permanently diverging."
That was wrong — the post is deferred past commit, so conversion and invoice are
already committed when the refusal fires and the advance is unclaimed either way.
What a re-throw would have bought is **loudness, not divergence prevention**. The
replacement comment says exactly that.

### 5.3 Blast radius — CORRECTED, and the hierarchy change REVERTED (round-1 findings 3 + 4)

Round 0 also re-parented `UnbalancedJournalEntryException` from `\RuntimeException` to
`\InvalidArgumentException`, justified by the claim that *"no `catch (\RuntimeException)`
exists on any `createInvoiceGLEntries` / `createCreditNoteGLEntries` / `reverseDocumentGl`
caller."* **That claim was true only for DIRECT callers and false transitively, and the
re-parenting has been fully reverted.**

**The transitive path the original check missed:**

```
CreditNoteController::post()  (:374)
  → DocumentPostingService::post
    → DB::afterCommit( event(new InvoicePosted …) )        DocumentPostingService.php:507-518
      → EventServiceProvider.php:95 → InvoicePostedListener.php:30   (NOT ShouldQueue)
        → AccountingService::createCreditNoteGLEntries
          → assertLegsBalance → throw UnbalancedJournalEntryException   (:350)
… the afterCommit callback's exception propagates out of DB::transaction
   → CreditNoteController::post()'s  catch (\RuntimeException)  at :406
```

Under the re-parenting that `catch (\RuntimeException)` stopped matching, so an
unbalanced credit-note GL entry silently changed from
`500 {code: CONFIGURATION_ERROR, message: <detail>}` to the generic
`500 {code: INTERNAL_ERROR, message: <generic>}` envelope (`bootstrap/app.php:938-957`).
Not fail-open — still a 500 — but a **silent API-contract change on a fiscal refusal
path**, produced by a verification claim that was never tested transitively.

**Round-1 finding 4, the other direction:** the re-parenting also made the type a
`\LogicException`, exposing it to the ~30 `catch (\InvalidArgumentException)` blocks in
`app/` that render 400/422 `VALIDATION_ERROR` responses — and it invalidated two
explicit in-tree design contracts that say the opposite
(`AccountingService.php:304-306` and `UnpostableDocumentGlException.php:26-27`:
*"stays an unmapped `RuntimeException` (a 500 + alert), never a 422"*). The reviewer
found no currently-reachable downgrade, so the exposure was latent — but the guardrail
was gone while the files still claimed it.

**Decision: revert the parent to `\RuntimeException`.** This resolves findings 3 and 4
completely and is the *right* design independent of them — a post-seal balance refusal
must never be reportable as client validation error, which is exactly what those two
docblocks deliberately chose. The re-parenting was the round-0 mistake; adopting the
house type at the chokepoint never required a hierarchy change at all.

**Blast radius of the revert, verified exhaustively this round.** Only two places in
`app/` catch a type that matches the refusal *and* wrap a GL post:

| site | caught type | effect of the revert |
|---|---|---|
| `SalesOrderToInvoiceConverter.php:590` | `\InvalidArgumentException\|\RuntimeException` (union) | **none** — matches under either parent |
| `PostShiftCashVarianceAdjustment.php:184` | `UnbalancedJournalEntryException` (concrete) | **none** — matches the concrete type |

Everything else that catches `\InvalidArgumentException` in `app/` does not wrap a GL
post. Two **tests** pinned the old bare type and were updated (message assertions
preserved verbatim):
`GLIntegrationTest.php:471` (`catch` type) and `GeneralLedgerPostEntryScaleTest.php:75`
(`expectException`).

**Regression guard added** so this cannot recur:
`ChokepointUnbalancedGuardTest::test_the_house_unbalanced_exception_is_never_a_logic_exception`
asserts the type is a `\RuntimeException` and is **not** a `\LogicException`. Both
docblocks were updated to record that the parent is load-bearing, that the chokepoint
is now a second source of the type, and that M1 made and reverted this mistake.

### 5.4 Did the revert create any NEW swallow? (checked, no)

Making the refusal a `\RuntimeException` means `catch (\RuntimeException)` blocks now
match it where previously the bare `\InvalidArgumentException` did not. Every such site
was checked:

| site | caught type | is the post synchronous there? | verdict |
|---|---|---|---|
| `BatchWriteOffService.php:122` | `\RuntimeException` | **No — deferred.** `createInventoryWriteOffEntry`'s `$postSynchronously` defaults to **false** (`GeneralLedgerService.php:4765`) and this caller does not pass it, so the `…AfterCommit` branch (`:4886`) runs inside the caller's `DB::transaction` and defers past the catch | no new swallow; added as R-9 (same "protected by nesting" class) |
| `InventoryGlPostingService.php:85,126,206` | — | **Yes — `postSynchronously: true`** | **no catch blocks at all in that file** — the refusal propagates fail-closed. Safe |
| `CreditNoteController.php:406` | `\RuntimeException` | deferred (afterCommit), propagates out of `DB::transaction` | **restored** to its pre-M1 behaviour by the revert — this is finding 3's fix |

### 5.5 CATCHER CENSUS (round-1 finding 1 — the section the round-0 census lacked)

Every `catch` in `app/` whose type could intercept a balance refusal **and** which
lexically wraps a call reaching a GL post. Swept mechanically, then each row's
synchronous-vs-deferred status resolved by reading the post path.

| # | catch site | caught type | post is | what the catch does | disposition |
|---|---|---|---|---|---|
| 1 | `Treasury/Application/Listeners/PostShiftCashVarianceAdjustment.php:210` | `Throwable` | **SYNCHRONOUS** — `createRepositoryAdjustmentJournalEntry` posts via `postEntryNow` (`GeneralLedgerService.php:1307`), no deferral | `refuse(… 'exception' …, level: 'error')` | **GENUINE SWALLOW → FIXED**, see §5.8 |
| 2 | `Accounting/Listeners/PostGrIrOnGoodsReceipt.php:41` | `\Throwable` | deferred — `flushPendingGlPostings` runs inside `post()`'s `DB::transaction` (`GoodsReceiptService.php:393,403`) | `Log::error`, explicitly never re-throws | protected by nesting accident → **reported R-9** |
| 3 | `BatchExpiry/Domain/Services/BatchWriteOffService.php:122` | `\RuntimeException` | deferred (`$postSynchronously` false, §5.4) | `Log::warning`, never re-throws | protected by nesting accident → **reported R-9** |
| 4 | `Treasury/Application/Projections/TreasuryReceiptBridge.php:595` | `\Throwable` | synchronous (`postEntryNow` `:473,:547`) | **re-throws** (`:602`) — "the rethrow is the load-bearing part" (`:566`) | already fail-closed ✅ |
| 5 | `Document/…/SalesOrderToInvoiceConverter.php:590` | `\InvalidArgumentException\|\RuntimeException` | deferred (§5.2) | `Log::warning` + `gl_entry_skipped` | protected by nesting accident → **reported R-7** |
| 6 | `Document/Presentation/Controllers/CreditNoteController.php:406` | `\RuntimeException` | deferred, propagates out of `DB::transaction` | maps to `500 CONFIGURATION_ERROR` | intentional, loud, restored by the revert ✅ |
| 7 | `Inventory/…/InventoryGlPostingService.php` | *(none)* | synchronous | — | fail-closed by absence of any catch ✅ |

Narrowly-typed catches around GL posts that **cannot** match a balance refusal, checked
and excluded: `ToleranceExceededException` / `InvoiceAlreadyPaidException`
(`InvoiceController.php:1119,1131`), `QueryException`
(`RefundCompensationService.php:345`, `TreasuryMovementService.php:394`),
`ModelNotFoundException` (`ReceiptPaymentService.php:419`),
`AdjustmentToleranceAccountMissingException` / `AdjustmentAmountBelowCurrencyPrecisionException`
(`RepositoryAdjustmentController.php:71,79`),
`InsufficientRepositoryBalanceException` / `RepositoryFrozenException`
(`PostShiftCashVarianceAdjustment.php:160,171`).

> **Stated limitation of the sweep.** It is lexical: it matches a `catch` whose *try
> body within 60 lines* contains a post-reaching call. It therefore does **not** find
> catchers separated from the post by an event dispatch or an `afterCommit` hop — which
> is exactly how round-1 finding 3's `CreditNoteController:406` hides (row 6 above was
> added by hand from the reviewer's trace, not by the sweep). Any future re-run must
> keep tracing transitive/event-mediated paths by hand.

### 5.6 The real queued-context swallow — FIXED (round-1 finding 1)

`PostShiftCashVarianceAdjustment::handle()` calls `adjustmentService->post(...)` (`:310`)
→ `createRepositoryAdjustmentJournalEntry`, which posts via **`postEntryNow`**
(`GeneralLedgerService.php:1307`) — **no `afterCommit` deferral**. The chokepoint's
refusal is therefore raised *inside* the listener's `try` and was caught by
`catch (Throwable)` at `:210`, becoming `refuse($event, 'exception', …, level: 'error')`.

**Failure scenario (the reviewer's, confirmed):** a shift closes with a cash variance
whose adjustment entry is unbalanced → chokepoint refuses → the listener records a
generic refusal and returns → the Z-report/shift close reports success, no GL entry
exists, no retry, no dead-letter, and the fault is indistinguishable from any other
crash without string-matching a class name.

**Disposition — closed, with the residual reported.** The balance refusal now gets its
**own machine-readable reason at `error` level**, which is the protection this file
already established for its riskiest inputs (gate re-review N4 gave
`insufficient_repository_balance` and `repository_frozen` their own reasons for exactly
this complaint — that the generic `exception` bucket made them indistinguishable from a
crash).

**Re-throw was considered and deliberately rejected**, and this is the justification the
round-1 note asked for. The listener is `final readonly class` — **not `ShouldQueue`** —
registered as a plain synchronous listener (`TreasuryServiceProvider.php:185`). It runs
*after* the shift is closed and the Z report sealed. Throwing would therefore (a) surface
as a **500 on a close that genuinely succeeded**, breaking the file's documented
never-block invariant, and (b) still **not retry anything**, because there is no queue
worker behind it — so it would buy noise, not the retry/dead-letter the brief asks for.
Real retry/dead-letter requires making this listener queued, which is an architectural
change outside M1's scope. **That residual is reported as R-8**, so the gap is recorded
rather than papered over.

**Why this one was fixed and R-7/R-9 were only reported** — the single behavioural
standard applied throughout: **fix what is reachable and provable red-first; report what
is unreachable.** This path is reachable under a supported configuration
(`TREASURY_SHIFT_VARIANCE_GL_ENABLED`, `config/treasury.php:28`) and its guard is
demonstrable with a genuinely discriminating test. R-7 and R-9 are architecturally
unreachable today (always deferred), so any "guard" added there would be exactly the
unreachable dead code plus non-discriminating test that round 1 correctly rejected in
§5.2.

### 5.7 Red-first evidence

All tests were written and run **before** the corresponding production change. `git
stash` was **not** used at any point. `GeneralLedgerService` is `final` and was never
mocked — every imbalance is produced with real production machinery (an Eloquent
`created` hook adding a third leg to a still-unchained Draft, which
`JournalLineObserver` permits).

**RED — the real swallow (round-1 finding 1).** Run at the round-0 tip, before the
listener change:

```
$ ./vendor/bin/phpunit tests/Feature/Treasury/ShiftCashVarianceAdjustmentTest.php \
    --filter '^…::test_an_unbalanced_adjustment_entry_is_refused_under_its_own_reason$'

F                                                                   1 / 1 (100%)

1) …::test_an_unbalanced_adjustment_entry_is_refused_under_its_own_reason
Failed asserting that '{"exception":"App\\Modules\\Accounting\\Domain\\Exceptions\\UnbalancedJournalEntryException",
"message":"Cannot post unbalanced journal entry: total debit 11.000 does not equal total credit 5.000.",
"reason":"exception","shift_id":"b7352649-…","z_report_id":"746d1041-…","company_id":"0758adbc-…"}'
contains "unbalanced_journal_entry".

FAILURES!
Tests: 1, Assertions: 2, Failures: 1.
```

> That payload **is** the reviewer's failure scenario, captured verbatim: an unbalanced
> chokepoint refusal recorded under `"reason":"exception"` — the generic crash bucket.

**GREEN — after the listener change:** `OK (1 test, 5 assertions)`.

**RED — the chokepoint type normalization (round 0, still valid).** Run at base:

```
$ ./vendor/bin/phpunit tests/Feature/Accounting/ChokepointUnbalancedGuardTest.php \
    --filter '^…::test_chokepoint_raises_the_house_unbalanced_exception_type$'

1) …::test_chokepoint_raises_the_house_unbalanced_exception_type
Failed asserting that exception of type "InvalidArgumentException" matches expected exception
"App\Modules\Accounting\Domain\Exceptions\UnbalancedJournalEntryException".
Message was: "Cannot post unbalanced journal entry: total debit 100.000 does not equal total credit 90.000." at
  .../GeneralLedgerService.php:3406      ← the chokepoint's bare throw
  .../GeneralLedgerService.php:2781/2774 ← postEntry

FAILURES!
Tests: 1, Assertions: 1, Failures: 1.
```

**GREEN — after:** `OK (2 tests, 4 assertions)`.

**Regression — every test touching this exception path, all green after the revert:**

| test path | result |
|---|---|
| `tests/Feature/Accounting/ChokepointUnbalancedGuardTest.php` | `OK (2 tests, 4 assertions)` |
| `tests/Feature/Accounting/GeneralLedgerPostEntryScaleTest.php` (type updated, message preserved) | `OK (2 tests, 3 assertions)` |
| `tests/Feature/Accounting/GLIntegrationTest.php` (type updated, message preserved) | `OK (29 tests, 120 assertions)` |
| `tests/Feature/Accounting/InvoiceGLIntegrationTest.php` | `OK (15 tests, 68 assertions)` |
| `tests/Feature/Accounting/CreditNoteGLIntegrationTest.php` | `OK (13 tests, 84 assertions)` |
| `tests/Unit/Accounting/DoubleEntryValidationTest.php` | `OK (9 tests, 10 assertions)` |
| `tests/Feature/Document/DocumentConversionScenarioTest.php` (incl. the preserved graceful `gl_entry_skipped` case) | `OK (14 tests, 36 assertions)` |
| `tests/Feature/Treasury/ShiftCashVarianceAdjustmentTest.php` | `OK (23 tests, 98 assertions)` |
| `tests/Feature/Treasury/ShiftCashVarianceOfflineDevicePayloadTest.php` | `OK (3 tests, 19 assertions)` |
| `tests/Feature/Treasury/ShiftCashVarianceTriggerPathsTest.php` | `OK (5 tests, 23 assertions)` |

### 5.8 Acceptance commands

Per gate-r2 R2-H-7 the selected-test count is asserted **nonzero** from the PHPUnit
summary line (`apps/api/phpunit.xml` sets no `failOnEmptyTestSuite`, so an empty
selection exits 0 and proves nothing).

```bash
cd apps/api
./vendor/bin/phpunit tests/Feature/Treasury/ShiftCashVarianceAdjustmentTest.php \
  --filter '^Tests\\Feature\\Treasury\\ShiftCashVarianceAdjustmentTest::test_an_unbalanced_adjustment_entry_is_refused_under_its_own_reason$'
# → OK (1 test, 5 assertions)                              N = 1  ≥ 1  ✅

./vendor/bin/phpunit tests/Feature/Accounting/ChokepointUnbalancedGuardTest.php \
  --filter '^Tests\\Feature\\Accounting\\ChokepointUnbalancedGuardTest::test_chokepoint_raises_the_house_unbalanced_exception_type$'
# → OK (1 test, 2 assertions)                              N = 1  ≥ 1  ✅

./vendor/bin/phpunit tests/Feature/Accounting/ChokepointUnbalancedGuardTest.php \
  --filter '^Tests\\Feature\\Accounting\\ChokepointUnbalancedGuardTest::test_the_house_unbalanced_exception_is_never_a_logic_exception$'
# → OK (1 test, 2 assertions)                              N = 1  ≥ 1  ✅

./vendor/bin/phpunit tests/Feature/Document/DocumentConversionScenarioTest.php \
  --filter '^Tests\\Feature\\Document\\DocumentConversionScenarioTest::it_pins_the_deferral_that_keeps_an_unbalanced_prepayment_post_loud$'
# → OK (1 test, 1 assertion)                               N = 1  ≥ 1  ✅
```

**No acceptance command is submitted for any class-(c) census row**, because there are
none of that kind (§0, §4) — per the milestone contract, a module with zero class-(c)
rows submits census evidence only and **no placeholder green**.

### 5.9 Static analysis + style (touched files)

```bash
$ ./vendor/bin/pint --test <touched files>
{"result":"pass"}

$ ./vendor/bin/phpstan analyse --memory-limit=4G --no-progress <touched app/ files>
 [OK] No errors
```

PHPStan ran at level 8 against a live PG env (`phpstan.neon` `paths:` covers `app/`
only, so touched test files are outside its scope by configuration).

---

## 6. Reported findings (out of 3(a) balance scope — handed to the parent)

| id | finding | evidence | why not fixed here |
|---|---|---|---|
| **R-1** | **Live `journal_entries` DELETE.** `RepositoryTransferService.php:105` performs an unconditional `$draft->delete()` on a `journal_entries` row. It is safe *today* only because the row is an unchained Draft inside the caller's own transaction and the replay path rolls back to a savepoint first. Two residual weaknesses: (i) `JournalEntryObserver::deleting()`'s `isChained()` guard reads the **stale in-memory** `$draft`, not the instance `postEntryNow` mutated, so the guard is not load-bearing; (ii) there is **no DB-level append-only trigger** on `journal_entries`. | `RepositoryTransferService.php:103-106`; `TreasuryMovementService.php:296-298,341-348,394-401`; `JournalEntryObserver.php:43-48`; `JournalEntry.php:127-130` | Append-only violation, **not a balance-guard gap** — the deleted row is a Draft and never passed the chokepoint. Fixing the DELETE is outside 3(a). Recorded as instructed by the parent ledger lead. |
| **R-2** | **Orphan-draft surface.** Drafts reachable from production that are **never posted**: `treasury_transfer` (`GLS:1413`, unconditional), `pos_account_charge` (`GLS:3999`, unconditional — the bridge *asserts* Draft at `TreasuryAccountChargeBridge.php:189`), `payment_tolerance` (`GLS:1583` when `PaymentAllocationService.php:228` supplies a non-`User` actor), `prepayment_application` (`GLS:1711` when `SalesOrderToInvoiceConverter.php:152-153` has no `actor_user_id`), `manual` (until an operator hits the post endpoint). | as cited | Unposted drafts never seal, so they are not a balance-guard gap. But an AR charge that never posts means revenue/AR is never recognised — a real fiscal-completeness issue for the parent. |
| **R-3** | **Dead GL creators.** No production call site: `createFromInvoice` (`:146`), `createFromCreditNote` (`:230`), `createPaymentEntry` (`:353`), and both `UninvoicedDeliveryNoteService` creators (`:530`, `:591`). The last is independently corroborated by `ProvisioningRequiredPurposesV1.php:78`. Note `createPaymentEntry` is P1 baseline index 1 and `UninvoicedDeliveryNoteService` is index 10 — i.e. **two of P1's four `journal_entries` violation rows sit on dead code.** | as cited | Dead-code removal is out of scope; but it materially changes how P1's violation partition should be read. |
| **R-4** | **Knowingly-sealed unbalanced entry.** `AccountingService::reverseDocumentGl` deliberately skips the balance assertion for **lineless** documents, sealing a one-legged (unbalanced) entry — accepted in-code as "the lesser evil" so a lineless document remains cancellable. | `AccountingService.php:860-881` (GL gate finding M-1) | A pre-existing, documented, deliberate acceptance from an earlier gate. Re-litigating it is not 3(a)'s call. |
| **R-5** | **Rule-19 boundary drift (cosmetic, no reachable defect).** `AccountingOpeningService::postBatch` writes line amounts without `CurrencyScale::bcformatStrict`, relying on upstream `validateRow` normalisation. Safe today, but the invariant lives in a different class from the write. | `AccountingOpeningService.php:299-307` vs `:190,:196` | No demonstrable failing behaviour (§4.1) — implementing it would be scope creep and would ship a green-at-base test. |
| **R-7** | **`SalesOrderToInvoiceConverter.php:590` — balance refusal protected only by transaction nesting.** Its `catch (\InvalidArgumentException\|\RuntimeException)` would swallow a chokepoint balance refusal into a `Log::warning` + `gl_entry_skipped`, and does not today only because `transferPrepayments()` always runs inside the billing retrier's transaction, so the post defers past the frame. If that transaction is ever removed the swallow becomes live. | `SalesOrderToInvoiceConverter.php:165,590`; `DeliveryNoteBillingConcurrencyRetrier.php:40`; `GeneralLedgerService.php:96-110` | Architecturally unreachable today — a guard here would be unreachable dead code with a non-discriminating test, which is exactly what round 1 rejected (§5.2). Pinned instead by `it_pins_the_deferral_that_keeps_an_unbalanced_prepayment_post_loud`, which goes red if the nesting changes. |
| **R-8** | **Shift-variance GL refusals have no retry or dead-letter (residual of the §5.6 fix).** `PostShiftCashVarianceAdjustment` is a plain synchronous listener (`final readonly`, NOT `ShouldQueue`, registered at `TreasuryServiceProvider.php:185`). A balance refusal is now recorded loudly under its own queryable reason at `error` level, but it is still not retried and never reaches a dead-letter queue — the brief's "retryably/dead-letter" discipline cannot be satisfied without making this listener queued. | `PostShiftCashVarianceAdjustment.php:184,210`; `TreasuryServiceProvider.php:185` | Making the listener queued is an architectural change (queue registration per rule 20, replay/idempotency semantics for an already-sealed Z report) well outside 3(a). Recorded so the gap is visible rather than implied closed. |
| **R-9** | **Two more "protected by transaction-nesting accident" catchers, same class as R-7.** (i) `PostGrIrOnGoodsReceipt.php:41` `catch (\Throwable)` → `Log::error`, explicitly never re-throws, around `createGoodsReceiptGrIrEntry`; safe only because `flushPendingGlPostings` runs inside `post()`'s transaction. Note the fail-closed twin at `GoodsReceiptService.php:409` calls the GL service directly, outside the listener. (ii) `BatchWriteOffService.php:122` `catch (\RuntimeException)` → `Log::warning`, around `createInventoryWriteOffEntry`; safe only because `$postSynchronously` defaults to false (`GeneralLedgerService.php:4765`) so the `…AfterCommit` branch defers. | as cited; `GoodsReceiptService.php:393,403,409` | Same reasoning as R-7 — unreachable today, so no guard and no test. Both are one refactor away from becoming live swallows; listed so the parent can decide whether the nesting invariants deserve their own pins. |
| **R-6** | **Census blind spot for the P1 scanner.** `JournalEntry::query()->create` is invisible to a `JournalEntry::create` grep and hides **6** creators. `DocumentPerActionWriteScanner` should be re-checked for the same pattern, and P1's 116-site census re-derived if it shares the blind spot. | §1(i); `GeneralLedgerService.php:1084,2884,2993,3079,3134,3201` | P1 is landed and closed; changing its baseline is the parent's call, not P3's. |

---

## 7. Deviations from the brief

| id | deviation |
|---|---|
| **M1-D1** | **Line numbers in the brief have drifted** from the landed base. Actual: `sealAndPersistEntry` `:3378` (balance check `:3396-3410`), not `:3480-3510`; `postEntry` `:2772`, not `:2874`; `postEntryNow` `:3348`, not `:3450`; `createPOSChargeEntry` `:3999`, not `:4072`. Every cited fact was verified at the **actual** location. |
| **M1-D2** | **The P1 baseline was insufficient as a census seed** — it holds 4 `journal_entries` keys (a violation set), not a creator inventory. The census was rebuilt independently and its completeness proven (§1). |
| **M1-D3** | **Zero class-(c) guards added, zero class-(c) red-first tests.** This is the *correct* outcome under the C-2 ruling, not an omission: every structural bypass is green-at-base and therefore disqualified. Two leads were pursued to proof and killed (§4). The milestone's implementation content is deliverable D. |
| **M1-D4** | **Worktree had no `vendor/`.** `composer install` was run in the worktree (a symlink to the main repo's `vendor` would autoload **stale main-repo** `App\` classes and invalidate every test result). Autoloader confirmed worktree-local. |
| **M1-D7** | **Round-1 self-corrections (fix_rounds 1).** Four round-0 positions did not survive review and were changed rather than defended: (i) the census covered creators but not **catchers**, so it targeted the wrong site — §5.5 adds the catcher census; (ii) the `SalesOrderToInvoiceConverter` re-throw was **withdrawn** as unreachable, proven by deleting it and watching its test stay green (§5.2) — that file now carries zero behavioural change; (iii) the `UnbalancedJournalEntryException` **re-parenting was reverted** — it silently turned a `500 CONFIGURATION_ERROR` into a generic `500 INTERNAL_ERROR` on `CreditNoteController::post` and invalidated two docblocks that deliberately specify "never a 422" (§5.3), and a regression test now pins the parent; (iv) the genuinely swallowed unbalanced post at `PostShiftCashVarianceAdjustment:210` was **found by the reviewer, not by round 0**, and is now fixed red-first with its no-retry residual reported as R-8. The behavioural standard applied to every catcher — *fix what is reachable and provable red-first, report what is unreachable* — is stated at §5.6 and is what justifies fixing finding 1 while only reporting R-7 and R-9. |
| **M1-D6** | **A claim in this document was falsified by its own red run and corrected, not quietly dropped.** The first reading of `SalesOrderToInvoiceConverter:589` asserted that an unbalanced GL post *is* silently swallowed there today. The red baseline trace showed the throw travelling through `DatabaseTransactionRecord` — i.e. deferred past the `try` by `DB::afterCommit` — so the escape is real but incidental. §5.2 now states the weaker, provable claim and explicitly withdraws the stronger one. The fix stands on the corrected justification (an untyped refusal whose only protection is transaction-nesting timing), not on the withdrawn one. |
| **M1-D5** | **PG verification instance.** The `numeric` rounding fact in §4.1 was verified against the **port 5432 Homebrew PostgreSQL 15** instance (`TimeZone = Africa/Tunis`), using a dedicated scratch database `p3m1_test`. `autoerp_test` was **not** touched. |
