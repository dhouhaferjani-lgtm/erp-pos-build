Adversarial merge-gate review — **Wave 3 / 3C, milestone M3, round 3**

Scope: brief `docs/handoff/CODEX-DISPATCH-wave3-3c-3d-2026-08-10.md:439-483` + the M3 evidence-contract row (`:542`). Amending authority: none. Diff reviewed: `26b63f0ff..HEAD` (`a6ab3dcbb`), with round-3 attention on `a59263411` / `a6ab3dcbb`. Lenses: **inventory-costing** and **fiscal-pos** — both apply, both exercised. Worktree left untouched (`git status --porcelain` empty at exit).

Verified locally, by path, on PostgreSQL (127.0.0.1:5433) unless noted:

| Suite | Result |
|---|---|
| `CheckCogsCoverageCommandTest` | OK 22 / 43 assertions |
| `ReceiptReturnFlowTest` | OK 21 / 119 |
| `PosReturnScrapWriteOffTest` | OK 11 / 42 |
| `StoreReturnRequestDispositionTest` | OK 5 / 17 |
| `CompleteSalesCycleWithReturnTest` | OK 1 / 55 |
| `PosCoreReceiptProjection*` (13 of 14 files, run individually) | OK |
| **`PosCoreReceiptProjectionVariantStockTest`** | **ERRORS! 5 tests, 1 error** (reproduced on PG *and* on sqlite `:memory:`) |
| `pint --test` (3 touched production files) | pass |
| `phpstan` level 8 (3 touched production files) | `[OK] No errors` |

---

## Register

**1. P1 — CONFIRMED — `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:1820` — round 3 leaves a shipped test RED on the branch.**
`tests/Feature/Fiscal/PosCoreReceiptProjectionVariantStockTest::test_variant_sale_with_no_variant_stock_row_does_not_fall_back_to_product_grain` errors on `HEAD`:
```
Mockery\Exception\InvalidCountException: Method warning(<Any Arguments>) … should be called
 at least 1 times but called 0 times.  (…VariantStockTest.php:217)
```
Cause: `decrementStockForLines` now `continue`s on `$lineStockMovementExpected[$index] === false` (`:1820`) *before* reaching `decrementStock`, whose `$stockLevel === null` branch owns the alarm (`:1966-1977`). `stockGrainExists` (`:1248-1253`) returns false for exactly the case that test constructs (variant line, product-level row only), so the warning can never fire.
*Failure scenario:* CI runs this file and fails; more importantly the alarm it pins — "the absent variant grain is logged so an unseeded-variant leak is observable rather than silent" (`:1966-1971`, test `:191-195`) — is gone in production. The M3 evidence's round-2 remediation section records a "Fresh PostgreSQL" green for exactly three files (`PosCoreReceiptProjectionRefundDispositionStockTest`, `CheckCogsCoverageCommandTest`, `CogsRelocationCharacterisationTest`) and never ran the projection suite the change edits, so this was not detected by the milestone's own verification.

**2. P1 — CONFIRMED — `PosCoreReceiptProjection.php:1248-1254` + `:1820` + `CheckCogsCoverageCommand.php:295` — the unseeded-variant COGS leak is now invisible on BOTH channels simultaneously.**
For a POS **sale** line whose variant-scoped `stock_levels` row does not exist: `stockGrainExists` → false → `stock_movement_expected = false` persisted → the decrement is skipped → **no `stock_movements` row → nothing enqueued to `InventoryGlPostingBuffer` → no COGS entry** → and D-f's `->where('lines.stock_movement_expected', true)` (`:295`) now excludes the line. The log alarm that used to be the sole compensating control is suppressed by the same `continue` (finding 1). The symmetric refund alarm (`:2373-2379`, "variant refund/void found no variant-scoped stock_levels row") is likewise unreachable for v4 refunds, which `continue` at `:2123-2131`.
*Failure scenario:* the launch parapharmacy adds a new size/variant and sells it before its variant grain is seeded (variant grains are created only by `OpeningBalancePostingService`, `WeightedAverageCostService`, `StockAdjustmentService` — never at product/variant creation). Revenue is booked, stock is untouched, COGS is zero, gross margin is overstated, and neither the nightly detector nor the log reports it. This is precisely the hole D-f exists to find, and it was reported by D-f before round 3 (the column defaulted `true`). Rounds 1 and 2 blocked on detectors that fire when they should not; round 3 has produced the mirror defect — a detector that stays silent when it should fire — on a population the projection's own code labels a *leak*, not a by-design outcome.

**3. P2 — CONFIRMED — `CheckCogsCoverageCommand.php:295` + `PosCoreReceiptProjection.php:1248-1254` — "stock-tracked" is implemented as a mutable *state* probe, not a product property, blinding D-f across the whole sale lane.**
The brief scopes D-f's population to "physical **+ stock-tracked** products" (`:452`). The implementation equates stock-tracked with "a `stock_levels` row exists at this exact company/location/product/variant grain, at projection time". A genuinely inventory-tracked physical product is then indistinguishable from a service item whenever its grain at that location has not yet been created.
*Failure scenario:* a second branch/terminal opens. Products stocked at HQ have no `stock_levels` row at the new location until the first receipt or transfer posts there. Every POS sale in that window books revenue with no movement and no COGS, and D-f is silent — permanently, since nothing back-fills the column. Round 2 offered this as one of two acceptable options, so the direction is sanctioned; what is missing is the record: the four other deferred gaps each got a ticket under `docs/superpowers/tickets/` and a §5 owner entry, while this one has a single sub-clause in `docs/follow-ups/2026-08-10-dpa-wave3-cogs-at-exit-release-note.md:53` and no owner, no ticket, no removal trigger.

**4. P2 — CONFIRMED — `PosCoreReceiptProjection.php:2123-2131` — the movementless-refund warning was widened from an anomaly signal to a per-transaction log on a normal disposition.**
Before round 3 the warning was guarded by `if ($disposition === ReturnLineDisposition::Restock)` — i.e. it fired only for the anomalous regulated-never-restock case. It is now unconditional for every line the writer classified movementless, which includes `ReturnLineDisposition::NotReceived` (a routine, high-volume disposition) and every refund line with no stock grain. The distinct never-restock message is gone; all four causes now share one string differing only by a context key.
*Failure scenario:* under production `LOG_LEVEL=warning`, a store with ordinary not-received refunds emits one WARNING per refund line forever, burying the genuinely anomalous regulated-item case. The same file states the rule this violates 100 lines below — `applyScrapDisposition` bails early specifically so a "benign, recurring case does not emit an ERROR on every occurrence (gate M2 log-noise)" (`:2231-2234`).

**5. P3 — CONFIRMED — `PosCoreReceiptProjection.php:1248` vs `:1970` — an explicitly unlocked pre-flight probe was promoted to the authoritative gate for the sale decrement.**
`stockGrainExists`'s own docblock says "no lock — this is a pre-flight benign-case probe; both legs take their own locks" (`:2308-2311`). `decrementStock` takes `lockForUpdate()` (`:1970`). Round 3 made the unlocked probe decide whether the locked write runs at all (`:1820`). In the non-race case the `continue` buys nothing — `decrementStock` already returns without writing when the row is absent — so it is pure added risk on top of finding 1's regression.
*Failure scenario:* a goods receipt (`WeightedAverageCostService::recordPurchase`) creates the grain between `writeLines` and `applyStockMovementForLines` for a concurrently-projected sale; the sale silently skips a decrement it would previously have made, and records `stock_movement_expected = false`, permanently hiding it from D-f.

**6. P3 — CONFIRMED — `tests/Feature/Fiscal/PosCoreReceiptProjectionRefundDispositionStockTest.php:129-145` — the new writer→column linkage test covers only the product-level grain.**
`test_sale_without_a_stock_grain_records_that_no_movement_was_expected` uses a plain, variantless product. No test pins the variant-grain case on the new path, which is why finding 2 shipped and why the only surviving signal was a *pre-existing* test in a file the round-3 evidence never ran. The D-f-side negative (`CheckCogsCoverageCommandTest:404-410`) is a column-level fixture, so it cannot detect the writer misclassifying.

**7. P3 — `docs/handoff/reviews/wave3-3c-3d/M3-evidence.md` (round-2 remediation section) — the evidence set does not cover the change surface.**
The recorded fresh run is three test files; the change edits `PosCoreReceiptProjection::writeLines` / `decrementStockForLines` / `restockForLines`, whose dedicated suite is 14 files. The revert-replay is honest for the three *new* assertions but is silent on regressions, which is the failure mode the M3 evidence-contract row (`brief:542`) names ("a watermark that silently reports everything" / green-that-was-already-green). A per-file run of `tests/Feature/Fiscal/PosCoreReceiptProjection*` takes ~4 minutes on PG and would have caught finding 1.

---

## Items verified and PASSING (not findings)

- **The M3 derived obligation — explicit reviewer confirmation.** D-e carries the `reference_type != 'stock_adjustment'` exclusion matching D-b (`CheckCogsCoverageCommand.php:226-229`), so D-20's deliberate no-GL adjustment population does not read as a permanent detector fire. **CONFIRMED PRESENT.** The counting-lane companion exclusion (`:231-236`) is now pinned by a real ticket with a removal trigger (`docs/superpowers/tickets/2026-08-18-remove-counting-detector-exclusion-with-t21.md`) plus release-note §2 — round-2 finding 3 closed.
- **Round-2 finding 4 (enum-cast rejection risk)** — closed correctly: `'disposition' => ReturnLineDisposition::tryFrom(...)?->value` (`:1294-1297`) restores the "a projector may never reject an already-signed event" doctrine.
- **Round-2 finding 6 (unscoped anti-join)** — closed: `stock_movements.tenant_id`/`company_id` now join `receipts` (`:299-300`), with a non-vacuous negative (`test_df_pos_arm_is_not_silenced_by_a_cross_company_movement`, asserts exit 1).
- **Round-2 finding 5 (inert interactive-return write)** — the misleading write and its claim were removed from `ReceiptReturnService::computeReturnTotals`; imports remain used, `StoreReturnRequestDispositionTest` green.
- **Round-2 finding 2 (archived-product scrap)** — closed at the writer boundary and pinned on both sides (`RefundDispositionStockTest:214-215` positive scrap expects `true`; `:509-510` archived scrap expects `false`).
- **D-f contract invariants intact** — `amount => null` (never `0`) on all three arms (`:280`, `:330`, `:388`); **no grace window** on the POS arm (no `occurred_at`/age gate); `arm`/`line_id`/`product_id`/source id/`document_number`/`document_date`/`age_days` all present; `check` widened to `a|b|c|d|e|f|g`; WO population still stubbed and not reported.
- **T18 / T19 / T19b / C-5 / R-5 / R-12 / R-14 / R-15** — untouched by round 3; rounds 1–2 confirmations stand (verified the round-3 diff touches none of those files).
- **Rule 19** — the round-3 diff contains no money or quantity arithmetic. No float, no `bcformat($float, …)`, no bare `getScale()`. `stock_movement_expected` is boolean; `age_days` is a non-monetary int.
- **Sealed bytes / fiscal-pos** — nothing that hashes changed; `stock_movement_expected` and `disposition` are projection columns with no canonical-payload participation and no verifier serialization. No new migration, queue, workflow, or i18n surface in round 3. Constructor injection preserved (`app()` only in tests).

## Bypasses attempted that FAILED (recorded)

1. *`ReceiptCreationService::decrementStock`'s "no stock record — skip" path (round-2 finding 1's second writer) still creates `stock_movement_expected`-defaulted lines that fire D-f* — new-sale authoring through that service is retired: `POST /api/v1/pos/receipts` and `POST /api/v1/pos/orders/{id}/close` are 410 Gone at the route closure (`ReceiptController.php:392-408`, `OrderController.php:350-363`), and `ExchangeService` (`:233`) has no controller or route. Failed.
2. *Interactive-return lines now default `stock_movement_expected = true` after the write was removed, so a real interactive restock hole fires D-f* — those lines are stored with a negative quantity (`ReceiptReturnService.php:1018`) and D-f requires `lines.quantity > 0`. Inert either way. Failed as a regression.
3. *A scrap refund whose `applyScrapDisposition` savepoint rolls back for a non-archived reason (unmapped write-off account) fires D-f forever with `expected = true`* — the GL leg is buffered, not posted inline; `ReturnScrapWriteOffService` throws only on product-unresolvable/valuation, both of which the round-3 `Product::exists()` guard already classifies. Failed as a P1; the residual (insufficient-available-quantity) is unreachable because the restore leg runs first.
4. *`stockGrainExists` uses `$productFk` while `decrementStock` uses `$line->productId`, so the probe and the write can disagree* — `resolveProductFk` returns the identical id when it resolves (`:1326-1348`); both pass `$line->variantId`, both scope by `$event->company_id`. Failed.
5. *`stock_movement_expected` is mutable after the fact by another writer* — the column has exactly one production writer (`writeLines`); `ReceiptLine.php:36,105,132` only declare it. Immutability against later catalogue-policy edits is pinned by `CheckCogsCoverageCommandTest:394-398`. Failed.
6. *`Restock` arm dereferences a null `$productFk` in `restockPolicyResolver->resolve()`* — `&&` short-circuits behind `$stockGrainExists`, which requires `$productFk !== null`. PHPStan level 8 clean. Failed.
7. *Round 3 broke another projection suite* — 13 of the 14 `PosCoreReceiptProjection*` files pass individually on PG, plus the five related POS/accounting/document suites. Only the variant file is red. (Running the whole directory under one `--filter` produced 37 spurious failures from cross-file tenant-DB pollution; per-file runs are the trustworthy signal — recorded so the next round does not mistake that for a wider regression.)

---

**Gate rationale.** Round 3 closes round 2's findings 2, 3, 4, 5 and 6 cleanly, and the `stock_movement_expected` mechanism — decided once in `writeLines`, driving both the persisted classification and the stock branch — is the right shape. But the fix reached past D-f into the sale writer: gating `decrementStockForLines` on the captured flag silenced the projection's own unseeded-variant alarm, which leaves a shipped test red on `HEAD` (reproduced twice, on two engines) and converts a real, remediable COGS hole into one that is invisible to the log *and* to the detector at the same time. M3's evidence contract asks each check to fire on a constructed positive and stay silent on a constructed negative; round 3 has bought silence on a negative by making a genuine positive unreportable, and the evidence run was too narrow to see it. Fix scope is small: drop the `continue` at `:1820` (it is redundant outside a race), keep the variant grain out of the "no movement expected" classification, restore the disposition-specific warning at `:2123`, and add a variant-line writer test plus a ticket for the location-grain blind spot.

VERDICT: CHANGES-REQUIRED
