# Final whole-branch review — Loyalty earn-on-purchase (demo)

**Branch:** `feat/loyalty-earn-per-product` (9 commits, `08bda972a..2d9197639`)
**Reviewer role:** final pre-merge whole-branch reviewer (cross-task integration focus)
**Date:** 2026-06-27

---

## VERDICT: READY TO MERGE

The end-to-end earn path is coherent and correct across all three layers (projection → contract → service → existing engine). Every cross-task signature, the idempotency design, the PostgreSQL in-transaction safety, the refund/void/training exclusions, the cross-module boundary, and both-layer module gating are verified-correct against the actual code. Scoped tests pass (BE 16/16, FE 2/2), PHPStan L8 clean, Pint clean, FE typecheck clean. No new Critical or Important findings. The carried Minors are all cosmetic/coverage polish — none block merge.

The one substantive observation (test gives partial confidence on the PG-abort scenario, see below) is a test-coverage gap, not a code defect — the protecting mechanism is real and present in the code.

---

## Whole-branch checks — results

### 1. End-to-end earn path coherence — PASS
- `PosCoreReceiptProjection::apply()` (line 323) calls `earnLoyaltyPoints(...)` with `$receiptId, $event, $view, $payload, $receiptTypeEnum, $totalNorm` — all in scope before the call (`$totalNorm` assigned at line 192, `$payload`/`$view`/`$receiptTypeEnum` earlier).
- `earnLoyaltyPoints()` (line 863) builds `SaleEarnContext` from the sealed view: `contactId: $view->buyer?->contactId`, `partnerId: $view->buyer?->customerId`. `BuyerDTO` (`Fiscal/Domain/DTOs/Canonical/BuyerDTO.php:28-29`) exposes exactly `?string $contactId` / `?string $customerId`. ✓
- `event_time_device` is cast `datetime` on `FiscalEvent` (Carbon) → satisfies `SaleEarnContext::$postedAt: CarbonInterface`. ✓
- Contract method name matches across boundary: `LoyaltyEarningContract::earnForSale(SaleEarnContext): void`, implemented by `SaleEarningService`, bound in `LoyaltyServiceProvider::register()`. ✓
- Repo methods consumed exist with matching signatures: `LoyaltyProgramRepositoryInterface::findByTenantAndStatus(string, ProgramStatus): Collection`, `EarningRuleRepositoryInterface::findActiveByProgram(string)` and `::save(EarningRule)`. ✓
- `ProgramActivated::$programId` exists (event ctor) — consumed by the seed listener. ✓
- `earnBase = $totalNorm = normalize($payload->total)` = receipt TTC total — matches the spec's locked "points per dinar paid". ✓
- Spend math (`PointEarningService::calculateBasePoints`) keys on `reward_value` only, never `reward_type`, so the seed's `reward_type='multiplier'` vs the factory's `'fixed'` is immaterial. ✓

### 2. Idempotency end-to-end (exactly-once) — PASS
- Layer 1 (projection): the `fiscal_event_id` fast-path guard short-circuits a replayed event before `earnLoyaltyPoints` runs. Proven by `test_replaying_the_same_fiscal_event_credits_once`.
- Layer 2 (engine): `EarningProcessingService::earnPoints` checks `findBySourceDocument($sourceType, $sourceId)` (line 66) and throws the already-earned `InvalidArgumentException` *before* opening its transaction. Proven by `SaleEarningServiceTest::test_replaying_the_same_source_credits_points_exactly_once` (two direct `earnForSale` calls → 1 Earn txn, balance unchanged).
- Both replay tests assert balance unchanged, so exactly-once holds at both layers. ✓

### 3. PostgreSQL in-transaction earn safety — PASS (mechanism confirmed in code)
- The earn runs *inside* the projection's outer `DB::transaction`, before `decrementStockForLines`. The protecting mechanism is real: `EarningProcessingService::earnPoints` wraps its writes in its **own** `DB::transaction` (line 91). Nested under the outer transaction, Laravel issues a **SAVEPOINT** on PostgreSQL, so a write failure inside earn rolls back only to the savepoint and re-throws; the outer sale transaction stays valid and `decrementStockForLines` proceeds.
- `SaleEarningService` performs only SELECTs outside that nested transaction (program lookup, member resolution, enrollment query) — no writes that could poison the outer transaction without savepoint cover.
- Two catch layers ensure a loyalty failure never propagates: `SaleEarningService` catches per-enrollment (`InvalidArgumentException` discriminated from `\Throwable`), and `earnLoyaltyPoints` wraps the whole call in `try/catch(\Throwable)+Log::error`. ✓

### 4. Refund/void/training excluded — PASS
- Guard at `earnLoyaltyPoints` (line ~872): `if ($receiptType !== ReceiptType::Sale || $payload->trainingFlag === true) return;`. REFUND/VOID map to `ReceiptType::Return` (via `resolveReceiptType`), training sets `trainingFlag=true` — both skip earning. Proven by `test_refund_receipt_credits_nothing` (projects original SALE first, then refund → Earn count stays 1) and `test_training_receipt_credits_nothing` (0 transactions). No code path credits points for non-sales. ✓

### 5. Cross-module boundary + both-layer gating — PASS
- POS imports only `App\Shared\Contracts\Loyalty\{LoyaltyEarningContract,SaleEarnContext}` — no Loyalty model referenced in `PosCoreReceiptProjection`. `SaleEarnContext` is a primitives-only readonly DTO. ✓ (rule 6)
- Backend gate: `GET loyalty/earn-rate` sits in the `module:Loyalty` route group with `->middleware('can:loyalty.view')`. Frontend gate: section + `LoyaltyPointsDisplay` rendered only under `hasModule('Loyalty')` (ProductForm sectionDefs + render block). The 403 test grants `loyalty.view` then disables the module, isolating `module:Loyalty` as the sole 403 source. ✓ (rule 12)

### 6. No float on money in persisted/credited path — PASS (branch code) / pre-existing caveat (engine)
- The branch passes money as strings end-to-end: `$totalNorm` (string) → `SaleEarnContext::$earnBase` (string) → `transactionData['amount']` (string). No `(float)` introduced by this branch on the credited path. ✓
- The FE `Number(salePrice)` / `Number(rate)` in `LoyaltyPointsDisplay` is display-only, never persisted and never added to `buildProductPayload` (untouched) — correctly ESLint-suppressed with rationale. ✓
- **Caveat (pre-existing, out of scope):** `PointEarningService::calculateSpendPoints` line 281 does `number_format((float) $rawAmount, $scale+4, …)` — a float cast on the credited amount. This lives in the **unchanged earning engine** the spec explicitly declared "the unchanged engine" and is not modified by this branch. For demo-scale TND totals the round-trip is exact (tests confirm `'10.00'→'10.000'`, `'12.000'→'12.000'`). Not a merge blocker; flagged for the future precision sweep, not this cutoff.

---

## New findings

**None at Critical or Important severity.**

### Minor (new) — N1: the "failure doesn't break the sale" test under-exercises the PG-abort path
`test_loyalty_earn_failure_does_not_break_the_sale` binds a stub whose `earnForSale` throws a `RuntimeException` **before any DB statement**. It therefore proves only that the projection's `try/catch` boundary swallows a pre-DB throw — it does **not** exercise the realistic scenario (a DB-level failure *inside* `earnPoints` that would abort an unsaved PostgreSQL transaction). The protecting mechanism (the savepoint from `earnPoints`'s own `DB::transaction`) is real and present in code, so this is a coverage gap, not a defect.
- **Severity:** Minor / defer. Acceptable for a controlled demo. If hardened later, add a test where the loyalty failure originates from a real failed write inside the nested transaction and assert both `pos_receipts` AND the stock decrement succeeded (proving the outer transaction was not poisoned).

---

## Triage of carried Minors

| # | Carried minor | Disposition |
|---|---|---|
| T2a | Three dead `use Illuminate\…\Factories\Factory;` imports in `EarningRule`/`Enrollment`/`LoyaltyMember` (docblocks now reference the concrete factory; `Factory` no longer used). Neither PHPStan nor Pint flags them. | **Defer** (cosmetic). Trivial to drop if touching the files anyway; not worth a churn commit on its own. |
| T2b | Non-duplicate `InvalidArgumentException` branch and the `\Throwable` branch log the **identical** message, making a contract violation indistinguishable from infra failure in logs. | **Defer, recommend follow-up.** Minor observability defect, not correctness. Suggest adding an `error_class` field (or distinct messages) when the loyalty hardening work lands. Not merge-blocking for a demo. |
| T4 | Activation test doesn't assert `conditions == []` nor that a pre-existing rule is left untouched (only asserts count stays 1). | **Defer.** Existing assertions adequately cover the seed/no-seed branches. Nice-to-have. |
| T5 | Earn-rate test uses `EarningRule::create` instead of `::factory()`. | **Defer.** Works correctly; purely stylistic. |
| T6 | `ProductForm` passes `salePrice={salePriceValue}` (brief suggested `?? ''`); redundant `Number.isNaN` alongside `Number.isFinite` in `LoyaltyPointsDisplay`. | **Defer.** FE typecheck is clean (so `salePriceValue` is typed `string`), and the NaN/undefined cases are safely caught by the existing guards. `Number.isFinite(NaN)` is already `false`, so the extra `Number.isNaN` is harmless dead-ish redundancy. Cosmetic. |

**None of the carried Minors must be fixed before merge.**

---

## Verified-correct (summary)
- 3-layer earn path wires up with no type/name mismatches across task boundaries.
- Exactly-once idempotency proven at both the projection (`fiscal_event_id`) and engine (`findBySourceDocument`) layers.
- PostgreSQL savepoint protection (nested `DB::transaction` in `earnPoints`) keeps a loyalty failure from aborting the sale transaction; duplicate check runs before the transaction opens.
- Refund/void/training credit nothing (guard + two passing tests).
- Cross-module boundary clean (POS imports no Loyalty model; primitives-only DTO).
- Both-layer module gating present and test-isolated.
- Money kept as strings through the branch's credited path; FE `Number()` is display-only and never persisted.
- Default 1 TND = 1 point Spend rule seeded idempotently on activation (no-clobber when an active rule exists).
- `apiGet` single-unwrap contract respected by `useLoyaltyEarnRate` (controller returns `{data:{rate}}`).
- All declared OUT-of-scope items (per-product persistence, pay-with-points, refund reversal, durable retry queue, multi-enrollment idempotency) are correctly absent — not flagged.

---

## Recommendation
Merge as-is. Optionally fold T2a (dead imports) and T2b (distinct log messages) into the next loyalty-hardening commit; neither gates this demo cutoff.
