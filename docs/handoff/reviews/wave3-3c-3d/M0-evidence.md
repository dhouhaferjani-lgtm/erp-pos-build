# Wave 3C/3D M0 preflight evidence

## Pinned base and entry conditions

- `BASE_SHA`: `26b63f0ff29be6353015ca1cd2c5b362aa6bfc18`
- `HEAD` before M0 mutations: `26b63f0ff29be6353015ca1cd2c5b362aa6bfc18`
- base commit: `26b63f0ff29be6353015ca1cd2c5b362aa6bfc18 2026-08-11 13:18:28 +0100 harness: self-reviewing Codex waves (inline Opus reviews + YAML progress)`
- foundations ancestor check: `6974231d0` is an ancestor of `BASE_SHA` (exit 0).
- dedicated branch/worktree: `codex/dpa-wave3-3c` at `.worktrees/dpa-wave3-3c`.
- the worktree was clean immediately after creation; subsequent M0 artifact and progress changes are this milestone's own work.
- pin provenance: the copy of the progress YAML inside commit `26b63f0ff` still named an abandoned sibling harness commit (`7f84dc91a…`). The current `dev` dispatch state read before worktree creation, at `8ce919be2`, independently ratified `26b63f0ff` as the base. This run did not choose a new base to match its HEAD; it copied the already-ratified current dispatch pin into the isolated branch and records that substitution here explicitly.
- required tickets present on the pinned commit:
  - `docs/superpowers/tickets/2026-08-07-wo-quote-tax-inclusive-line-total.md`
  - `docs/superpowers/tickets/2026-08-10-workshop-parts-goods-lane-gap.md`

## Mechanical citation inventory

Command:

```text
php scripts/wave3-citation-inventory.php docs/handoff/reviews/wave3-3c-3d/M0-citation-inventory.csv
```

Actual output:

```text
N_extracted=256 N_mapped=256 relocated=15 unresolved=0 output=docs/handoff/reviews/wave3-3c-3d/M0-citation-inventory.csv
csv_rows=256 extensionless=13 relocated=15 file_scope=0 comment_anchors=0
```

The extractor consumes the dispatch and the authoritative plan from its beginning through the end of §4. It extracts both `File.php:line` and extensionless `Class:line` forms, resolves each path, maps from the plan reference SHA where possible, and fails non-zero if the path, mapped line, real enclosing symbol, or classified executable assertion is absent. Comment/docblock fragments, file-scope fallbacks, and punctuation-only anchors are forbidden for code rows. Fifteen rows have the distinct `relocated` status: the two independently verified 3E extractions plus stale or comment-only citations carried to executable semantic successors. The complete row set is in `M0-citation-inventory.csv`; `scripts/tests/wave3-citation-inventory-test.php` pins the live corpus, four critical relocations, and the forbidden anchor classes.

Known moved-anchor validation:

| Citation | Current semantic anchor |
|---|---|
| POS projection's former `PosCoreReceiptProjection.php:456-458` | `apply()`'s rounding block ends at line 476 and its `DB::transaction` closure ends at line 477; the future flush belongs between those two boundaries. The mechanical row maps the old range to `475-477`. |
| C-1's former `InvoiceController.php:761` transaction | `InvoiceController::confirmDeliveriesAndPost()` opens its root transaction at current line 803; its nested DN confirm is line 808 and invoice post is line 818. |

### Bare continuation-citation register

The dispatch also contains 13 bare ```:line``` continuations. They are not independently resolvable `file:line` citations, so the extractor does not guess a file from prose. Ten are bounded by a neighbouring explicit citation or method name and are covered by its mechanical row: `DeliveryNoteController :290-331`; `SalesOrderToDeliveryNoteConverter :257-266`; `DeliveredQuantityResolver :104` and `:201`; the already-recorded POS `:457 → :476/:477`; C-1 `:803`; and plan T23 `:3175-3185` under the explicit `plan-wave3.md:2291-2299` reference. Their current executable anchors are respectively controller transaction `308-331`, conversion guard `266-274`, predicate calls `104-106` and `201-203`, POS closure tail `476-477`, C-1 root `803`, and the plan's D-a acceptance block `3175-3185`.

The remaining R3-2 trio is deliberately re-derived here because the dispatch marks it unrecoverably stale. Current order in `PosCoreReceiptProjection::apply()` is `writePayments()` at **457**, `redeemVouchers()` at **458**, `earnLoyaltyPoints()` at **466**, then `applyStockMovementForLines()` at **467**; the transaction closure ends at **477**. Thus the historical `:438`/`:458`/`:459` record is not used as a current address, and the future flush remains inside the closure after the stock call and rounding tail.

## D-28 caller sweep on the pinned tree

Production call sites were enumerated with `rg`; comments/tests were excluded. “Observed depth” is the Laravel transaction level at the writer's future flush point before Wave-3 changes.

| Writer | Production call site | Root frame / current anchor | Observed depth | Register disposition |
|---|---|---|---:|---|
| `DeliveryNoteService::confirm()` | `DeliveryNoteController::confirm()` line 330 | controller transaction `308-331`; service transaction `88-93` | 2 | C-3; register controller closure and flush after the service returns. |
| `DeliveryNoteService::confirm()` | `InvoiceController::confirmDeliveriesAndPost()` line 808 | transaction `803-829` | 2 | C-1; flush after `postingService->post()` and after every response payload read at the closure tail. |
| `DeliveryNoteService::confirm()` | `InvoiceController::createDeliveryAndPost()` line 995 | transaction `909-1031` | 2 | C-5; flush after DN payload `invoiced_at` update and immediately before the response return. |
| `ReturnNoteService::confirm()` | `ReturnNoteController::confirm()` line 427 | no controller transaction; service transaction `508-512` | 1 | standalone writer flushes in its own root. |
| `ReturnNoteService::confirmWithin()` | `ReturnNoteService::confirm()` line 510 | service transaction `508-512` | 1 | standalone root path. |
| `ReturnNoteService::confirmWithin()` | `RefundService::cancelInvoiceWithDecision()` line 237 | root transaction `81-86`; `confirmWithin()` currently opens no nested transaction | **1, not the plan's stated 2** | C-2. M2 must add the implied inner savepoint around `confirmWithin()` before its writer-tail flush, then convert the root arrow closure to a block and flush at its final statement. This makes the documented depth-2 composition true without changing the domain call or union cost lock. Pin with the composite test. |
| `PosCoreReceiptProjection::apply()` | `ApplyFiscalEventProjectionJob` line 394 through the projector registry | job opens no surrounding transaction; projection transaction `240-477` | 1 | writer flushes at the semantic tail between lines 476 and 477. |
| counting listener `handle()` | queued event registration in `InventoryServiceProvider` line 80 | `handle()` line 54 has no transaction; item loop line 70; replay transaction `257-288` is per item | 0 / per-item 1 | T21 must create one root transaction around the loop; no 3C composite registration. |
| `GoodsReceiptService::post()` | `GoodsReceiptController` line 124 | service owns its root transaction | 1 | non-buffer writer; deferred `GoodsReceived` mechanism, no buffer flush. |
| `GoodsReceiptService::post()` | `StandaloneReceiptService` line 134 | outer transaction `117-146`, then service transaction | 2 | C-4 EXEMPT; only subsequent write is `procurement_idempotency_keys` `137-143`, not an inventory-class lock. |

The C-2 depth discrepancy is actionable but not an architecture contradiction: the plan already requires depth 2 and a composite-root flush, and one nested `DB::transaction` savepoint around the existing `confirmWithin()` call establishes exactly that model while preserving the existing root transaction and sorted union cost lock.

### I-1 frame notes

- C-1's known composite order is invoice row (self) → DN chain head → `stock_levels` → invoice chain head. The company GL advisory spans the full frame and therefore must be acquired only at the C-1 tail.
- C-3 retains its shipped controller `lockForUpdate()` idempotency read; the chosen remedy is registration, not removal of the controller transaction.
- C-5's tail includes the confirmed DN payload update at line 1015. Flushing before that write would violate I-1 with `documents` retained in the inventory-class set.
- C-4 remains exempt because the GR lane uses deferred events rather than `InventoryGlPostingBuffer`.

## T11c fixture set derived from the sweep

| Pair | Root-frame reason |
|---:|---|
| 1. DN confirm × DN confirm | direct delivery writer overlap |
| 2. DN confirm × RN confirm | delivery stock row locks versus RN's sorted product-cost locks |
| 3. DN confirm × POS projection | shared stock rows across document and device lanes |
| 4. counting listener × goods receipt | per-item counting product-cost locks versus GR's up-front locks. This remains the authoritative plan row (`plan-wave3.md:2716`). It is executable before T21 because a replay item already opens `DB::transaction` at current lines 257-288 and the legacy adjustment service owns its own transaction; T21's future whole-counting root is not required to exercise one item's reversed lock order. |
| 5. POS projection × goods receipt | carried T5b ordering proof |
| 6. C-1 multi-DN confirm/post × concurrent invoice post | required nested composite; additionally prove all DNs post in one root flush |
| 7. POS refund-with-scrap × DN confirm | inline scrap advisory before T16d |
| 8. POS refund-with-scrap × POS sale | same violation on the projection lane |
| 9. voucher-tendered POS sale × DN confirm | voucher GL advisory precedes stock before T16e |
| 10. voucher-tendered POS sale × POS sale | same voucher violation on the projection lane |

C-3 and C-5 also require endpoint-level composite posting tests and the structural boundary leak guard. They do not add a distinct reversed-resource pair beyond the ten above.

C-5 is dismissed as a separate T11c pair by resource class, not by lane name: C-3 can hold an existing DN `documents` row before shared stock, while C-5 creates and later updates its own new DN row after shared stock. Those document rows cannot be the same row, so the frames share stock but no second contended resource from which an AB-BA cycle can be constructed. C-5 still receives its required composite and leak-boundary coverage.

## POS refund cost-basis ruling proposal (R-1)

Adopt option (a): a POS refund/void re-entry uses the original sale movement's persisted `unit_cost`, never the live product WAC.

Semantic implementation anchor:

1. Resolve the refund's `pos_receipts.original_receipt_id` from the refund receipt id already passed to `restockStock()`.
2. Query original `stock_movements` by `reference_type = 'pos_receipt'`, `reference_id = original_receipt_id`, and the same `(product_id, variant_id, location_id)` grain.
3. Drain original exits deterministically by `(occurred_at ASC, id ASC)` for the refunded quantity and compute a quantity-weighted unit cost at `COST_SCALE = 6`, using decimal-string math only.
4. Never fall back to live WAC. The upstream `PosCoreReceiptProjection::assertOriginalReceiptResolvableForRefundOrVoid()` gate at `PosCoreReceiptProjection.php:617-655` already requires a resolvable original receipt. If that receipt has no attributable exit movement, emit a zero-cost row plus a warning and let D-b expose the missing basis; using current cost would silently reinstate the exact residual R-1 closes. The signed POS event is not refused after acceptance.

Distinguishing red-first test: project a sale at `10.000000`, move `products.cost_price` to `12.000000`, project its linked refund, then assert the POSReturn movement and inventory-entry JE both use `10.000000`. The competing live-WAC choice produces `12.000000` and makes the test red.

This is POS-local and does not restore D-24's deleted `POSSale` arm.

## R-11 pre-deploy data probe

Probe command, verbatim:

```text
psql -h 127.0.0.1 -p 5432 -d postgres -Atqc "select datname from pg_database where datistemplate = false and datname like 'tenant%' order by datname" | while IFS= read -r tenant_db; do count=$(psql -h 127.0.0.1 -p 5432 -d "$tenant_db" -Atqc "select count(distinct sm.id) from stock_movements sm join document_lines dl on dl.document_id = sm.reference_id and dl.product_id = sm.product_id join products p on p.id = sm.product_id and p.tenant_id = sm.tenant_id and p.company_id = sm.company_id where sm.reason = 'delivery' and p.is_physical = false" 2>/dev/null) || count=QUERY_ERROR; printf '%s,%s\n' "$tenant_db" "$count"; done
```

Probe SQL (run read-only on every local PostgreSQL database matching `tenant%` on port 5432):

```sql
SELECT count(DISTINCT sm.id)
FROM stock_movements sm
JOIN document_lines dl
  ON dl.document_id = sm.reference_id
 AND dl.product_id = sm.product_id
JOIN products p
  ON p.id = sm.product_id
 AND p.tenant_id = sm.tenant_id
 AND p.company_id = sm.company_id
WHERE sm.reason = 'delivery'
  AND p.is_physical = false;
```

Per-database results:

```text
tenant019e6b9b-eb15-72cb-ac14-50b706f0b171,0
tenant019e6ba9-c119-71ab-b267-6558f9a93835,0
tenant019e6bac-4af4-7342-ab89-1fb77e1b041d,0
tenant019e6ca2-e57d-73b6-bbd7-a8a3f9d01244,0
tenant019e6caa-5856-708c-afa3-52a6e12d88b4,0
tenant019e6cad-50e2-7205-bfdd-5f30f83e5c72,0
tenant019e6cae-c554-70c0-b887-2f489d93f5e7,0
tenant019e6cb1-9d43-70ee-862d-172b7cbfcd3e,0
tenant019e6cb4-7101-7242-bbd2-833561a5fcd0,0
tenant019e6cb5-3011-7059-b60b-24c7be835413,0
tenant019e6cb5-8274-7208-853a-4b27c078023a,0
tenant019e6cb8-4a10-7027-9c91-24da0d8ffed4,0
tenant019ea8ab-fbc2-72a9-b519-525d3b91f019,0
tenant019f7946-812a-7297-9448-ed4bc5d7415e,0
```

Integer result on this local sample: **0** across **14** tenant databases. Denominators independently checked during round 1: **0** `stock_movements` with `reason='delivery'`, **12** total stock movements, **0** products with `is_physical=false`, and **2003** total products. This developer sample is therefore vacuous and does **not** self-close the deploy-target question. The deploy-target integer remains unmeasured here; T19 must carry the conditional remediation section and require this same query against every deploy tenant before cutover.

## Goods-receipt D-f movement anchor

The literal is established, not inferred: both free and paid GR movement calls pass `referenceType: 'Document'` and `referenceId: $purchaseOrder->id` at `GoodsReceiptService.php:576-577` and `625-626`; `WeightedAverageCostService::recordPurchase()` persists those arguments at lines `278-279`.

The line grain is also established. `goods_receipt_lines` has unique nullable `movement_id` and `free_movement_id` links (`2026_07_04_100000_create_goods_receipts_tables.php:48-49,61-69`), and `GoodsReceiptService.php:701-702` writes the exact movements created for that receipt line. M3's anti-join must therefore:

1. start from each posted `goods_receipt_line` with a positive received/free quantity;
2. join the corresponding `movement_id` or `free_movement_id` to `stock_movements.id` so two partial receipts for one PO/product cannot satisfy one another;
3. additionally validate the mandated source tuple on that row: `reference_type = 'Document'`, `reference_id = goods_receipts.purchase_order_id`, and matching `product_id`.

This preserves the dispatch's source key while making the receipt-line identity non-vacuous. A line with a null link or a missing/mismatched movement fires D-f.

## WAC float and quantity follow-up

The focused WAC call sweep returned zero float casts. The stale `(float)` entry condition is closed on the pinned base.

The intentionally deferred quantity-scale trigger remains at `DeliveryNoteService.php:272` and `ReturnNoteService.php:696`: both use `CurrencyScale::bcformatStrict(..., 4)`. It must close before any >4dp unit ships; M1/M2 must not widen into that redesign.

## D-19 register — 18 rows

The register is historical across the 3A/3B→3E integration, so moved consumers retain distinct rows and current semantic anchors are stated where the original site was extracted or centralized.

| # | Candidate / current semantic anchor | Disposition |
|---:|---|---|
| 1 | `DocumentPostingService` former physical-line copy → `DeliveryComplianceGate::hasPhysicalLines()` line 403 | adopted on integrated base |
| 2 | `InvoiceController` former physical-line copy → `DeliveryComplianceGate::hasPhysicalLines()` line 403 | adopted on integrated base |
| 3 | `DeliveryNoteService` line 256 | adopted, relation form |
| 4 | `ReturnNoteService` line 659 | adopted, relation form |
| 5 | `SalesOrderService` line 124 | adopted, relation form |
| 6 | `RefundService::requiresReturnDecision()` line 1141 | adopted, relation form |
| 7 | `RefundService` RN-line source index line 499 | adopted, relation form |
| 8 | `SalesOrderToDeliveryNoteConverter::hasPhysicalProducts()` line 572 | adopted, scoped form; the 3C one-line obligation is already present on the integrated base and must not be duplicated |
| 9 | `DeliveredQuantityResolver::resolve()` line 104 | adopted, relation form |
| 10 | `DeliveredQuantityResolver::unresolvedLocationProductIds()` line 201 | adopted, relation form |
| 11 | `SalesOrderToDeliveryNoteConverter::copyLinesForFullDelivery()` lines 252-277 | deliberate non-adoption, twin A; the scoped lookup excludes a resolved non-physical product but deliberately retains an unresolvable product id. |
| 12 | former `SalesOrderToInvoiceConverter` twin, now `DeliveryNoteFromDocumentFactory` lines 103-119 | deliberate non-adoption, twin B; the same scoped-lookup containment is preserved. Ship neither. |
| 13 | `DeliveredQuantityResolver::priorReturnsPerTuple()` lines 502-521 | deliberate non-adoption: historical capacity ledger; mutable catalogue flags would un-net past returns, while current key consumption is fail-closed. |
| 14 | `SalesOrderToInvoiceConverter::hasDeliveryNotesForPhysicalItems()` lines 317-345 | assigned to 3C: pure adoption onto the predicate; behavior-preserving scoped lookup. |
| 15 | `SalesOrderToInvoiceConverter` three-way classifier lines 255-293 | assigned to 3C as a documented classifier; do not replace with a boolean predicate that cannot express `mixed`. |
| 16 | `SalesOrderToDeliveryNoteConverter::hasPhysicalProducts()` line 572 | assigned one-line obligation, already satisfied by the integrated base; preserve and count it, do not churn. |
| 17 | `PostCOGSOnInvoice::extractPhysicalProductLines()` lines 126-141 | assigned to 3C, self-closing by T17 deletion; record as retired-by-cutover, not adopted. |
| 18 | 3E merge-gate site `DeliveryComplianceGate::hasPhysicalLines()` line 403 | off-branch row now executed and adopted on the integrated base; `PhysicalLinePredicateTest` drives it. |

Count reconciliation: **18 total = 10 adopted register rows + 3 deliberate non-adoptions + 4 assigned-to-3C rows + 1 integrated off-branch merge-gate row**. Rows 1, 2, and 18 intentionally converge on current `DeliveryComplianceGate::hasPhysicalLines()` line 403 after 3E centralized two earlier consumers and the merge gate adopted the shared predicate; they remain separate historical register obligations. The duplicated current anchor at rows 8/16 is also intentional: row 8 is the 3A adopted-site history, while row 16 is the dispatch's carried 3C obligation and is recorded as already satisfied on the pinned integration base.

Twin containment safety depends on the relation-form/scoped-form asymmetry. `PhysicalLinePredicate::forLine()` consumes the loaded relation and returns non-physical for a missing relation, while both conversion twins perform tenant/company-scoped product lookups and deliberately keep a line whose product id cannot be resolved. Adopting only one would silently drop that defensive line in one conversion path but retain it in the other. Shipping neither preserves the same fail-open containment at both document factories; resolving whether an unresolvable id should be copied is a separate data-integrity ruling, not safe predicate cleanup.

### NEW-3 mutable-flag exposure

Predicate coherence intentionally reads mutable `products.is_physical`; a catalogue edit can change current delivery classification. The Wave-3 accounting seam and detectors therefore key economic history on immutable `stock_movements`, never on a live product flag. The prior-return capacity ledger stays catalogue-blind for the same reason.

## M0 conclusion

All mechanically satisfiable preconditions resolve. Citation `unresolved = 0`, the caller sweep is enumerated (including C-5 and the C-2 depth correction), both Workshop tickets exist, the WAC float cast is absent, the GR reference literal and per-receipt-line grain are established, and D-19 reconciles to 18. R-11's local integer is 0 on a zero-denominator sample; deploy-target execution remains a documented T19 cutover precondition rather than being falsely self-closed.

## Adversarial round 1 response

The round-1 register is retained in `M0-round1.md`. Its required changes were handled as follows:

- P1-1/P1-2: the extractor now includes extensionless class citations and rejects missing real symbols, file-scope fallbacks, and unclassified semantic assertions. The regression initially failed with `Missing extensionless citation: SalesOrderToInvoiceConverter:334`; it now reports `wave3 citation inventory regression: PASS (256 rows)`.
- P1-3: the local R-11 result is explicitly labelled vacuous and the deploy query remains a T19 cutover prerequisite.
- P2-1: the pin provenance and independent dispatch ratification are disclosed above.
- P2-2: no substitution was made. The authoritative plan itself names `counting listener × goods receipt` as pair 4 at `plan-wave3.md:2716`; the per-item replay transaction is sufficient to exercise the reversed order in M1 even though T21 later widens the transaction to the whole count.
- P2-3: the D-f design is keyed by the receipt line's unique movement links and also validates the mandated PO/product source tuple.
- P3: the moved POS/C-1 anchors, D-19 convergence, original-cost no-fallback ruling, C-2 I-1 reason, and verbatim probe command are all recorded above.

Fix-commit revert/replay evidence: reverting `5158f7432` produced `N_extracted=243` and the covering check exited 1 with `FAIL: missing extensionless citation SalesOrderToInvoiceConverter:334`. Reapplying the fix at `d2b5765e0` restored the 256-row green result.

Round-2 P1-1 was reproduced red before the fix: the regression exited 255 at `DeliveredQuantityResolver:399-402`, whose stale mapping landed on a comment. The executable-only validator and relocations now produce 256/256 rows, 15 explicitly marked `relocated`, zero comment anchors, and zero unresolved rows. Round-2 P2-1 is closed by the continuation register and exact R3-2 ordering above. Its P3 evidence-tightening notes were also incorporated: distinct relocation status, C-5's resource-class dismissal, twin containment safety, the original-receipt gate citation, exact GR line ranges, and the live-corpus wording for the regression.
