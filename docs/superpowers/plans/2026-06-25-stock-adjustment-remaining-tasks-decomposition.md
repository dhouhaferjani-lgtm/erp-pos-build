# Stock Adjustment / Write-Off — Remaining Tasks (bite-sized decomposition for SDD)

> **Purpose:** Execute the remaining stock-adjustment work in a **fresh session** via `superpowers:subagent-driven-development`. This doc decomposes the v2 plan's task-altitude items (C, B3, B4, G) into independently-testable, TDD-shaped sub-tasks with files, interfaces, test-gates, and the design decisions already resolved. Parent: `2026-06-24-stock-adjustment-writeoff-plan-v2.md` (+ its `§0.1` review refinements).

## Already shipped on `origin/dev` (do NOT redo)
B0 `d01c71d10` · A1+A1b-writeoff `f9891f530` · A2 `43fccac30` · A3 `0954ef20a` · A4 `94061c244` · E1 `32f087a4b` · B1 `7b676f2b3`+`25ebf84e7` · B2 `9d0a67273`+`60de03291`. So: reason persistence (incl. CountCorrection), `directionForRow()`, typed manual-adjust reason (backend+web), opening-balance reason (+ a real postBatch bug fix), `available_quantity` expired-lot query + `GET /batches/expired`, and write-off `unit_cost`/`total_cost` snapshot (unified `resolveUnitCost`) are all DONE.

## Execution environment (every subagent must be told)
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp.stock-adj`; backend `apps/api`, web `apps/web`. Branch `feat/stock-adjustment-writeoff` (off `origin/dev`); composer + pnpm deps installed. Implementers **commit but do NOT push** — the orchestrator pushes to `dev`.
- Tests: backend `php artisan test <path>` (sqlite `:memory:`); web `pnpm exec vitest run <path>` + `pnpm typecheck` + `pnpm exec eslint <file>`. **NEVER run the whole PHPUnit suite** (no path/filter) — it crashes the machine. Always scope.
- ~9 PRE-EXISTING failures in `tests/Feature/Inventory` + `tests/Feature/BatchExpiry` (test-isolation + a `BatchChainE2ETest` QueryException) are unrelated — do NOT touch.
- Precision: quantity `decimal(15,4)` scale 4; cost `decimal:6`; money via currency-scale resolver at the GL boundary. **bcmath only, never float/parseFloat.** Web: `<QuantityInput>`/`<MoneyInput>` (strings), `formatQuantity`.
- Custom validation 422 envelope is `{error:{code,message,errors:{…}}}` — in tests use `$response->assertJsonValidationErrors('field', 'error.errors')`.
- Module routes: `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class,'module:<Mod>']` + per-route `can:`; literal routes BEFORE `{uuid}` routes. Write-off permission is the existing `batches.write-off`.
- Quality gate per task: failing test first → green; Pint + PHPStan L8 on changed PHP; tsc+eslint on changed web; commit. Web copy via `t()` only; design tokens only.
- Push discipline (orchestrator): the `dev-push-guard` PreToolUse hook blocks a push when local `dev` is behind `origin/dev`. Catch up FIRST in the `apps/erp.dev-consolidation` worktree: `git -C ../erp.dev-consolidation fetch origin dev && git -C ../erp.dev-consolidation merge --ff-only origin/dev`, then push `git push origin HEAD:dev` as a clean fast-forward (in a SEPARATE command — the guard scans the whole command up-front).
- Ledger: `.superpowers/sdd/progress.md` in the worktree. Append one line per completed task. Resume from the first unchecked task after any compaction.

## Resolved design decisions (orchestrator)
- **D-C0 (immutability):** Reversal (C2) does NOT require it — C2 creates a NEW movement, never mutates the original. So **do C1→C2→C3 first**; treat the field-immutability guard (C0) as a LATER, optional hardening sub-task (it requires refactoring `StockTransferService::markMovementAsTransfer`, the only post-create mutator — Codex MED 2 — which is the riskiest change). When done, prefer **create-time-final-metadata** (create transfer movements with final `movement_type`/`reference_*` at insert) over a mutate-guard whitelist.
- **D-B4 (UI source):** the expiry write-off UI uses the **grouped** endpoint (B3) — select multiple lots, confirm once is the correct pharmacy UX. So **B3 before B4.**
- **Double-reverse prevention** is enforced at BOTH the DB (unique partial index) and the app layer.

## Recommended order
C1 → C2 → C3 → B3a → B3b → B4a → B4b → B4c → (optional) C0 → (optional) G.

---

## Phase C — Reversal / correction of a posted write-off

### C1 — `reverses_movement_id` self-FK + relations + double-reverse DB guard
- **Files:** new migration `database/migrations/tenant/…_add_reverses_movement_id_to_stock_movements.php`; `Inventory/Domain/StockMovement.php` (fillable + relations); test `tests/Feature/Inventory/…`.
- **Do:** add nullable `uuid reverses_movement_id` (self-FK → `stock_movements.id`, nullOnDelete). Add `reversesMovement(): BelongsTo` and `reversalOf(): HasOne` (inverse). Add a **UNIQUE partial index** `WHERE reverses_movement_id IS NOT NULL` so a movement can be reversed at most once at the DB level. Add `reverses_movement_id` to `$fillable`.
- **Test-gate:** relation resolves; column nullable + indexed; inserting two rows with the same `reverses_movement_id` raises a unique violation (PG; note the sqlite test caveat — assert at the app layer too).
- **Risk:** low. **Model:** cheap.

### C2 — `ReverseWriteOffService::reverse(StockMovement $original, string $userId): StockMovement`
- **Files:** new `BatchExpiry/Domain/Services/ReverseWriteOffService.php` (or a method on `BatchWriteOffService`); uses `StockAdjustmentService`, `BatchStockService`, `GeneralLedgerService`; route+controller+FormRequest under `BatchExpiry/Presentation`; tests.
- **Do (one DB transaction):**
  1. Validate `$original` is a reversible write-off (its `reason ∈ {Expiry,Damage,WriteOff}`, not already reversed — check `reversalOf` + the DB unique index), scoped to caller tenant/company.
  2. Post an **inverse aggregate movement** via `StockAdjustmentService::receive(... reason: $original->reason, unitCost: $original->unit_cost)` (recovers original cost from the row — B2 made that possible) referencing the original.
  3. Restore lot stock: `BatchStockService::receiveBatchStock(... movementId: $inverse->id)` (inverse of `issueBatchStock`) — find the original batch via the original's `inventory_batch_movements` link.
  4. Post a **reversing journal entry** mirroring the original write-off JE: look it up by `source_type='batch_write_off', source_id=$original->id` (handle ABSENT — non-positive amount or GL was swallowed — and Draft-vs-Posted: mirror the original's status/amount with debit/credit flipped). Use `resolveUnitCost`/the stored `unit_cost` so the reversed amount equals the original.
  5. Set `$inverse->reverses_movement_id = $original->id`.
- **Test-gates:** aggregate `stock_levels` AND `inventory_batch_stock` restored to pre-write-off values; one inverse `inventory_batch_movements` row; reversing JE balances and references the original (amount equals original); `reverses_movement_id` set; **double-reverse rejected** (DB + app); cross-tenant/company original rejected; reversing a non-write-off movement rejected.
- **Risk:** HIGH (fiscal GL + batch + aggregate). **Model:** most-capable. Codex-style adversarial review recommended after.

### C3 — Web "Reverse / correct" action
- **Files:** `apps/web/src/features/...` (wherever movements/write-offs are listed; likely a batch or movement detail/list); api client; i18n en/fr; vitest.
- **Do:** a "Reverse" action (never "Edit") on a posted write-off → confirm dialog → POST the C2 endpoint → invalidate stock queries. Show the original as immutable. Tokens + `t()` only.
- **Test-gate:** action posts a reversal; disabled/absent for already-reversed; vitest for the component.
- **Risk:** medium. **Model:** standard.

---

## Phase B3 — Grouped multi-lot write-off (concurrency-safe)

### B3a — `GroupedWriteOffService` with canonical cross-domain lock order + idempotency
- **Files:** new `BatchExpiry/Domain/Services/GroupedWriteOffService.php`; tests incl. a concurrency/ordering test.
- **Do (one DB transaction):** input `{location_id, lines:[{batch_id, quantity}], reason, idempotency_key}`. Acquire locks in ONE canonical order to avoid the cross-domain deadlock (§0.1 HIGH 3): **first** `stock_levels` row locks sorted by `(product_id, variant_id, location_id)`, **then** `inventory_batch_stock` locks sorted by `batch_id` — BEFORE any mutation. All-or-nothing (any lot short → whole group rolls back). Idempotency: persist a group record keyed by `idempotency_key`; a replay returns the prior result without re-applying. Reuse the single-lot decrement primitives (`issue()` no-batchId for aggregate + `issueBatchStock` for the lot, per B0) but batch the locking.
- **Test-gates:** two requests over overlapping lots/products in opposite line order do not deadlock and do not oversell; replay with the same `idempotency_key` is a no-op returning the same result; one short lot rolls back ALL lots; each resulting movement carries `reason` + `unit_cost` (A1/B2).
- **Risk:** HIGH (concurrency). **Model:** most-capable. Adversarial review recommended.

### B3b — Grouped write-off route + controller + FormRequest
- **Files:** `BatchExpiry/Presentation/{Controllers,Requests,routes.php}`; tests.
- **Do:** `POST /api/v1/batches/write-off-grouped` (or similar), `can:batches.write-off`, module gating; `FormRequest` validating `location_id` (uuid/scoped), `lines.*.batch_id`, `lines.*.quantity` (4dp regex), `reason ∈ {expiry,damage,other}`, `idempotency_key`.
- **Test-gate:** validation (422 via `error.errors`), auth (403), tenant scope; happy path 201.
- **Risk:** low-medium. **Model:** standard.

---

## Phase B4 — Dedicated expiry write-off UI (uses B3 + `/batches/expired`)

### B4a — API client + types
- **Files:** `apps/web/src/features/batches/api/batches.ts` (+ types). **Do:** add `getExpiredBatches(locationId?)` (→ `GET /batches/expired`) and `groupedWriteOff(payload)` (→ B3b). **Test-gate:** typecheck; thin client test if the area has them.

### B4b — Expiry write-off screen
- **Files:** new `apps/web/src/features/batches/…ExpiryWriteOffPage.tsx`; i18n en/fr `inventory`/`batches`; vitest.
- **Do:** pick location → list expiring/expired lots (show on-hand + reserved from the resource) → select lots + per-lot quantity (`<QuantityInput>`, scale-4 strings, no parseFloat) → reason auto `Expiry` (+ optional note) → confirm → grouped write-off → invalidate stock. `t()` + design tokens only; permission guard `can('batches.write-off')`.
- **Test-gate:** vitest: lot selection + confirm calls the client with the right payload; no raw text; tsc + eslint clean.
- **Risk:** medium. **Model:** standard.

### B4c — Navigation/route entry
- **Files:** `apps/web/src/routes/index.tsx`, sidebar/command-palette. **Do:** route + nav entry under Inventory module gating. **Test-gate:** route renders behind the gate.

---

## Phase C0 (optional, later) — Posted-movement field immutability
- **Files:** `Inventory/Application/Services/StockTransferService.php` (C0a refactor), `Inventory/Domain/StockMovement.php` (C0b guard), tests.
- **C0a:** create transfer movements with their final `movement_type`/`reference_type`/`reference_id` at insert (extend the `issue()/receive()` path or a dedicated transfer-create), removing `markMovementAsTransfer`'s post-create mutation (the ONLY such mutator — Codex MED 2). **C0b:** model `saving` guard rejecting changes to financial fields (`quantity`, `quantity_before/after`, `reason`, `unit_cost`, `total_cost`, `product_id`, `location_id`, `movement_type`) on an existing row.
- **Test-gates:** updating a financial field on a saved movement throws; transfer creation still produces correct `transfer_in/out` rows; full transfer + WAC suite green.
- **Risk:** HIGH (touches transfers). **Model:** most-capable. Do AFTER C2/C3, gated on a deliberate review.

## Phase G (optional, last) — Inventory↔BatchExpiry boundary cleanup
- **G1:** Inventory-owned port interface in `Inventory/Domain/Contracts` for batch-stock mutation, implemented by BatchExpiry. **G2:** route `StockAdjustmentService::recordBatchMovement` + the `issue/receive(batchId)` callers (only `BatchWriteOffService` + `StockTransferService` — Codex HIGH 4) through the port; audit `BatchController::transfer`→`BatchStockService::transferBatchStock` (moves batch stock with NO aggregate movement). **G3:** remove the direct `BatchExpiry\Domain\Entities` imports from Inventory.
- **Risk:** HIGH (broad refactor, regression-prone). **Model:** most-capable. Heavy regression coverage; own branch. Lowest priority (cleanup, not feature).

## Deferred follow-up status (owner decision, 2026-06-26)
Core subsystem (B0, A1–A4, B1–B4, C1–C3, E1) is **shipped and merged to `dev`** (`7a42ad46a`, merge of `feat/stock-adjustment-writeoff`, clean ff promotion, fiscal reversal/grouped tests green pre-merge). The two remaining items below are **explicitly deferred** — they are hardening/cleanup, not feature gaps, and do **not** block the customer demo.
- **C0 (posted-movement immutability)** and **G (Inventory↔BatchExpiry boundary cleanup)** are to be done **later, each in its own feature branch**, **C0 before G** (G would otherwise re-churn the same transfer/batch files).
- Each must land with **regression coverage / documented use-cases** that prove no behavior change (C0: full transfer + WAC suite; G: byte-identical stock/ledger results pre/post on every batch-aware path) **plus a Codex adversarial pass** before merge. Merge only when green.
- Cheap win extractable independently of the full G refactor: the **`BatchController::transfer()` → `transferBatchStock()` audit** (moves batch stock with no aggregate `stock_movements` row — may hide a real ledger gap).
- See risk/complexity write-up in the session handoff; both are HIGH-surface-area, most-capable-model work.

## Cross-session: shared `journal_entries(source_type, source_id)` uniqueness — RESOLVED (2026-06-26)
The procurement-to-pay session added a uniqueness guard on the shared `journal_entries(source_type, source_id)` table (their Task B2, `134e7b382`, 20 tests). Decision (coordination note `docs/superpowers/coordination/2026-06-26-procurement-to-stock-adjustment-coordination.md`): **supplier-scoped partial index** `WHERE source_type IN ('supplier_invoice','supplier_credit_note')` — **NOT global.**
- **Why not global:** a global unique index would break flows that legitimately write multiple JEs per `source_id` — independently confirmed for `prepayment_application` and `pos_receipt`. (See [[reference_journal_entries_no_global_source_uniqueness]].)
- **Impact on us: none.** The index excludes `batch_write_off`/`batch_write_off_reversal`. Verified our flows are already strictly one-JE-per-`(source_type, source_id)`: write-off posts `batch_write_off`+movementId (single, and grouped multi-lot loops one JE per line/movement — never a shared id); the reversal posts a **distinct** `source_type='batch_write_off_reversal'` + the inverse movement id, so it never collides with the original.
- **Our idempotency is unchanged:** app-level already-reversed guard + the `stock_movements.reverses_movement_id` partial unique index (the C1/MED-3 DB double-reverse guard). If we ever want DB-level structural idempotency on write-offs too, add our **own** `WHERE source_type IN ('batch_write_off','batch_write_off_reversal')` partial index in a coordinated migration — do NOT add an overlapping/global one on this shared table.

## Deferred (not in this decomposition)
- Approval gating (Phase D) — context-agnostic, when F&B-POS/high-value need is concrete.
- Justification documents (Phase F) — via the media-unification session (`docs/superpowers/coordination/2026-06-24-media-unification-handover.md`).
- A1b WAC-create-site reason backfill — sensitive/lower-value; optional.

## Minor findings carried for the final whole-branch review
- B2: test+impl in one commit (RED not in git history); two `Product::find()` in the write-off path (perf, low-traffic).
