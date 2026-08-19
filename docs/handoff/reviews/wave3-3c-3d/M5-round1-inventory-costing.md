## M5 adversarial whole-branch merge-gate review — round 1 — inventory-costing lens

**Milestone:** M5 (FINAL). **M5 delta reviewed:** `d425434cd..1db4bafa9` — `cf9994b11` (ruling), the
three adopted cherry-picks `3c2791724` / `cdbefd40f` / `75a06d987`, `dfeb7bea8` (T21 + T22),
`1db4bafa9` (evidence). **Whole-branch re-check:** `48cebf0f2..HEAD` (71 files), spot-verified for
this lens's M4-accepted claims rather than re-reviewed.
**Amending authority applied:** `ORCHESTRATOR-RULING-2026-08-19-m5-oq12-gate.md` (flag-off shipping +
the adoption of the three preserved commits), `TREASURY-RULING-2026-08-19-t20-option-a.md` (Option A
`6586`/`7586` — not relitigated), `ORCHESTRATOR-RULING-2026-08-19-m4-stop-b.md` (F-1, F-8, S-16).
**Base register:** `M4-round6.md` (ACCEPT; findings 1/2/5 carried into this gate).
**Working tree:** clean before and after. Two temporary probe classes were created, run and DELETED;
I staged and committed nothing but this file. Tip at commit time: `454ae733d` (treasury register).

**Lens applicability.** Applies in full: the count-correction cost basis, the WAC snapshot question,
the movement-driven COGS-at-stock-exit seam, the D-a/D-b/D-e detector populations, lot/FEFO on the
counting lane, and the lock order around `stock_levels` / `ProductCostLock`.

---

## M4-round6 carried findings — verified at this tip

- **#1 (P3, pre-policy template hard-aborts registration) — CLOSED** by the adopted commits.
  `InventoryVarianceAccountProvisioner.php:66-77` now grafts REQUIRED shrinkage via
  `fallbackTemplateParent()` (`:249-260`) instead of throwing. See finding 8 for the residual.
- **#2 (missing-parent diagnostic names a code that may be present) — STILL OPEN.** The message at
  `:127-132` still reports `parent_code` without naming the required `type`. Now unreachable on the
  template path (the fallback intercepts), so it survives only on legacy/backfill.
- **#3 (legacy/backfill installer resolves parent by code only) — STILL OPEN**, unchanged at `:125`.
- **#5 (seven BatchExpiry fixtures label the shrinkage account "Cost of Goods Sold" at `601`) —
  STILL OPEN**, verified untouched at `BatchWriteOffCostPersistenceTest.php:156-164`.
- **#6 (`blockers: []` while two promotion blockers were open) — CLOSED.**
  `wave3-3c-3d.progress.yaml:182-186` now carries three blockers, including the flag-flip gate.

## Whole-branch spot checks (48cebf0f2..HEAD) — all HOLD at the tip

- **F-1 / F-8.** `git diff --name-only 48cebf0f2..HEAD` (71 files) intersects **no** seeder and **no**
  `.github/workflows/**`.
- **Counter-family partition.** `MovementReason.php:73-94` — 17 of 17 cases, **no `default` arm**,
  `CountCorrection => DirectionalVariance`, `Damage`/`Expiry`/`WriteOff => Shrinkage` (off COGS).
- **Deptrac.** I ran it at the tip: **174 violations, 12971 uncovered, 13258 allowed** — byte-equal to
  M4's measured 174 at both base and M4 tip, so **M5 adds zero edges**. Confirmed structurally too:
  `git diff d425434cd..1db4bafa9 -- StockAdjustmentService.php | grep '^[+-]use'` is **empty** — the
  Domain service gained no import; the sink is a bare `?\Closure` (`:1272`) and the
  Application-layer listener owns the buffer.
- **T22 ticket citations** verified line by line: `:729` (`deltaBasedDefaultLot: false`), `:859`
  (`true`), `:886` (docblock), `:905`, `:921`. All correct.

---

## Register

### 1 — P2 · CONFIRMED (reproduced) · the flag-tied D-e exclusion, once lifted, reports every ZERO-VARIANCE counted line — the population it exists to surface is not the one it will report

`CheckCogsCoverageCommand.php:237-265` (the `when()` exclusion), `StockAdjustmentService.php:1352-1396`
(`postCountCorrection` has **no zero-delta guard**), `InventoryGlPostingService.php:42-45`
(`direction === 'flat'` → `return null`), `M5-evidence.md:133-142` (the claim this contradicts)

`applyCountResult` calls `postCountCorrection()` unconditionally whenever `$postOpening` is false
(`:1319-1323`) — including when `$adjustment` is exactly `0`. The movement is written with
`quantity_before == quantity_after`, so `directionForRow()` is `flat` and `postForCountCorrection()`
correctly returns `null`: there is nothing to post. But D-e's predicate is
`reason ∈ nonCogsGlReasons AND NOT EXISTS(entry)`, with **no** flat/zero-delta exclusion — so the
moment `inventory.count_correction_gl_posting_enabled` flips, every matched count line becomes a
finding.

**Reproduced**, on PostgreSQL, with a purpose-built probe (a one-item counting whose `final_qty`
equals its on-hand, flag ON, full Option A chart present):

```
PROBE1 movements=1 qty_before=10.0000 qty_after=10.0000 unit_cost=4.256667
PROBE1 entries=0
PROBE1 D-e output:
  D-e Inventory movement requiring GL has no entry. document=COUNT_REPLAY
  1 lane-separation finding(s) reported. See the application log.
```

**Failure scenario.** A 900-line full-location count where 870 lines match produces 870 D-e findings
on the first scheduled run after the flag flip, drowning the ~30 real ones. §1.7 of the evidence
asserts the exclusion "reports exactly the failed or declined count-correction postings it exists to
surface" — it does not; a `flat` decline is a by-design non-event, not a failure. This is the closing
mechanism for ticket `2026-08-18-remove-counting-detector-exclusion-with-t21`, so the ticket's intent
is not in fact preserved. The covering test
(`CheckCogsCoverageCommandTest.php:352-386`) uses a non-zero movement only and therefore cannot see
this.

Nothing is wrong at rest and nothing posts today (flag OFF), which is why this is P2 and not P1 —
but the flag flip is the entire point of the milestone, and the detector is unusable on day one.
**Free to close:** add `->whereColumn('quantity_before', '<>', 'quantity_after')` (or exclude
`quantity = 0`) to the D-e query, with a red-first zero-variance case.

### 2 — P2 · CONFIRMED (disproved by experiment) · the evidence and the filed ticket mis-diagnose an executor-environment artifact as "three genuine PG-only divergences" in the counting replay window

`M5-evidence.md:323-336` (§6.1), `docs/superpowers/tickets/2026-08-19-replay-finalize-test-not-pg-runnable.md`
(Finding 2 and its measured table), `config/database.php:87-98` (the actual root cause)

**Finding 1 of that ticket is correct and I verified it**: `ReplayFinalizeTest.php:157` builds
`'CNT-RPL-'.uniqid()` = **21 characters** against
`inventory_countings.counting_number varchar(20)` (`2026_03_04_100000_add_tenant_id_and_counting_number_to_inventory_countings.php:15`),
so the file has never run on PostgreSQL.

**Finding 2 is wrong.** I copied the file, shortened the fixture to `'R'.uniqid()` and ran it on PG.
I reproduced the executor's numbers exactly:

```
1) test_case_b_pre_count_sale_excluded            expected '17.0000'  got '12.0000'
2) test_case_c_basket_window_flags_and_skips      expected '10.0000'  got '19.0000'
3) test_finalize_stamps_final_qty_as_of…          expected …+00:00    got …+01:00
Tests: 11, Assertions: 33, Failures: 3
```

Then I re-ran the **identical** file against the **identical** database with only the PostgreSQL
session timezone forced:

```
PGTZ=UTC TZ=UTC … phpunit -c phpunit-pgsql.xml tests/Feature/Inventory/ZzReplayPgProbeTest.php
->  OK (11 tests, 36 assertions)
```

All three pass. My machine is `CET+0100` and the local PG session inherits it
(`SHOW timezone` → `now()` renders `+01`); `config/database.php`'s `pgsql` block pins `charset` and
`search_path` but **no `timezone`**, so a datetime bound without an offset is interpreted in the
session zone — shifting `final_qty_as_of` one hour earlier, which (b) admits the pre-T sale into the
replay window and (c) pushes the t+5min movement outside the 15-minute basket window. Both observed
deltas match that shift arithmetically (b: 20 − 5 − 3 = 12; c: 20 − 1 = 19).

**Why it matters for this lens:** the ticket instructs a future lane to "root-cause the (b) and (c)
divergences in the replay window" and warns "do not 'fix' the test to match PG if PG is the one that
is wrong" — sending it after a product defect in `MovementReplayService` that does not exist, while
the real, cheap fix (pin the connection timezone; compare instants, not rendered offsets) goes
unrecorded. It also means the wave's stated reason for excluding its own primary replay regression
file from the PG lane is not the true one: the file is excluded for the 21-character fixture alone,
and once that is fixed the file is green on PG.

**Free to close:** correct §6.1 and the ticket's Finding 2 to record the measured PGTZ root cause and
the 11/11 green under `PGTZ=UTC`; add `'timezone' => 'UTC'` to the `pgsql`/`tenant` connections as a
separate lane.

### 3 — P3 · CONFIRMED · the root-frame comment asserts a lock release that `pg_advisory_xact_lock` does not perform

`ApplyStockAdjustmentsOnCountingCompleted.php:144-147` vs `ProductCostLock.php:41-49`

The comment reads *"the company advisory taken after the last item released its product-grain
locks."* `ProductCostLock` takes `pg_advisory_xact_lock` (`:47`), which releases at the **outermost**
commit. Under M5's root frame that is `handle()`'s `DB::transaction` (`:91`), so at flush time
(`:147`) **every** product advisory and **every** `stock_levels` row lock taken for items 1..N is
still held, and the per-company GL advisory (`GeneralLedgerService.php:5026`, `:3420`) is taken on
top of them.

**The invariant nevertheless HOLDS**, which is why this is P3: the resulting order is
product-grain → company advisory, i.e. the advisory is terminal — exactly the house-canonical
normalisation `GoodsReceiptService.php:735-753` documents (*"Every row lock this writer takes … is
already held; only now is the per-company GL advisory acquired"*). The residual is real but bounded:
a 900-line counting now holds 900 product advisories plus 900 row locks plus the company GL advisory
for the whole job, where pre-M5 each released at its per-item commit. The comment should say
"acquired last", not "after the locks were released" — a future maintainer reading it could conclude
the frame may safely be widened further.

### 4 — P3 · CONFIRMED · the class's central "one basis" oracle TRUNCATES where production rounds HALF-UP, so it never exercises the rounding it claims to pin — and would reject correct behaviour if it did

`CountCorrectionGlPostingTest.php:568-578` vs `InventoryGlPostingService.php:207-214` /
`CurrencyScale.php:172-197`

`assertEntryAmountEqualsRowCostTimesAbsoluteDelta()` computes
`bcadd(bcmul($unitCost, $delta, 9), '0', 3)` — `bcadd` **truncates**. Production computes
`CurrencyScale::bcround(bcmul(...), $scale)` — round-half-away-from-zero. Proven divergent with a
probe (`cost_price = 1.000500`, delta 1, TND scale 3):

```
production posts   debit = 1.001
the helper expects        = 1.000     (php -r 'echo bcadd(bcmul("1.000500","1.0000",9),"0",3);')
```

The three fixtures in the class (`4.25 × 4`, `4.25 × 2`, `4.25 × 3`) all land exactly on 3 decimals,
so the divergence is latent — but WAC `cost_price` is `decimal(19,6)` and a real running average
crosses a rounding boundary routinely, so the **one boundary rounding of the whole T21 feature is
untested**, and the assertion that is supposed to prove it is the wrong oracle. The literal `3` is
also a hardcoded currency scale without the `precision-ok` annotation the same commit applies at
`StockAdjustByDeltaTest.php:210,706`.

### 5 — P3 · CONFIRMED · the shrinkage/gain entry is dated at POST time, not at the count's completion

`ApplyStockAdjustmentsOnCountingCompleted.php:382` (`entryDate: new \DateTimeImmutable('now')`)

The listener is `ShouldQueue` with `$tries = 3` / `$backoff = 10`, and finding 3's enlarged frame
makes a whole-job retry the normal recovery path. A worker backlog, a retry, or a redelivery dates
the entry at drain time. A count finalized 31 December and drained 1 January books its shrinkage in
the **next fiscal year**, and — because `postEntryNow` → `isDateInClosedPeriod`
(`GeneralLedgerService.php:3429`) is evaluated on that date — a count drained into a closed period
throws `ClosedFiscalPeriodException` out of an **uncontained** flush (`flushIfOutermost()` at `:147`
passes `contained: false`), rolling back the entire counting's stock correction. Precedent is mixed:
`DeliveryNoteService.php:311` also uses `now()`, but the queued/projection analogues
(`ReceiptReturnService.php:467,496`, `PosCoreReceiptProjection.php:1849`) carry the event date, which
is the right precedent for a queued listener. `$counting->completed_at` / the movement's
`occurred_at` are both available at the call site.

### 6 — P3 · CONFIRMED · `currencyCode` is resolved from the COUNTING's company while `companyId` on the context comes from the MOVEMENT's, with no assertion tying them

`ApplyStockAdjustmentsOnCountingCompleted.php:92` + `:399-402` (currency from `$counting->company_id`)
vs `:373` (`companyId: $movement->company_id`), `StockAdjustmentService.php:1675-1678`
(`resolveCompanyId` is an **unscoped** `Location::findOrFail`)

The replay path passes no `expectedCompanyId` (unlike the legacy path, `:237`), so a counting item
pointing at another company's location yields a movement — and a journal entry — for company B scaled
at company A's currency. **Not currently constructible**, which is why this is P3 and not P2: counting
scope locations are company-validated at creation (`InventoryCountingService.php:319`) and
`resolveTenantId($productId, $companyId)` (`:1690-1696`) re-scopes the product to the location's
company, so a genuine cross-company item throws before reaching the sink. Nothing asserts it, though,
and the cheap fix is to resolve the currency from `$movement->company_id`.

### 7 — P3 · CONFIRMED · `postForCountCorrection` hard-throws for a periodic-valuation company, uncontained, where the POS arm deliberately warns-and-skips

`InventoryGlPostingService.php:47` (`requirePerpetual`) vs `:143-153` (the POS softening),
`InventoryValuationModeResolver.php:76-86`

With the flag live, a count correction in a periodic company raises
`UnsupportedValuationModeException` inside the uncontained flush, rolling back the whole counting's
stock corrections and burning all three attempts — for a physical count, which every valuation mode
performs. Unreachable today (periodic is refused at the settings request and by this resolver, and
cannot be set by accident), so it is a latent asymmetry, not a live defect. Recorded because the
enabling lane is explicitly "later, without DDL".

### 8 — P3 · CONFIRMED · `fallbackTemplateParent` constrains TYPE but not activity or node kind; the legacy/backfill strict path IS byte-identical

`InventoryVarianceAccountProvisioner.php:249-260`, `:123-134`, `:158-178`

Verified as claimed on both halves. **No wrong-family graft is possible**: the probe filters
`->where('type', $definition['type'])` and `->whereNull('parent_id')`, so an expense purpose can only
land under an expense root, never under a revenue or asset one, and never under a leaf. **The legacy
and backfill paths are byte-identical**: `definitions()` (`:158-178`) always yields a non-null
`parent_code` for both purposes, so the new `if ($definition['parent_code'] !== null)` wrapper is
always true there and the same `RuntimeException` with the same message is thrown. The residual is
narrow: the fallback root is not checked for `is_active` (unlike `assertUsable`, `:181-195`, which is
applied only to the account being promoted), and a chart whose lowest same-type expense root is
`9000` gets `6586` nested under `9000` — legal by type, incoherent by code tree. The checklist already
requires reconciling a fallback-grafted account through a reviewed template correction
(`dpa-inventory-shrinkage-deploy-checklist.md:47-49`).

### 9 — P3 · CONFIRMED · the operator-facing deploy checklist never names the flag it must gate

`docs/handoff/dpa-inventory-shrinkage-deploy-checklist.md:62-63`

The only trace is one prose line — *"Expert-comptable ratification under OQ-12/H-5 is still required
before M5 makes count-correction posting live."* The env var
`INVENTORY_COUNT_CORRECTION_GL_POSTING_ENABLED` appears nowhere in the checklist; nor does "ships
FALSE", nor any pre-flip verification (both purposes mapped on every active company; finding 1's D-e
consequence). The binding text lives in `wave3-3c-3d.progress.yaml:184` and `M5-evidence.md:122-132`
— neither of which is the artifact a deployer reads.

### 10 — P3 · CARRIED BY RULING · T22's own P2 (DEFAULT-lot inflation) ships OPEN on the counting lane, including the replay path T21 now attaches a GL leg to

`StockAdjustmentService.php:1398-1400` and `:981-982` (target-based
`ensureDefaultBatchForImplicitPositiveStock`), `:886-899` (the recorded contradiction),
`docs/superpowers/tickets/2026-08-19-counting-lane-c2-default-lot-inflation.md`

A positive count correction on a batch-tracked product that already holds real lots tops the DEFAULT
lot up to the whole post-update aggregate, leaving `Σ lots > aggregate` and a FEFO surface that can
over-issue. The plan ruling is **RE-TICKET, do not absorb**, and the ticket is correctly filed with
verified citations, so I do not relitigate it. Recorded here because the M5 GL leg now values that
lane's deltas: T21's own direction/magnitude are aggregate-derived (`quantity_before`/`quantity_after`)
and therefore correct under either lot strategy, but the lot ledger underneath them is not.

---

## Standing checks

- **Rule 19 (no float on money/quantity).** Clean. `git diff d425434cd..1db4bafa9 -- 'apps/api/**' |
  grep '^+' | grep -E '\(float\)|floatval|number_format|round\('` returns **nothing**. The new
  production arithmetic is `bcadd`/`bcmul` at `self::COST_SCALE` (`StockAdjustmentService.php:1804-1805`);
  `resolveRowUnitCost()` (`:1717-1726`) performs no arithmetic at all. The GL amount is
  `bcmul(..., $scale + 6)` rounded once by `CurrencyScale::bcround` (`InventoryGlPostingService.php:207-214`).
  Only test-side bcmath literals appear, two of them correctly annotated `precision-ok`; the third is
  finding 4.
- **Rule 19/20 (explicit currency on queue-reachable costing).** Verified end to end for the new
  queued path. `resolveCurrencyCode()` (`:399-402`) reads the company ISO code once per job and it is
  carried on `MovementGlContext::$currencyCode`; every downstream scale resolution is explicit —
  `InventoryGlPostingService.php:64,109,172,214` and `GeneralLedgerService.php:4560` all use
  `getScale($ctx->currencyCode)`. `GeneralLedgerService`'s bare `scale()` (`:61-64`) exists but I
  traced its ten call sites (`:183,849,1690,1698,1700,2615,2616,4773`) — **none** is on the
  count-correction path; `createInventoryMovementEntry` never touches it. `resolveMovementUnitCost()`
  is resolver-independent by construction (`Product.php:318-325`, a `decimal(19,6)` column read).
- **Constructor injection.** `private readonly` throughout — the listener's five dependencies
  (`:51-57`), `InventoryVarianceAccountProvisioner:22-25`. **No `app()` in the production delta**
  (`git diff … -- 'apps/api/app/**' | grep '^+' | grep 'app('` → empty); the `config()` helper at
  `:364` and `CheckCogsCoverageCommand.php:257` follows established precedent (50 files under
  `app/Modules` use it).
- **Tenant/company scoping.** `resolveRowUnitCost` scopes the `Product` lookup to the company the
  locked `StockLevel` already validated (`:1719-1723`) — a forged `productId` cannot value a movement
  off another company's cost. Every detector query is `company_id`-scoped
  (`CheckCogsCoverageCommand.php:199,219,238`); the fallback parent probe is `company_id`-scoped
  (`:252`). Finding 6 is the one asymmetry.
- **Event immutability.** No event class renamed, restructured or deleted. `StockMovementRecorded` /
  `…V2` keep their shapes; only the *values* of `unitCost`/`totalCost` change from `'0.00'` to a real
  cost on the adjustment path (`StockAdjustmentService.php:1019-1020,1037-1038`) — see bypass 2.
- **Movement sign vs reason.** Every new movement is written through `recordMovement` with an explicit
  `MovementReason` enum; no hand-rolled sign. `postCountCorrection` hard-codes
  `MovementReason::CountCorrection` (`:1383`), and the direction is derived from the persisted row by
  `directionForRow()`, not asserted by the caller.
- **Named queues / Horizon.** None added. N/A.
- **en + fr.** No new user-facing strings; the flag, the warning tokens and the `LogicException`
  (`:375`) are operator/developer diagnostics.
- **Flag-off shipping — verified on all three surfaces.** `config/inventory.php:29` is
  `env(..., false)`. (a) Listener: `:364` returns before `enqueue()`, so the buffer stays empty and
  `flushIfOutermost()` (`:147`) iterates zero contexts. (b) Replay path: identical — the sink is the
  same helper (`:316-318`), and `postCountOpening()` has no sink at all, so an onboarding first count
  posts nothing regardless. (c) Detector: `CheckCogsCoverageCommand.php:256-261` reinstates the
  `inventory_counting` exclusion while dormant. I confirmed silence-while-dormant and
  bite-when-live empirically — the bite is finding 1.

## Verification I ran myself

| Command / probe | Result |
|---|---|
| `phpunit -c phpunit-pgsql.xml CountCorrectionGlPostingTest.php` (isolated PG database `autoerp_test_invcost`) | **OK (7 tests, 42 assertions)** — matches `M5-evidence.md:243` exactly |
| `phpunit -c phpunit-pgsql.xml ProvisioningFlagMatrixTest + CheckCogsCoverageCommandTest + StockAdjustByDeltaTest` | **OK (66 tests, 156 assertions)** — decomposes to the evidence's 19 / 26 / 21 |
| `phpunit -c phpunit-pgsql.xml InventoryGlPostingSeamTest + MovementReasonClassificationTest + InventoryCountingDefaultBatchTest` | **OK (47 tests, 192 assertions)** |
| **PROBE 1** — zero-variance replay count, flag ON, full chart, then `accounting:check-cogs-coverage` | 1 movement (`10.0000 → 10.0000`, `unit_cost 4.256667`), 0 entries, **D-e reports it** → finding 1 |
| **PROBE 2** — rounding boundary, `cost_price 1.000500`, delta 1 | production `debit = 1.001`; the test helper's oracle yields `1.000` → finding 4 |
| **PROBE 3** — `ReplayFinalizeTest` copy with a ≤20-char counting number, on PG | **3 failures**, reproducing the evidence's table exactly |
| **PROBE 3b** — the same file, same database, `PGTZ=UTC TZ=UTC` | **OK (11 tests, 36 assertions)** → finding 2 |
| `deptrac analyse` at the tip | **174 violations / 0 skipped / 12971 uncovered / 13258 allowed** — equal to M4's base and tip; no violation names a file M5 touched beyond the pre-existing `BatchStockService` / `WeightedAverageCostService` / `ReplayAuditDto` token pairs |
| `git diff --name-only 48cebf0f2..HEAD \| grep -iE 'seeder\|\.github/workflows'` | **empty** (71 files total) |
| `git status --porcelain` after the review | empty; both probe classes deleted |

**Driver note.** Unlike M4-round6 I ran the asserting driver: every green above is PostgreSQL 
(`phpunit-pgsql.xml`, isolated database `autoerp_test_invcost`, created for this review so the
concurrent treasury lane's runs could not interfere). I did **not** run the full suite. My local
PostgreSQL session is `CET+0100`, which is what surfaced finding 2 — and which means every green I
report was obtained under the *unfavourable* timezone, so none of them is a timezone artifact.

## Bypasses I tried that FAILED (the code held)

1. **A second GL writer double-costing the same correction.** `grep MovementGlKind::CountCorrection`
   returns exactly one production writer (`ApplyStockAdjustmentsOnCountingCompleted.php:371`) and one
   dispatcher (`InventoryGlPostingBuffer.php:79`); `sourceType: 'inventory_shrinkage'` is written at
   exactly one place (`InventoryGlPostingService.php:76`). Idempotency is keyed on
   `(source_type, source_id = movement_id)` with a double-checked read inside the transaction
   (`GeneralLedgerService.php:4577-4589`, `:4606-4613`).
2. **Double-costing via the movement events.** `StockMovementRecorded`/`…V2` now carry a real
   `unitCost`/`totalCost` on the adjustment path. The only listener is
   `DispatchStockChangeToChannels` (`ChannelServiceProvider.php:29`) — a quantity feed. No GL or WAC
   listener exists on either event.
3. **Cross-job buffer contamination in a long-lived worker.** `InventoryGlPostingBuffer` is bound with
   `$this->app->scoped(...)` (`InventoryServiceProvider.php:40`), which the queue worker forgets
   between jobs (`Worker.php:180-181`), not `singleton`.
4. **A stale context surviving a savepoint rollback and posting against a phantom `movement_id`.**
   The listener has no `try`/`catch` between enqueue and flush, so every throw reaches the root frame;
   `Connection::handleTransactionException` rethrows rather than retrying while nested, so
   `applyCountResult`'s `attempts: 3` cannot silently re-enter; and the root rollback is caught by the
   `TransactionRolledBack` listener at `InventoryServiceProvider.php:98-116`, which resets the buffer
   at `transactionLevel() === 0`.
5. **Divergent cost between the replay and legacy paths for the same correction.** Both call the same
   `resolveRowUnitCost()` (`:1384` and `:949`), under the same `ProductCostLock`, so the `cost_price`
   read is serialized against any concurrent WAC recompute. A retry cannot diverge either: the root
   frame rolls everything back, so only one cost is ever persisted.
6. **WAC drift from the count correction.** `postCountCorrection` changes quantity only and never
   touches `products.cost_price`; valuing both shortage and overage at the *current* average leaves
   the running average invariant, and the GL inventory balance tracks `qty × WAC` on both sides. No
   truncation to currency scale enters the running average — the count path performs no WAC
   arithmetic at all.
7. **A detector-population drift from the D-20 half-fix.** `adjustByDelta()`'s only production caller
   (`StockAdjustmentDocumentService.php:252-267`) always passes
   `referenceType: StockMovementReferenceType::StockAdjustment`, so the now-costed document lines stay
   inside D-a's and D-b's `!= 'stock_adjustment'` exclusions (`CheckCogsCoverageCommand.php:204-206`,
   `:223-225`). `CountCorrection` is `DirectionalVariance`, hence in neither `costedExitReasons` nor
   D-a/D-b at all.
8. **A valuation-reconciliation gap from newly non-zero `total_cost` on adjustment documents.** No
   consumer aggregates it: the only reads are report metadata
   (`CheckCogsCoverageCommand.php:283`), a batch write-off reversal
   (`GroupedWriteOffService.php:199`) and the opening reversal
   (`ResetOpeningBalanceService.php:101-147`, `OpeningBalance` only). No `SUM(total_cost)` valuation
   exists to drift.
9. **A cross-family graft through `fallbackTemplateParent`.** The `type` predicate and
   `whereNull('parent_id')` close both directions — see finding 8.
10. **A new Domain → Application edge from the closure seam.** No import added, deptrac unchanged at
    174.

---

**Gate disposition.** The engineering is sound and the lens's hardest questions all answer cleanly.
The cost basis is genuinely ONE definition threaded to both counting paths and the adjustment
document (`:1717-1726` → `:1384`, `:949`), resolved under the `ProductCostLock` **before** the
movement is written and read back off the persisted row at post time, so the replay and legacy paths
are structurally incapable of valuing the same correction differently and no since-moved WAC can leak
into the amount. WAC invariance holds on both directions. There is exactly one GL writer for count
corrections, movement-keyed idempotency, no second stock-movement path, no float, explicit currency
on every queue-reachable scale resolution, and zero new deptrac edges — I ran deptrac and got M4's
174 exactly. The root frame's lock ORDER is the house-canonical terminal-advisory order, the
partial-failure behaviour is all-or-nothing and correctly idempotent on retry, and the frozen seeders
and workflows are untouched across the whole branch. The adopted commits do what the ruling says:
no wrong-family graft is constructible and the legacy/backfill strict path is byte-identical.

Two findings keep this from an ACCEPT, and both are narrow. **Finding 1** is a defect in T21's own
deliverable, reproduced on PostgreSQL: the flag-tied D-e exclusion — the mechanism by which the wave
closes ticket `2026-08-18-remove-counting-detector-exclusion-with-t21` — reports every zero-variance
counted line the moment the flag flips, because `postCountCorrection()` writes a movement for a
matched line and the D-e query has no flat-delta predicate. On a real full-location count that is
hundreds of false findings on day one, and it contradicts the evidence's §1.7 claim verbatim. The fix
is one query predicate and one red-first test. **Finding 2** is a disproved evidence claim: §6.1 and
the filed ticket record "three genuine PG-only divergences" in the replay window as fact, including
the pre-count-sale exclusion; I reproduced them and then made all eleven cases pass by changing only
`PGTZ`, which locates the cause in the unpinned `pgsql` session timezone, not in
`MovementReplayService`. Left standing, it sends a follow-up lane after a product bug that does not
exist and leaves the real one unrecorded. The remaining eight are P3s — one false comment about lock
lifetime, a truncating test oracle that leaves the feature's only rounding boundary unexercised, a
post-time entry date, an unasserted currency/company pairing, a latent periodic-mode throw, a
fallback-parent activity check, a deploy checklist that never names its own flag, and the two carried
by ruling or by M4. None of those is worth a fix round on its own; findings 1 and 2 are.

VERDICT: CHANGES-REQUIRED
