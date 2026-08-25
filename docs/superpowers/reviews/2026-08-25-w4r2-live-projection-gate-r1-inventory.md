# W4R-2 gate r1 — inventory-costing lens (live POS projection lot legs)

**Lane:** `fix/campaign-w4r2-live-pos-projection-lots` · worktree
`/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/w4r2-live-projection-lots`
**Reviewed:** HEAD `23ba417b1` (last CODE commit `f8cf731ed`, the `merge dev`)
**Diff:** `git diff dev...HEAD` — 6 files, +1406/−12; merge base `94f29795c`.
**Gate run:** 2026-08-25, by execution, throwaway PostgreSQL 16 `autoerp_test_w4r2g`
(`127.0.0.1:5433`, `autoerp`/`autoerp_secret`, dropped at the end); probe worktree
`…/scratchpad/w4r2probe` (detached, vendor copied so autoload resolves to the probe tree —
verified by `ReflectionClass::getFileName()`); the re-run tenant DB touched with `SELECT` only.

> **VERDICT: spec ⚠️ partial · quality CHANGES-REQUESTED · merge-blocking: YES**
>
> The lane does what the brief asked on the happy path and I confirmed every one of its
> own claims by execution — FEFO draw, per-lot legs keyed to the issue movement, allocation
> snapshots, provenance-first refunds, replay safety, sealed bytes, scrap parity, canonical
> lock order, zero float. It also introduces **two new defects the suite does not reach**:
> a sealed REFUND event that the projector now THROWS on (F-1, dead-letter), and a refund
> whose provenance hint is silently discarded whenever the sale drew a `DEFAULT` lot (F-2) —
> which on the actual first tenant is **every** batch-tracked product, because its `DEFAULT`
> lot is the earliest-expiry lot on every one of them. Both are small fixes. F-3 makes the
> 27-unit historical backlog worse rather than neutral.

---

## 0. What I verified green (executed, not read)

| Claim | Evidence |
|---|---|
| New suite green on PG | `tests/Feature/Fiscal/PosCoreReceiptProjectionBatchLotTest.php` → **8 passed (39 assertions)** on `autoerp_test_w4r2g` |
| Red-first is real | `git apply -R` of the lane's whole `apps/api/app` diff in the probe worktree → **7 failed, 1 passed** on PG (the 1 is `test_non_batch_tracked_sale_writes_no_lot_legs`, the regression guard). Re-applied → 8 passed again. |
| Σ lots == stock_levels after sale / refund / partial refund / multi-lot / scrap | probes P2 `9.0000 == 9.0000`, P3 `10.0000 == 10.0000`, P4 `12.0000 == 12.0000`, plus the lane's own three assertions |
| Replay writes no second leg | lane tests + re-run; `apply()`'s `Receipt::exists()` probe on `fiscal_event_id` (`PosCoreReceiptProjection.php:232`) returns before any stock or lot write |
| Sealed bytes / chain hash untouched | lane test (byte compare through `stream_get_contents`, positive control `inventory_batch_movements > 0`); and the diff writes to `inventory_batch_stock`, `inventory_batch_movements`, `pos_receipt_line_batch_allocations` only — no `fiscal_events` write appears in `git diff … -- apps/api/app` |
| Canonical lock order held | projection locks `stock_levels` first (`:2187` decrement, `:2796` restock) and only then reaches `inventory_batch_stock`. That is the documented canonical order — `GroupedWriteOffService::acquireLocks()` states it verbatim ("FIRST: stock_levels … THEN: inventory_batch_stock", `GroupedWriteOffService.php:250-274`), `StockReservationService.php:164-220` follows it, `DeliveryNoteService::issueStock()` follows it (`:304` recordSale → `:370` consume). **No new deadlock edge.** |
| No float, no scale downgrade | diff grep for `(float)`/`floatval`/`number_format`/`round(` on money-qty → nothing; the only cast is `(int) $allocation->batch_id` (an integer PK, `:2560`). All arithmetic is `bcmul`/`bcdiv`/`bcsub`/`bccomp` at scale 4 with an intermediate at 8 (`:2553-2558`), each carrying a `precision-ok` marker. |
| Rule 19/20 | the lot arm touches no money, so it adds **no** scale resolution at all — no queue-reachable no-arg `getScale()` introduced. Diff grep for `getScale|CompanyContext|app(` returns only the two "Rule 20: no CompanyContext" comments. Every scope is read off `$event`. |
| `ReceiptCreationService` quarantine is annotation-only and its callers really are inert | its diff is **comments only**. `POST /pos/receipts` → 410 closure `POS/routes.php:172-180`; `POST /pos/orders/{id}/close` → 410 closure `POS/routes_orders.php:60-68`; `grep -rn ExchangeService apps/api/app apps/api/routes` returns only its own file, two DTO docblocks and two service docblocks — no controller, no route. `ChokepointCompletenessTest` → **7 passed (109 assertions)**. |
| Drift census reproduced | independent read-only SELECT on `tenant01a038cd-…` returns the identical 9 rows and identical totals: GEL-HYDR/ARIA 10, SIRO-TOUX/MAIN 6, LAIT-CORP/ARIA 5, COMP-MAGN/MAIN 4, GEL-HYDR/MAIN 2 = **27.0000**; `pos_receipts=4, pos_sale=6, pos_return=1, inventory_batch_movements=10, pos_receipt_line_batch_allocations=0`. |
| Gates | PHPStan level 8 on the changed file **[OK] No errors** · deptrac ratchet **PASS 183/183** · manifest checker **OK, EXIT=0** · `ChokepointCompletenessTest` 7 passed · `DocumentPerActionWriteGuardTest` 6 passed |
| DPA — zero new keys | on the lane's base the ratchet reports two growth keys (`GeneralLedgerService::clearCustomerAdvanceToReceivable::journal_entries::delete#1/#2`). **Dev has since fixed them** (`12867e5fc`, N-6, merged `4966cc93f`). I merged CURRENT dev into the probe worktree and re-ran: the NEW/STALE test **passes**; the only remaining failure is the fail-closed `DPA_BASELINE_PROTECTED_BLOB` probe, which is unset locally by design. Zero new keys from this lane, confirmed by execution. |
| Inherited sqlite red is inherited | `PosCoreReceiptProjectionRefundDispositionStockTest` on sqlite = **15 failed, 1 warning** with the lane applied AND with the whole `apps/api/app` diff reverse-applied — byte-identical. Same class on PG = **16/16, 0 failures (77 assertions)**. (My first attempt at this comparison was contaminated: `DB_DATABASE` leaks past `phpunit.xml`'s non-forced `<env>` into a sqlite run. The clean re-run is the number above.) |

---

## 1. Findings

### F-1 [CRITICAL] `restoreLotsForRefundLine()` can THROW, and a sealed REFUND is then dead-lettered
`apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:2477-2515`
→ `apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:464-470`
→ `FEFOInventoryService.php:673-679`

The lane argues at length, correctly, that "a projector may never REJECT an already-signed
event" and makes the OUTBOUND arm non-strict for exactly that reason
(`strictFulfillment: false`, `:2052`; docblock `:1986-2005`). The INBOUND arm got no such
containment. `restoreBatchesForReturn()` ends at the DEFAULT-lot fallback, and
`defaultBatchId()` **refuses** to mint a product-level `DEFAULT` lot for a product that has
active variants:

```
FEFOInventoryService.php:673-679
if ($variantId === null) {
    $activeVariants = $this->variantLookup->listForProduct($productId, true);
    if ($activeVariants->isNotEmpty()) {
        throw MissingVariantException::forProduct($productId);
    }
}
```

`restoreLotsForRefundLine()` has no try/catch, and `restockForLines()` runs inside
`apply()`'s `DB::transaction` (`:252`). **Executed proof** (probe P6, PG): batch-tracked
product + one active variant + a product-level refund line →

```
P6 after sale:  stock=6.0000 receipts=1
P6 refund THREW: App\Shared\Domain\Exceptions\MissingVariantException
                 — Product '…' has active variants; batches must be variant-scoped …
P6 receipts after throw: 1        ← the refund receipt was NOT written; the whole
                                    projection rolled back
```

`ApplyFiscalEventProjectionJob` catches `Throwable`, advances attempts and, on exhaustion,
flips the row to `dead_lettered` (terminal) — `ApplyFiscalEventProjectionJob.php:414-419`,
`:43`, `:471`. The exception is deterministic, so every retry reproduces it: a signed,
money-refunded event permanently loses its receipt, its VAT rows, its payment legs and its
drawer movement. On **dev** the same refund projects fine (there is no lot call at all), so
this is a failure mode this lane introduces.

Reachability, stated honestly: tenant #1 has `product_variants = 0` today, so it is not a
day-one break there. It arms itself the moment any batch-tracked product gains an active
variant (an ordinary catalog action) and a product-level line of an older sale is refunded —
and on any other tenant that already has variants.

**Fix:** contain the whole lot arm the way `applyScrapDisposition()` already contains its
own nested work (`:2676-2711`) — `try { … } catch (\Throwable $e) { Log::error(…); }` around
the body of `restoreLotsForRefundLine()` **and** of `consumeLotsForSaleLine()` (the latter is
non-strict for shortfall but is still one `QueryException` away from the same outcome). A
lot leg that cannot be written must degrade to logged drift, never to a lost receipt — which
is precisely the doctrine the lane's own docblocks argue.

### F-2 [CRITICAL] a provenance hint naming a `DEFAULT` lot is silently discarded — the refund credits a dated lot that never shipped the units
`FEFOInventoryService.php:547` (`->where('pb.batch_number', '!=', self::DEFAULT_BATCH_NUMBER)`)
with `FEFOInventoryService.php:422-426` (`$ceiling = $outstanding[$batchId] ?? null; if ($ceiling === null) { continue; }`)

`restoreBatchesForReturn()` caps every provenance hint by the lot's outstanding outbound —
and looks that ceiling up in `outstandingShippedLots()`, which **excludes `DEFAULT` lots by
construction**. So a hint that names a `DEFAULT` lot is not capped, it is *dropped*: the
credit falls through to the shipment-history heuristic (step 2), which is exactly the
"credits whichever lot shipped last" behaviour that gate r5 R5-1 built the provenance arm to
prevent.

**Executed proof** (probe P4, PG). `DEFAULT` expiring in 10 days, `LOT-DATED` in 200 days.
Receipt 1 sells 4 (drawn from `DEFAULT`, FEFO); receipt 2 sells 8 (drains `DEFAULT`, bites
`LOT-DATED` for 2). Refunding **receipt 1**:

```
after two sales:      DEFAULT 0.0000   LOT-DATED 8.0000
after refund of r1:   DEFAULT 2.0000   LOT-DATED 10.0000     (stock 12.0000, Σ lots 12.0000)
```

Two of the four returned units were credited to `LOT-DATED`, a lot that never shipped them.
`LOT-DATED` is now +2 against its physical content, `DEFAULT` is −2, `BatchTraceabilityController`'s
recall trail is wrong in both directions, and `Σ lots == stock_levels` reconciles — which is
why nothing else catches it.

**This is the first tenant's dominant shape, not an edge case.** Read-only census of
`tenant01a038cd-…`: every one of the 7 batch-tracked products carries a `DEFAULT` lot at
`2027-08-25`, and on every product that also has a dated lot the `DEFAULT` lot is the
**earlier** expiry (`COMP-MAGN` DEFAULT 2027-08-25 vs LOT 2027-11-30; `SIRO-TOUX` vs
2028-03-31; `HUIL-ARGA` vs 2029-01-31). FEFO will therefore draw `DEFAULT` first on
essentially every POS sale, and every refund of one of those sales takes this path.

The bug lives in the shared Domain service, and the document channel
(`ReceiptReturnService::lotProvenanceForLine()`, `ReceiptReturnService.php:1900-1938`) has it
too — but that channel's sales are authored by the retired `ReceiptCreationService`, so it
was dormant. **This lane is what makes it live.**

**Fix (smallest correct):** in `restoreBatchesForReturn()`, let a provenance hint credit a lot
that is absent from `$outstanding` when the hint itself is the evidence (it already came from
the sale's own `pos_receipt_line_batch_allocations` row and is bounded by what that line
took) — e.g. `$ceiling = $outstanding[$batchId] ?? $hinted;` — or include `DEFAULT` lots in
`outstandingShippedLots()` for the provenance pass while keeping them out of the heuristic
pass. Either way it needs its own test on the tenant-#1 shape (DEFAULT earliest + a dated lot
with outstanding outbound).

### F-3 [IMPORTANT] refunding a PRE-LANE sale now mints a phantom `DEFAULT` lot and makes the 27-unit backlog worse
`PosCoreReceiptProjection.php:2509-2513` → `FEFOInventoryService.php:460-470`

A pre-lane sale left no allocation rows AND no outbound lot leg. So on refund:
`lotProvenanceForOriginalLine()` returns `[]` (`:2543`), `outstandingShippedLots()` finds no
outbound leg for the lot (its only leg is the positive receipt leg), and the credit lands on
a freshly minted `DEFAULT` lot at `today + 365`.

**Executed proof** (probe P1, PG — sale projected, then the lot side rewound to exactly the
historical state: aggregate moved, lot untouched, zero allocations):

```
before refund:  LOT-A 10.0000                          stock 6.0000   (drift +4)
after refund:   LOT-A 10.0000  DEFAULT(2027-08-25) 4.0000
                stock_levels 10.0000   Σ lots 14.0000  (drift +4)
```

On **dev** the same refund credits no lot at all, so the aggregate returns to 10 and Σ lots
stays 10 — **drift 0**. The lane therefore converts a self-healing case into a permanent
+4 and mints the exact `DEFAULT@today+365` phantom that W2-7 shipped
`inventory:repair-phantom-default-batches` to remove, re-labelling short-dated goods as
untracked stock that FEFO will ship last. Every one of the 27 units on the re-run tenant is
in this state, and the tenant already has one `pos_return` on the board.

**Fix or ruling needed before onboarding:** either (a) skip the lot credit when the original
sale line has no allocation rows AND the original receipt predates the fix (preserve dev's
behaviour for the backlog), or (b) run the repair pass first so no pre-lane sale is
refundable in this state, or (c) an explicit owner accept with the number written down. The
handback names the repair gap (its residual 1) but does not name this interaction — the fix
does not merely fail to repair history, it degrades it.

### F-4 [IMPORTANT] a lot shortfall is logged and nothing else — the drift it creates has no detector
`PosCoreReceiptProjection.php:2079-2088`; candidate predicate `FEFOInventoryService.php:249`.

I agree with the lane's central judgement: a projector may not refuse a sealed event, so
non-strict here is right and the divergence from `DeliveryNoteService`'s strict arm
(`DeliveryNoteService.php:343-377`, "EXPIRED lots are invisible … a delivery that cannot be
drawn from real lots is REFUSED") is correct and correctly argued. **Executed proof of the
divergence** (probe P5, PG): a product whose only lot is expired sells 4 → `stock_levels`
6.0000, Σ lots 10.0000, `DRIFT 4.0000`, zero legs, one `lot shortfall` warning.

What is missing is the other half. The predicate is narrower than the aggregate's in four
independent ways — expired, recalled, inactive (`:249`), reservations netted through
`available_quantity` (`:248`), variant-scoped lots absent while a variant stock grain exists,
and `FOR UPDATE OF ibs SKIP LOCKED` (`:251`) means even *transient* lock contention from a
concurrent reservation or write-off converts into **permanent** lot drift, because the sale
can never be retried. There is no `Σ lots vs stock_levels` detector anywhere in the tree
(`app/Console/Commands` has only `RepairPhantomDefaultBatchesCommand`, which is the opposite
direction). A drift that only a `Log::warning` records will not be found.

**Condition:** ship a detector — a console census or a scheduled check emitting
`Σ inventory_batch_stock` vs `stock_levels` per (product, variant, location) — or file it as
a named LEDGER row with an owner. Not necessarily in this lane, but it must not close silent.

### F-5 [IMPORTANT] manifest `gated_ceiling` collides with W4-3 — the second lane to merge must resolve to 1187
`apps/api/tests/feature-lane-manifest.json`

* Lane declares `groups.Fiscal.classes` **81 → 82** and `gated_ceiling` **1185 → 1186**.
* **Current dev is `c0ca71f18`, not `94f29795c`** — dev moved during this review (N-6 DPA
  rollback + three review commits landed). Its manifest is still `gated_ceiling 1185`,
  `Fiscal 81`, `Document 88`, so the lane's arithmetic is correct **against dev as it stands**:
  I merged current dev into the probe worktree and `feature-lane-manifest-check.php` still
  reports `OK … 1186 class(es) parked`, EXIT=0.
* **But W4-3 (`docs/superpowers/reviews/2026-08-25-w43-openings-gate-r3-treasury.md:199-203`)
  is approved to land `Document 88 → 89` and `gated_ceiling 1185 → 1186`.** Both lanes claim
  the same ceiling. Whichever merges SECOND must re-take the union to **1187** in its merge
  commit. Re-run the checker in the merge commit, per the manifest's own note.
* `ci.yml` append is minimal and correct: one class name added to the single
  `backend-test-pgsql --filter` alternation (`.github/workflows/ci.yml:1025`), nothing else
  in the file changed. Precedent (D-1 `SaleReceiptV5PostRemiseVatBaseTest`, Q-6 r2) holds,
  and here it is stronger than precedent because 7 of the 8 tests can only execute on PG.
  actionlint not re-run by me; the change is a single-token alternation append.

### F-6 [MINOR] partial-refund lot attribution is decided by unordered row order
`PosCoreReceiptProjection.php:2539-2541` — `DB::table('pos_receipt_line_batch_allocations')->where(…)->get([...])`
with no `orderBy`, and `restoreBatchesForReturn()` consumes `$preferredLots` in array order
(`FEFOInventoryService.php:417`).

On a PARTIAL refund of a sale that spanned several lots, which lot gets credited is therefore
whatever order PostgreSQL happens to return. Probe P3 (sale 5 = 3 from `LOT-EARLY` + 2 from
`LOT-LATE`, refund 2) credited `LOT-EARLY 0.0000 → 2.0000` and left `LOT-LATE` alone — the
FEFO-safe answer, but by accident, not by contract, and not asserted anywhere (the lane's
refund test refunds the line in full, where order cannot matter). Add
`->orderBy('batch_id')` (or better, join the lot's expiry and order by it) and pin the
partial-multi-lot case.

### F-7 [MINOR] the new test class advertises coverage it does not have
`tests/Feature/Fiscal/PosCoreReceiptProjectionBatchLotTest.php:59` — the class docblock lists
"(h) a composite line explodes to its recipe leaves and moves leaf lots" as pinned contract.
There is no such test, and §8 of the handback (W4R-3) explains why there cannot be one on
this path. Delete the bullet or restate it as "composite lines move nothing — see W4R-3".

### F-8 [MINOR] one `Batch::query()->find()` per consumed lot inside the projection loop
`PosCoreReceiptProjection.php:2066` — mirrors the retired service, but a sale spanning N lots
issues N extra queries inside the receipt transaction just to read `batch_number`. A single
`whereIn` before the loop would do. Cosmetic; noted because this runs per line per receipt on
the queue.

---

## 2. Answers to the six numbered probes

1. **Sale.** FEFO draw confirmed (expiry ASC, `created_at` tiebreak, `FEFOInventoryService.php:250`),
   one `inventory_batch_movements` leg per lot keyed to the issue movement id
   (`:294-300`; asserted at test `:142-159`), one `pos_receipt_line_batch_allocations` row per
   lot with the `batch_number`/`expiry_date` snapshot (`:2068-2075`) — write shape byte-equal
   to the retired `ReceiptCreationService::allocateBatches()` (same six fillable keys, no
   `tenant_id` column on that table). Σ lots == aggregate after the receipt: confirmed in four
   independent probes. Expired/recalled/inactive policy **diverges** from `issueStock()`:
   the DN arm REFUSES, this arm shortfalls — correct for a projector, see F-4 for what is
   missing. WAC/COGS amounts unchanged: the diff adds no cost arithmetic, `decrementStock`
   /`restockStock` bodies are untouched apart from the `?StockMovement` return.
   **Judgement on the silent shortfall:** acceptable as a *decision* (a sealed event cannot be
   rejected), NOT acceptable as an *observability posture* — it is surfaced nowhere but a log
   line, and F-2/F-3 show the same silence hides worse things. F-4 is the condition.
2. **Refund.** Provenance path confirmed (`:2501-2515`, netting `:2537-2568`); partial refunds
   net correctly (probe P2: 5 out, 2 back, 2 back → lot 9.0000, aggregate 9.0000); `scrap`
   credits no lot (lane test, and `applyScrapDisposition()` `:2638` never reaches the lot arm
   — V10 parity holds); `not_received` / `RestockPolicy::Never` `continue` before
   `restockStock` so no movement and no lot leg. **Refund of a pre-lane sale does NOT fall back
   cleanly** — it mints a phantom `DEFAULT` (F-3), and a sale that drew a `DEFAULT` lot loses
   its provenance entirely (F-2).
3. **Replay safety.** Confirmed twice by execution; the `fiscal_event_id` guard at `:232`
   precedes every write, and the lot arms add no second idempotency surface.
   **Concurrency:** lock order is `stock_levels` → `inventory_batch_stock` in the projection,
   identical to `GroupedWriteOffService::acquireLocks()` (which documents it as the canonical
   plan), `StockReservationService`, `DeliveryNoteService::issueStock()` and
   `BatchStockService::issueBatchStock()`. Outbound lot locks are `SKIP LOCKED` (never wait);
   `creditLot()` blocks but takes the same order. **No deadlock edge introduced** — the cost
   of `SKIP LOCKED` is silent drift instead (F-4).
4. **Sealed payload / hash / quarantine.** All confirmed — see §0.
5. **W4R-3.** Confirmed, and it is **wider than composites**. `flattenMenuToProducts`
   (`apps/pos/src/api/productApi.ts:124-149`) rewrites the `id` of **every** menu row —
   whatever its `sellable_type` — to `buildMenuCompositeId(sellable_id, category.id)` =
   `"<uuid>_<uuid>"` (`apps/pos/src/lib/menu/compositeId.ts:31-33`); `SaleReceiptPayload.ts:317`
   seals `item.product.id` verbatim; the only `parseMenuCompositeId` unpacking is
   `apps/pos/src/lib/sync/syncService.ts:2546`, on the RETIRED wire path. So on a **Menu
   tenant every POS line** — not just composites — fails `Str::isUuid()` at
   `PosCoreReceiptProjection.php:1933`, moves no aggregate stock, no lot stock, and also lands
   `pos_receipt_lines.product_id = NULL` via `resolveProductFk()`. **Pre-existing** (the guard
   is on dev, unchanged by this lane) and **zero impact on tenant #1** (parapharmacy, no menu;
   census shows all 27 drift units are direct-line). **Suggested LEDGER severity: P2** — not a
   launch blocker for tenant #1, a hard blocker for the first Menu/F&B tenant, and it should be
   filed as "Menu-mode sealed `product_id` is not a UUID" rather than "no composite arm",
   because the read-model and reporting consequences are broader than inventory.
6. **Manifest / gates.** See F-5 and §0. deptrac **183/183 PASS**; DPA **zero new keys**
   (proven by merging current dev, not by assertion); PHPStan clean; red-proof executed both
   ways; the sqlite `RefundDispositionStockTest` red is inherited byte-identically.

---

## 3. Manifest values (for the merge commit)

| | dev `c0ca71f18` (current) | lane declares | union at merge |
|---|---|---|---|
| `groups.Fiscal.classes` | 81 | **82** | 82 |
| `groups.Document.classes` | 88 | 88 (untouched) | 89 **if W4-3 lands first** |
| `gated_ceiling` | 1185 | **1186** | **1186 if this lane merges before W4-3; 1187 if after** |
| deptrac | 183/183 | 183/183 held | — |

`feature-lane-manifest-check.php` in the lane, and again after merging current dev into a
probe worktree: `OK — 1439 Feature classes in 74 groups`, `1186 class(es)` parked, EXIT=0.

---

## 4. Merge-blocking

**YES.** F-1 and F-2 must be fixed and pinned before this lands; F-3 needs a fix or a written
owner accept; F-4 needs a detector or a LEDGER row; F-5 is mechanical but must be re-taken in
the merge commit. F-6/F-7/F-8 are fix-while-you-are-there.

**What to fix before merge:** contain the lot arms so no lot failure can dead-letter a sealed
receipt (F-1), stop dropping the provenance hint when the sale drew a `DEFAULT` lot (F-2), and
decide what a refund of a pre-lane sale does before it mints a phantom `DEFAULT` on the first
tenant (F-3).
