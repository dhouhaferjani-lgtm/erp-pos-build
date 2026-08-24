# N-2 stock-level lane — adversarial gate r1 (inventory-costing lens)

Lane: `fix/campaign-n2-stocklevel` · worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/n2-stocklevel`
Commits reviewed: `7e2b61edb` (fix+tests), `076eadc5a` (manifest), `09723d0fc` (handback).
Lane base = `e70dcaad4`. **Local `dev` at review time = `ff56dd2f6`** (NOT `d5443c1c7` as stated in the dispatch — dev moved; Session B Q-2 `850e86217` landed since).
Reviewer posture: adversarial, code-grounded. Every claim below was re-derived from the files, not from the handback.

## VERDICT: spec ✅ / quality **ACCEPT-with-conditions**

The defect is real, the fix is correct, the costing math is untouched, and there is no new 500 on any caller.
Three conditions before merge (I-1, I-2, M-1); two ledger tickets (I-3, M-3).

---

## 1. Verification ledger (what I actually ran)

| Check | Result |
|---|---|
| New class, sqlite, BY PATH (`phpunit.xml`) | `OK (5 tests, 32 assertions)` |
| New class, **PostgreSQL 16** (throwaway `autoerp_test_n2gate` @ `127.0.0.1:5433`, `phpunit-pgsql.xml`) | `OK (5 tests, 32 assertions)` — DB dropped after |
| **Red-proof** — both service files reverted to `dev`, exception class + controller arms kept | `Tests: 5, Failures: 5` (see §2) |
| **Mutation A** — new catch arm moved BELOW `catch (\RuntimeException)` in `DeliveryNoteController` | test 3 RED (`-'INSUFFICIENT_STOCK' +'CONFIGURATION_ERROR'`) → arm ordering is genuinely load-bearing and pinned |
| **Mutation B** — `first()` replaced by an explicit zero-row create in `reserve()` | test 1 still **GREEN** → the "no phantom row" assertion is vacuous (finding M-2) |
| Regression BY PATH: `Marketplace/StockReservationTest` + `Inventory/StockReservationDefaultBatchTest` + `Unit/Inventory/WeightedAverageCostServiceTest` + `Architecture/InventoryCostLockCoverageTest` | `OK, Tests: 49, Assertions: 185` (4 PHPUnit deprecations, pre-existing) |
| Inherited-red spot-check on **dev `ff56dd2f6`**: `tests/Feature/Inventory/ExitMovementPrecisionTest` | `Tests: 4, Failures: 1` at `:137` (`'123456.789012'` vs `'1.000000'`) — **identical to the handback's claim → INHERITED confirmed** |
| `pint --test` on all 7 touched files | `{"result":"pass"}` |
| `phpstan analyse` (level 8, live-DB env) on the 6 touched production files | `[OK] No errors` |
| `php apps/api/tools/feature-lane-manifest-check.php` in the lane | `EXIT=0` |
| deptrac ruleset shape (`apps/api/deptrac.yaml`) | layers are TIER-based; `ModulePresentation → ModuleDomain` and `ModuleApplication → ModuleDomain` are both allowed → the new `Inventory\Domain\Exceptions\…` import from three Document controllers adds **no** new violation |

Lane left byte-identical (`git status --porcelain` empty, HEAD `09723d0fc`). Only one test process ever ran at a time; the full suite was never run.

---

## 2. Red-proof detail (services reverted to `dev`, all five red)

```
1) test_sales_order_confirm_refuses_with_typed_422_when_stock_level_row_is_absent
   Expected 422, received 404
2) test_sales_order_confirm_refuses_identically_when_the_row_exists_at_zero
   Expected 422, received 500        <-- pre-existing zero-qty behaviour was a 500, not a refusal
3) test_delivery_note_confirm_refuses_with_typed_422_when_stock_level_row_is_absent
   -'INSUFFICIENT_STOCK'  +'CONFIGURATION_ERROR'   <-- base DN confirm was 422, NOT 404
4) test_create_delivery_and_post_… : Expected 422, received 404
5) test_confirm_deliveries_and_post_… : Expected 422, received 404
```

---

## 3. Dispatch item-by-item

**(1) Policy: absent row ⇒ 0.0000 ⇒ typed 422, no phantom row — does it match pre-existing zero-qty behaviour?**
At the SERVICE level, yes. `StockReservationService.php:144-156` reads a null row as `'0.0000'` and refuses through the same `bccomp($available, $quantity, 4) < 0` predicate an existing row at 0 already fails; `WeightedAverageCostService.php:412-420` maps the absent row onto the same negative-residual refusal at `:450-458`.
At the HTTP level it does **NOT** match, and the lane deliberately changed it: on base, `SalesOrderController::confirm` had no `\RuntimeException` arm, so an EXISTING row at zero escaped as a **500** (red-proof test 2). The lane converts that to 422 as well. This is the "500→422 side-fix" the dispatch anticipated (item 4) and it is covered by test 2 — in scope, correct, and honestly documented in handback §2 "Side-finding".
No path creates a row where it did not before: `reserve()` uses `first()` (`:141`), `resolveDefaultBatchIdForImplicitReservation` uses `first()` + `return null` (`:261-265`), `recordSale` keeps `firstOrFail()` (`:411`). I confirmed `InventoryCostLockCoverageTest:337-354` really does string-pin `firstOrFail` inside `recordSale`'s body, so the handback's reason for keeping it is real (it is a weak grep-pin, but it exists).

**(2) Exception hierarchy — any upstream catch of `\DomainException` that now misses it (silent 500)?** **No. Proven by exhaustive caller enumeration.**
`recordSale()` has exactly ONE caller: `DeliveryNoteService.php:278` (`grep -rn "recordSale(" app/` — the only other hit is `POS/Domain/Services/CashDrawerService.php:324`, an unrelated cash method). `DeliveryNoteService::confirm()` has exactly three callers: `DeliveryNoteController.php:583`, `InvoiceController.php:816`, `InvoiceController.php:1018` — all three now carry the new arm.
`reserve()` callers: `SalesOrderController::confirm` (new arm), `CatalogCartController.php:224` (`catch (\RuntimeException)` → 422 `BUSINESS_ERROR`), `StockReservationController.php:208` (`catch (\RuntimeException)` → 422 `INSUFFICIENT_STOCK`), `MarketplaceOrderService.php:57-64` (guards with its own `\RuntimeException` before delegating), and the three internal callers `reserveWithFEFO`/`reserveForWorkOrder` (`:686-667` guards absent-row itself with `\RuntimeException`).
The type-compat argument is airtight: `ModelNotFoundException extends RecordsNotFoundException extends RuntimeException` (verified in `vendor/laravel/framework/src/Illuminate/Database/RecordsNotFoundException.php:7`), so **every** site that already handled the old absent-row throw still handles the new one. No `catch (ModelNotFoundException` exists on any of these paths. POS is untouched: `PosCoreReceiptProjection::decrementStock` (`:1980-2010`) has its own `first()`+null handling and never calls `recordSale`, so the exactly-once server-side decrement lane is unaffected, and no queue/console path reaches the new code.

**(3) Four controller arms.** `SalesOrderController.php:524-533`, `DeliveryNoteController.php:586-595`, `InvoiceController.php:843-851`, `InvoiceController.php:1056-1064`. Each is a NEW arm for the concrete type, placed FIRST; no existing catch widened; none became `\Throwable`. Envelope is `HandlesDocuments::validationErrorResponse()` (`Concerns/HandlesDocuments.php:298-306`) = `{error:{code,message}}` + 422, per `docs/conventions/01-API-RESPONSES.md`. Ordering is load-bearing in `DeliveryNoteController` only, and Mutation A proves the test catches a regression there.
Invoice unchanged on refusal: verified by test (`:185-194`) — status stays `Confirmed`, `fiscal_hash` null, `payload` identical, zero `DeliveryNote` rows, zero `StockMovement` rows. **No number burned**: `DocumentNumberingService::generateForKeyOnce` (`:42-44`) wraps the `document_sequences` bump in a nested `DB::transaction` on the SAME connection, so the outer rollback discards the increment (savepoint semantics). Not asserted by the test — see M-6.

**(4) WAC integrity.** Nothing in the costing chain moved: `workingScale()`/`costScale()` (`:431-433`), `avg_cost_before/after` (`:474-475`), `total_cost` at `$working` then formatted to `$costScale` (`:473`) — all byte-identical to base. Lock order (stock_level row → product row) unchanged. Quantities stay bcmath strings at scale 4; no float enters. Movement sign correct: `MovementType::Issue` / `MovementReason::Delivery` with `bcmul($quantityStr, '-1', 4)` (`:467-469`), taken from the reason enum, not hand-rolled. The existing-row-at-zero 500→422 side-fix is correct and is pinned by test 2.

**(5) Red-proof / PG / tampering.** Done — see §1 and §2. Two mutations performed (one found a real weakness, M-2). Inherited-red spot-check confirmed on dev.

**(6) Manifest.** See I-2 — the raise is correctly named and the checker is green in the lane, but it is now stale against `dev`.

**(7) The five "unreachable day-one" `firstOrFail()` sites.** Honest, with one omission. The four release/expire sites (`StockReservationService.php:350`, `:358`, `:474`, `:482`) only run against a `StockReservation` row, which can only exist because a `stock_levels`/`BatchStock` row existed when it was created — unreachable day-one, agreed. `Product::findOrFail()` at `:243` and `:576` is scoped to the caller's own tenant+company and the product is on the document — unreachable day-one, agreed. The census **omits** `BatchStock::…->firstOrFail()` at `:114`, inside `reserve()` itself — see M-5 (verified currently unreachable, but it belongs in the census).

---

## 4. Findings

### Important

**[IMPORTANT] I-1 — `apps/api/tests/Feature/Document/MissingStockLevelConfirmRefusalTest.php` (whole class) — zero coverage of a batch-tracked product, which is the campaign's own vertical.**
The fixture product is created without `requires_batch_tracking` (`apps/api/tests/Traits/BuildsDeliveryPolicyFixtures.php:144-155`; the column defaults to `false` per `database/migrations/tenant/2026_01_05_150003_add_batch_tracking_to_products_table.php:14`). So the branch the lane changed at `apps/api/app/Modules/Inventory/Application/Services/StockReservationService.php:249-265` (`resolveDefaultBatchIdForImplicitReservation`, `firstOrFail()` → `first()` + `return null`) **executes in none of the five tests**.
Why it matters: parapharmacy verticals default EVERY product to batch tracking, and that is the vertical this first-tenant campaign is rehearsing — the batch-tracked day-one confirm is the *most likely* production shape, not an edge case. I verified the behaviour is currently CORRECT (`BatchStockService::ensureDefaultBatch` returns `null` for a non-positive target, `apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:71-73`, so `batchId` stays null and the aggregate branch raises the honest refusal) — but nothing pins it. If `ensureDefaultBatch` ever mints a zero lot, day-one batch-tracked confirms silently route into `BatchStock::…->firstOrFail()` at `StockReservationService.php:111-114` and the exact N-2 raw-404 defect returns, undetected.
Fix: add one test — same shape as `test_sales_order_confirm_refuses_with_typed_422_when_stock_level_row_is_absent`, with the fixture product updated to `requires_batch_tracking => true` — asserting 422 `INSUFFICIENT_STOCK` and zero `BatchStock` rows.

**[IMPORTANT] I-2 — `apps/api/tests/feature-lane-manifest.json:9` and `:779-781` — the raise is stale against current `dev`; it WILL conflict and must be reconciled to 1152.**
The lane sets `gated_ceiling: 1148 → 1149` and `groups.Document.classes: 77 → 78`. Current `dev` (`ff56dd2f6`) already carries `gated_ceiling: 1151` with `groups.Document.classes: 77` (read via `git show dev:apps/api/tests/feature-lane-manifest.json`). The dispatch's "N-1 also raises to 1151" is already *landed* on dev, not in flight.
Post-merge the correct values are **`gated_ceiling: 1152`** and **`groups.Document.classes: 78`**. The `gated_ceiling` line is a guaranteed textual conflict; resolving it by taking either side verbatim leaves the checker red (1149 = under-count, 1151 = under-count by exactly this class). Also note the dispatch's stated dev tip `d5443c1c7` is stale.
Fix at merge: `git merge dev` in the lane, resolve `gated_ceiling` to `1152`, keep `groups.Document.classes` at `78`, re-run `php apps/api/tools/feature-lane-manifest-check.php` (must be EXIT=0), and merge the note text so both the N-2 sentence and dev's prior sentences survive.

**[IMPORTANT] I-3 — `apps/api/app/Modules/Inventory/Domain/Exceptions/InsufficientStockForFulfilmentException.php:74-83` — the new primary operator-facing refusal is hardcoded English, unreachable by i18n.**
`buildMessage()` composes `"Insufficient stock for '{$product}' at '{$location}'. Available: {$available}, Requested: {$requested}"` and the controllers pass `$e->getMessage()` straight through. I verified the FE renders it verbatim: `getErrorMessage` (`apps/web/src/lib/api.ts:82-95`) returns `data.error.message` first, and all four call sites toast it (`apps/web/src/features/documents/sales-orders/SalesOrderDetailPage.tsx:190-192`, `delivery-notes/DeliveryNoteDetailPage.tsx:76-78`, `invoices/InvoiceDetailPage.tsx:218-220` and `:260-262`). So the lane's handback item 4 is factually right (nothing swallows it) — the problem is the opposite: an untranslated English sentence is now the *primary* refusal in a product whose first tenant is Tunisian (FR/AR UI), against house rule 11. Precedent for doing better sits in the very same catch chain: `InvoiceController.php:1069-1071` translates `GuidedDeliveryCannotBeGeneratedException` via `__('documents.guided_delivery.fefo_allocation_failed')`.
Fix (either): translate at the controller boundary — `__('documents.stock.insufficient', ['product' => $e->productName ?? $e->productId, 'location' => …, 'available' => …, 'requested' => …])` — keeping `$e->getMessage()` as the log/exception text; or ship the structured fields in `error.details` and let the FE compose. Acceptable as a ledger ticket if the parent prefers to batch the i18n work.

### Minor

**[MINOR] M-1 — in-code comments assert a "raw 404" on delivery-note confirm that never existed.**
`apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:400-404` ("letting `ModelNotFoundException` escape turned that into a raw 404 on delivery-note confirm and on the two invoice convenience endpoints"), plus the same wording in `DeliveryNoteController.php:586-591`, `InvoiceController.php:844-847`, `InvoiceController.php:1057-1060`, the exception docblock (`InsufficientStockForFulfilmentException.php:20-25`) and the test docblock (`MissingStockLevelConfirmRefusalTest.php:24-29`).
On base, `DeliveryNoteController::confirm` already had a `catch (\RuntimeException)` arm (`git show dev:…/DeliveryNoteController.php`, the arm two lines below the `\DomainException` arm), and `ModelNotFoundException` IS a `\RuntimeException` — so the DN case returned **422 `CONFIGURATION_ERROR`**, never 404. I proved it: red-proof failure 3 is a code mismatch, not a status mismatch. Only the two invoice endpoints and sales-order confirm produced 404.
The handback gets this right (§3 row 3, §5), so this is a comment-only inaccuracy — but these comments are the durable record a future reader trusts. Fix: reword the four comment blocks and two docblocks to "a raw 404 on sales-order confirm and the two invoice convenience endpoints, and a misleading `CONFIGURATION_ERROR` on delivery-note confirm".

**[MINOR] M-2 — `MissingStockLevelConfirmRefusalTest.php:120-124` and `:166-170` — the "no phantom `stock_levels` row" assertions cannot fail, so they do not pin the `first()`-not-`firstOrCreate` decision.**
I mutated `StockReservationService.php:137-141` to create a zero-quantity row when `first()` returns null; test 1 stayed green (`OK (1 test, 7 assertions)`), because the refusal throws out of `reserve()`'s `DB::transaction` and the enclosing controller transaction, discarding any row written inside it. Handback §2 point 2 claims "The parity is pinned by a test (§5, test 2)" — for the phantom-row half, it is not.
Why it matters: the design rationale most likely to be reverted by a future maintainer ("just use `firstOrCreate`, `StockAdjustmentService` does") is the one with no guard. Fix (optional): either drop the "pinned" claim from the handback, or convert it into a real guard — e.g. an architecture-style assertion that `reserve()`'s body contains `->first()` and not `firstOrCreate`, mirroring `InventoryCostLockCoverageTest:337-354`.

**[MINOR] M-3 — `InsufficientStockForFulfilmentException.php:81-82` — human-facing quantities rendered at raw scale 4.**
The message emits `Available: 0.0000, Requested: 2.0000`. `docs/architecture/precision-contract.md` (Emission & display) requires human-facing quantities at the product unit's `decimal_places` via `QuantityScale::formatForUnit`. The raw-scale-4 wording is inherited from the old messages, but the lane promotes it from a 500 / `CONFIGURATION_ERROR` into the primary operator toast, so it is now a display-contract surface. PHPStan is green because `ForbidFixedScaleQuantityLiteralRule` does not reach string interpolation. Fix: format both numbers through the unit's precision when composing the operator message (keep the scale-4 values on the readonly properties for machine consumers).

**[MINOR] M-4 — `WeightedAverageCostService.php:419` — `CurrencyScale::bcformat($quantity, 4)` applied to a QUANTITY.**
Rule 19 routes quantities through `QuantityScale`, not `CurrencyScale`. It matches the neighbouring pre-existing lines `:434` and `:439`, so it is consistent-with-file and PHPStan-clean, but it is a NEW occurrence added by this lane. Fix (cheap): `QuantityScale::format($quantity)` — or leave and log the whole `recordSale` block for the QuantityScale sweep.

**[MINOR] M-5 — handback §7's adjacent-`firstOrFail()` census omits `StockReservationService.php:111-114`.**
`BatchStock::where('batch_id', …)->where('location_id', …)->lockForUpdate()->firstOrFail()` sits inside `reserve()` itself, not on a release path. I verified it is currently unreachable with an absent row — `SalesOrderService.php:144-154` never passes `batchId`, `StockReservationController::store` (`:171-193`) neither validates nor forwards a `batch_id`, and the only other producer, `ensureDefaultBatch`, `firstOrCreate`s the `BatchStock` row whenever it returns non-null (`BatchStockService.php:94-97`). So no defect today — but any future caller that forwards an explicit `batch_id` reopens the raw-404 class, and the census is the artefact that should say so. Fix: add the line to §7.

**[MINOR] M-6 — `MissingStockLevelConfirmRefusalTest.php:189-194` does not assert the delivery-note sequence was not burned.**
The dispatch explicitly asked. I verified behaviourally that it is not (nested `DB::transaction` in `DocumentNumberingService::generateForKeyOnce`, `:42-44`, same connection ⇒ savepoint ⇒ discarded by the outer rollback), but nothing pins it. Fix (optional): capture `DocumentSequence` `last_number` for `delivery_note` before and after the refused call and assert equality.

### Pre-existing observations (NOT this lane's, for the ledger — do not block)

- **O-1 — float cast on a quantity in the reservation release/expire paths.** `StockReservationService.php:478` and `:484` (`release()`), `:475` and `:483` (`expireReservations()`): `->decrement('reserved_quantity', (float) $reservation->quantity)` and `->decrement('reserved', (float) $reservation->quantity)`. Under rule 19 this is a Critical-class defect (float touching a `decimal(N,4)` quantity), and it corrupts `reserved` on ordinary magnitudes. Present on `dev`, untouched by this diff. Worth its own lane.
- **O-2 — policy divergence between the document lane and the POS lane.** `PosCoreReceiptProjection::decrementStock` (`:1993-2010`) silently no-ops a product-level line with no `stock_levels` row (deliberate, per its own comment: "Product-level lines with no row are normal (non-inventory / service items)"), while the document lane now hard-refuses the identical shape. Both are defensible; the divergence deserves an explicit owner ruling row rather than living in two comments.
- **O-3 — aggregate `reserved` is not mirrored into `BatchStock.reserved_quantity`.** `ensureDefaultBatch` tops the lot up to the aggregate `quantity` ignoring aggregate `reserved` (`BatchStockService.php:100-107`), while `reserve()`'s batch branch checks only `batchStock.reserved_quantity` (`StockReservationService.php:117-118`). A batch-tracked product whose aggregate stock is fully reserved can therefore be over-reserved through the batch branch. Pre-existing; flagged by the FEFO/lot-integrity lens.

---

## 5. What must change before merge

1. **I-2 (mandatory, merge-mechanical):** `git merge dev` in the lane, resolve `gated_ceiling` to **1152**, keep `groups.Document.classes` at **78**, re-run the manifest checker to EXIT=0.
2. **I-1 (mandatory, one test):** add a `requires_batch_tracking => true` case so the changed `resolveDefaultBatchIdForImplicitReservation` branch is executed and the parapharmacy day-one shape is pinned.
3. **M-1 (mandatory, comment-only):** correct the "raw 404 on delivery-note confirm" wording in the four comment blocks and two docblocks — the base behaviour there was `CONFIGURATION_ERROR`.
4. **I-3 and M-3:** open ledger tickets (untranslated operator refusal; quantity display precision in the refusal message). Not merge-blocking.
5. **M-2, M-4, M-5, M-6:** at the author's discretion; M-2 at minimum should have its "pinned by a test" claim withdrawn from the handback.

CI-UNVERIFIED (S-17): every result above is local.
