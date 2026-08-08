# F-7 FEFO quantity-precision — residuals and merge disclosures

> **Source:** fix lane `fix/f7-fefo-quantity-precision` (worktree `apps/erp.fix-f7-fefo`), closing
> [`docs/superpowers/audits/2026-08-07-openapi-lane-codebase-findings.md`](../audits/2026-08-07-openapi-lane-codebase-findings.md) §F-7.
> **Gates:** inventory-costing reviewer → APPROVE-WITH-FIXES; fiscal-pos reviewer → APPROVE-WITH-FIXES (no criticals). All required fixes applied on-branch.
> **Status:** residuals below are NOT fixed in this lane. Each needs its own lane.

---

## Merge disclosures (carry into the merge record)

### D-1 — Input validation TIGHTENED on a Sanctum-reachable endpoint (unversioned)

`GET /api/v1/pos/products/{productId}/batches` previously validated `quantity` as bare
`numeric`. It now also requires `regex:/^\d{1,11}(\.\d{1,4})?$/`. Inputs that used to
return 200 and now return **422**:

| Input | Previously | Now |
|---|---|---|
| `".5"` | accepted | 422 (leading digit required) |
| `"1e2"` / `"1E2"` | accepted (scientific notation) | 422 |
| `"+1.5"` | accepted | 422 |
| `"1.25055"` (scale 5+) | accepted, silently float-truncated | 422 |
| `"123456789012"` (>11 integer digits) | accepted | 422 |

This is the intended rule-19 contract and **zero known callers are affected** (see the
consumer audit in §F-7 of the findings register: the POS device has no consumer, and the
only web caller now sends canonical 4dp strings). It is nonetheless an unversioned
tightening of a token-reachable endpoint — flagged deliberately, not silently.

### D-2 — B2B document composition can change on batch-tracked conversions

`FEFOInventoryService::suggestBatchesForSale()` feeds three document converters
(`SalesOrderToInvoiceConverter:548`, `SalesOrderToDeliveryNoteConverter:277` and `:408`).
Under the float pipeline a residual remainder of ~1e-16 could survive the loop and
persist a spurious near-zero DocumentLine (rendering as `0.0000`) on delivery-note and
invoice conversion for batch-tracked products. Post-fix those lines no longer appear, so
**line counts and batch splits can legitimately differ** from pre-fix output for the same
source order.

The change is in the correct direction (the spurious lines were the defect). All 34
conversion/reservation tests are green. The **device fiscal chain and signed bytes are
untouched** — this pipeline is server-side document composition only and does not
participate in receipt hashing.

---

## Residuals (each needs its own lane)

### (a) `BatchStockService` guard path still compares availability via the float accessor — **highest value**

`apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php`

- `:230` — `$available = $batchStock !== null ? (string) $batchStock->available_quantity : '0.0000';`
- `:274-275` — `bccomp((string) $sourceBatchStock->available_quantity, $quantity, 4)` and the
  `$available` string built the same way in the transfer guard.

Both stringify `BatchStock::getAvailableQuantityAttribute()`, which is a **float** accessor
(`BatchStock.php:44`, `return $this->quantity - $this->reserved_quantity;`). This is the same
F-7 pattern, but on the **issue/transfer guard path — the path that actually moves stock**,
not the read-only suggestion path this lane fixed. A float that stringifies to scientific
notation (values < 1e-4) would make `bccomp` throw; ordinary values can compare off-by-epsilon
at the sufficiency boundary.

Fix shape: read `getRawOriginal('available_quantity')` as F-7 does, or give `BatchStock` a
`numeric-string` availability accessor and migrate both call sites.

### (b) The load-bearing `getRawOriginal()` line has no discriminating test coverage

`FEFOInventoryService.php` reads availability via `getRawOriginal('available_quantity')`
specifically to bypass the float accessor. **No test in this lane proves that choice matters**,
because SQLite returns generated decimal columns as doubles either way — the raw value and the
accessor agree under the test DB. Verified: **Postgres returns exact decimal strings**, so the
distinction is only observable there.

Owed: a Postgres-run integration test that pins a value where the float accessor and the raw
column diverge. Until then the line is correct but unguarded against regression.

Related coverage caveat: `test_large_quantity_keeps_sub_unit_digits` discriminates on **type
only**, not on magnitude — `99999999.1234` is 12 significant digits, comfortably inside float's
~15-digit budget, so it would survive a float round-trip. It proves strings come out; it does
not prove float would have failed.

Second untested-by-design line: the `preg_match` guard that rejects a blank/non-decimal
`available_quantity` is **deliberately uncovered**. It has no reachable trigger today —
`available_quantity` is a GENERATED column (SQLite and Postgres both refuse `UPDATE`s to it,
verified) and the explicit `->select('inventory_batch_stock.*')` guarantees it is projected.
The guard exists so a future `->select()` change fails loudly instead of silently zeroing every
lot's availability. Noted here rather than covered by an artificial test.

### (c) `Batch` float accessors feed `BatchResource` across 10 endpoints — the F-7 scope extension

`apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:127-135` —
`getTotalQuantityAttribute()` (`:127`) / `getAvailableQuantityAttribute()` (`:132`) are float aggregates
(`(float) $this->batchStock()->sum(...)`), surfaced by `BatchResource` on
`/v1/batches` (list/show/create/patch), `/expired`, `/expiring`, `/recall`, `/transfer`,
`/write-off` and `/v1/products/{id}/batch-stock`.

This is the scope extension recorded in findings §F-7 (2026-08-07, `fe0c9f33e`). It needs its
**own consumer audit** before flipping number→string: unlike the FEFO endpoint, these have real
web consumers, and `apps/web/src/features/batches/types.ts` currently documents
`ExpiredBatch.total_quantity` / `.available_quantity` as JSON numbers **on purpose**.

### (d) `getTotalAvailableQuantity()` returns float — zero production callers

`FEFOInventoryService.php:415` (`getTotalAvailableQuantity`) — `return (float) $query->sum('inventory_batch_stock.available_quantity');`.
Only caller is `tests/Unit/BatchExpiry/FEFOInventoryServiceTest.php`. Left alone to bound this
lane's diff; fold into (c).

### (e) SQLite-only landmine in the test environment (production is safe)

SQLite returns generated decimal columns as **floats**. A value below `1e-4` stringifies to
scientific notation (`1.0E-5`), which `bcadd`/`QuantityScale::round` reject with a `ValueError`.
Postgres never emits scientific notation for `decimal(15,4)`, so **production is unaffected** —
but a future SQLite-backed test using sub-`0.0001` batch stock will fail with a confusing
`ValueError` rather than an assertion. Note it before someone burns an hour on it.

### (f) FEFO tie-breaking on equal `expiry_date` is unpinned (pre-existing)

`suggestBatchesForSale()` orders by `product_batches.expiry_date ASC` only. Two lots sharing an
expiry date are returned in **DB-arbitrary order**, so which lot is drawn first is not
deterministic. `consumeBatchesAtomically()` already tie-breaks with `, b.created_at ASC` — the
suggestion path does not, so suggestion and consumption can disagree on which lot goes first.
Pre-existing; not introduced by F-7.

### (g) `expiry_status` casing is inconsistent between batch endpoints

`BatchResource.php:37` emits `strtoupper($this->expiryStatus()->value)` (`"WARNING"`), but
`BatchSuggestionDTO::toArray()` emits the raw enum value (`"warning"`). The web
`ExpiryStatus` type is uppercase and is therefore **wrong for the FEFO endpoint only**; this
lane declared a separate `FEFOExpiryStatus` lowercase union rather than widen scope.
Worth unifying when (c) is done.

---

## Merge record (appended by orchestrator, 2026-08-08)

Merged LOCAL dev ff→`d338b5fa4` (rebased clean over DPA Wave-0 `984a020dd`; core precision
tests re-run green post-rebase 25/91). Dual adversarial gate: inventory-costing
APPROVE-WITH-FIXES + fiscal-pos APPROVE-WITH-FIXES, ZERO criticals; consolidated fix round
applied (6 commits) and gate items verified. Orchestrator ratifies the implementer's FLOOR
deviation on the availability-side boundary (rounding stock-on-hand HALF_UP could suggest
a draw larger than the lot holds; FLOOR is strictly conservative; no-op today under
decimal(15,4) inputs). Disclosures D-1 (validation tightening, pinned by DataProvider
tests) and D-2 (spurious 0.0000 DN/invoice lines no longer produced) stand as the
behavior-change record. Promotion to origin/dev pending the owner's batch go.
