# W4R-2 gate r1 — fiscal-pos lens (live POS projection lot legs)

**Lane:** `fix/campaign-w4r2-live-pos-projection-lots` — HEAD `23ba417b1`
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/w4r2-live-projection-lots`
**Lens:** fiscal-event projection contract + sealed payload (inventory/FEFO/WAC reviewed in parallel by the inventory lens)
**Method:** read + BY EXECUTION on throwaway PostgreSQL 16 `autoerp_test_w4r2f` (127.0.0.1:5433), tamper worktree at the lane HEAD (lane never modified), one test process at a time, never the full suite.

**Throwaway DB `autoerp_test_w4r2f` dropped and the tamper worktree removed after the run.**

## Execution baseline

Lane suite reproduced on the throwaway PG DB, from a detached copy of HEAD `23ba417b1`:

```
tests/Feature/Fiscal/PosCoreReceiptProjectionBatchLotTest.php → 8 passed (39 assertions), 15.50s
```

## Probe results (tamper worktree, PostgreSQL)

| Probe | Setup | Observed |
|---|---|---|
| **P1** real-code exception in the refund lot arm | batch-tracked product WITH one active `ProductVariant`, product-level (`variant_id = null`) sale line, no lots seeded → sale shortfalls, no allocation snapshot, no lot legs (= every pre-lane historical sale). Then REFUND `disposition=restock`. | `MissingVariantException` escapes `apply()`; **refund row absent** (`pos_receipts.fiscal_event_id` = refund → NO); stock stays `6.0000` (only the sale's decrement). The sealed refund is REJECTED. |
| **P1 red-proof** same probe with this lane's `apps/api/app` diff `git apply -R` | identical fixture | `thrown=NONE`, `receipts=2`, refund row present, stock back to `10.0000`. **The rejection is introduced by this lane.** Diff re-applied afterwards; probe reproduces the failure again. |
| **P2** generic throw from `FEFOInventoryService::consumeBatchesAtomically` (`\LogicException`) | batch-tracked sale, lots present | exception escapes `apply()`; `pos_receipts=0`, `stock_movements=0`, stock `10.0000`. Transaction boundary is CORRECT (all-or-nothing, no movement-without-legs), but **containment is not implemented** — only the shortfall arm is contained. |
| **P3** out-of-order (refund applied before its sale ever projected) | v4 REFUND referencing an unprojected sale event | `OriginalReceiptUnresolvableException` (retryable `ProjectionDependencyMissingException`), `pos_receipts=0`, `pos_receipt_lines=0`, `stock_movements=0`. Defined, clean, no crash. ✅ |
| **P4** composite/Menu id (`<uuid>_<uuid>`) in the sealed payload | batch-tracked product, `product_id = "<uuid>_<uuid>"` | `Str::isUuid=NO`, no throw, receipt + line written, `stock_movements=0`, `inventory_batch_movements=0`. W4R-3 root cause CONFIRMED as pre-existing (`Str::isUuid` guard at `PosCoreReceiptProjection.php:1933`, untouched by this lane) — **not a new hole**. ✅ |
## Other executed gates (tamper worktree at HEAD, PostgreSQL)

| Gate | Result |
|---|---|
| `tests/Feature/Fiscal/ChokepointCompletenessTest.php` | **7 passed, 109 assertions** — the `ReceiptCreationService` / `ExchangeService` carve-outs still reconcile after the quarantine annotation (comment-only change; manifest anchors are line_anchor text, not line numbers). |
| `tools/deptrac-ratchet.php` | **PASS — TOTAL 183/183, no boundary regression.** POS→BatchExpiry imports already had precedent (`ReceiptCreationService`, `ReceiptReturnService`, `POS/Domain/ReceiptLineBatchAllocation`). |
| `tools/feature-lane-manifest-check.php` at HEAD | **OK, EXIT 0** — 1439 Feature classes, gated 1186 vs ceiling 1186. See F-3: stale against today's dev tip. |
| Regression `PosCoreReceiptProjectionRefundDispositionStockTest` | **16 passed, 77 assertions** on PG — disposition arms (scrap / not_received / never-restock) unaffected. |
| Red-proof A — `lotProvenanceForOriginalLine()` neutered to `return []` | `test_refund_credits_the_lot_the_sale_actually_took` **FAILS** at :310 (short-dated lot not credited). |
| Red-proof B — allocation snapshot write skipped | `test_batch_tracked_sale_draws_fefo_lots_and_snapshots_allocations` **FAILS** at :160. |
| New `onQueue(...)` in the diff | **none** (`git diff dev...HEAD -- apps/api/app \| grep '^+' \| grep onQueue` empty) → `HorizonQueueCoverageTest` unaffected. |
| Money/precision in the new code | **no money math at all** — no `getScale()`, no `bcformat`, no `(float)`; only quantity bcmath at scale 4/8 with `precision-ok` markers. Rule 19 clean. |
| Rule 20 CompanyContext | new arms read tenant/company off `$event` only; every probe above ran with `app(CompanyContext::class)->clear()` and none of the new code needed it. ✅ |
| Rule 8 (events immutable) | no Event class renamed/restructured/deleted; no new refund event type invented — REFUND still a `SALE_RECEIPT` with `invoice_type_code`. ✅ |
| Idempotency anchor coverage | both lot arms are downstream of the `INSERT … ON CONFLICT DO NOTHING` anchor (`PosCoreReceiptProjection.php:460-465` returns before `writeLines`/`applyStockMovementForLines`), so they inherit exactly-once. Executed: replay tests green. ✅ |

## Findings

### F-1 [CRITICAL — merge-blocking] The new refund lot arm can REJECT an already-signed refund event

`apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:2477` `restoreLotsForRefundLine()` calls
`FEFOInventoryService::restoreBatchesForReturn()` at `:2501` with no try/catch. That service falls through to
`apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:466` → `defaultBatchId()`, which **throws**:

```php
// FEFOInventoryService.php:673-678
if ($variantId === null) {
    $activeVariants = $this->variantLookup->listForProduct($productId, true);
    if ($activeVariants->isNotEmpty()) {
        throw MissingVariantException::forProduct($productId);
    }
}
```

The exception escapes `apply()`, `apply()`'s `DB::transaction` (`:252`) rolls the whole projection back, and the job
fails. `MissingVariantException` is **deterministic**, so every Horizon retry fails identically → the signed refund
dead-letters: no `pos_receipts` row, no refund lines, no VAT rows, no GL, no drawer movement, for money already
handed back at the till.

Reachability is ordinary, not exotic: a batch-tracked product that has active variants, refunded on a
product-level line (`variant_id = null` in the sealed payload) whose sale left no lot provenance — i.e. **exactly the
pre-lane historical sales the handback §6 census counts (27 units, zero allocation rows)**, and any product that gained
variants after the sale.

Proved by execution (PG, `autoerp_test_w4r2f`):

```
P1 thrown = App\Shared\Domain\Exceptions\MissingVariantException: Product '832b70a7…' has active variants;
            batches must be variant-scoped — a variant_id is required.
P1 receipts = 1 (the sale only)   P1 refund_row = NO   P1 stock = 6.0000
```

Red-proof (`git apply -R` of this lane's `apps/api/app` diff, same fixture, then re-applied):

```
P1 thrown = NONE   P1 receipts = 2   P1 refund_row = YES   P1 stock = 10.0000
```

**This lane converts a refund that previously projected cleanly into a permanently rejected one.** That is the exact
doctrine the lane states for itself at `:2455-2470` ("a projector may never REJECT an already-signed event") — the
doctrine was implemented for the STRICT-fulfilment shortfall and for nothing else.

*Why it matters:* device is fiscal source of truth; the server projection may only reflect. A dead-lettered refund
means the server ledger permanently disagrees with the sealed device chain, and the refund's VAT and cash leg are
missing from Z/NF525 reporting.

*Fix:* wrap the refund lot arm (and the sale lot arm, F-2) in its own `DB::transaction` savepoint + `catch (\Throwable)`
using the idiom already in this file at `:2711`: rethrow when `ConcurrencyFault::isRetryable($e)` (infrastructure,
retry is correct), otherwise `Log::error` and let the receipt project. The savepoint is load-bearing here because
`restoreBatchesForReturn()` writes `creditLot()` legs *before* it can throw (`FEFOInventoryService.php:440`,`:455`
then `:464-470`) — a bare catch would commit a half-credited lot ledger.

### F-2 [IMPORTANT] Only a SHORTFALL is contained; any other exception from the lot arms rejects the sealed receipt

Same root as F-1, on the SALE side. `consumeLotsForSaleLine()` (`:2015`) guards `is_numeric` and passes
`strictFulfillment: false`, but nothing else. Any `QueryException`, lock timeout, FK violation on
`pos_receipt_line_batch_allocations`, or future throw inside FEFO takes the whole sealed sale down with it.

Executed with a `\LogicException` injected into `consumeBatchesAtomically`:

```
P2 thrown = LogicException: lot arm blew up
P2 receipts = 0   P2 stock_movements = 0   P2 stock = 10.0000
```

The transaction boundary is **correct** — all-or-nothing, no movement-without-legs and no legs-without-movement — so
this is a containment gap, not a partial-state bug. But the receipt, its payments and its GL are lost to an inventory
sub-act, which the lane's own docblock says must never happen.

*Fix:* as F-1. Note that a retryable concurrency fault must still rethrow (the `ConcurrencyFault::isRetryable` branch),
otherwise a genuine deadlock silently drops the lot legs.

### F-3 [IMPORTANT] `gated_ceiling` and the ci.yml allowlist are stale against today's dev tip

Local `dev` is now `06806d62c` (test-infra inherited-reds merge), not the `94f29795c` this lane merged.

* dev manifest: `gated_ceiling = 1186`, `Fiscal.classes = 81`; dev working tree measures **1186 gated classes — zero slack**.
* lane manifest: `gated_ceiling = 1186`, `Fiscal.classes = 82`.

Both sides wrote the same value `1186`, so git will merge that line **without a conflict** and the post-merge tree will
hold **at least 1187** gated classes against a 1186 ceiling → `feature-lane-manifest-check.php` RED on the merge commit.
Resolve to **1187** (dev's current 1186 + this lane's one Fiscal class) after re-fetching dev, exactly as the lane's own
note instructs. Fiscal `classes: 82` is correct as long as dev's Fiscal group is still 81 at merge — re-take it too.

Related, non-blocking: dev's test-infra merge appended ~16 classes to the same `backend-test-pgsql --filter` line this
lane edits (lane `.github/workflows/ci.yml:1025`, dev `:1048`). That single line WILL conflict; the resolution must be the **union**
(dev's 16 + `PosCoreReceiptProjectionBatchLotTest`), not either side wholesale. The `--filter` append itself is
legitimate and precedented (D-1, Q-6 r2), and is the only place 7 of the 8 new tests can execute while
`feature-lane-fiscal-finance` is parked — the manifest checker confirms the entry is anchored and uniquely matched.

### F-4 [MINOR] The new test class advertises a contract it does not pin (and that the lane proves false)

`apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionBatchLotTest.php:59` lists as pinned contract
"(h) a composite line explodes to its recipe leaves and moves leaf lots". There is **no such test**, and handback §8 /
`PosCoreReceiptProjection.php:1888-1903` establish the opposite: a composite line moves no stock at all on this path.
A future reader grepping this class for composite coverage will be misled. Delete item (h) or restate it as the W4R-3
residual.

### F-5 [MINOR / owner ruling] Lot shortfall is logged, never detected

`:2079-2088` logs a structured warning and leaves `Σ lots > stock_levels` by the shortfall. Correct per doctrine
(better than rejecting), but nothing surfaces it — no counter, no alert, no report row. Combined with the 27 unrepaired
historical units (handback §6, forward-only fix, no repair command for this direction), the first tenant will carry
silent lot drift. Recommend the sibling repair lane the handback names, plus a drift detector, before onboarding.

### F-6 [MINOR] Provenance netting counts refunds that credited no lot

`lotProvenanceForOriginalLine()` (`:2537`) computes `alreadyReturned` as `SUM(ABS(quantity))` over every
`pos_receipt_lines` row pointing at the original line, with **no disposition filter** — so an earlier
`scrap` / `not_received` refund (which deliberately credits NO lot) still dilutes the provenance hint of a later
`restock` refund. The hint is a hint (the service caps and then falls back to the heuristic), so this can only
under-attribute, and the same shape exists in `ReceiptReturnService::lotProvenanceForLine()` — so it is **not a
regression**, but it is a real mis-attribution path on multi-lot lines. Worth a follow-up.

## Verdict

**VERDICT: spec ❌ + quality CHANGES-REQUESTED — merge-blocking: YES (F-1).**

The mechanism is right and the evidence is unusually honest: the lot legs are correctly keyed to the aggregate
movement, sit downstream of the `ON CONFLICT` idempotency anchor, credit the provenance lot on a refund, credit
nothing on scrap, leave the sealed bytes and chain hash byte-identical, and the two red-proofs show the tests really
bite. What is missing is the other half of the lane's own doctrine: the projector must not reject a signed event, and
today it does — on refunds, deterministically, for exactly the historical shapes the lane exists to fix.
