# Stock Adjustment & Write-Off — Implementation Plan v2

> **For agentic workers:** REQUIRED SUB-SKILL: use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement task-by-task. Steps use `- [ ]` checkboxes.
>
> **Supersedes** the plan section of `2026-06-23-stock-adjustment-writeoff-audit-and-plan.md` (v1). The v1 **audit findings table (§1)** and **reframe (§0)** remain valid and are not repeated here — read them first. This v2 absorbs the Codex adversarial review (`docs/superpowers/reviews/2026-06-24-stock-adjustment-plan-codex-review.md`, verdict REJECT, 2 BLOCKER / 7 HIGH), all of whose load-bearing findings were independently verified against the code.
>
> **Status:** ✅ **Codex v2 review: APPROVE-WITH-CHANGES (87%), 0 BLOCKER / 5 HIGH / 4 MED** (`docs/superpowers/reviews/2026-06-24-stock-adjustment-plan-v2-codex-review.md`). **B0 is cleared to execute now** (Codex independently verified the fix + transaction rollback). The HIGH/MED items are refinements folded in below (§0.1) and must be applied to the *later* phases before they run — they do **not** gate B0.
>
> **B0 ✅ SHIPPED to `origin/dev` (`d01c71d10`, 2026-06-24)** — TDD regression test added, fix verified, Pint + PHPStan L8 clean, sibling audit done (bug was unique to write-off). Note discovered during execution: write-off GL is already **Posted** (not Draft) on dev, so Codex's HIGH 5 is largely moot — re-scope B2 accordingly. Remaining v1-scope phases (A, B1–B4, C, E) and Phase G are unstarted.

**Goal:** Make manual stock adjustments and lot/expiry write-offs carry a typed, mandatory reason, be lot-correct (no double-decrement), be reversible without mutation, and post correct cost/GL — by hardening the existing state-first posting path, not forking a new subsystem.

**Architecture:** State-first (verified): `stock_levels.quantity` is an authoritative cache mutated directly under a pessimistic lock inside a DB transaction; the movement ledger is parallel; events fire after-commit (advisory); **no** hash chain on movements. New work extends this pattern.

**Tech stack:** Laravel 12 / PHP 8.4 hexagonal (`apps/api/app/Modules/<Module>/{Domain,Application,Infrastructure,Presentation}`), PostgreSQL db-per-tenant (migrations under `apps/api/database/migrations/tenant/`), React 19 (`apps/web/src`). MinIO = `s3` disk.

## Global Constraints (verbatim, apply to every task)
- Quantity scale = 4 (`decimal(15,4)`); bcmath at scale 4. Money via currency-scale resolver at the GL boundary only. Never float on money/quantity.
- Cross-module communication only via `Shared/Contracts/` interfaces, Events, or a module's public Service (CLAUDE.md rule 6). No cross-module model imports.
- Routes: `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class,'module:<Module>']` + per-route `can:` gate.
- TDD: failing test first; tenant/company isolation tested with cross-tenant IDs; PHPStan level 8; Pint; ESLint. **Do not run the full PHPUnit suite** — scope with `--filter`.
- Frontend: all copy via `t()` (no raw text); design tokens only; `<QuantityInput>` (scale-4 strings, never `parseFloat`).
- **DB scope:** schedule the full PHPUnit suite/`preflight` only with explicit owner permission.

---

## 0. What changed from v1 (verified corrections)

| v1 claim | Correction (verified) | Plan impact |
|---|---|---|
| Expiry write-off is "~80% built; harden + surface". | **`BatchWriteOffService` double-decrements lot stock** (BLOCKER). `issue(batchId)` → `recordBatchMovement()` updates `inventory_batch_stock` (`StockAdjustmentService.php:825-858`); then `issueBatchStock()` updates it **again** (`BatchStockService.php:161-167`). Masked by an over-seeded test (`BatchWriteOffScalingTest.php:124-126`). | **B0 first**: fix the double-decrement with a regression test, before anything else in Phase B. |
| §6-A: keep `adjust()` lot-agnostic to **avoid coupling** core Inventory to optional BatchExpiry. | Coupling **already exists**: `StockAdjustmentService.php:7-8` imports `BatchExpiry\Domain\Entities\{BatchMovement,BatchStock}` and mutates `inventory_batch_stock` internally. | §6-A reframed as **boundary cleanup** (Phase G): an Inventory-owned port that BatchExpiry implements, OR move batch-stock mutation fully into BatchExpiry. Routing write-offs through BatchExpiry stands. |
| Movement `quantity` = positive magnitude, direction from `movement_type`. | **Not a universal invariant.** `issue()` stores positive (`:200`); the WAC path stores **negative** for the same `Issue` type. | Direction must be derived from the signed `quantity_before→quantity_after` delta, **not** from `quantity` sign or the enum alone (fixes Phase A3 design). |
| Acceptance: "GL/COGS post as today." | Write-off GL entry is created as **`JournalEntryStatus::Draft`**, not posted (`GeneralLedgerService.php:1482-1490`). The `issue()` movement carries **no cost fields** (`:782-794`), so reversal can't recover write-off cost. | New **cost/GL contract** task (B2): store unit/total cost on write-off movements; decide Draft-vs-posted. |
| New permission `inventory.writeoff`. | Existing **`batches.write-off`** already gates `WriteOffBatchRequest` (`RolesAndPermissionsSeeder.php:270-277`); BatchExpiry routes lack `module:` + `can:` middleware. | Reuse `batches.write-off`; add the missing route middleware (B-perm task). |
| §6-B: counting sets a typed reason (use generic `Adjustment*`). | Generic `AdjustmentPositive/Negative` don't distinguish count corrections from manual adjustments. | Add a **count-specific** `MovementReason::CountCorrection` and set it in the counting listener (behavior-neutral). |
| Justification docs = "add one `MediaOwnerType` case + rows". | Upload is product-specific (`MediaUploadService::uploadForProduct`, image-only). | Stays deferred (Phase F); the media-unification session owns genericization. |

---

## 0.1 Review response — refinements folded in (Codex v2 review)

Apply these to the named tasks at execution time. None gate B0.

- **[HIGH 1 → A1/A1b/B2] Extend the `issue()`/`receive()` contract, not just `adjust()`.** Write-offs post via `issue()` (`StockAdjustmentService.php:158-167`), which takes no reason/cost args, and `recordMovement()` (`:762-794`) writes neither. A1 must add an optional `?MovementReason $reason` AND B2 an optional cost (`?string $unitCost`) to `issue()`/`receive()`/`recordMovement()` — additively, without affecting unrelated issue callers — or BatchExpiry can never persist `Expiry/Damage/WriteOff` or original cost on the movement it returns.
- **[HIGH 2 → A2] `CountCorrection` is NOT behavior-neutral — update every enum helper + generated types.** Adding the case breaks the exhaustive `MovementReason::label()` `match` (`:75-94`) and is mis-defaulted by `getMovementType()` (unlisted ⇒ `'out'`, `:31-40`) and `requiresGLEntry()` (unlisted ⇒ `false`, `:57-72`, but adjustments need GL). A2 MUST add explicit branches in all three helpers AND update the generated TS union (`packages/shared/types/generated.d.ts:696`) via `php artisan typescript:transform`. Drop the "behavior-neutral" wording.
- **[HIGH 3 → B3] One canonical lock order across BOTH lock domains.** Sorting only `inventory_batch_stock` rows is insufficient: `issue()` locks `stock_levels` first (`StockAdjustmentService.php:173-192`), then `issueBatchStock()` locks the batch row (`BatchStockService.php:143-148`). Requests over `[A,B]` vs `[B,A]` can deadlock on the aggregate locks before the sorted batch phase. B3 must acquire **sorted `(product_id,variant_id,location_id)` aggregate locks, then sorted batch-stock locks**, in one orchestration, before any mutation.
- **[HIGH 4 → Phase G] Corrected caller list (v2's was wrong).** Direct `issue/receive(batchId)` callers are **only `BatchWriteOffService` and `StockTransferService`** (`:227-236,:323-332,:440-449`). GoodsReceipt/delivery-note use WAC + `BatchStockService` (not `issue(batchId)`); FEFO consumes batch rows directly; reservations only store batch ids; `AccountingOpeningService`'s `batchId` is an accounting-event payload (**remove it from the list**). **Add the missed path: `BatchController::transfer()` → `BatchStockService::transferBatchStock()` (`:178-216`)** moves batch stock between locations with **no aggregate `stock_movements`** — Phase G must audit it and either pair it with an aggregate transfer movement or restrict/retire it.
- **[HIGH 5 → B2] Draft-vs-posted is a real workflow choice, not a flag.** Reports aggregate `status='posted'` only (`TrialBalanceService.php:150-188`), so Draft write-offs never hit financials. Posting requires `postEntry()` (computes GL fiscal hash, requires a user/actor, `GeneralLedgerService.php:1091-1111`). B2 must name the **posting actor/system user, hash-chain behavior, and whether missing-GL-account is still swallowed** (today `BatchWriteOffService.php:82-106` catches and commits anyway) — and test the reporting consequence.
- **[MED 1 → A5] DROP A5 (locked).** A "manual path" CHECK isn't expressible: manual adjust, WAC cost-adjustment, and counting all use `MovementType::Adjustment`; WAC cost-adjust writes `reason=NULL` with `quantity_before==quantity_after`. Enforce mandatory reason at the **application boundary (A4) only**, unless a durable source/path discriminator column is added first.
- **[MED 2 → C0] Prefer create-time final metadata over a mutate-guard.** Safer than whitelisting post-create `movement_type` edits: change `StockTransferService` to create transfer movements with their final `movement_type`/`reference_*` in the first insert (`markMovementAsTransfer` is the **only** post-create mutator — `:618-624`). If a guard is kept, narrow it to exactly the receipt/issue→transfer_in/out transition while `reference_*` is empty; block all other `movement_type` changes.
- **[MED 3 → C2] Add original-GL handling + a DB double-reverse guard.** Reversal must look up the original write-off JE by `source_type='batch_write_off', source_id=$movementId` (`GeneralLedgerService.php:1488-1490`) and handle it being **absent** (non-positive amount, or GL swallowed) or Draft vs Posted. Add a **unique partial index on `reverses_movement_id` WHERE NOT NULL** (not just an app check) so concurrent reversals can't both pass.
- **[MED 4 → A4] `AdjustStockRequest` does not exist yet.** Manual adjust validates inline in `StockMovementController::adjust()` (`:214-245`). A4 must **create the FormRequest and rewire the controller** (not patch a nonexistent file).
- **[LOW 1 → A3] `directionForRow()` must return/​handle `'flat'`.** WAC cost-adjustment creates `quantity=0`, `before==after` (`WeightedAverageCostService.php:736-755`); every consumer migrated off `isInbound()` must handle the third state.

## 1. Locked decisions (v2)
- **Lot mechanism:** lot write-offs go through BatchExpiry's `BatchWriteOffService` (extended for multi-lot); Inventory's generic `adjust()` stays lot-agnostic. The existing Inventory→BatchExpiry coupling is cleaned up in **Phase G** (separate, non-blocking).
- **Counting discriminator:** add `MovementReason::CountCorrection`; the counting listener sets it (no behavior change to counting otherwise).
- **Correction:** `reverses_movement_id` self-FK + a `reverse()` flow with a **full cost/GL/batch-restore/scope contract** (Phase C). No status lifecycle; instead, **field-level immutability guardrails** on financial fields that still permit the legitimate post-create metadata updates existing flows need (e.g. `StockTransferService::markMovementAsTransfer()` sets `movement_type`/`reference_*` after create — `StockTransferService.php:440-451`).
- **Write-offs are quantity-out (delta);** count corrections keep the absolute `new_quantity` they reconcile to. UI/API contracts must make the two impossible to confuse.
- **Approval (Phase D) and justification docs (Phase F): DEFERRED** (see v1 §6).

---

## 2. File structure
Backend (`apps/api/app/Modules/`):
- `BatchExpiry/Domain/Services/BatchWriteOffService.php` — B0 (de-dupe), B-reason, B-cost, B-multilot.
- `BatchExpiry/Application/Services/BatchStockService.php` — single authority for `inventory_batch_stock` (B0/G).
- `BatchExpiry/Domain/Services/FEFOInventoryService.php` — expired-lot query → `available_quantity` (B1).
- `BatchExpiry/Presentation/{Controllers,Requests,routes.php}` — expired-lot list + grouped write-off endpoint + route middleware.
- `Inventory/Domain/Services/StockAdjustmentService.php` — persist `?MovementReason` (A1); cost fields on write-off issue (B2); port extraction (G).
- `Inventory/Domain/StockMovement.php` (+ migration) — `reverses_movement_id` self-FK + `reversesMovement()` + `directionForRow()` helper (A3); field-immutability guard (C).
- `Inventory/Domain/Enums/MovementReason.php` — add `CountCorrection`; add `requiresDocument()` (no-op default).
- `Inventory/Application/Listeners/ApplyStockAdjustmentsOnCountingCompleted.php` — pass `CountCorrection` (A2).
- `Inventory/Application/Services/{WeightedAverageCostService,InventoryOpeningService}.php` — reason backfill at create sites (A1b).
- `Inventory/Presentation/Requests/AdjustStockRequest.php` — mandatory restricted reason (A4).
- `Accounting/Domain/Services/GeneralLedgerService.php` — write-off journal status + reversing entry (B2/C).
- `database/seeders/RolesAndPermissionsSeeder.php` — confirm `batches.write-off`; grants.

Frontend (`apps/web/src/features/batches/`):
- `api/batches.ts` — add write-off + expired-lot clients (none today).
- new expiry-write-off screen + grouped-lot selection; reason-coded adjustment screen.

---

## 3. Phases & tasks

### Phase B0 — Fix the lot double-decrement (BLOCKER; do first). ✅ DONE (`d01c71d10`).

**Files:**
- Modify: `apps/api/app/Modules/BatchExpiry/Domain/Services/BatchWriteOffService.php:59-110`
- Test: `apps/api/tests/Feature/BatchExpiry/BatchWriteOffDoubleDecrementTest.php` (new)
- Fix: `apps/api/tests/Feature/BatchExpiry/BatchWriteOffScalingTest.php:123-155` (remove the 2× over-seed once the bug is gone)

**Root cause:** `writeOff()` calls `issue(batchId: …)` (which decrements `inventory_batch_stock` via `recordBatchMovement`) **and** `issueBatchStock(...)` (which decrements it again). `issueBatchStock` is the only one of the two that performs the batch-availability check (`InsufficientBatchStockException`). **Fix direction:** call `issue()` **without** `batchId` (aggregate `stock_levels` only) and let `issueBatchStock()` be the single authority for `inventory_batch_stock` + the `inventory_batch_movements` link (it already takes `movementId`). This keeps the availability guard and yields exactly one batch decrement + one ledger row.

- [ ] **Step 1 — Write the failing regression test.** Seed aggregate `stock_levels.quantity = '100.5000'` and `BatchStock.quantity = '100.5000'` (exactly the write-off amount, NOT over-seeded). Write off `100.5000`; assert both end at exactly `0.0000` and a single ledger row.
```php
public function test_write_off_decrements_batch_stock_exactly_once(): void
{
    // Seed tenant/company/location/product(cost_price '1.234')/batch as in BatchWriteOffScalingTest,
    // but seed StockLevel.quantity = '100.5000' and BatchStock.quantity = '100.5000' (exact, not over-seeded).
    app(BatchWriteOffService::class)->writeOff(
        batch: $this->batch, locationId: $this->warehouse->id,
        quantity: '100.5000', reason: 'expiry', userId: $this->user->id,
    );

    $this->assertSame('0.0000', (string) BatchStock::where('batch_id', $this->batch->id)
        ->where('location_id', $this->warehouse->id)->value('quantity'));        // bug → '-100.5000'

    $this->assertSame('0.0000', (string) StockLevel::where('product_id', $this->product->id)
        ->where('location_id', $this->warehouse->id)->value('quantity'));        // aggregate decremented once

    $this->assertSame(1, BatchMovement::where('batch_id', $this->batch->id)->count()); // one ledger row, not two
}
```
- [ ] **Step 2 — Run; verify it FAILS** (batch stock ends at `-100.5000` and/or 2 `BatchMovement` rows).
  Run: `cd apps/api && php artisan test --filter test_write_off_decrements_batch_stock_exactly_once`
- [ ] **Step 3 — Fix `BatchWriteOffService::writeOff()`** — drop `batchId` from the `issue()` call so it only touches aggregate stock; keep `issueBatchStock()` as the sole batch-stock writer:
```php
// 1. Deduct AGGREGATE stock only (no batchId → issue() no longer touches inventory_batch_stock)
$movement = $this->stockAdjustmentService->issue(
    productId: $productId, locationId: $locationId, quantity: $quantity,
    reference: "Write-off: Batch {$batch->batch_number}".($notes !== null ? " - {$notes}" : ''),
    userId: $userId, expectedCompanyId: $batch->company_id,
);
// 2. Deduct BATCH-level stock (single authority; performs availability check + links the movement)
$this->batchStockService->issueBatchStock(
    tenantId: $batch->tenant_id, batchId: (int) $batch->id, locationId: $locationId,
    quantity: $quantity, movementId: $movement->id,
);
```
- [ ] **Step 4 — Run; verify it PASSES.** Same `--filter` command → PASS.
- [ ] **Step 5 — De-seed the masking test.** In `BatchWriteOffScalingTest.php`, change the two `500.0000` seeds (commented "need ≥ 201") to the true single-decrement amount and update the comment. Run `--filter BatchWriteOffScalingTest` → PASS.
- [ ] **Step 6 — Audit sibling callers (defensive, same file scope).** Run `grep -rn "issueBatchStock\|receiveBatchStock" app` and confirm no other service both passes `batchId` to `issue()/receive()` **and** separately calls `issue/receiveBatchStock` for the same movement (GoodsReceipt/transfers/FEFO/delivery-note appear to rely on one path only — verify). Note findings in the commit message; fix any twin double-decrement the same way.
- [ ] **Step 7 — Commit.** `git commit -m "fix(batch): stop double-decrementing lot stock on write-off"`

### Phase A — Reason persistence & direction (decision-independent). Task altitude.
- **A1 — Persist `?MovementReason` through `StockAdjustmentService::recordMovement()`/`adjust()`** (optional param; null-safe). Test-gate: a movement created via `adjust(reason: …)` persists `reason`; existing callers unaffected. (Backwards-compatible — column already nullable, cast already `MovementReason|null`.)
- **A1b — Backfill reason at the other `StockMovement::create()` sites** that should carry one: `WeightedAverageCostService` (`:254-274,:550-567,:737-755`), `InventoryOpeningService` (`:279-292` → `OpeningBalance`), `BatchWriteOffService`'s movement (→ Expiry/Damage/WriteOff). Test-gate: each path persists the expected reason. **Do NOT add a NOT NULL / CHECK yet.**
- **A2 — Add `MovementReason::CountCorrection`; set it in `ApplyStockAdjustmentsOnCountingCompleted`** (`:84-92`), keep the `COUNTING:` reference. Test-gate: finalized count writes `reason = CountCorrection`; counting behavior otherwise unchanged.
- **A3 — Row-level direction helper.** Add `StockMovement::directionForRow(): 'in'|'out'|'flat'` derived from `bccomp(quantity_after, quantity_before)` (robust to the quantity-sign inconsistency). Do **not** rely on `MovementType::isInbound()` for adjustments. Update any report/consumer that currently mis-derives adjustment direction. Test-gate: negative-delta adjustment → `'out'`.
- **A4 — `AdjustStockRequest`: mandatory reason from the restricted manual set** (`AdjustmentPositive/Negative, Damage, WriteOff, OpeningBalance`); reject `CountCorrection`/document/POS reasons; keep the absolute `new_quantity`. Test-gate: missing/excluded reason → 422.
- **A5 — DROPPED (locked, §0.1 MED 1).** A "manual path" CHECK is not expressible from current columns: manual adjust, WAC cost-adjustment, and counting all use `MovementType::Adjustment`. Enforce mandatory reason at the application boundary (A4) only. Revisit only if a durable source/path discriminator column is added first.

### Phase B — Expiry write-off hardening + UI (after B0). Task altitude.
- **B1 — Expired-lot query correctness.** Change `FEFOInventoryService::getExpiredBatchesWithStock()` (`:284-294`) to filter `available_quantity > 0` (not raw `quantity`); expose it via a route. Return on-hand AND reserved per lot. Test-gate: a lot with all stock reserved is excluded.
- **B2 — Cost/GL contract (design sub-step, then implement).** Decide & document: (a) store `unit_cost`/`total_cost` on the write-off `issue()` movement (so reversal can recover it); (b) write-off journal status — **recommend posting (not Draft)** for write-offs, or document why Draft; (c) COGS/write-off expense account selection. Implement `calculateWriteOffAmount` to persist cost on the movement. Test-gate: write-off movement carries non-null `unit_cost`/`total_cost`; journal entry has the decided status and balances.
- **B-perm — Permissions/middleware.** Keep `batches.write-off`; add `module:BatchExpiry` + per-route `can:batches.write-off` middleware to `BatchExpiry/Presentation/routes.php` (currently absent). Test-gate: unauthorized user → 403; cross-tenant batch id → 404/403.
- **B3 — Grouped multi-lot write-off API (idempotency + locking).** New endpoint accepting `{location_id, lines:[{batch_id, quantity}], reason, idempotency_key}`. Sort lock acquisition by `batch_id` (deterministic order) across `inventory_batch_stock` rows; all-or-nothing in one transaction; persist a group/reference id; reject replays by `idempotency_key`. Do **not** loop the one-lot method naively. Test-gate: concurrent requests over overlapping lots don't oversell; replay is a no-op.
- **B4 — Frontend expiry write-off screen.** Pick location → list expiring/expired lots (B1, showing on-hand + reserved) → select lots+quantities (`<QuantityInput>`, scale-4) → reason auto `Expiry` → confirm → grouped write-off (B3). New i18n namespace/keys; `can('batches.write-off')` guard; e2e: select lots → confirm → stock + ledger update. No raw text.

### Phase C — Correction / reversal (BLOCKER 2; after B2 cost contract). Task altitude.
- **C0 — Field-immutability guardrails (not a status machine).** Protect financial fields (`quantity`, `quantity_before/after`, `reason`, `unit_cost/total_cost`, `product_id`, `location_id`) from post-create mutation (model `saving` guard or DB rule), **while preserving** the legitimate `markMovementAsTransfer()` updates to `movement_type`/`reference_*` — either by whitelisting those fields or by changing `StockTransferService` to set them at create time first. Test-gate: updating `quantity` on a posted movement throws; `markMovementAsTransfer` still works.
- **C1 — Migration: `reverses_movement_id` self-FK + `reversesMovement()` relation.** Test-gate: relation resolves; nullable; indexed.
- **C2 — `reverse(StockMovement $original, string $userId): StockMovement`.** Posts the inverse aggregate movement (recovering cost from the original's stored `unit_cost`), restores `inventory_batch_stock` + writes the inverse `inventory_batch_movements` row (via BatchExpiry), posts a **reversing journal entry** mirroring the original (account flip), enforces tenant/company scope, links `reverses_movement_id`. Test-gate: aggregate + batch stock restored to pre-write-off values; reversing JE balances and references the original; double-reverse rejected.
- **C3 — UI "Reverse / correct" action** (never "edit"), with a confirm dialog distinguishing reversal from a new adjustment. Test-gate: action posts a linked reversal; the original is shown immutable.

### Phase E — Opening balance polish (decision-independent). Task altitude.
- **E1 — Persist `MovementReason::OpeningBalance`** on `InventoryOpeningService` movements (folded into A1b); optional per-product single-shot opening entry. Test-gate: opening movement carries `OpeningBalance`.

### Phase G — Boundary cleanup (HIGH 5; independent, sequence after B). Task altitude.
- **G1 — Make BatchExpiry the single owner of `inventory_batch_stock`/`inventory_batch_movements`.** Introduce an Inventory-owned port (interface in `Inventory/Domain/Contracts`) that BatchExpiry implements, and stop `StockAdjustmentService::recordBatchMovement()` from writing BatchExpiry tables directly (it currently imports `BatchExpiry\Domain\Entities\{BatchMovement,BatchStock}`). **Corrected caller scope (§0.1 HIGH 4):** the only direct `issue/receive(batchId)` callers are **`BatchWriteOffService`** and **`StockTransferService`** — migrate those. **Also audit `BatchController::transfer()` → `BatchStockService::transferBatchStock()`** (moves batch stock with no aggregate movement). GoodsReceipt/delivery/FEFO/reservations/AccountingOpening do **not** call `issue(batchId)` and are out of scope. **Large; own branch; heavy regression coverage.** Test-gate: every batch-aware path produces identical stock/ledger results pre/post refactor.

### Deferred (not in this plan)
- **Phase D — Approval gate:** context-agnostic, built when the F&B-POS/high-value need is concrete (v1 §6).
- **Phase F — Justification documents:** via the unified MediaAsset system once the media-unification session genericizes upload for non-product owners + PDFs.

---

## 4. Test phase gates (promoted from examples)
- B0: regression proving single lot decrement (+ de-seeded scaling test).
- A: reason persisted at **every** constrained `StockMovement::create()` site; CountCorrection on finalize; direction helper for negative adjustments.
- B: expired-lot uses `available_quantity`; write-off movement carries cost; grouped write-off concurrency/idempotency; permission/tenant isolation.
- C: reversal restores aggregate + batch stock + posts balanced reversing JE + linkage; immutability guard blocks financial-field edits but allows transfer metadata.
- Frontend: i18n (no raw text), permission guards, scale-4 entry, lot-selection e2e.
- Precision: 4dp preserved through stock, batch stock, GL amount, UI (MED 6 — prefer the `QuantityScale` helper over scattered literal `4`s in new code).

## 5. Sequencing summary
**B0 (bug) → A1/A1b/A2/A3/A4 (reason+direction) → B1/B2/B-perm (query+cost+perms) → B3/B4 (grouped API+UI) → C0–C3 (reversal) → E1 (opening) → G (boundary cleanup).** A5 (DB constraint) only after A1b backfill. D and F deferred.
