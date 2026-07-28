# CODEX BRIEF — Live Inventory Counting: completion lane

**Date:** 2026-07-28
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.counting-completion`
**Branch:** `feat/live-counting-completion` (based on `dev` @ `32da5bd9d`, which == `origin/dev`)
**Owner of merge:** the orchestrating Claude session. **You do NOT merge, you do NOT push to `dev`.**
**Design reference:** [`docs/superpowers/specs/2026-07-06-live-inventory-counting-design.md`](../superpowers/specs/2026-07-06-live-inventory-counting-design.md)

---

## 0. Why this lane exists

Live counting (counting while the shop keeps selling) shipped and its **core is correct** — `StockAdjustmentService::applyCountResult()` re-reads live on-hand under a row lock and posts a *delta* computed by replaying movements in `(T → now]`. That is not in question and must not be redesigned.

What shipped incomplete are the **guards around** that core. A tenant is about to be onboarded and a non-technical tester is about to run counts on staging with coarse scopes. Each task below closes one verified gap.

Every claim below was verified against the code in this worktree. File:line citations are given so you can re-verify independently — **do so before changing anything**, and if a citation does not match what you find, STOP and report the discrepancy rather than guessing.

---

## 1. HARD CONSTRAINTS — read before writing any code

### 1.1 The fiscal-payload freeze (violating this corrupts a parallel lane)

A **parallel session owns `feat/pos-cash-rounding`**, which is mid-flight on a **SALE_RECEIPT v3 canonical payload migration** (new key set, strict-parser changes, Z-report hash key, v3-gated projection writes). Canonical event-version expansions are not parallel-safe — this is a standing locked decision in this codebase.

**You must NOT touch any of:**

- `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php`
- the fiscal event payload registry, `StrictCanonicalParser`, `CanonicalPayloadReader`, `BestEffortPayloadParser`, `FiscalPayloadConstraintValidator`
- any canonical golden-vector fixture or drift gate
- `packages/shared/types/generated.d.ts` (regenerate only if a DTO you own changes, and say so loudly in your report)

Task A3 below is deliberately designed to need **none** of these. If you find yourself reaching for the projection, you have taken a wrong turn — stop and report.

### 1.2 Repo rules that will fail CI if broken

- **Never run the full PHPUnit suite.** It crashes the owner's laptop. Run tests **by path** only, e.g. `cd apps/api && ./vendor/bin/phpunit tests/Feature/Inventory/ZoneScopedCountingTest.php`.
- **TDD is mandatory.** Write the failing test first, watch it fail, then implement. Every task below names its tests.
- **Constructor injection only** — `private readonly`, never the `app()` helper.
- **Strict typing** — no `mixed` in PHP, no `any` in TS. PHPStan level 8, zero new errors.
- **Precision contract**: quantities are decimal **strings** at scale 4 via `QuantityScale`/bcmath. No floats, no `parseFloat`/`Number()` on quantity or money. Frontend uses `<QuantityInput>` / `<MoneyInput>`.
- **UoM guards are live and their baselines are EMPTY** (`[]`) as of this base commit. Any new hardcoded decimal-places literal or raw quantity input will fail the guards. Do not add baseline entries to re-allow drift.
- **All user-facing frontend text via `t()`** — no hardcoded strings.
- **Enums for every status/type** — no magic strings.
- **TanStack query keys** for tenant data must use `tenantScopedKey([...])`.

### 1.3 Deploy-safety

Pushing to `origin/dev` **auto-deploys staging and runs `tenants:migrate`**. If any task here needs a migration, it must be **self-guarding in code** (idempotent, safe on a DB where the prerequisite has not run). Say so explicitly in your report if you add one.

---

## 2. Tasks

Work them **in order**. Each has a review gate that must pass before you start the next.

---

### A1 — Counting must preserve precise placements inside the counted subtree

**Size: S. Highest priority — the tester will hit this within a day.**

**The bug (verified):** `InventoryCountingService::assignCountedItemToZone()` (`apps/api/app/Modules/Inventory/Application/Services/InventoryCountingService.php`, the `assignCountedItemToZone` method) calls `$this->zoneService->assignProduct(...)` unconditionally for every counted item on a zone-scoped count. `LocationNodeService::assignProduct()` (`apps/api/app/Modules/Inventory/Application/Services/LocationNodeService.php`) then **unconditionally overwrites `node_id`** on the live `ProductPlacement`.

Consequence: counting a coarse ancestor (Aisle `A1`) re-homes every product beneath it up to `A1`, destroying precise bin placements (`A1/R2/B7` → `A1`). Stock math is unaffected — placements are labels — but the shelf map is silently flattened by a single aisle-level count.

**Owner-ruled semantics (do exactly this):** on finalize, re-home a counted product **only** when its current live placement is **outside** the counted subtree, or it has no placement. A product already placed anywhere **within** the counted node's subtree keeps its precise placement untouched.

**Implementation notes:**
- The subtree-membership check must reuse the canonical collision-safe form — `LocationNode::scopeSubtreeOf` (there is an existing `subtreeOf` usage in `zoneItemSeeds` in the same service you can model on). A bare prefix `LIKE` is WRONG: `A1` would match `A10`.
- **Placement label writes only.** No stock, quantity, or movement logic may change.

**Tests (TDD, red first)** — extend `apps/api/tests/Feature/Inventory/ZoneScopedCountingTest.php`:
1. Product placed at `A1/R2/B7`, counted under scope `A1` → placement **remains** `A1/R2/B7` after finalize. *(fails on current behavior)*
2. Product placed at `B9/...` (outside subtree), counted under `A1` → re-homed to `A1`. *(preserves current behavior)*
3. Unplaced product counted under `A1` → placed at `A1`. *(unchanged)*
4. Collision: product at `A10/R1`, counted under `A1` → treated as OUTSIDE → re-homed to `A1`.
5. Regression: the existing E2E in that file currently asserts the *coarsening* behavior (a product moved to a bin mid-test ends at the aisle). Update it to assert the new preserve semantics, and assert stock rows are byte-unchanged.

**Review gate A1:** dispatch the `inventory-costing-reviewer` agent on **Opus**. Save its review to `docs/handoff/gate-reviews-counting/A1-<round>.md`. Fix findings, re-review until APPROVE.

---

### A2 — POS terminal must pick up a sales-block without a restart

**Size: S. Client-side only — the server already works.**

**Verified state:** `TerminalController::show()` (`apps/api/app/Modules/POS/Presentation/Controllers/TerminalController.php`) **does** eager-load `->with(['location', 'company'])`, so `TerminalResource`'s `whenLoaded('location', ...)` fires and `GET /pos/terminals/{id}` **already returns** `active_counting_block` and `counting_zone_advisories` (`TerminalResource.php`, the `active_counting_block` / `counting_zone_advisories` keys). **No server change is needed. Do not add one.**

**The gap is purely in the POS client:** `pullTerminalState()` (`apps/pos/src/lib/sync/syncService.ts`) polls that exact endpoint on the periodic sync loop, but its `TerminalStateResponse` interface does not declare the two counting fields, so they are parsed away and never projected into the terminal store. `useTerminalStore.initialize()` (`apps/pos/src/stores/terminalStore.ts`) reads them **only at boot/login**.

Consequence: a till already running when a manager starts a `block_sales` count **keeps selling** until the app is restarted. "Blocking" mode does not block in practice. The server accepts the signed sale by design (device is fiscal source of truth) and merely flags it — so the operator gets no signal at all.

**Implement:**
- Extend `TerminalStateResponse` with `active_counting_block` and `counting_zone_advisories`, both **optional** in the wire shape (a stale server must not break the pull — follow the exact defensive pattern already used for `fiscal_schema_version` and `max_shift_number` in that same interface, including their explanatory comments).
- Project both into the terminal store / local state on every successful pull, so `stockGate.ts`'s existing `blockedByCounting` gate and the `cartIngress.ts` advisory toasts react within one sync interval.
- Absent/undefined ⇒ treat as "no block" / empty advisories. Never let a missing field wedge the till into a permanent block — a fail-closed bug here stops the shop trading.

**Tests:** POS vitest alongside the existing sync tests — (a) a pull carrying an active block flips the store and `gateStockForAdd` refuses a cart add; (b) a pull with the block cleared restores selling; (c) a stale-server payload omitting both fields leaves selling enabled and does not throw.

**Review gate A2:** `fiscal-pos-reviewer` on **Opus** (POS sync + device-state path). Save to `docs/handoff/gate-reviews-counting/A2-<round>.md`.

---

### A3 — Late-syncing sales must not silently double-subtract

**Size: L. The one genuine correctness hole. Read §1.1 again before starting.**

**The defect (verified):** a POS sale can sync **after** a count has already finalized while its `occurred_at` (device event time) **precedes** the count instant. The physical count already reflected that sale — the item was gone from the shelf when counted — so finalize's replay correctly excluded it. When the movement row lands later, `decrementStock` subtracts it again from the already-corrected level. Result: an unexplained, unflagged phantom shortfall equal to that sale, with no link back to the count that absorbed it.

`CountingBlockService::ACTIVE_BLOCK_STATUSES` excludes `Finalized`, so the existing late-sale flagging never runs for this case. The design doc (§4, "Unsynced-device warning") specified a mitigation; grep confirms **it was never built**.

Deliver **two independent halves**. Both live entirely in the Inventory module and read `stock_movements`. **Neither may touch the POS projection** (§1.1).

**A3a — Pre-finalize: unsynced-device warning that gates finalize.**
Before finalize, surface whether any POS terminal at the scoped location(s) has unsynced/pending receipts or a stale last-sync. Show it on the review page; require explicit acknowledgement before the Finalize button enables. Reuse whatever terminal last-sync/heartbeat signal already exists — **audit first and report what you found**; if no adequate signal exists, report that and propose the cheapest honest one rather than inventing a heavy new subsystem.

**A3b — Post-finalize: detect and surface the residual.**
A read-only detector that finds movements where `COALESCE(occurred_at, created_at)` precedes a finalized count's `final_qty_as_of` for that `(product, location, variant)` grain, but whose row was **created after** that count's finalize. Attribute each to the count it slipped past, and surface them on the counting's detail/report view as "sales that arrived after this count was finalized — stock has been reduced twice for these lines," with the quantity involved.

**Explicitly NOT in scope:** auto-correcting the stock. Automatic reversal risks double-correcting a variance an operator already fixed by hand. Detect, attribute, and surface — the operator decides. State this limitation in the UI copy.

**Tests:** table-driven in a new `apps/api/tests/Feature/Inventory/LateSyncResidualTest.php` — (a) sale with `occurred_at` before `T`, row created after finalize → detected and attributed; (b) sale after `T` synced before finalize → NOT flagged (normal replay handles it); (c) sale before `T` synced before finalize → NOT flagged; (d) multiple counts on the same grain → attributed to the correct one; (e) detector is read-only — assert no stock rows or movements change. Per repo rule 20, projection-adjacent tests must `app(CompanyContext::class)->clear()` before `apply()`, and queued/console contexts need explicit currency passed to scale resolution.

**Review gate A3:** **two** reviewers, both **Opus**: `inventory-costing-reviewer` (correctness of the residual math) **and** `fiscal-pos-reviewer` (confirm nothing touched the fiscal/projection path). Both must APPROVE. Save to `docs/handoff/gate-reviews-counting/A3-<reviewer>-<round>.md`.

---

### A4 — Show the reviewer what will actually post, before they finalize

**Size: M.**

**Verified state:** `expected_qty_at_apply` and `replay_audit` are populated **only after** finalize runs, by the queued listener `ApplyStockAdjustmentsOnCountingCompleted`. `CountingReconciliationPayloadBuilder::transform()` exposes those fields, but at review time they are still null. The reviewer therefore sees raw counts, `theoretical_qty`, and variance — but **no preview** of the replay-adjusted quantity that will actually post. They click Finalize blind. The design doc §6 specified these review columns; only `late_sales_flags` made it into the UI.

**Implement:** a **read-only dry-run** of the replay for a count in `PendingReview` — reuse `MovementReplayService` on a non-mutating path; do not duplicate its math. Surface per line: movements-since-count, expected-now, and the adjustment that would post. Feed the existing review table.

Frontend must obey the precision contract (quantity strings, `<QuantityInput>`, no `parseFloat`) and `t()` for all copy, with FR keys added alongside EN.

**Tests:** backend — dry-run matches what finalize actually posts for the same fixture (assert equality against an actual finalize in a second scenario), and the dry-run mutates nothing. Frontend vitest — the new columns render, and a flagged line renders its "will not auto-post" state.

**Review gate A4:** `inventory-costing-reviewer` (Opus) for the dry-run equivalence, `frontend-conventions-reviewer` (Opus) for the FE. Save to `docs/handoff/gate-reviews-counting/A4-<reviewer>-<round>.md`.

---

### A5 — Arabic i18n for counting

**Size: S. Do this last; it must not block A1–A4.**

`apps/web/src/locales/ar/inventory.json` has ~34 of the 233 `counting.*` leaf keys that `en` and `fr` both carry — roughly 199 missing. AR users currently see a mix of Arabic and English throughout the feature.

Translate the missing `counting.*` keys into Arabic, matching the existing tone and terminology already used in the AR locale files. Do not restructure the key tree. Verify EN/FR/AR `counting.*` leaf-key counts match exactly after your change.

**Review gate A5:** `frontend-conventions-reviewer` (Opus), light pass — key-parity and no structural drift.

---

### A6 — `batchAddProducts` accepts any string as a product ID

**Size: S. Added 2026-07-28 after the mobile audit; verified in this worktree before writing.**

**The gap:** `InventoryCountingController::batchAddProducts` validates `products.*.productId` as a bare `string` — no `uuid` rule, no `exists:products,id`. The barcode-lookup branch only runs when `productId` is falsy (`if (! $productId && isset($productData['barcode']))`). So when a client sends a barcode *in the `productId` field*, the lookup never fires, the raw value is appended via `$productIds[] = $productId` with no existence check, persisted into `scope_filters['product_ids']`, and the endpoint returns `"status": "success"`.

The count's product list is silently poisoned with values that match no product. Item generation then yields nothing for those entries, and the counter has no idea because the scan reported success.

The mobile app is currently doing exactly this (its own lane, B1, fixes the client). **Fix the server side too** — it is the durable half: a count must not be corruptible by any client, including old builds already in the field.

**Implement:** validate `products.*.productId` as a real product reference — UUID-shaped **and** existing within the caller's company (company scoping matters; a valid UUID from another tenant must be rejected). Reject with a per-item error in the existing `errors[]` array rather than failing the whole batch, so a counter scanning 40 items doesn't lose the other 39.

**Tests** — new `apps/api/tests/Feature/Inventory/BatchAddProductsValidationTest.php`: (a) a barcode string sent as `productId` is rejected with a clear per-item error and is NOT written to `scope_filters`; (b) a well-formed UUID belonging to another company is rejected; (c) a valid `productId` still succeeds; (d) a valid `barcode` with no `productId` still resolves through the existing lookup; (e) a mixed batch persists the good items and reports the bad ones.

**Review gate A6:** `inventory-costing-reviewer` (Opus). Save to `docs/handoff/gate-reviews-counting/A6-<round>.md`.

**Coordination note:** the mobile lane (B1) is fixing the client concurrently. The two fixes are independent and safe in either order — but once A6 lands, a mobile build that still sends barcodes as `productId` will start receiving errors instead of silent success. That is the correct outcome; mention it in your final report so the owner sequences the mobile release accordingly.

---

## 3. Review protocol (applies to every gate)

1. Dispatch the named reviewer agent(s), **pinned to Opus**.
2. The reviewer must cite `file:line` for every finding and must not invent issues.
3. **Save each review to a file** under `docs/handoff/gate-reviews-counting/` — never inline-only.
4. Fix findings, then re-review. Iterate until APPROVE.
5. Commit the review record alongside the fix.
6. **You never merge and never push to `dev`.** When a gate approves, commit on `feat/live-counting-completion` and continue.

## 4. Definition of done for the lane

- A1–A6 complete, each with an APPROVE review record committed.
- `./scripts/preflight.sh` green — or, if it is too heavy here, at minimum: PHPStan level 8 clean, Pint clean, the named test files green **by path**, web `pnpm lint` + `pnpm typecheck` green, and the UoM guard scanners still reporting empty baselines.
- A final report at `docs/handoff/counting-completion-report.md` stating: what changed, what you verified, **anything you could not verify**, any migration added and why it is self-guarding, and any residual risk the owner should know before the tester starts.

## 5. If you get stuck

Report the blocker with evidence and stop. Do not invent a workaround that widens scope, and do not touch anything in §1.1 to get unblocked. A partial lane with an honest report is worth more than a complete lane with a corrupted fiscal payload.
