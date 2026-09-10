# Gate r1 — W-LOT-A-1a (inventory-costing-reviewer, 2026-09-10)

**Audited HEAD:** `04e60530c` on `lane/w-lot-a-1a`, merge base `4373ba2f6`, worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/w-lot-a-1a`. Read-only. 66 files, +4065/−595.

**Static gates (run from the worktree's `apps/api`):**
- `./vendor/bin/phpstan analyse --no-progress <29 changed app/*.php>` (project `phpstan.neon`, level 8, larastan + baseline + `ForbidHardcodedBcmathScale` / `ForbidFixedScaleQuantityLiteralRule` / `ForbidQuantityScaleConstantInPresentationRule`) → **`[OK] No errors`**.
- `./vendor/bin/pint --test <48 changed php files>` → **`{"result":"pass"}`**.

**SQLite runs, one file at a time (`DB_CONNECTION=sqlite DB_DATABASE=:memory:`, default `phpunit.xml`):**

| File | Result |
|---|---|
| `tests/Architecture/BatchTraceabilityModuleBoundaryTest.php` | **OK (1 test, 1 assertion)** — 0.013s |
| `tests/Feature/BatchExpiry/BatchActionPermissionsTest.php` | **OK (6 tests, 22 assertions)** — 7.8s |
| `tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php` (PG-lane per §13; run anyway) | **OK (5 tests, 37 assertions)** — 6.7s |
| `tests/Feature/BatchExpiry/BatchExpiringLocationScopeTest.php` (PG-lane per §13) | **OK (2 tests, 6 assertions)** — 4.6s |
| `tests/Feature/BatchExpiry/BatchTraceReaderContractTest.php` (PG-lane per §13) | **OK (3 tests, 23 assertions)** — 5.3s |

No PostgreSQL was started. 17 tests / 89 assertions green.

---

## BLOCKER

None. No production write path to `inventory_batch_stock`, `stock_levels`, `stock_reservations`, `document_lines`, `pos_receipt_line_batch_allocations`, `journal_entries` or `journal_lines` is added or altered; no WAC arithmetic, allocation, or FEFO ranking is touched; no float is introduced on any money or quantity.

---

## MAJOR

### I-1 — Restricted actors receive **company-wide** on-hand back through every mutation response, contradicting the §2 scope guarantee
`apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:229` (`update`), `:268` (`recall`), `:464` (`transfer`), `:503` (`writeOff`) all still call `$result->refresh()->load(['product', 'batchStock'])` / `load(['product', 'batchStock.location'])` — **unfiltered**. `BatchResource` then sums that unfiltered relation at `apps/api/app/Modules/BatchExpiry/Presentation/Resources/BatchResource.php:68-90` and emits every location's row at `:56-61`.

*Not found by the tenancy reviewer.* Failure scenario, post-activation: a branch manager restricted to location B1 posts `POST /api/v1/batches/{uuid}/write-off` for B1 (this route intentionally carries no `BatchActionAccess` middleware per §6.4, and is authorized by the existing `WriteOffBatchRequest`). The 200 body returns `total_quantity`/`available_quantity` summed across B1 **and** B2, plus a `batch_stock[]` entry naming B2's `location_id` and on-hand. The immediately-following `GET /api/v1/batches/{uuid}` for the same lot returns the B1-only figure (`BatchRepository.php:29-34`). Two different on-hand numbers for one lot in one operator session, and the larger one is exactly the cross-branch stock §2 says a restricted user must not see ("Restricted users see only current or historical lots attributable to allowed locations"). This is a *response-body* defect, not an authorization change, so fixing it does not touch §6.4's "authorization remains unchanged" nor §15's deferred eligibility.

**Minimum correction:** in those four methods replace the unfiltered load with the same scoped closure `BatchRepository::findVisibleByUuid` already uses (`apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php:33`), driven by `$this->resolvedReadLocationIds($request)` (`BatchController.php:56`) — i.e. `$result->load(['product', 'batchStock' => fn ($s) => $locations === null ? $s : $s->whereIn('location_id', $locations)])`. Add one case to `BatchReadLocationScopeTest` asserting a restricted write-off/update response carries only the allowed `location_id` and the branch-scoped total.

### I-2 — Push 3 is **not inert** while the flag is off on `/batches/expiring` and `/batches/expired` (§6.1 violation), confirming the tenancy reviewer's B-2 from the costing side
Two independent, unconditional (never flag-gated) changes:

1. `apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:859-863` — the `batchStock` eager load is now filtered by `$locationId` **whenever `$locationId !== null`**, with no `enforced()` guard. Pre-diff the load was `->with(['product.unitOfMeasure', 'batchStock'])`, unfiltered.
2. `BatchResource.php:40-41` — batch-level totals switch from the company-wide accessors `Batch::getTotalQuantityAttribute()` / `getAvailableQuantityAttribute()` (`apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:143-151`, each a fresh `batchStock()->sum(...)` on the whole company) to a sum over **only the loaded** relation, again with no `enforced()` guard.

The concretely reachable flag-off change is **`/batches/expired`**, not `/expiring`: `getExpiredBatchesWithStock` already filtered its eager load before this lane (`FEFOInventoryService.php:903-911`, pre-existing), and the pharmacy expiry write-off screen already sends the filter — `apps/web/src/features/batches/api/batches.ts:167-172` passes `location_ids`. So the moment Push 3 lands with `LOT_ACTION_PERMISSIONS_ENFORCE=false`, `apps/web/src/features/batches/pages/ExpiryWriteOffPage.tsx:215` ("On hand") and `:229` ("Available") flip from company-wide to selected-location, and the per-lot `max` at `:245` / the validity check at `:155` tighten to the selected location. `/batches/expiring?location_id=` narrows the same way but is not reachable from the web today (`batches.ts:111` sends only `days`).

The *direction* is a correctness improvement — the grouped write-off debits a specific location, so a company-wide `max` was over-permissive and would have produced a service-level 422 — but it is a behaviour change in a window §2 declares inert, and **nothing tests it**. `BatchActionPermissionsTest::test_flag_off_preserves_pre_activation_access_and_payloads` (`apps/api/tests/Feature/BatchExpiry/BatchActionPermissionsTest.php:41-47`) despite its name asserts only that the middleware calls `$next` — it makes zero assertion about any payload (this is the tenancy reviewer's M-1; from my lens it is worse than "asserts nothing", it *names* the guarantee it does not check).

**Minimum correction:** keep the behaviour (see the B-2 ruling below) and amend §6.1 to name `/batches/expiring` and `/batches/expired` as intentional non-inert Push-3 changes, **and** replace the vacuous test with a real flag-off contract case: with `enforce=false`, seed one lot with stock at L1 and L2, hit `GET /batches/expired?location_ids[]=L1`, assert `total_quantity`/`available_quantity` and the `batch_stock[]` cardinality against a committed expected shape.

### I-3 — `BatchResource` returns a silent `"0.0000"` on-hand when `batchStock` is not loaded
`BatchResource.php:68-78` and `:80-90` initialise to `bcadd('0','0',4)` and add nothing when `relationLoaded('batchStock')` is false, so an unloaded relation is indistinguishable from a genuinely empty lot.

Today the only reachable path is `BatchController.php:211` (`store()` loads `['product']` only) and, on that path, the value is **factually correct** — `BatchRepository::create` inserts into `product_batches` only, so a brand-new lot has no `inventory_batch_stock` rows. I explicitly refute the reading that `store()` currently returns a wrong quantity. The defect is that correctness there is accidental: any future caller that builds a `BatchResource` from an unloaded model ships a zero on-hand straight into an operator screen with no error, and the write-off `max` bound derives from exactly this field.

**Minimum correction:** make the two accessors fail loud rather than fail zero — either emit via `whenLoaded('batchStock', …)` so an unloaded relation omits the key, or `throw new \LogicException` when `! $this->relationLoaded('batchStock')` — and pair it with the R1 loads in I-1 plus `$batch->load(['product', 'batchStock'])` at `BatchController.php:211`.

### I-4 — POS suggestion has no second-company / second-location / unchanged-output evidence, and its scope check is side-effect-only
§2 guarantees "Batch list, detail, stock, expiry, product-stock, **POS suggestion**, and trace reads honor company and membership-location scope". At `BatchController.php:374-376` the enforcement is:

```php
if ($this->activation->enforced()) {
    $this->resolvedReadLocationIds($request, [$locationId]);
}
```

— the return value is discarded; the call exists purely so `LocationScopeResolver::resolve()` throws (`apps/api/app/Modules/Company/Services/LocationScopeResolver.php:41-43`). I verified that resolver **does** throw `AuthorizationException` on an out-of-scope request, so the endpoint is fail-closed, and `suggestBatchesForSale` is genuinely untouched (the whole `FEFOInventoryService` diff is two hunks, at `:840` and `:922`), so FEFO ordering and suggestion output are byte-identical for unrestricted actors both enforced and not.

But the *only* coverage of this route in the lane is `BatchActionPermissionsTest.php:22`, which asserts the middleware string is attached to the route. There is no test that (a) a restricted actor requesting a foreign branch's `location_id` gets 403, (b) an unrestricted actor's suggestion payload is identical enforced vs. not, (c) a second company's lots never appear. Convention 09 requires second-company + second-location + re-run for a catalogue-keyed lane; `BatchReadLocationScopeTest::test_second_company_selected_second_location_and_duplicate_create_are_isolated` (`:175-221`) delivers that for list/detail/traceability/create-rerun and is genuinely excellent (it even snapshots all seven stock/GL tables at `:216-220`) — POS suggestion and `/partners/{id}/batch-history` are the two §2 surfaces it omits.

**Minimum correction:** two cases in `BatchReadLocationScopeTest` — restricted actor + foreign `location_id` on `/pos/products/{id}/batches` → 403 with the seven-table snapshot unchanged; unrestricted actor's suggestion JSON identical with `enforce` true and false. One case for backward trace under a second company.

### I-5 — Nullable-to-`""` coercion in the trace adapters extends beyond `product_id`
The tenancy reviewer found `productId: (string) $line->product_id` at `apps/api/app/Modules/Document/Infrastructure/BatchTraceability/DocumentBatchTraceReaderAdapter.php:56` (`DocumentLine.php:20` declares `@property string|null $product_id`; pre-diff `BatchTraceabilityController` emitted the raw nullable value). I audited **every** remaining field and found one more of the same class that they missed:

`documentNumber: (string) $line->document->document_number` at `DocumentBatchTraceReaderAdapter.php:25` (forward trace) **and** `:56` (backward trace). `apps/api/app/Modules/Document/Domain/Document.php:53` declares `@property string|null $document_number NULL while DRAFT — see requireDocumentNumber()`, and the adapter's own filter (`:79`) selects on `type IN (Invoice, DeliveryNote)`, **not** on status — so a Draft invoice line carrying a `batch_id` emits `"document_number": ""` where the pre-diff controller emitted `null`. A pharmacy recall trace that renders `""` as a document reference is unusable; `null` at least renders as "—".

Everything else is clean, and I verified it field by field:
- `batchId: (int) $line->batch_id` — safe, the query carries `whereNotNull('batch_id')` at `:79`.
- `productName: $line->description` — `DocumentLine.php:26` `@property string $description`, non-null.
- `documentDate: …->document_date->toJSON()` — `Document.php:54` `@property Carbon $document_date`, non-null; `toJSON()` is byte-identical to the pre-diff raw-Carbon JSON encoding.
- `batchNumber`, `expiryDate`, `partnerId`, `receiptNumber`, `saleDate`, `customerName`, `customerIdentifier` — all declared `?string` on the DTOs and passed through unmodified. Nullability preserved.
- `partnerName: (string) (optional($line->document->partner)->name ?? 'Unknown')` at `:27` — a strict improvement over the pre-diff `$line->document->partner->name ?? 'Unknown'`.

**Minimum correction:** change `BackwardDocumentBatchTraceData::$documentNumber` / `ForwardDocumentBatchTraceData::$documentNumber` / `::$productId` to `?string` and drop the three `(string)` casts. Extend `BatchTraceReaderContractTest::test_forward_and_backward_json_contracts_are_field_for_field_compatible` (`:86-112`) with a Draft-document / null-`product_id` line — the current fixture always produces a numbered document (`:25-26`), so the drift is invisible to it.

---

## MINOR

### I-6 — `getByProduct` deliberately drops the history OR-branch, undocumented
`BatchRepository.php:110` calls `$this->scopeVisible($query, $locationIds)` with no `$history` argument, so `/products/{id}/batch-stock` shows a restricted actor only lots with `quantity > 0` at an allowed location — never a depleted lot reachable through allowed-location history, unlike `getByCompany` (`:150`) and `findVisibleByUuid` (`:32`). For a stock/transfer picker that is the *right* behaviour (you must not offer a zero-stock lot for allocation), and it means the stock-transfer batch picker can no longer build a draft the FEFO check at `apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx:88-100` would have to reject. But it is an unremarked asymmetry against §6.2 and no test pins it. Add a one-line comment and a test case.

### I-7 — `BatchTraceabilityModuleBoundaryTest` guards only one of the two files that consume the readers
`apps/api/tests/Architecture/BatchTraceabilityModuleBoundaryTest.php:13` hard-codes `BatchTraceabilityController.php`. `BatchController` now also injects `DocumentBatchTraceReader` / `PosBatchTraceReader` (constructor at `BatchController.php:46-49`). I confirmed by grep that `BatchController.php` currently has **zero** `use App\Modules\Document\…` / `use App\Modules\POS\…` imports, so the boundary holds today — but the ratchet does not defend it. Widen the `preg_match_all` to glob `app/Modules/BatchExpiry/Presentation/Controllers/*.php`.

### I-8 — the diff produced `/batches/{uuid}/stock` `available_quantity` as a string but left its only consumer on `parseFloat`
`BatchController.php:345` now emits `bcsub($stock->quantity, $stock->reserved_quantity, QuantityScale::SCALE)` instead of the `BatchStock::getAvailableQuantityAttribute()` float accessor (`apps/api/app/Modules/BatchExpiry/Domain/Entities/BatchStock.php:43-46`, which subtracts two `decimal:4`-cast *strings* and returns `float` — a real rule-19 violation this lane removes). The consumer `apps/web/src/features/batches/pages/BatchDetailPage.tsx:279` and `:301` still does `parseFloat(level.available_quantity)`. Pre-existing, still functional on `"6.0000"`, no regression — but the lane touched the producer and left the float consumer, and rule 4 (no scope creep) argues for a ticket rather than an in-lane fix. Note it, don't block on it.

### I-9 — `resolvedReadLocationIds` is invoked twice per `/batches/{uuid}/stock` request
`BatchController.php:331` (via `findVisibleBatchOrFail` → `:90`) and again at `:337`. Two identical `$request->validate()` runs plus two `LocationScopeResolver::resolve()` calls, each of which issues `Location::where('company_id', …)->pluck('id')` (`LocationScopeResolver.php:71-78`). Hoist to a local.

---

## §14 inventory citations

Each bullet §14 requires the inventory reviewer to cite, with the evidence I actually read:

| §14 bullet | Verdict | Evidence |
|---|---|---|
| **Four-decimal scoped totals** | **Present but ungated** | `BatchResource.php:40-41` → `:68-78` / `:80-90`; both accumulate with `bcadd`/`bcsub` at `QuantityScale::SCALE`, seeded from `bcadd('0','0',QuantityScale::SCALE)`, returning `string`. Zero float, zero hard-coded scale literal (`ForbidHardcodedBcmathScale` green). Asserted on the wire at `BatchReadLocationScopeTest.php:132` (`'2.1234'` / `'2.0234'`), `:169`, `:211`, `BatchExpiringLocationScopeTest.php:23`. **Finding I-2/I-3 apply.** |
| **Filtered eager-load totals** | **Correct where enforced; leaks on four mutation paths** | Filtered load in `BatchRepository.php:33` (`findVisibleByUuid`), `:112` (`getByProduct`), `:152` (`getByCompany`), `FEFOInventoryService.php:859-863` (`getExpiringProducts`), `:903-911` (`getExpiredBatchesWithStock`, pre-existing), `:929` (`getBatchStockByLocation`). `BatchResource` performs **no** fresh relationship query — §6.3 satisfied. **Finding I-1** covers the four unfiltered `load()` calls. |
| **Unchanged batch stock** | **Confirmed** | Grepping all `+` lines of the diff for `inventory_batch_stock`/`stock_levels`/`->increment(`/`->decrement(`/`adjustQuantity`/`reserve(` returns **only** test and e2e occurrences (`BatchActionPermissionsTest.php:72-74`, `BatchReadLocationScopeTest.php:116-117,216`, `apps/web/e2e/batch-permissions.spec.ts`). `FEFOInventoryService`'s diff is exactly two hunks (`:840`, `:922`), both read-only. `BatchStockService`, `BatchWriteOffService`, `ReverseWriteOffService`, `GoodsReceiptService`, `StockTransferService`, `LandedCostService`, `WeightedAverageCostService`, `InventoryOpeningService`, `PosCoreReceiptProjection` are **not in the diff at all**. |
| **Unchanged reservations** | **Confirmed** | `stock_reservations` appears only in the two test snapshots above. `reserved_quantity` is read (`BatchResource.php:59-60`, `BatchController.php:344-345`) and written nowhere. `BatchStock::reserve()`/`releaseReservation()` (`BatchStock.php:52-69`) untouched. |
| **Unchanged Document/POS history** | **Confirmed, and now behind a Shared contract** | The two adapters (`DocumentBatchTraceReaderAdapter.php`, `PosBatchTraceReaderAdapter.php`) are read-only: `->get()`, `->pluck()`, `->distinct()`, zero `create`/`update`/`save`/`delete`. Both fail closed on `[]` (`:19-21`, `:35-37`, `:64-66` and `:17-19`, `:33-35`). Wire compatibility pinned field-for-field at `BatchTraceReaderContractTest.php:95-108`, including a flag-on/flag-off equality assertion at `:109-111`. **Finding I-5** is the only drift. |
| **Unchanged journal entries and lines** | **Confirmed** | `journal_entries`/`journal_lines` appear only inside the test snapshot arrays (`BatchActionPermissionsTest.php:73`, `BatchReadLocationScopeTest.php:216`) and the e2e SQL. `InventoryGlPostingViaBufferOnly` PHPStan rule green. `BatchActionPermissionsTest::deniedMutation` (`:64-78`) proves a denied cashier/viewer/manager mutation leaves all seven tables byte-identical, and `BatchReadLocationScopeTest.php:217-220` proves the same for a duplicate-create re-run. |
| **No eligibility or hold behavior** | **Confirmed** | No recall-request/hold table, no `requested`/`released`/`rejected` status, no `batches.recall.request` route (`apps/api/app/Modules/BatchExpiry/Presentation/routes.php` adds only `BatchActionAccess` strings). `Batch::recall()` (`Batch.php:134-141`) is byte-unchanged. No sale/transfer/StockTransfer eligibility predicate is introduced. §15 A-1b/A-1c boundaries respected — and I explicitly do **not** fault the absent hold/eligibility/release/reject work. |

**Precision sweep (rule 19):** grepping every added PHP line for `(float)`, `floatval`, `number_format`, `round(`, `parseFloat` → **zero hits**. Trace quantities stay `numeric-string` end-to-end (`DocumentLine.php:135` `'quantity' => 'decimal:4'`; `ReceiptLineBatchAllocation.php:53` `'quantity' => 'decimal:4'`), typed `string $quantity` on all three DTOs, asserted as `'1.1234'` / `'1.0000'` at `BatchTraceReaderContractTest.php:98,102,106`. No `mixed` in any of the five `app/Shared/Contracts/BatchTraceability/*.php` files; all are `final readonly` with promoted `public readonly` properties. No scale downgrade, no `getScale()` no-arg call in a queue/console-reachable costing path (the only new console command, `ApplyLotActionPermissionDelta`, touches roles, not money).

---

## Ruling input for B-2

**Recommendation: Option B — adopt the 4-dp string totals at Push 3 for everyone (do NOT flag-gate `BatchResource`), with three mandatory riders.**

**Consumer census — what actually reads the batch-level `total_quantity` / `available_quantity` today, and what the float→string flip does to each:**

| Consumer | Reads | Effect of the flip | Status |
|---|---|---|---|
| `apps/web/src/features/batches/pages/BatchListPage.tsx:178,219` | `batch.available_quantity ?? 0` | **Would have thrown** — the pre-diff code was `totalQuantity.toFixed(decimals)`, and `"3.0000".toFixed` is not a function → white screen on the batch list | **Already migrated inside this same diff** to `formatQuantity(totalQuantity, decimals)` |
| `apps/web/src/features/batches/pages/ExpiryWriteOffPage.tsx:129,140,155,215,229,245` (the write-off `max` bound and the on-hand/available columns) | `batch.available_quantity`, `batch.total_quantity` | None at runtime — `formatQuantity` (aliased `toQuantityString` at `:12`) accepts `string \| number` (`apps/web/src/lib/decimal.ts:206`, `typeof amount === 'number' ? new Big(amount) : safeBig(amount)`). The `max` becomes exact and location-scoped instead of float and company-wide | **Safe, and improved** |
| `apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx:80,84` | per-location `batch_stock[].available_quantity` (`BatchResource.php:60`) | `String("6.0000")` = `"6.0000"` vs `String(6)` = `"6"`; fed straight into `bccomp` at `:84` | **Safe, and removes a ~1e-16 float carry** |
| `apps/web/src/features/batches/pages/BatchDetailPage.tsx:273-302` | `/batches/{uuid}/stock` per-location fields (`BatchController.php:342-345`) | `parseFloat("6.0000")` works | **Safe** (see MINOR I-8) |
| `apps/web/src/features/batches/types.ts:38-39, 281, 322-324` | type declarations + doc comments asserting "PHP float aggregate (`batchStock().sum(...)`) → JSON number" | **Now false.** Types compile-time-permit `.toFixed()` on a runtime string | **NOT updated — rider R3** |
| POS device (`apps/pos/src`) | — | grep finds **no** consumer of either field | n/a |

So: after the `BatchListPage` migration already contained in this diff, **nothing breaks at runtime under Option B today.** The one thing standing is a lying type declaration.

**Why Option A (flag-gate everything) is the worse choice, from the costing lens:**
1. Reverting flag-off emission to `Batch::getTotalQuantityAttribute()` / `getAvailableQuantityAttribute()` (`Batch.php:143-151`) re-installs an explicit `(float)` cast on a `SUM` over `decimal(15,4)` at the JSON emission boundary — precisely the rule-19 pattern this repo's `ForbidFloatCastOnDecimalProperty` guard exists to stop — and ships it as the live path for the entire Push-3 → activation window.
2. Those accessors issue a **fresh `SELECT SUM(...)` per resource instance**, so every batch collection (`/batches`, `/batches/expiring`, `/batches/expired`, `/products/{id}/batch-stock`) is N+1 by construction. The scoped sum reads an already-loaded relation and costs zero extra queries.
3. A flag-dependent **wire type** is strictly worse than a one-time change: the FE has to accept both shapes regardless, and the activation flip becomes a *second*, separately-untested contract change on a live tenant.
4. The old shape was already internally inconsistent, before this lane: `/batches/expired?location_ids[]=L1` returned a location-filtered `batch_stock[]` (`FEFOInventoryService.php:903-911`, pre-existing) sitting next to a company-wide `total_quantity`. The write-off `max` derived from that mismatch was over-permissive by exactly the other branches' stock. Option B makes `total_quantity` agree with the `batch_stock[]` array printed beside it in the same payload — that is the invariant worth defending.

**Riders (all three required before merge):**
- **R1 — load the scoped relation before building the resource; do NOT compute from a fresh scoped query.** Fix the four sites in **I-1** plus `BatchController.php:211`. Recomputing from a fresh query would reintroduce the per-resource query in collections *and* let `total_quantity` disagree with the `batch_stock[]` array in the very same response — the failure Option B is meant to eliminate.
- **R2 — make an unloaded relation loud, not zero** (**I-3**).
- **R3 — flip `apps/web/src/features/batches/types.ts:38-39, 281, 322-324` to `string`** and delete the now-false "PHP float aggregate" comments at `:270-276` and `:291-295`, so the next consumer cannot type-check a `.toFixed()`. Add a Vitest case rendering `BatchListPage` and `ExpiryWriteOffPage` with `"3.1234"`-shaped strings.

Plus **the §6.1 amendment + real flag-off contract test from I-2** — Option B is only defensible if the plan stops claiming Push 3 is inert on these two endpoints and starts testing what it actually emits.

---

## Verified fine

- **No stock, reservation, or GL writer anywhere in the diff.** Seven-table byte-identity is asserted on both a denied mutation (`BatchActionPermissionsTest.php:64-78`, three roles) and a duplicate-create re-run (`BatchReadLocationScopeTest.php:216-220`).
- **FEFO/expiry ordering preserved verbatim**: `orderByRaw('(expiry_date IS NULL) ASC, expiry_date ASC')->orderBy('id')` in both `BatchRepository.php:113-115` and `:153-155`; `orderBy('expiry_date','asc')` at `FEFOInventoryService.php:864`. `suggestBatchesForSale` and the whole allocation/deduction half of `FEFOInventoryService` are untouched.
- **`null` unrestricted / `[]` fail-closed** is implemented consistently and proven: `BatchController.php:58-59` and `:66-68` return `null` only when the flag is off or the membership is genuinely unrestricted; `LocationScopeResolver.php:37-45` never converts `[]` into an omitted predicate; both trace adapters return `[]` on `[]` (`:19-21`, `:35-37`, `:64-66`, `:17-19`, `:33-35`); asserted end-to-end at `BatchReadLocationScopeTest.php:135-146` (six surfaces → `[]`/404) and `BatchExpiringLocationScopeTest.php:26-31`.
- **Unrestricted actors keep company lot metadata including zero-stock lots** — `scopeVisible` (`BatchRepository.php:42-49`) is a **no-op** when `$locationIds === null`; proven at `BatchReadLocationScopeTest.php:148-155`.
- **Null-location history correctly excluded from restricted actors, retained for unrestricted** — `DocumentBatchTraceReaderAdapter.php:80-86` (line-level `location_id`, falling back to the parent document's), proven at `BatchTraceReaderContractTest.php:74-84`.
- **Depleted-lot-via-history visibility** works and returns an honest `total_quantity: "0.0000"` (`BatchReadLocationScopeTest.php:157-173`) — `findVisibleByUuid` deliberately filters the eager load by location while allowing the row through on the history OR-branch. Correct and self-consistent.
- **Module boundary held**: `BatchTraceabilityController` no longer imports any `App\Modules\Document\*` or `App\Modules\POS\*` symbol; `BatchController` imports none either (verified by grep). Cross-module traffic is via `App\Shared\Contracts\BatchTraceability\*` interfaces bound in `DocumentServiceProvider` / `POSServiceProvider`. Rule 6 satisfied.
- **Convention 09 read side**: second-company + second-location + duplicate-create-re-run covered for list, detail, product-stock, expiry and forward trace in one real HTTP test using real models, `RefreshDatabase`, and `RolesAndPermissionsSeeder` — `BatchReadLocationScopeTest.php:175-221`. It asserts data meaning (balances, which rows the other company sees, table snapshots after a re-run), not status codes. Gaps are POS suggestion and backward trace only (**I-4**).
- **No new `unique(['tenant_id', …])` on a catalogue table.** The one migration (`2026_09_06_205000_add_provisioning_source_to_roles.php`) touches `roles`, not an inventory catalogue table.
- **`meta.outcome => 'already_exists'` on duplicate create is correctly flag-gated** (`BatchController.php:206`), so the 422 payload is genuinely inert while off.
- **`posAvailableBatches` hardened**: `'location_id' => ['required', 'uuid', ScopedExists::company(...)]` (`BatchController.php:362`) adds a UUID check ahead of the scoped-exists lookup; the `quantity` rule keeps its `regex:/^\d{1,11}(\.\d{1,4})?$/` scale-4 ceiling and the decimal-string pass-through comment at `:378-381`.
- **SQLite masking risk assessed and cleared** for the one aggregate-shaped predicate this lane adds: `whereRaw('quantity > ?', ['0'])` (`BatchRepository.php:46`) binds a string against a `decimal(15,4)` column. SQLite applies NUMERIC column affinity to the text operand and PostgreSQL infers the unknown literal as numeric, so both compare numerically. The four location-scope tests are nonetheless PG-lane per §13 and their PG results must come from the tenancy/PG run, not from mine.
- PHPStan level 8 clean, Pint clean, 17/17 SQLite tests green.

---

**Fix before merge:** scope the `batchStock` eager load on the `update`/`recall`/`transfer`/`writeOff` responses (I-1), make an unloaded relation raise instead of emitting `"0.0000"` and load it in `store()` (I-3), replace the vacuous flag-off inertness test with a real `/batches/expired` payload contract test plus a §6.1 amendment declaring the expiring/expired narrowing intentional (I-2), restore `?string` nullability for `document_number` and `product_id` in both trace adapters (I-5), add POS-suggestion and backward-trace second-company/second-location cases (I-4), and flip `apps/web/src/features/batches/types.ts:38-39,281,322-324` to `string` (B-2 rider R3).

VERDICT: CHANGES-REQUIRED
