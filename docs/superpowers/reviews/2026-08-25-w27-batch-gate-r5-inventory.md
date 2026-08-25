# W2-7 gate r5 — inventory-costing lens (r4 fix round: R4-1 outstanding-outbound ceiling, R4-4..R4-12)

**Lane:** `fix/campaign-w27-phantom-default-batch` · worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/w27-default-batch` · HEAD `c06c8c529`
**Reviewer:** inventory-costing-reviewer (r5) · **Date:** 2026-08-25
**Diff reviewed:** `git diff 50cc9fe66...HEAD` — lane commits `d9099a33b` (dev merge + manifest union), `a4cb152dd` (R4-1/4/5/6/7/8/9/10/11), `c06c8c529` (handback §"Fix round r4"). Everything else in the range arrived through the dev merge and is out of lens.
**Prior rounds:** r1–r4 (`2026-08-24-w27-batch-gate-r{1,2,3}-inventory.md`, `2026-08-25-w27-batch-gate-r4-inventory.md`).
**Scope (as dispatched):** verify R4-1..R4-12 are closed and that the r4 fixes introduced nothing new of CRITICAL severity. The lane was NOT re-reviewed end to end.
**Hygiene:** the lane worktree was left **pristine** — `git status --porcelain` → 0 lines, HEAD unchanged `c06c8c529`. Every probe and every tamper ran in throwaway `git worktree`s (`…/scratchpad/tamper` at HEAD, `…/scratchpad/w27g5` = HEAD merged with current dev), both **removed** (`git worktree list` verified, temp branch `tmp-g5-merge` deleted). Throwaway PG database `autoerp_test_w27g5` created and **dropped**. One test process at a time; the full suite was never run. Tenant contact was **read-only psql** on the wave-4 tenant only; `--execute` was never run anywhere.

# VERDICT: spec ❌ + quality CHANGES-REQUESTED — **merge-blocking: YES**

**R4-1 is genuinely closed** and I proved it through the *production* path, not the lane's hand-rolled one: a GRN-sourced lot of 18, a real `dpConfirmedDeliveryNote(5)`, a real `ReturnNoteService` confirm — the origin lot goes 18 → 13 → **18**, the leg census is exactly 4 legs with **one** `+5.0000 customer_return` credit on the origin lot, `Σ lots == stock_levels == 30`, **zero** `DEFAULT` lots. Reverting the ceiling expression to the r3 net-of-all form reddens **4** tests. R4-4, R4-5, R4-8, R4-9, R4-10, R4-11 are closed; R4-6 is now measured by execution and is an acceptable residual for tenant #1; R4-12 is confirmed inherited.

Two things block the merge.

**R5-1 is a new CRITICAL, introduced by the r4 fix round.** R4-7 asked for one restore policy; the lane converged both channels onto the **weaker** one. The POS channel had **exact per-line provenance** (`ReceiptLineBatchAllocation` names the lot and quantity each receipt line consumed) and the fix **deleted** it in favour of the document channel's *most-recently-shipped-first* heuristic. Measured, same fixture, same probe, only `ReceiptReturnService` differing: at `50cc9fe66` the return of receipt #1 credits the **short-dated origin lot**; at `c06c8c529` it credits the **long-dated other lot** and leaves the origin at zero. That is wrong lot quantity at rest on both lots, a broken recall trail, and short-dated goods re-labelled long-dated — the lane's own stated product-safety failure mode, one notch less visible than the `DEFAULT` phantom it replaced. It is reachable on the wave-4 tenant today (one product carries two real dated lots).

**R5-3: the manifest union is stale a third time.** `dev` moved to `c97a735ad` (W2-6 merged `51e6c1ed0`) and dev's `Document` is **already 83**. Merging the lane's `83 / 1171` verbatim turns dev's own checker red by 1 on `Document` and by 4 on `gated_ceiling`. Computed by running the checker on the actual merged tree: **Document 84, Inventory 114, Company 31, `gated_ceiling` 1173** → `EXIT=0`.

---

## 0. What I verified BY EXECUTION

| # | Check | Evidence | Result |
|---|---|---|---|
| 1 | Lane suites on **PostgreSQL 16** (`autoerp_test_w27g5`, 5433) — `BatchTrackedSalesOrderConfirmFefoTest` + `ReceiptStockPolicyTest` + `RepairPhantomDefaultBatchesCommandTest` + `EnsureDefaultBatchTest` | one process | **OK — 43 tests, 207 assertions** |
| 2 | Same four suites on **sqlite** | one process | **43 tests, 137 assertions, 13 skipped** (r4 was 19 run / 13 skipped; **30 now run** → R4-5 delivered) |
| 3 | `--testdox` on sqlite, FEFO file | | the three new R4-1/R4-8 tests **execute on sqlite** (`✔`), only the DN-confirm tests are `↩` |
| 4 | **PROBE — R4-1 through the PRODUCTION path** (real DN confirm + real `ReturnNoteService`, GRN-sourced lots), PG | my probe | 4 legs: `batch1 +18 goods_receipt`, `batch2 +12 goods_receipt`, `batch1 −5 delivery`, **`batch1 +5 customer_return`**; lots `18 / 12`, `Σ=30`, aggregate `30`, **DEFAULT lots = 0** ✅ |
| 5 | **RED-PROOF 1** — revert `outstandingExpr` to r3's `−SUM(all legs)` (≡ `HAVING SUM < 0`) | throwaway worktree, sqlite | **4 failures**: `a return of grn sourced stock…`, `a second return cannot credit…`, `the restore order is by most recent shipment…`, `the returned quantity carries its own batch movement leg` → the new pins genuinely bind |
| 6 | **RED-PROOF 2** — short-circuit the POS `restoreBatchesForReturn` call (`if (false && …)`) | throwaway worktree, PG | **1 failure**: `a pos return credits the origin lot through the shared domain service` — *"the returned units go back on the lot they left"* → the R4-7 pin binds |
| 7 | **PROBE — R5-1 wrong-lot regression**, PG. Lot A (exp +30d, 5) + lot B (exp +150d, 10); receipt 1 sells 5 (FEFO → lot A), receipt 2 sells 5 (→ lot B), return **receipt 1** | at HEAD `c06c8c529` | `lotA(short)=0.0000  lotB(long)=10.0000` — **wrong lot** (allocation snapshot says receipt 1 consumed batch 1) |
| 8 | Same probe, only `ReceiptReturnService` reverted to `50cc9fe66` | throwaway worktree, PG | `lotA(short)=5.0000  lotB(long)=5.0000` — **correct lot** → the regression is this fix round's |
| 9 | **PROBE — R5-2**, batch-tracked product given one **active variant**, then a return note confirm | sqlite | **THREW `MissingVariantException`** — *"Product … has active variants; batches must be variant-scoped"* |
| 10 | Same probe with `FEFOInventoryService` reverted to `50cc9fe66` | throwaway worktree | *"return note confirmed OK"* → the hard failure is new in r4 |
| 11 | Wave-4 tenant reachability of R5-2 (read-only psql) | `tenant01a035ba-…` | **7** batch-tracked products, **0** with active variants → not reachable **today** |
| 12 | Wave-4 tenant reachability of R5-1 (read-only psql) | same | product `01a035bf-096d-…` carries **two real dated lots** (`LOT-SIRÔP-2026A@2028-03-31`, `LOT-SIRÔP-2026C@2028-06-30`) → **reachable day one** |
| 13 | Wave-4 tenant reachability of R4-6 (read-only psql) | same | `composite_items` = **0**, `pos_receipt_lines` with NULL `product_id` = **0** → **not reachable** for tenant #1 |
| 14 | **R4-6 measured** — `test_returning_a_combo_leaves_the_leaf_undecomposed_but_never_drifts_the_lot_ledger` | PG, green | leaf aggregate **and** leaf lots both stay at 9.0000 → in sync, no lot-vs-aggregate drift, no phantom. It stays green under RED-PROOF 2, i.e. it is a **characterisation** test, not a binding pin (correctly, and its docblock says so) |
| 15 | R4-5 fixture shape — grep for legless lot creation in the lane's test files | `BatchTrackedSalesOrderConfirmFefoTest` | **0** `Batch::create` / `BatchStock::create`; `seedLot()` goes `findOrCreateBatch` → receipt `StockMovement` → `receiveBatchStock(movementId:)`; the R4-1 test asserts its own 2-positive-leg precondition ✅ (but see R5-6 for `ReceiptStockPolicyTest::seedLeafLot`) |
| 16 | R4-7 — one restore service, both channels | grep, whole `apps/api/app` | exactly two call sites: `ReturnNoteService.php:867` and `ReceiptReturnService.php:523`, both `FEFOInventoryService::restoreBatchesForReturn()`. `restoreBatchAllocations()` / `cumulativeBatchRestitution()` **deleted** ✅ |
| 17 | DPA scanner on the **merged** tree | `DocumentPerActionBaselineRatchetTest` + `WriteGuardTest` | 2 failures, **both inherited**: `DPA_BASELINE_PROTECTED_BLOB` unset (env), and the only unbaselined keys are dev's **two** `GeneralLedgerService::clearCustomerAdvanceToReceivable::journal_entries::delete` — **zero** keys from this lane, and **no stale-key error**, so the baseline removal is legitimate |
| 18 | The baseline removal is exactly one key | `git diff a4cb152dd^ a4cb152dd` | removes only `…ReceiptReturnService::restoreBatchAllocations::inventory_batch_stock::query_builder#1` ✅ |
| 19 | deptrac ratchet on the **merged** tree | | **PASS — TOTAL 183/183**, no boundary regression (the new `POS\Application → BatchExpiry\Domain` edge does not regress the baseline) |
| 20 | PHPStan L8 (live-DB env), the two touched production files | | **`[OK] No errors`** |
| 21 | Pint `--test`, 2 production + 3 test files | | **`{"result":"pass"}`** |
| 22 | Rule 19 scan of every production line **added by `a4cb152dd`** | `grep -E '\(float\)|floatval|number_format|parseFloat|Number\('` over `+` lines | **0 hits**; no new hardcoded bcmath scale; the only rounding is `QuantityScale::round(…, SCALE, FLOOR)` on the ceiling ✅ |
| 23 | Manifest union vs **current** dev `c97a735ad` | checker run on the real merged tree | lane values RED: *"Document now holds 84, ceiling is 83"*, *"Inventory now holds 114, ceiling is 111"*, *"1173 class(es) … ceiling is 1169"* → **R5-3** |
| 24 | The corrected values pass | Document **84** / Inventory **114** / Company **31** / gated **1173** | checker **EXIT=0** ✅ |
| 25 | R4-12 inherited-red claim | `tests/Unit/POS/ReceiptReturnServiceTest` at HEAD **and** with both files from dev | **11 errors in both** → confirmed inherited, not deepened |
| 26 | C-1 blob, recomputed against current dev | `git rev-list c06c8c529 --not dev` × `cat-file` | **same 7 commits** (`1c3adf33b d33f51089 fb469f423 391a787d1 99454be34 a6543b3a5 6911365a9`), blob `81e058d0d`, **55,666,691 B** |
| 27 | `.deptrac.cache` in HEAD's tree | `git ls-tree -r c06c8c529` | **0 hits** ✅ |
| 28 | Migrations in the lane | `git diff --name-only dev...HEAD -- database/migrations` | **none** — the merge is migration-free |
| 29 | SQLite float-SUM risk on the new aggregate (fractional legs 0.7 + 0.1, return 0.8) | my probe, sqlite | lot back to **18.0000**, `DEFAULT` lots **0** → no FLOOR under-credit artefact; **no finding** |

---

# FINDINGS

## [CRITICAL] R5-1 — the r4 "one restore policy" converged the POS channel onto the WEAKER policy: a POS return now credits the WRONG lot, where it used to credit the right one

`apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:502-527` replaced the snapshot restore with

```php
if ($restoreMovement !== null && $this->fefoService->productRequiresBatchTracking($originalLine->product_id)) {
    $this->fefoService->restoreBatchesForReturn(… quantity: $qty, movementId: $restoreMovement->id, variantId: $originalLine->variant_id);
}
```

and deleted `restoreBatchAllocations()` / `cumulativeBatchRestitution()` (`-163` lines in the same diff).

The deleted code read `ReceiptLineBatchAllocation::where('receipt_line_id', $originalLine->id)` — a **per-line snapshot of exactly which lots that line consumed and how much of each**, written on the sale at `ReceiptCreationService.php:1436`. The replacement resolves the origin lot from the movement ledger, aggregated over the whole `(product, location, variant)` tuple, ordered *most-recently-shipped first* (`FEFOInventoryService.php:474-476`). The ledger cannot tell which **receipt** shipped a leg, so the heuristic is only right when the returned sale happens to be the last one out of that tuple.

Measured on PostgreSQL 16, identical fixture, identical probe, the only variable being `ReceiptReturnService`:

```
lots: LOT-A exp=+30d qty=5   LOT-B exp=+150d qty=10
receipt 1 sells 5  -> FEFO consumes LOT-A   (allocation snapshot: receipt_line=<r1>, batch=1, qty=5.0000)
receipt 2 sells 5  -> FEFO consumes LOT-B   (allocation snapshot: receipt_line=<r2>, batch=2, qty=5.0000)
return receipt 1's 5:
  at 50cc9fe66 (snapshot restore) : lotA(short)=5.0000  lotB(long)=5.0000     <-- correct
  at c06c8c529 (ledger restore)   : lotA(short)=0.0000  lotB(long)=10.0000    <-- WRONG LOT
```

Why this is Critical and not cosmetic:

1. **Two lot balances are wrong at rest.** `LOT-A` reads 0 while 5 physical units of it are back on the shelf; `LOT-B` reads 10 while only 5 of its units exist. `Σ lots == stock_levels` still reconciles, which is exactly why nothing in the suite catches it.
2. **The recall trail breaks in both directions.** `BatchTraceabilityController.php:77` still reconstructs POS sales from `ReceiptLineBatchAllocation` — the very rows the return no longer honours — so a recall of `LOT-A` reports 5 units sold-and-not-returned that are actually on the shelf, and a recall of `LOT-B` under-counts.
3. **It is the lane's own product-safety failure mode.** Short-dated units are re-labelled with a longer expiry, so FEFO will ship them **last** and the expiry sweep will not flag them. That is the same defect class as the `DEFAULT@today+365` phantom this lane exists to kill, just harder to see.
4. **Reachable on tenant #1 day one.** Read-only psql on `tenant01a035ba-592b-72aa-a12a-1e6b2f7e1d06`: product `01a035bf-096d-71e1-8dcf-4026b78fd544` carries **two** real dated lots (`LOT-SIRÔP-2026A@2028-03-31`, `LOT-SIRÔP-2026C@2028-06-30`).

The lane's own new pin cannot see this: `test_a_pos_return_credits_the_origin_lot_through_the_shared_domain_service` (`ReceiptStockPolicyTest.php:349-413`) seeds **one** lot, so every candidate ordering gives the same answer.

**Fix — keep the convergence, keep the provenance.** R4-7's real complaint was that the snapshot arm restored **nothing** when the snapshot was absent (the composite leaf). That is fixed by making the snapshot an *origin hint*, not by deleting it:

- give `restoreBatchesForReturn()` an optional `array<int,numeric-string> $preferredLots` parameter, applied first and still capped by each lot's outstanding outbound, then fall through to the existing most-recently-shipped scan and the `DEFAULT` arm for whatever the hint does not cover;
- have `ReceiptReturnService` pass the line's `ReceiptLineBatchAllocation` rows (minus what prior non-voided returns already credited) as that hint.

Both channels still go through the one Domain service; the document channel, which genuinely has no snapshot, is unchanged. **Re-pin with two lots and two receipts** — the probe above, verbatim.

## [CRITICAL] R5-3 — the manifest union is stale for the third round: `Document` must be **84** and `gated_ceiling` **1173**

`dev` is now `c97a735ad1265a0724be0dd8b9f76bafafd3f73e` (W2-6 merged as `51e6c1ed0`), and dev's own `Document` entry is **already 83** — W2-6 raised 82 → 83 for `PurchaseOrderUnpricedLineConfirmTest`, and 1168 → 1169. The lane's `d9099a33b` merged the *previous* dev (`df816b701`) and set `Document: 83 / gated_ceiling: 1171`, which now collides.

Verified by running `apps/api/tools/feature-lane-manifest-check.php` on the **actual merged tree** (throwaway worktree, `HEAD` merged with current `dev`), not by arithmetic:

```
✗ PARKED-LANE COVERAGE GREW: group "Document" now holds 84 class(es), ceiling is 83.
✗ PARKED-LANE COVERAGE GREW: group "Inventory" now holds 114 class(es), ceiling is 111.
✗ GATED-LANE COVERAGE GREW: 1173 class(es) … ceiling is 1169.
```

and then, with `Document: 84`, `Inventory: 114`, `Company: 31`, `gated_ceiling: 1173` → **`EXIT=0`**.

| Key | dev `c97a735ad` | lane `c06c8c529` | **correct at merge** |
|---|---|---|---|
| `gated_ceiling` | 1169 | 1171 | **1173** |
| `Document.classes` | 83 | 83 | **84** |
| `Inventory.classes` | 111 | 114 | **114** ✅ |
| `Company.classes` | 31 | 31 | **31** ✅ |

The `Document` **note** must keep dev's W2-6 `82 → 83` paragraph verbatim and append this lane's `83 → 84` for `BatchTrackedSalesOrderConfirmFefoTest`. As in r3 and r4: **compute this at merge time, not at handback time**, and re-derive again if `dev` moves before the squash lands.

## [CRITICAL-CONDITION, carried unchanged] R5-4 — C-1: the 55.6 MB blob is still reachable from the same seven commits. The merge MUST be `--squash`

Recomputed today against current dev — byte-identical to r2/r3/r4: blob `81e058d0d`, **55,666,691 bytes**, reachable as `<commit>:.deptrac.cache` from

```
1c3adf33b  d33f51089  fb469f423  391a787d1  99454be34  a6543b3a5  6911365a9
```

`git ls-tree -r c06c8c529 | grep deptrac.cache` → **0**, so HEAD's tree is clean; it is the *history* that carries it. A `git merge` (ff or `--no-ff`) makes those seven commits ancestors of `dev` and the object travels to `origin` permanently. **`git merge --squash` is mandatory** — record it in the merge ledger.

## [IMPORTANT] R5-2 — R4-4's guard turns a working return-note confirm into a hard `MissingVariantException` for any batch-tracked product that has an active variant

`FEFOInventoryService.php:604-619` (the restated `findOrCreateBatch` guard) refuses when `$variantId === null` and the product has active variants. But its only document-channel caller does not thread the variant: `ReturnNoteService.php:867-875` (`restoreBatchStock()`) omits `variantId` entirely, while the **outbound mirror** does pass it (`DeliveryNoteService.php:348-355`, `variantId: $line->variant_id`). So on a variant-bearing batch-tracked product the delivery consumes variant-scoped lots, the restore looks for product-level ones (`whereNull('pb.variant_id')`, `FEFOInventoryService.php:487-491`), finds none, falls to the `DEFAULT` arm — and **throws**.

Measured, sqlite, the lane's own fixture product plus one active `ProductVariant`:

```
at c06c8c529 : THREW App\Shared\Domain\Exceptions\MissingVariantException
               "Product '…' has active variants; batches must be variant-scoped — a variant_id is required."
at 50cc9fe66 : return note confirmed OK
```

The throw is inside the confirm transaction, so the operator cannot process the return **at all** — a new refusal on a path that worked at r3 and on dev.

Not merge-blocking on its own: read-only psql on the wave-4 tenant shows **7** batch-tracked products and **0** with active variants, so it is unreachable today. It arms itself the moment anyone adds a variant to a batch-tracked product — and in a parapharmacy vertical *every* product is batch-tracked, so that is one ordinary catalogue edit away. Refusing rather than minting a forbidden product-level lot is the right instinct; the missing half is threading `$line->variant_id` through `ReturnNoteService::restoreBatchStock()`, which also restores symmetry with `DeliveryNoteService`. (The `recordReturn()` product-level aggregate credit stays a named residual either way — it does not cause the throw.)

## [IMPORTANT] R5-5 — R4-6 answered: "no decomposition" is an acceptable P1 residual for tenant #1, but it is an inventory-truth hole, not merely a lot-ledger one

Confirmed by execution (`test_returning_a_combo_leaves_the_leaf_undecomposed_but_never_drifts_the_lot_ledger`, PG, green): a composite `pos_receipt_lines` row carries `product_id = NULL`, so `restoreStock()` returns `null` and neither the leaf's aggregate nor its lots move. Leaf aggregate stays 9.0000, leaf lot stays 9.0000 — **in sync, no drift, no phantom**, exactly as the handback claims. The lane's sale-side W4-5 change did **not** introduce lot-vs-aggregate drift here.

**Acceptability for tenant #1: yes, P1 residual, not merge-blocking.** Read-only psql on the wave-4 tenant: `composite_items` = **0** and `pos_receipt_lines` with a NULL `product_id` = **0**, so the path is unreachable there today.

But state it accurately in the LEDGER: this is not "the lots are not restored", it is "**a returned combo never comes back into stock at all**" — the aggregate is missing the units too. That half predates this lane; the lane merely extends the same silence to the lot ledger. It must be closed before the first tenant sells a combo, and the fix has to decompose the aggregate credit, the GL and the disposition together.

Also note the test is a **characterisation** test: it stays green under the RED-PROOF-2 tamper that disables the whole new POS restore call, so it pins today's behaviour and binds nothing. That is the honest choice given what it documents — but it must not be counted as coverage of the POS restore arm.

---

## MINOR

**R5-6** — `FEFOInventoryService.php:353-360`: the `restoreBatchesForReturn()` docblock still describes the **r3** algorithm — *"Sum every `inventory_batch_movements` leg for this product + location, per lot. A lot whose legs net NEGATIVE …"* — which is precisely the rule R4-1 proved wrong, and it still points at `POS\…\ReceiptReturnService::restoreBatchAllocations()`, **deleted in this same commit**. `ReturnNoteService.php:844-852` repeats the same stale description. R4-11 was raised because a false comment hid a Critical for a whole round; this is the same comment, one method up.

**R5-7** — `ReceiptReturnService.php:30`: `use App\Modules\POS\Domain\ReceiptLineBatchAllocation;` is now unused (the only remaining mention is inside a comment at `:504`). Pint and PHPStan both pass, so nothing catches it. Delete it — or, better, it stops being dead the moment R5-1 is fixed the way suggested.

**R5-8** — `ReceiptStockPolicyTest.php:546-570` (`seedLeafLot()`) still creates lots with `Batch::create()` + `BatchStock::create()` and **no ledger leg** — the exact shape R4-11 corrected in the sibling file. Under the new outstanding-outbound ceiling an inbound leg is genuinely irrelevant (only negative legs and return-credit positives are counted), so this hides nothing **today**; it is a consistency debt that will mislead the next reader of the two files side by side.

**R5-9** — `FEFOInventoryService.php:474-476`: `MAX(CASE WHEN ibm.quantity < 0 THEN sm.created_at END) DESC` **ties** whenever one sale spilled across several lots, because all of that sale's legs share a single `movement_id` and therefore one `created_at`. The credit order among tied lots is then whatever the driver returns. Moot for a full return (all tied lots get credited), visible on a partial one. Add `, ibm.batch_id DESC` as a deterministic tiebreak.

**R5-10** — carried from r4, unchanged and re-verified: `tests/Unit/POS/ReceiptReturnServiceTest` is **11 errors** (`ArgumentCountError` on `ReceiptReturnService::__construct()`) at HEAD **and** with both files taken from `dev` — inherited, not deepened by the added constructor parameter. It still deserves a LEDGER row: two of the dead tests were the POS lot-restore pins, so that channel's coverage now rests entirely on this lane's two new PG tests, and neither of those two discriminates lot provenance (R5-1).

---

# Answers to the gate's questions

**1 — R4-1 outstanding-outbound ceiling.** **CLOSED.** `FEFOInventoryService.php:466-476` computes `-SUM(CASE WHEN ibm.quantity < 0 THEN ibm.quantity WHEN sm.reason IN (?,?) THEN ibm.quantity ELSE 0 END)` with `MovementReason::CustomerReturn` / `POSReturn`, driver-agnostic `CASE WHEN` as asked, `HAVING … > 0`, `sm.company_id` scoping (R4-9), and outbound-only ordering (R4-8). Binding placement is correct — `select()` clears `bindings['select']` before `addBinding(…, 'select')` is called, and having/select bindings are emitted by type, not by chain order. *Fixtures:* `seedLot()` now mints via `findOrCreateBatch` → real receipt `StockMovement` → `receiveBatchStock(movementId:)`; **grep of `BatchTrackedSalesOrderConfirmFefoTest` finds zero legless lot creation**, and the R4-1 test asserts its own "2 positive legs" precondition. (`ReceiptStockPolicyTest::seedLeafLot` is still legless — R5-8, harmless under the new ceiling.) *The gate's probe, run through the production path on PG:* origin lot **18 → 13 → 18**, `Σ lots == stock_levels == 30`, **no DEFAULT**, leg census exactly 4 with one `+5.0000 customer_return` credit. *Anti-double-credit:* green, and it is real — the second return's 5 lands on `DEFAULT` and `Σ lots` still reconciles. *Ordering (R4-8):* green and red-proofed. All four go red under the r3 revert.

**2 — R4-4 / R4-5 / R4-7 / R4-6.**
*R4-4:* the guard is present at `FEFOInventoryService.php:604-619` and **does** refuse — I probed it. But its document-channel caller never threads the variant, so the refusal fires on an ordinary return instead of on a forbidden mint (**R5-2**).
*R4-5:* closed — the sqlite leg now runs **30** tests where r4 ran 19, and `--testdox` confirms the three new restore tests execute there.
*R4-7:* structurally closed — one Domain service, two call sites, snapshot restore deleted, and the DPA key removal is **legitimate** (the scanner reports zero keys from this lane, no stale-key error, and the only unbaselined keys are dev's two inherited `GeneralLedgerService` deletes). **But the convergence went the wrong way** (**R5-1**).
*R4-6:* confirmed by execution — leaf aggregate **and** leaf lots both stay decremented, so no drift. **Acceptable for tenant #1** (zero composite items, zero NULL-product receipt lines on the wave-4 tenant) — a **P1 residual**, restated as "a returned combo never re-enters stock at all", which is broader than the lot ledger (**R5-5**).

**3 — Manifest / deptrac / blobs / tree.** Manifest union **WRONG** — `Document` **84**, `Inventory` 114, `Company` 31, `gated_ceiling` **1173** against dev `c97a735ad`, verified by running the checker on the merged tree both ways (**R5-3**). deptrac **PASS, 183/183** on the merged tree. Blob list **unchanged** — same 7 commits, `81e058d0d`, 55,666,691 B. HEAD's tree carries **no** `.deptrac.cache`. No migrations in the lane.

**4 — Red-proofs and PG leg counts.** Two independent tampers, each in a throwaway worktree: reverting the ceiling reddens **4** tests; disabling the POS restore call reddens **1**. PG leg census for the document-channel probe: **4** legs (`+18` GRN, `+12` GRN, `−5` delivery, `+5` customer_return), one credit leg per return, keyed to the return's own receipt movement.

---

## Merge instruction for the parent (once R5-1 and R5-3 are closed)

```
git merge --squash fix/campaign-w27-phantom-default-batch      # --squash is MANDATORY (R5-4)
```

Resolve `apps/api/tests/feature-lane-manifest.json` to:

```
"gated_ceiling": 1173
"groups"."Document"."classes": 84     # dev's W2-6 82->83 note VERBATIM + append this lane's 83 -> 84
"groups"."Inventory"."classes": 114
"groups"."Company"."classes": 31      # dev verbatim
```

then re-run `php apps/api/tools/feature-lane-manifest-check.php` on the merged tree and require **EXIT=0**. If `dev` moves again before the squash lands, **re-derive** — these are merge-time values.

## What to fix before merge

**R5-1** — restore the POS channel's per-line lot provenance (pass the `ReceiptLineBatchAllocation` rows into `restoreBatchesForReturn()` as a capped origin hint) and re-pin with two lots / two receipts; **R5-3** — re-merge `dev` at merge time and set `Document` = **84**, `gated_ceiling` = **1173**; **R5-4** — `git merge --squash`. Then **R5-2** (thread `$line->variant_id` through `ReturnNoteService::restoreBatchStock()`), **R5-5** (LEDGER row, restated), and the one-liners R5-6..R5-10 in the same round.
