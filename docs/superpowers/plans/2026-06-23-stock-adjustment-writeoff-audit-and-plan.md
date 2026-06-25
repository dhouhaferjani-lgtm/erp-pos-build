# Stock Adjustment & Write-Off — Audit Findings + Adapted Implementation Plan

> **Status:** ⛔ SUPERSEDED by **v2** (`docs/superpowers/plans/2026-06-24-stock-adjustment-writeoff-plan-v2.md`) — execute that. This doc remains the canonical **audit (§1) + reframe (§0)** reference. v1's plan was **NOT EXECUTION-READY — Codex review verdict REJECT (88%), 2 BLOCKER / 7 HIGH** (`docs/superpowers/reviews/2026-06-24-stock-adjustment-plan-codex-review.md`). Decisions in §6 still stand, but the plan understated correctness risk and must be revised to **v2** before any TDD expansion. Verified-confirmed blockers: (1) `BatchWriteOffService` **double-decrements** lot stock today (existing bug, masked by an over-seeded test) — Phase B must START by fixing it; (2) reversal (Phase C) is underspecified for cost/GL/batch-restore/scope and a blanket immutability rule would break `StockTransferService::markMovementAsTransfer()`'s legitimate post-create mutation. Also corrected: §6-A coupling rationale is wrong (Inventory **already** imports `BatchExpiry` `BatchMovement`/`BatchStock`); movement `quantity` sign is **not** a universal positive-magnitude invariant (WAC path stores negatives); write-off GL posts as **Draft**, not posted; permission is the existing `batches.write-off`, not `inventory.writeoff`. **No code yet.**
>
> **Source:** Adapts `docs/stock-adjustment-writeoff-spec.md` (a discussion-derived spec written without codebase access). Replaces its assumptions with verified `file:line` facts from a 6-agent code audit. **Audit validity note:** the audit ran on branch `docs/media-subsystem-architecture` (~490 commits behind dev); the inventory/batch/counting/reason findings were diff-verified **identical on `origin/dev`**, but the **media** finding was stale and is corrected here (a unified `MediaAsset` system is merged on dev).

**Goal:** Close the integrity gaps in the *existing* inventory stock-movement posting path so manual adjustments and write-offs (esp. lot/expiry for pharmacy) carry a typed, mandatory reason, are lot-aware, are correctable without mutation, and are optionally approval-gated — without forking a parallel subsystem.

**Architecture (as-is, verified):** On-hand is an **authoritative, directly-mutated cache** (`stock_levels.quantity`), written read-modify-write under a pessimistic lock inside a DB transaction; the `stock_movements` ledger is a parallel audit trail; domain events fire `afterCommit` and are advisory. There is **no event-sourcing projector and no hash chain** on stock movements. The new feature extends this state-first pattern; it does **not** introduce event-first posting.

**Tech stack:** Laravel 12 / PHP 8.4 hexagonal modules (`apps/api/app/Modules/<Module>/{Domain,Application,Infrastructure,Presentation}`), PostgreSQL (db-per-tenant), React 19 frontend (`apps/web/src`). bcmath quantity scale = 4; currency scale via resolver at GL boundary.

---

## 0. How reality differs from the source doc (read first)

The source doc is directionally good but rests on **eight assumptions that the code contradicts**. Each changes the plan:

| # | Source doc assumed | Codebase reality (evidence) | Consequence |
|---|---|---|---|
| D1 | On-hand is *derived* from the ledger (compute-then-cache projection). | `stock_levels.quantity` is authoritative, mutated directly under `lockForUpdate` in `StockAdjustmentService` (e.g. `StockAdjustmentService.php:77,192,593`). No projector recomputes it. | Keep **state-first** posting. Do **not** rebuild posting as event-first. Principle #1/#6 of the doc are aspirational, not current. |
| D2 | Posting must be event-first + write a hash chain synchronously. | Events dispatched `afterCommit` (advisory); **no hash chain** on `stock_movements` (hash-chaining exists only on `InventoryCountingEvent` and POS fiscal engine). | Fiscal-grade tamper-evidence for write-offs is a **separate, larger decision** (§6-E), not part of the base feature. |
| D3 | A `COUNT_CORRECTION` reason exists and must be "reserved". | **No `COUNT_CORRECTION` constant exists anywhere.** Counting and the live manual endpoint both write `MovementType::Adjustment`; counting's only discriminator is a free-text `reference='COUNTING:<number>'` prefix (`ApplyStockAdjustmentsOnCountingCompleted.php:43`). | Use the **already-existing-but-unpopulated `MovementReason` enum column** as the typed discriminator. Don't invent `COUNT_CORRECTION`; don't modify counting. |
| D4 | A granular adjustment *family* of movement **types** is needed (damage/expiry/loss/found). | Granularity already lives on the **`MovementReason` enum** (16 cases incl. `Damage`/`Expiry`/`WriteOff`/`OpeningBalance`), not on `MovementType` (only coarse `Adjustment`/`Opening`). | Do **not** add 5 new `MovementType` cases. Persist `reason` on the existing `Adjustment` type. |
| D5 | Reuse an existing **polymorphic** media system (MinIO→R2). | **CORRECTED (initial audit ran on a ~490-commit-stale branch).** On `origin/dev` a unified media system **is merged** (`Catalog` module, migrations `tenant/2026_06_12_10000{1,2,3}`): `media_assets` (file, per-asset `storage_disk` → MinIO) + `media_renditions` + `media_attachments` (link table: `media_asset_id` + **`owner_type` string + `owner_id` uuid** + role/channel/locale). It deliberately avoids Laravel `morphTo` but **is generalizable** — a new owner = one `MediaOwnerType` enum case + `media_attachments` rows. **However** it currently (a) lives inside `Catalog`, (b) is gated to `MediaOwnerType{Product,ProductVariant,Category}`, and (c) coexists with the **legacy** `Media/DocumentAttachment` (documents, **local disk**) — so two patterns still exist. | Justification docs should target the **MediaAsset** system (add `MediaOwnerType::StockMovement`), never the legacy table. But org-wide unification (promote media to its own module; migrate documents; add owners) is a **separate initiative** (§6-D). Base feature **defers** attachments. |
| D6 | Expiry write-off must be built. | **`BatchWriteOffService` already exists end-to-end**: `POST /api/v1/batches/{uuid}/write-off`, lot-targeted, FEFO, decrements lot + aggregate, posts GL, maps `expiry\|damage\|other → MovementReason` (`BatchWriteOffService.php:44-110`). FEFO "expiring/expired lots on hand" queries exist (`FEFOInventoryService.php:254-299`). | The pharmacy priority is mostly **harden + surface (UI)**, not build. |
| D7 | Opening balance must be made a locked movement. | Already a movement (`MovementType::Opening`) via `InventoryOpeningService::postBatch()` with a balanced GL entry — but **import-batch-driven only**, `reason` left NULL, no per-product entry point, no status/lock beyond convention. | Smaller scope: persist reason + (optionally) a per-product entry; immutability is a cross-cutting concern (D8). |
| D8 | Posted movements are immutable; corrections reference the original via `reverses_movement_id`; draft→posted lifecycle. | `StockMovement` has **no status column and no reverses/corrects FK**. Corrections today are new forward `Adjustment` rows with no link to what they correct. The canonical correction pattern in this repo is Document's `source_document_id` self-FK + `DocumentStatus` lifecycle (`CreditNoteService.php:98-106`). | Reversal-linkage + lifecycle are **genuinely net-new**; model them on the Document pattern. This is the largest decision (§6-C). |

**Net reframe:** This is ~70% *closing integrity gaps on an existing, sound posting path* and ~30% *net-new (reversal linkage, approval gate, justification docs)*. It is **not** a greenfield subsystem.

---

## 1. Phase 1 — AUDIT findings (the doc's requested deliverable)

Verdict legend: **Exists** / **Partial** / **Missing**. All paths under `apps/api/` unless noted.

| # | Audit item | Verdict | Evidence (file:line) | Key fact / gap |
|---|---|---|---|---|
| 1.1 | Inventory movement domain | **Exists** | `app/Modules/Inventory/Domain/StockMovement.php:50` (table `stock_movements`); migrations `database/migrations/tenant/2025_11_30_110000_create_inventory_tables.php` (+ extends) | Full column set known. **No `status`, no `batch_id`, no `reverses_*` columns.** Quantity stored as positive magnitude; direction via `movement_type`. |
| 1.1b | Movement-type enum | **Partial** | `Domain/Enums/MovementType.php:7-14` | Cases: `receipt, issue, transfer_in, transfer_out, adjustment, opening`. Only one coarse `adjustment` — **no granular family** (by design; see `MovementReason`). `isInbound()` wrongly treats `Adjustment` as always inbound (`MovementType.php:16`) — bug, since adjust deltas can be negative. |
| 1.1c | On-hand cache + recompute | **Exists (but not a projection)** | `Domain/StockLevel.php:40`; writes at `StockAdjustmentService.php:77,192,308,593` | `stock_levels.quantity` is **authoritative**, mutated read-modify-write under `lockForUpdate` (`lockStockLevel()` `:697-727`) + `ProductCostLock` advisory. **No recompute-from-ledger path.** Two writers: `StockAdjustmentService` + `WeightedAverageCostService`. |
| 1.1d | Posting is event-first? | **Missing (state-first instead)** | dispatch via `DB::afterCommit` `StockAdjustmentService.php:110,225,356,614` | Transactional + pessimistically locked, but events are **after-commit advisory**; `StockMovementRecordedV2` has **no consumer**. No hash chain. |
| 1.2 | Reason codes — closed set? | **Partial** | enum `Domain/Enums/MovementReason.php:7` (16 cases, with `getMovementType()` direction + `affectsCOGS()`/`requiresGLEntry()`); column `database/migrations/tenant/2025_12_24_133827_extend_stock_movements_table.php:16` | Closed enum **exists** but `stock_movements.reason` is `varchar(50) NULL`, **no FK/lookup, no CHECK, no `requires_document` flag**. |
| 1.2b | Reason actually persisted? | **Missing on the service path** | `StockAdjustmentService::recordMovement()` `:762-794` (omits `reason`); `adjust()` passes human text to `reference` `:604` | **Core gap.** Every movement created via `StockAdjustmentService` (adjust/receive/issue/transfer, **counting**, **batch write-off**) lands `reason = NULL`; the human reason is dumped into free-text `reference`. Only POS projections set `reason` correctly. |
| 1.3 | Justification doc upload (reusable media) | **Partial (on dev)** | **dev:** `Catalog/Domain/Media/{MediaAsset,MediaAttachment,MediaRendition}.php` + `media_attachments(owner_type,owner_id)` link table (MinIO); enum `Catalog/Domain/Enums/MediaOwnerType.php` = Product/ProductVariant/Category. **Legacy:** `Media/Domain/DocumentAttachment.php:44` (hard FK `document_id`, **local disk**). | A generalizable media system **exists** but is scoped to catalog owners and lives in `Catalog`; documents still use the legacy local-disk system. Extending to `StockMovement` = add an owner-type case + consume the media service (cross-module — see §6-D). `StockMovement` has no attachment relation today. |
| 1.4 | Lot/batch/expiry domain | **Exists (mature)** | `app/Modules/BatchExpiry/...`; tables `product_batches`, `inventory_batch_stock`, `inventory_batch_movements` (migrations `tenant/2026_01_05_15000*`) | Lot identity+expiry (`product_batches`, no qty), per-lot/location on-hand (`inventory_batch_stock.quantity`, authoritative), join ledger (`inventory_batch_movements.movement_id`). Variant-aware. Flag `products.requires_batch_tracking`. |
| 1.4b | Adjustments lot-scoped? | **Partial** | `StockAdjustmentService::receive()/issue()` accept `?int $batchId`; `adjust()` `:571` has **no** `batchId`; lot link via `recordBatchMovement()` `:825` | Receipts/issues/write-offs can target a lot; **generic count `adjust()` cannot**. No `batch_id` column on `stock_movements`. |
| 1.4c | Expiry write-off path | **Exists (backend)** | `BatchExpiry/Domain/Services/BatchWriteOffService.php:44-110`; route `POST /api/v1/batches/{uuid}/write-off` | Lot-targeted, decrements lot + aggregate, posts GL. **But** the underlying movement row still gets `reason=NULL` (uses `issue()`); no approval; no justification doc; no dedicated UI; one-lot-at-a-time. |
| 1.4d | "Lots expiring before X" query | **Exists** | `FEFOInventoryService::getExpiringProducts()` `:254` (route `GET /api/v1/batches/expiring?days=&location_id=`); `getExpiredBatchesWithStock()` `:284` (**not** route-exposed) | Both filter to lots with on-hand. `getExpiring` is days-threshold (not calendar-date); expired-with-stock query exists but unexposed. |
| 1.5 | Opening balance | **Partial** | `Inventory/Application/Services/InventoryOpeningService.php:200,279`; GL `:322-359` | Posts a real `MovementType::Opening` movement + balanced GL — **but import-batch-driven only**, `reason` left NULL, no per-product single-shot entry, no lock/immutability beyond convention. |
| 1.6 | Approval / posting state | **Missing (movements) / reusable parts exist** | no `status` on `StockMovement`; counting lifecycle `Domain/Enums/CountingStatus.php`; POS `Domain/OperatorApproval.php` + `Enums/ApprovalScope.php` + `Application/Services/PinVerifier.php` | No draft→posted on movements. Reusable approval = the **POS trio** (extensible, but coupled to POS fiscal chain + terminal rate-limiting; needs decoupling). No generic `Approvable` trait. |
| 1.7 | Routes/controllers convention | **Exists / conforms** | `Inventory/Presentation/routes.php:25` middleware `['api','auth:sanctum',SetPermissionsTeam,EnforceTokenTenantClaim,'module:Inventory']`; perms in `database/seeders/RolesAndPermissionsSeeder.php:130-139` | Manual adjust **already live**: `POST /api/v1/stock-movements/adjust` (`can:inventory.adjust`), free-text `reason` `max:500`, takes **absolute `new_quantity`** (conflicts with doc "never type a new number"). |
| 1.8 | Correction/immutability pattern | **Missing (movements) / pattern exists in Document** | `Document/Domain/Document.php:249-255` (`source_document_id` self-FK) + `Enums/DocumentStatus.php`; `CreditNoteService.php:98-106` | StockMovement has only generic `reference_type/reference_id` (→ source doc, not prior movement; and `adjust()` doesn't populate them). Reversal-linkage is net-new. |
| 1.9 | Precision contract | **Conforms** | `StockMovement.php:87-89` (`decimal:4`); `StockAdjustmentService` `SCALE=4` bcmath `:37` | Quantity scale hardcoded 4 (not the `QuantityScale` helper); cost `decimal:6`, currency scale at GL boundary. Write-off reasons already `affectsCOGS()/requiresGLEntry()=true`. |

**Counting integration fact (critical, do not break):** when a count finalizes it writes `MovementType::Adjustment` + `reference='COUNTING:<number>'`, `reason=NULL`, product/variant-grain, no batch — gated behind a real human review→finalize step. The new manual screen must **not** emit `COUNTING:`-prefixed references and must **not** change the counting flow.

---

## 2. Adapted target model (corrected to the codebase)

### 2.1 Movement model — reason-as-discriminator, NOT new types
- Keep `MovementType::Adjustment` (and `::Opening`). Do **not** add per-reason movement types (D4).
- **Persist `MovementReason`** on every adjustment movement. This single fix unlocks reason-codes, the counting/manual discriminator, and write-off typing.
- Manual-adjustment restricted reason set (subset of the existing enum), surfaced in the manual screen:
  `AdjustmentPositive`, `AdjustmentNegative`, `Damage`, `Expiry`, `WriteOff`, `OpeningBalance` (opening only at onboarding).
  **Excluded from the manual screen:** `POSSale/POSReturn/Delivery/GoodsReceipt/...` (document-driven) and any auto/counting context.
- Counting stays as-is. Optionally (decision §6-B) backfill counting to also set a typed reason — but only if "do not modify counting" is interpreted as "do not change its behavior," since adding a reason value is behavior-neutral to the count itself.

### 2.2 Reason codes — harden the existing enum + column
- Add per-reason metadata the enum lacks: a `requiresDocument(): bool` method (default false) — **no new table needed** unless tenant-overridable reasons are required (that's a bigger scope; default to enum-only).
- Make `reason` **mandatory** for `MovementType::Adjustment` created via the manual path (validated at the FormRequest/command boundary; DB CHECK optional, see §6-A).
- `Expiry` + lot-tracked product ⇒ batch reference **required** (domain invariant + validation; DB CHECK optional).

### 2.3 Justification documents — coordinate, likely defer
- There is no polymorphic media to reuse (D5). Options in §6-D. Base feature ships **without** attachments unless the owner chooses to pull the media-redesign dependency in.

### 2.4 Immutability & correction — net-new, modeled on Document
- Adopt the Document pattern: a posted movement is immutable; a correction is a **new reversing movement** linked to the original.
- Requires a new `reverses_movement_id` self-FK on `stock_movements` + a `reversesMovement()` relation. Decision on whether to also add a `status` lifecycle is §6-C (the existing model is append-only-by-convention with no status — adding one is invasive).

### 2.5 Lot-aware adjustments
- Extend `StockAdjustmentService::adjust()` to accept an optional `?int $batchId` (mirroring `receive()/issue()`), OR route lot write-offs exclusively through `BatchWriteOffService`. Decision §6-A.

---

## 3. File structure (where work lands)

Backend (`apps/api/app/Modules/`):
- `Inventory/Domain/Enums/MovementReason.php` — add `requiresDocument()`; (no new cases).
- `Inventory/Domain/Services/StockAdjustmentService.php` — accept + **persist** `?MovementReason $reason` in `adjust()`/`recordMovement()`; optional `?int $batchId` on `adjust()`. **Shared service — touched by counting; changes must be additive/backward-compatible.**
- `Inventory/Domain/StockMovement.php` (+ migration) — add `reverses_movement_id` self-FK + relation (per §6-C).
- `Inventory/Presentation/Requests/AdjustStockRequest.php` (new or extend existing) — mandatory restricted reason, lot-required-on-expiry rule.
- `Inventory/Presentation/Controllers/StockMovementController.php` — wire reason; new "reverse/correct" action.
- `BatchExpiry/Domain/Services/BatchWriteOffService.php` — ensure the movement persists `reason`; support multi-lot grouped write-off (per §6-A).
- `BatchExpiry/Domain/Services/FEFOInventoryService.php` — expose `getExpiredBatchesWithStock()` via a route; optionally add a calendar-date variant.
- `BatchExpiry/Presentation/...` — route for expired-lots list + (optional) grouped expiry write-off.
- Approval (per §6-B): extend `POS/Domain/Enums/ApprovalScope.php` + reuse `OperatorApproval`/`PinVerifier`, or new minimal inventory approval.
- `database/seeders/RolesAndPermissionsSeeder.php` — new `inventory.writeoff` / `inventory.approve_writeoff` permissions.

Frontend (`apps/web/src/features/`):
- New `inventory` adjustment screen (reason-coded, no `COUNTING:` reference), and a **dedicated expiry write-off** screen (pick location → expiring/expired lots → select lots+qty → reason auto `Expiry` → confirm). Reuse design tokens; `t()` for all copy.

---

## 4. Phased plan (task altitude — TDD steps expand AFTER §6 decisions)

> Per the writing-plans skill, each task is an independently-testable, single-reviewer unit ending in a commit. Full bite-sized TDD steps are intentionally **withheld** until the §6 decisions are locked, because several tasks' signatures depend on those choices. Phase A is decision-independent and can expand immediately on go-ahead.

**Phase A — Persist the reason (decision-independent, highest value, lowest risk).** Closes the central gap (1.2b) with no behavior change to counting.
- A1: `StockAdjustmentService::recordMovement()`/`adjust()` accept + persist `?MovementReason`. Backward-compatible (null-safe); counting keeps passing its `COUNTING:` reference (now optionally also a reason). Tests: a movement created via `adjust()` with a reason persists `reason`; existing callers unaffected.
- A2: Manual `AdjustStockRequest` requires a reason from the restricted set; reject `COUNTING:`-style/free-text-only. Tests: post without reason → 422; with excluded reason → 422.
- A3: Fix `MovementType::isInbound()` mislabel for negative adjustments (1.1b). Tests: negative-delta adjustment classified outbound.

**Phase B — Expiry write-off hardening + UI (pharmacy priority; mostly surface existing backend).**
- B1: Ensure `BatchWriteOffService` persists `reason` on the movement row (currently NULL via `issue()`).
- B2: Route-expose `getExpiredBatchesWithStock()`; optional calendar-date variant of the expiring query.
- B3: Dedicated expiry write-off API (multi-lot grouped) — granularity per §6-A.
- B4: Frontend dedicated expiry write-off screen.

**Phase C — Correction/reversal (net-new; gated on §6-C).**
- C1: Migration `reverses_movement_id` self-FK + relation.
- C2: `StockAdjustmentService::reverse(StockMovement $original, ...)` posts a linked inverse movement.
- C3: UI "Reverse / correct" action (never "edit").

**Phase D — Approval gate → DEFERRED (see §6 "Approval gating").** Not built in v1; design context-agnostic when the F&B/high-value need is concrete.

**Phase E — Opening balance polish (decision-independent).**
- E1: Persist `OpeningBalance` reason on the opening movement; optional per-product entry point.

**Phase F — Justification documents → DEFERRED (see §6-D + Media unification).** Not built in v1. When added, attach via the `MediaAsset` system through a new `MediaOwnerType::StockMovement` case; do not create a new table or use legacy `DocumentAttachment`.

---

## 5. Out of scope (unchanged from source doc)
- The **Inventory Counting** flow — integrate via the shared `Adjustment` type + (new) reason discriminator only; do not modify its behavior.
- Document-driven movements (purchase/sale/return/transfer).
- The media subsystem internals (reuse/coordinate, don't rebuild).
- Catalog/product master-data editing surfaces; product page on-hand stays read-only.

---

## 6. DECISIONS (owner-confirmed 2026-06-24)

- **§6-A — Lot-aware adjustment mechanism → LOCKED: route lot write-offs through BatchExpiry's `BatchWriteOffService` (extended for multi-lot); keep Inventory's generic `adjust()` lot-agnostic.** Rationale (hexagonal): `BatchExpiry` is a **vertical-activated** module that already depends on Inventory's core public service (`StockAdjustmentService::issue`). Correct dependency direction is optional/vertical-module → core, never core → optional-module. Putting `batchId` on core `adjust()` would couple core Inventory to a module that may be disabled. Lot identity / FEFO / lot-on-hand / lot-write-off orchestration already cohere in `BatchExpiry`. Two entry points: generic non-lot adjustments in Inventory; lot/expiry write-offs in BatchExpiry (calling Inventory's public service — satisfies CLAUDE.md module-boundary rule 6).
- **§6-B — Counting reason discriminator → LOCKED: set a typed `MovementReason` when counting posts (behavior-neutral), keep the `COUNTING:` reference prefix intact.** Does not change counting behavior; just stops leaving `reason` NULL.
- **§6-C — Correction model → LOCKED: add `reverses_movement_id` self-FK + a `reverse()` flow (modeled on Document `source_document_id`); NO status lifecycle for v1.** Movements stay append-only-by-convention; revisit a status machine only if approval needs a draft state.
- **§6-D — Justification documents → LOCKED: DEFER from the base feature; do NOT build any attachment code now.** When added, target the **MediaAsset** system (`Catalog` module today) via a new `MediaOwnerType::StockMovement` case — never a new hard-FK table and never the legacy `DocumentAttachment`. **Separate initiative recommended** (see "Media unification" note below) because the unified system must first be promoted out of `Catalog` into a first-class/shared module to legitimately serve Inventory/Document/SupplierInvoice without boundary violations, and the legacy document attachments should migrate onto it.
- **§6-E — Tamper-evidence → LOCKED: NO fiscal-grade hash chain for v1.** Keep state-first; separate compliance initiative only if a regulator requires destruction-record immutability.
- **§6-F — Absolute vs delta → LOCKED: write-offs are quantity-out (delta) only; count corrections may keep the absolute `new_quantity` they reconcile to.**

### Approval gating (was §6-B option 2) → REVISED: DEFER for v1
The POS `OperatorApproval`/`PinVerifier` trio is **genuinely POS-coupled** (terminal-scoped rate-limiting, validation woven into the POS fiscal-event chain) and there is no stock-adjustment surface inside POS today — so it is **not** cleanly applicable to a web-dashboard write-off. Forcing its reuse would drag POS/fiscal infra into Inventory. **Decision:** ship v1 without approval (it is config-gated, not on the happy path). When the need is concrete (e.g. future F&B POS perished-ingredient logging, or a high-value web write-off policy), build a **context-agnostic** approval in the Inventory/BatchExpiry domain (`inventory.approve_writeoff` permission + optional second-user/re-auth) usable from **both** web and POS — rather than coupling to POS now and building the wrong abstraction.

### Media unification (cross-cutting — recommend a dedicated session)
A unified media foundation already exists and is merged to dev (`media_assets`/`media_renditions`/`media_attachments` with `owner_type`+`owner_id`, MinIO-backed), but it (a) lives in `Catalog`, (b) is gated to catalog owner types, and (c) coexists with the legacy local-disk `Media/DocumentAttachment`. To become the org-wide media layer it needs: promotion to a first-class/shared module, `MediaOwnerType` cases for `Document`/`StockMovement`/`SupplierInvoice`, migration of legacy document attachments onto `media_assets` (and onto MinIO), and retirement of the old `Media` module. **This is out of scope for stock-adjustment and should be its own decision/session.** ⚠️ The parallel supplier-invoice session is reportedly following the **legacy document pattern** — align it to target `MediaAsset` instead, or it adds a third consumer of the pattern we want to retire.

---

## 7. Acceptance checks (adapted)
- [ ] Every manual adjustment persists a `MovementReason` from the restricted set; document-driven/auto reasons are not user-selectable; counting behavior unchanged.
- [ ] `Expiry` write-off on a lot-tracked product cannot post without a batch reference.
- [ ] On-hand changes only via a posted movement (no editable quantity field on the product page).
- [ ] A posted adjustment is not edited/deleted; a correction posts a new movement linked via `reverses_movement_id` (if §6-C accepted).
- [ ] Expiry write-off is reachable as a dedicated action listing expiring/expired lots with quantities.
- [ ] Posting remains transactional + pessimistically locked (existing pattern); GL/COGS entries post for `Damage/Expiry/WriteOff` as today.
- [ ] (If §6-D accepted) a reason flagged `requiresDocument` blocks posting until a document is attached.
```
