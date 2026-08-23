# TRIAGE — VPS state-machine & data-structure audit (2026-08-23)

> **Input:** `docs/handoff/AUDIT-state-machines-vps-2026-08-23.md` — 21 confirmed findings (8 HIGH / 9 MED / 4 LOW), produced by a REMOTE session against `origin/dev` at the `c6d6308ae` era.
> **Triaged against:** LOCAL dev tip `bdf9ce5e9` (~125 commits ahead: O-26 lineless-posting phase-out, r2f4 correcting documents, dpa-v8, C-2/C-6 device-Z, O-27/O-28/O-29, enforcement P1/P2/P3, and a dozen other lanes).
> **Method:** every finding re-derived at today's `file:line`. No disposition without a citation actually read. Read-only triage — no code changed by this session.
> **Tenant #1 = PARAPHARMACY (IziPOS retail).** Vertical reachability is a first-class disposition axis, per `apps/api/config/verticals.php:335-362`.

---

## 0. Verdict summary

| Re-verification | Count |
|---|---|
| **HOLDS** (claim intact at current code) | 14 |
| **PARTIALLY-SUPERSEDED** (core true, premise/severity/scope materially changed) | 6 |
| **ALREADY-FIXED** | 0 |
| **COULD-NOT-CONFIRM** | 0 |
| **Materially WRONG in ≥1 load-bearing detail** (see §5) | 8 findings, 13 distinct errors |

| Disposition | Findings |
|---|---|
| **(b) FOLD-NOW** — 6 micro-lanes | SM-1 (partial), DS-4, DS-5 (index half), DS-6, DM-6 (StockReservationService half), DS-1 (census half) |
| **(c) PROGRAM** — state-machine & transition-service charter | SM-2, SM-3, SM-4, SM-5, SM-6, DS-2, DM-3, DM-1 (partial), DM-4 (doc-reconciliation half) |
| **(d) VERTICAL-DEFER** — F&B / Dhouha PR-chain inheritance | SM-7, DM-8, and the F&B half of SM-1/SM-2/SM-4 |
| **(e) TICKET-ONLY** | DS-3, DM-2 (residual only), DM-5, DM-7 |

**Zero findings were fixed by the ~125 intervening commits.** The audit's staleness is not in *what it found* but in *the premises it built on* — the single most consequential being that the `closeOrder` half of its #1 HIGH exploit chain has been HTTP-dead since fiscal Phase 1 §14.2 (§2 below).

---

## 1. Vertical reachability — the gate that decides half these dispositions

**`pos_orders` (open / sent_to_kitchen / ready / closed / cancelled — the kitchen & table flow) is a DIFFERENT surface from `pos_receipts` + `pos_shifts` (the retail till).** For tenant #1:

| Layer | Orders (`/pos/orders`) | Kitchen (`/pos/kitchen`) | Tables |
|---|---|---|---|
| Vertical default modules (`config/verticals.php:344-361`) | — | **Menu: ABSENT** | **Tables: ABSENT** |
| FE route guard (`apps/web/src/routes/index.tsx`) | `RequirePermission` only (`:2976-2984`) | **`ModuleGuard module="Menu"`** (`:3226-3238`) | `ModuleGuard module="Tables"` (`:2986-2990`) |
| Sidebar (`apps/web/src/components/organisms/Sidebar/Sidebar.tsx`) | `:236` permission only | `:238` `module: 'Menu'` | — |
| **Backend route middleware** | `app/Modules/POS/routes_orders.php:15` — `['api','auth:sanctum',SetPermissionsTeam,EnforceTokenTenantClaim]`, **NO `module:`** | `app/Modules/POS/routes_kitchen.php:15` — same, **NO `module:`** | `module:Tables` present on the newer table routes |
| Controller authz | `Gate::authorize('pos.operate_terminal')` | `KitchenDisplayController.php:31,56,95,125` — same single permission | — |

**Definitive answer: `pos_orders` IS live for a parapharmacy tenant at the API layer, and is NOT gated by any module.** The FE hides the kitchen surface behind `ModuleGuard module="Menu"`, but the backend does not mirror it — a **CLAUDE.md rule 12 both-layer gating violation** (`docs/architecture/vertical-module-gating.md`). Compare `app/Modules/Menu/Presentation/routes.php:12` (`module:Menu`), `app/Modules/Catalog/Presentation/routes.php:32` (`module:CompositeItems`), `app/Modules/Loyalty/Presentation/routes.php:27` (`module:Loyalty`) — the idiom exists everywhere else.

**But in practice `pos_orders` is an empty table for tenant #1.** No client authors orders: `useCreateOrder` (`apps/web/src/features/pos/hooks/useOrders.ts:84`) has **zero non-test callers**; `apps/pos/src` (the Tauri retail till) makes **no `/pos/orders` calls at all**; the FE `OrdersPage` is a read-only Kanban over existing orders (`OrdersPage.tsx:17-125`) with an `OrderPanel` exposing only send-to-kitchen / cancel / remove-line (`OrderPanel.tsx:31-34`).

**Consequence:** every POS-Orders finding is *reachable only by a hand-crafted API sequence from an authenticated `pos.operate_terminal` holder*, never by using the product as shipped for a parapharmacy. That is a real authz/integrity hole, not a launch-path defect. It scopes the whole POS-Orders cluster to **small hardening now + program later**, not launch-blocking.

---

## 2. SM-1 [HIGH] — Closed/cancelled POS order reopened to Ready — **the end-to-end re-derivation**

**Verdict: PARTIALLY-SUPERSEDED. Exactly half the exploit chain is dead; the other half is live and reproducible.**

The audit's cited lines are byte-accurate — `OrderManagementService.php` has not drifted at all (`updateLineStatus` at `:542`, `checkAndTransitionOrderToReady` at `:713-735`, `closeOrder` at `:419/:433`, `cancelOrder` at `:479/:492`).

### The Closed branch — **DEAD**

```
routes_orders.php:43-50   POST /pos/orders/{id}/close  →  410 Gone, NEW_SALE_AUTHORING_RETIRED
```
The route was replaced by a bare closure under fiscal Phase 1 §14.2 (comment `routes_orders.php:28-42`). `OrderController::close` (`:370`) is **no longer routed** — `grep` over `app/Modules/POS/routes*.php` shows no `[OrderController::class, 'close']` binding. `OrderManagementService::closeOrder()` therefore has **zero production callers** (only `tests/Unit/POS/OrderToReceiptServiceTest.php` reaches the chain by reflection). No order can reach `OrderStatus::Closed` through any HTTP path. `KitchenDisplayTest.php:58` still constructs a Closed order by direct factory write — that is fixture-only.

→ **The audit's headline framing ("a terminal, receipt-backed/fiscally-closed order being reopened") is not reachable.** No receipt-backed closed order exists to reopen.

### The Cancelled branch — **LIVE, fully reproducible**

```
1. POST /pos/orders                              → status=Open                    (OrderManagementService.php:160)
2. POST /pos/orders/{id}/lines                   → line status=Pending            (:252)
3. POST /pos/orders/{id}/send-to-kitchen         → order=SentToKitchen, lines=Sent (:386-397)
4. POST /pos/orders/{id}/cancel                  → order=Cancelled                (:492 canBeCancelled allows
                                                    from SentToKitchen — Order.php:251-257;
                                                    LINES ARE NEVER TOUCHED, they stay 'sent')
5. PATCH /pos/kitchen/orders/{id}/lines/{lineId}/status  {status: "ready"}
     → updateLineStatus (:542) loads the ORDER but never inspects $order->status  ← the hole
     → validateLineStatusTransition(Sent → Ready) PASSES  (:699-703, Sent ⇒ [Preparing, Ready])
     → checkAndTransitionOrderToReady (:713)
         nonCancelledLines = [that line], allReady = true,
         $order->status (Cancelled) !== OrderStatus::Ready  ⇒ TRUE
     → $order->update(['status' => Ready, 'ready_at' => now()])   (:726-729)
     → OrderReady::dispatch()                                     (:731-733)
```

**Confirmed collateral:** `cancelled_at` stays populated alongside `status=ready` (impossible state); the table was already released to Available at `:512-521` so the order is Ready with no seat; the order **re-enters the KDS feed** (`KitchenDisplayController::index` selects `[SentToKitchen, Ready]`, `:37-40`); and a spurious `OrderReadyBroadcast` goes out over websockets (`BroadcastPosEventsListener.php:87-97`, `ShouldBroadcastNow`).

**Siblings correctly guarded** (audit over-broad here): `bumpOrder` DOES block terminal states — `in_array($order->status, [SentToKitchen, Ready], true)` at `:652`. `markOrderServed` requires `canBeServed()` = `status === Ready` (`Order.php:243-246`), so it only fires *after* the illegal flip. **The single hole is `updateLineStatus` → `checkAndTransitionOrderToReady`.**

### Launch answer

Reachable for a parapharmacy tenant? **Yes at the API, no through the product.** Step 5 requires `PATCH /pos/kitchen/...` — ungated by module (`routes_kitchen.php:15`), permitted by `pos.operate_terminal` (`KitchenDisplayController.php:56`), which every parapharmacy cashier holds. Steps 1-2 have no FE surface at all for any vertical (`useCreateOrder` has no callers), so the whole sequence is a deliberate API exercise.

**Disposition: (b) FOLD-NOW** — because the *fix* is three lines and the *neighbouring* defect (missing backend module gate) is a standing convention violation that also makes tenant #1 structurally unreachable. See lane **F1** in §3. Not launch-blocking; do it because it is cheap and closes a rule-12 gap that Dhouha's chain will inherit.

---

## 3. Disposition register — all 21

### §1 State machines (7)

---
#### **SM-1** · HIGH · Closed/cancelled POS order reopened to Ready via line-status update
**Re-verify: PARTIALLY-SUPERSEDED** — see §2. Closed branch DEAD (`routes_orders.php:43`, 410 Gone); Cancelled branch LIVE and reproduced end-to-end at `OrderManagementService.php:542 → 561 → 574 → 713-733`.
**Overlap:** (a) none in LEDGER. (b) none in-flight. (e) **Dhouha #201-206** — her chain makes table/kitchen flow load-bearing; her P2 register already carries *"10 core floor/table routes still ungated on `module:Tables`"* — this is the same class one module over.
**Vertical:** API-reachable for parapharmacy, product-unreachable (§1).
**→ (b) FOLD-NOW · XS** (see F1)

---
#### **SM-2** · HIGH · POS Order transitions scattered and ungoverned — no state machine, no guard type, no DB constraint
**Re-verify: HOLDS, every cite exact.** `OrderStatus` has only `label()`/`isActive()` (`app/Modules/POS/Domain/Enums/OrderStatus.php:1-39` — 39 lines total, no adjacency map, no `isTerminal()`). Guards are inline predicates on the model — `canBeSentToKitchen` `Order.php:221`, `canBeClosed` `:230`, `canBeServed` `:243`, `canBeCancelled` `:251`, plus the raw `in_array` at `OrderManagementService.php:652`. Six bare `update(['status' => …])` writes: `:160, :386, :439, :497, :668, :726`. All throw `\RuntimeException` — no typed transition exception exists in the POS module. `pos_orders.status` is `string(20) default 'open'` with no CHECK (`database/migrations/tenant/2026_03_11_400000_create_pos_orders_table.php:25`; full index block `:52-56` — no constraint).
**Overlap:** (d) the retro's G7 residual — *"not one reviewer agent is scoped to the domain model"* — and G2's canonical-entity manifest are the governance analogue of this finding. The audit's "propagate the Workshop pattern" IS itself a G-class structural proposal.
**Vertical:** POS-Orders cluster — F&B-primary, API-reachable everywhere.
**→ (c) PROGRAM** (charter §4, workstream A)

---
#### **SM-3** · HIGH · Document lifecycle has no state machine — 37 files mutate DocumentStatus
**Re-verify: PARTIALLY-SUPERSEDED — the core claim holds, three sub-premises are false.**
- **HOLDS:** no adjacency map, no single write path, no typed transition exception. `grep StatusMachine|InvalidTransition|assertAllowed|TransitionService` over `app/Modules/Document/` returns **nothing**.
- **HOLDS:** the immutability trigger is seal-only exactly as described — `database/migrations/tenant/2025_12_11_054716_add_document_immutability_trigger.php:25-27` (`IF OLD.fiscal_status != 'SEALED' THEN RETURN NEW`) with the literal comment `-- Allow operational status updates (e.g., posted -> paid)` at `:30`.
- **WRONG #1:** the audit says `DocumentStatus` *"exposes only isEditable/isDeletable/label — no isTerminal"*. **`isTerminal()` exists** at `app/Modules/Document/Domain/Enums/DocumentStatus.php:48-53`, landed by `785d2f8ee` (*"refuse allocation to a withdrawn document… (W-7 F-6)"*), and is enforced via `Document::isWithdrawn()` (`Document.php:689-692`) at `Treasury/Application/Services/DocumentAllocationStateGuard.php:40`. The specific `cancelled → paid` hole the audit's "paid→confirmed" example gestures at is **closed**.
- **WRONG #2:** `posted→draft` and `cancelled→posted` are **not** unprevented at the primary service. `DocumentPostingService` guards each of its own edges: post refuses non-Confirmed (`:85`), cancel refuses non-Posted and short-circuits already-Cancelled (`:152, :163`), revert refuses non-Confirmed (`:329, :336, :354`).
- **WRONG #3:** **31** files write `'status' => DocumentStatus::`, not 37.
**What genuinely holds:** the guards are *per-service*, so the 30 files outside `DocumentPostingService` — converters, `CreditNoteService`, `DraftPersistenceService`, `CorrectingEntryService`, six controllers, `ArApOpeningService`, Procurement/Expense/Income/Marketplace/Workshop adapters — each re-implement or omit the check. There is no graph, no backstop below `fiscal_status='SEALED'`.
**Overlap — this is the densest node in the register:**
- (a) **O-26** (LANDED): zero-line posting now refused at the L1 preflight inside `DocumentPostingService::post()` — a *content* guard added at exactly the write path this finding says needs a *transition* guard. Same seam, complementary axis.
- (a) **C-8 residual** (OPEN, trigger-widening lane): *"a raw-DB re-point of a SEALED correction's `source_document_id` migrates its footprint between documents… closing it = widening `trg_document_immutability` (schema lane)"*. **The C-8 trigger-widening lane and SM-3's "keep the trigger as the seal backstop but add an app-level graph" are the same lane.** Merge them.
- (b) **P1 auto-save** (in-flight, `docs/superpowers/tickets/2026-08-22-lineless-authoring-paths-hardening.md:30-52`): `POST /documents/auto-save` has *"no document-type or status filter — it will also REPLACE the line set of an already-`Confirmed` document with an empty one"*, plus no `can:` middleware, and burns a fiscal document number (`DraftPersistenceService.php:142`). **That is a status-blind mutation on the Document surface — SM-3's thesis instantiated, already ticketed as P1 with its own blast-radius review.**
**Vertical:** Documents are core to every vertical incl. parapharmacy — **fully launch-relevant**.
**→ (c) PROGRAM** (charter §4, workstream B) — with the P1 auto-save lane proceeding independently NOW (it must not wait for the program), and the C-8 trigger-widening lane folded into workstream B's DB slice.

---
#### **SM-4** · MED · OrderLine transition guard is private, inconsistently applied, bypassed by bulk updates
**Re-verify: HOLDS, exact.** `validateLineStatusTransition()` private at `OrderManagementService.php:692-708`, `\RuntimeException`, consulted only by `updateLineStatus` (`:561`). Three bulk paths bypass it entirely: `sendToKitchen` Pending→Sent `:392-397`, `bumpOrder` Sent/Preparing→Ready `:659-664`, `markOrderServed` Ready→Served `:616-620`. The allow-list at `:699-703` covers only `Sent⇒[Preparing,Ready]` and `Preparing⇒[Ready]` — `pending→sent` and `ready→served` are genuinely absent from it and occur only through the unguarded bulk paths. `OrderLineStatus` has only `label()` (`app/Modules/POS/Domain/Enums/OrderLineStatus.php:1-30`). `pos_order_lines.status` = `string(20) default 'pending'`, no CHECK (`2026_03_11_400001_create_pos_order_lines_table.php:32`).
**Overlap:** same cluster as SM-2. **Vertical:** F&B-primary.
**→ (c) PROGRAM** (workstream A)

---
#### **SM-5** · MED · No transition/audit log for POS order, line, or shift status changes
**Re-verify: HOLDS.** `ls database/migrations/tenant/ | grep -i transition` returns exactly two status-transition tables — `2026_04_19_130004_create_workshop_work_order_status_transitions_table.php` and `2026_04_19_140005_create_scheduling_appointment_status_transitions_table.php`. No POS, Document, Treasury or Inventory equivalent. The cancellation reason is still appended to free-text `notes` (`OrderManagementService.php:501-505`).
**Overlap:** (b) **R-8 shift variance** — the SV lane owns shift close/variance semantics (`docs/handoff/FINDINGS-shift-variance-gl-2026-08-11.md`, LEDGER G-5/O-19). The *shift* third of this finding should be routed to R-8/SV rather than the POS-Orders program; the order/line thirds belong to workstream A.
**Vertical:** shift transitions ARE parapharmacy-relevant; order/line transitions are not.
**→ (c) PROGRAM** (workstream A for order/line; **split the shift third to the SV lane as review input**)

---
#### **SM-6** · MED · `pos_orders` / `pos_order_lines` status columns unconstrained while `pos_shifts` is properly constrained
**Re-verify: HOLDS, and the contrast is sharper than stated.** `pos_shifts` carries four DB constraints plus a partial unique index — `2026_01_08_190641_create_pos_shifts_table.php:68` (one-OPEN-shift-per-terminal partial unique), `:72` `pos_shifts_positive_amounts`, `:77` `pos_shifts_variance_calc`, `:81` `pos_shifts_status`, `:84` `pos_shifts_closed_logic`. The order tables get none.
**Overlap:** merges with **DS-2** into one DB-CHECK hardening slice.
**→ (c) PROGRAM** — but see §4's answer on the **early migration-bearing slice**: this can land ahead of the app-layer work.

---
#### **SM-7** · LOW · POS `order_number` generation is a check-then-set race with no unique constraint
**Re-verify: HOLDS.** `OrderManagementService.php:104-109` — `max(CAST(REPLACE(order_number,'#','') AS INTEGER))` over `terminal_id` + `whereDate('opened_at', today)`, no `lockForUpdate` on the sequence source. The migration's index block (`2026_03_11_400000:52-56`) declares four plain indexes and **no unique** on `(terminal_id, order_number)`.
**Vertical:** requires concurrent order creation on one terminal — a table-service/kitchen phenomenon. Zero orders exist for tenant #1.
**Overlap:** (e) **Dhouha #201-206** — her chain is precisely the flow that creates concurrent orders per terminal.
**→ (d) VERTICAL-DEFER** — file as review input for the F&B/table-management track; do not spend a lane on it now.

---

### §2 Data structures / schema (6)

---
#### **DS-1** · HIGH · Treasury FKs stored as plain uuid columns with no FK constraint
**Re-verify: HOLDS, all nine line numbers exact** (`database/migrations/tenant/2025_11_30_120000_create_treasury_tables.php` — `payment_repositories.location_id:34`, `responsible_user_id:35`, `account_id:38`; `payment_methods.default_journal_id:75`, `default_account_id:76`, `fee_account_id:77`; `payments.instrument_id:144`, `repository_id:145`, `journal_entry_id:160`).
**Corrections:** the comment *"Linked accounts (nullable, no FK constraint for flexibility)"* sits at **`:74` and governs only `:75-77`** — the `payments` and `payment_repositories` columns carry no rationale at all. The same migration DOES constrain six sibling columns (`:92, :110, :142, :143, :173, :174`), so these nine are deliberate omissions, not a file convention. **No cross-database excuse exists** — `journal_entries` (`2025_11_30_100000`) and `accounts` (`2025_11_30_090000`) are tenant-DB, same database; the codebase says so itself at `2026_08_08_120000_create_repository_adjustments_table.php:102-105` and then adds a real FK to `journal_entries`. **Trajectory:** every treasury table built since mid-2026 is FK-clean (`2026_07_08_100100:49-50`, `2026_07_12_100200:21,28`, `2026_08_08_120000:103-105`); the 2025 originals were never retrofitted. **No orphan detector covers any of the nine** — `ReconcileTreasuryCommand`'s invariant 2 (`:51`, impl `:820-841`) walks `repository_movements.journal_entry_id`, not `payments.*`.
**Overlap:** (a) LEDGER **B-2/B-6(i)** money-semantics closeout just merged (`076df8e0e`) — same subsystem, same reviewer lens. (b) the b2-b6i guard lane is adjacent but does not touch these columns.
**Vertical:** payments/GL are fully live for a parapharmacy — **launch-relevant**.
**Fix risk under unattended `tenants:migrate`:** adding an FK is additive in *form* but **validating in effect** — Postgres scans every existing row and aborts the whole per-tenant migration on the first orphan, mid-fleet, leaving mixed schema state. Nobody knows the orphan population because no detector has ever run. This is the **S-16 precondition pattern** verbatim.
**→ SPLIT.** Census half = **(b) FOLD-NOW** (lane F5: read-only command + fleet run, zero migration). Constraint half = **(e) TICKET-ONLY → own gated lane**, `NOT VALID` + separate `VALIDATE CONSTRAINT`, armed behind a LEDGER staging-owes row modelled on S-16. Do **not** ship the FKs blind.

---
#### **DS-2** · MED · Enum-typed columns stored as unconstrained strings with no DB CHECK
**Re-verify: HOLDS.** `composite_items.vertical_type` `2026_02_19_100001:20`; `production_type` **`:22`** (audit said `:20-22`; `base_price` is at `:21`); `documents.type` `2025_11_30_080000:18`; `documents.status` `:19`; `products.type` `2025_11_30_052910:21`.
**Nuance the audit missed, and it matters:** the omission is *inconsistent within one table* — `documents` DOES have enum CHECKs on its fiscal columns (`2025_12_11_054337_add_fiscal_constraints_to_documents.php:46` `fiscal_category IN (…)`, `:53` `fiscal_status IN (…)`). And the rationale is **documented and deliberate** — `2026_06_26_110000_add_supplier_credit_note_reason_to_documents.php:19-20`: *"The enum cast on the Document model enforces the allowed set; no PG CHECK constraint is added to keep the migration simple and **SQLite-compatible for feature tests**."* That last clause is the structural driver: **95 of the 110 CHECK-bearing migration files are pgsql-guarded**, so SQLite feature tests never exercise any CHECK. Coverage is 150 `ADD CONSTRAINT … CHECK` across 110 files / 277 `Schema::create` calls. **No enum↔CHECK parity guard exists** — `pg_constraint` appears in only three tests, all for UNIQUE.
**→ (c) PROGRAM** — merged with SM-6 into the DB-CHECK hardening slice (§4).

---
#### **DS-3** · MED · UUID vs bigint primary-key inconsistency
**Re-verify: HOLDS on the fact, WRONG on scope and magnitude.**
- Confirmed: `categories` `2025_12_26_194624:14` `$table->id()`; `product_batches` `2026_01_05_150000:14` `$table->id()` — **but `:15` also declares `$table->uuid()->unique()`**, a dual-key shape the audit missed and which is the ready-made escape hatch if uuid addressing is ever needed. `composite_items.category_id` foreignId `2026_02_19_100001:19`; all five `batch_id` references confirmed foreignId.
- **WRONG:** *"470+ tables"* — the tree has **277 `Schema::create` calls**, 245 with `uuid('id')->primary()` and **13 with `$table->id()`**.
- **WRONG:** the bigint list is not two but **nine tenant tables** — also `stored_events` `2025_11_30_102448:12`, `snapshots` `2025_11_30_102449:12`, `onboarding_checklists` `2025_12_30_115832:15`, `inventory_batch_stock` `2026_01_05_150001:15`, `inventory_batch_movements` `2026_01_05_150002:15`, `composite_item_modifier_groups` `2026_02_19_100007:14`, `country_pricing_regulations` `2026_07_08_131000:14`. `stored_events` on bigint is arguably **correct** (monotonic ordinal semantics for an event spine), not drift.
**Fix risk:** bigint→uuid PK is a data-bearing change rippling to 5+ FK columns across five tables, requiring dual-write + backfill + FK drop/recreate + app cutover. **Categorically unshippable under unattended fleet auto-migrate.**
**→ (e) TICKET-ONLY** — record as *documented won't-fix* with the corrected nine-table list and the `product_batches` uuid escape hatch noted; keep the audit's residual value ("at minimum keep it out of new tables") as a checklist line for the retro's **G2 canonical-entity manifest** / the proposed domain-model reviewer.

---
#### **DS-4** · MED · Products soft-deleted but `stock_levels`/`stock_movements` FK uses `cascadeOnDelete`
**Re-verify: HOLDS, both cites exact, blast radius 4× larger than stated.** `stock_levels.product_id` `2025_11_30_110000_create_inventory_tables.php:19`; `stock_movements.product_id` `:35`; `products` softDeletes `2025_11_30_052910:32` + `Product.php:28,90`. **Eight tables cascade off `products`**, not two — also `price_list_items` `2025_12_01_201028:17`, `automotive_product_metadata` `2026_03_09_300000:16`, `menu_category_items` `2026_03_11_700000:26,58`, `enrichment_results` `2026_03_28_100001:17`, `location_zones` `2026_07_06_200002:45`.
**Latent, not live:** no production code force-deletes a Product — `ProductController::destroy` (`:926`) calls `->delete()` at `:952` (soft); zero `forceDelete` sites on Product in `app/`. **But there is no guard either** — `Product::booted()` (`Product.php:186-210`) registers only a `creating` hook, no `forceDeleting` block, and three tests DO exercise the hard path (`tests/Unit/Product/ParapharmacyProductMetadataTest.php:187`, `tests/Feature/Product/AutomotiveProductTest.php:439`, `tests/Feature/Product/ParapharmacyProductTest.php:256`).
**Fix risk: SAFE.** Changing a delete action is pure DDL — Postgres does not scan or validate existing rows.
**Vertical:** `stock_movements` is the inventory audit ledger for a parapharmacy — **launch-relevant**.
**→ (b) FOLD-NOW · S** (lane F2)

---
#### **DS-5** · LOW · `documents.source_document_id` has no self-referencing FK, delete policy, or index
**Re-verify: HOLDS — and the LOW grade is now wrong, because THIS session's own r2f4 lane made the column hot.** `2025_11_30_080000_create_documents_table.php:33` bare `uuid()->nullable()`; the index block `:38-44` names none of it. A whole-tree sweep finds **exactly one mention of `source_document_id` in all 570 migrations** — that create line. The two later `documents` index migrations (`2026_01_02_…`, `2026_07_14_120000_add_expense_analytics_documents_index.php`) do not add it.
**r2f4 confirmed the column and added nothing.** `CorrectingEntryService.php:23` — *"is set at creation and is never editable afterwards"*; written at `:80`; `:215` calls it *"the sole input to"* the correction lookup. The column now carries 132 references across 30+ files with **14 unindexed query predicates**, several hot: `Accounting/Application/Services/AccountingService.php:1451`, `Document/Domain/Document.php:435`, `CorrectingEntryController.php:55`, `DeliveredQuantityResolver.php:299,491`, `DocumentPostingService.php:437`, `Procurement/…/SupplierCreditNotePostingService.php:520`.
**Precedent for the fix is in-tree:** `2026_08_08_120000_create_repository_adjustments_table.php:94-96` — *"Postgres does not auto-index FK columns, so the GL→document lookup would otherwise be a sequential scan."*
**Overlap:** (a) **C-8** — the r2f4 correcting-entry class; the residual C-8 trigger-widening lane is exactly about protecting this column from raw-DB re-pointing. **The index and the trigger widening want the same lane.**
**Fix risk:** index half is **SAFE** (an index cannot be violated by data; use `CREATE INDEX CONCURRENTLY`, pgsql-guarded). FK half is DS-1-shaped (validating) and must be separated.
**→ SPLIT.** Index half = **(b) FOLD-NOW · XS** (lane F4). FK + delete-policy half = **(c) PROGRAM**, ride the C-8 trigger-widening lane.

---
#### **DS-6** · LOW · Leftover `.bak` migration files in the central migrations directory
**Re-verify: HOLDS, and they are TRACKED IN GIT** (the distinction the audit left open). All five are returned by `git ls-files`: `apps/api/database/migrations/2026_01_05_150000_create_product_batches_table.php.bak`, `…150001_create_inventory_batch_stock_table.php.bak`, `…150002_create_inventory_batch_movements_table.php.bak`, `…150004_add_batch_id_to_document_lines_table.php.bak`, `…150005_add_batch_id_to_stock_reservations_table.php.bak`. Committed by `feb872340` (*"feat(pos): add discount orchestration, receipt void, promotions, coupons…"*) — swept in as collateral by an unrelated POS commit, which is itself a small G7 data point.
**Zero risk to delete:** the `.bak` bodies are fully commented out (`// $table->id();`), they are the disabled central-DB predecessors of live `tenant/` twins that all exist, and Laravel's migrator globs `*.php` only — never loaded, never run, no migration-repository row.
**→ (b) FOLD-NOW · XS** (lane F6, pair with F4)

---

### §3 Domain-model / anti-patterns (8)

---
#### **DM-1** · HIGH · Anemic domain models: all business logic lives in god-services
**Re-verify: HOLDS, with one fabricated number.** Line counts exact: `Order.php` 317, `OrderLine.php` 128, `Receipt.php` 583, `OrderManagementService.php` **823**, `ReceiptCreationService.php` **1422**. Data-bag claim holds — `Order.php:213,221,230,243,251`; `OrderLine.php:116,124`; `Receipt.php:416-480` are all relations, scopes, or read predicates.
**WRONG:** `createReceipt` spans `ReceiptCreationService.php:117` to its closing brace at **`:730`** (next method `processModifiers` at `:744`) — **~614 lines, not ~1100**. The audit's "117-1259" was never true: `git show c6d6308ae` of that file is **byte-identical** to today's, so this is an arithmetic error in the audit, not drift. Cite 614.
**→ (c) PROGRAM (partial).** The *transition-behaviour* slice ("move `sendToKitchen`/`cancel` onto the aggregate") is exactly workstream A and should be absorbed there. The broader "de-anemify the whole POS domain / split `ReceiptCreationService`" is a multi-quarter refactor of a fiscal-critical, hash-chained surface — **explicitly out of charter scope**; record as documented won't-fix-now with the corrected 614 figure.

---
#### **DM-2** · HIGH · Money value object exists but is quarantined in Billing
**Re-verify: PARTIALLY-SUPERSEDED — facts right, disposition wrong.** The count is exactly right: **10 files, all under `Modules/Billing/`**. `'TND'` hardcoded confirmed at `OrderManagementService.php:130`.
**But the framing contradicts a ratified project contract.** The cited "casts" at `Order.php:42-46` / `OrderLine.php:29-33` are the **PHPDoc `@property` block**, not casts — and they declare `numeric-string`. The real casts are `Order.php:124-127` (`decimal:3`) and `OrderLine.php:84-89`. `docs/architecture/precision-contract.md:19` states: *"Eloquent `decimal:N` casts are declared on every money/quantity model property so reads return canonical strings."* The doc's value-object section (`:46-49`) names only Billing `Money` and Loyalty `PointsAmount`/`LoyaltyBalance`; the canonical project-wide representation is **numeric-string + bcmath + `CurrencyScale`** (`:13, :22-28`), enforced by PHPStan (CLAUDE.md rule 19). Promoting `Money` across POS/Inventory/Catalog would be a *replacement* of the ratified contract, not an application of it — and would have to be an owner-level architecture decision, not an audit follow-up.
**→ (e) TICKET-ONLY, residual only.** Reject the promote-Money recommendation. Keep one small item: the `'TND'` fallback at `OrderManagementService.php:130` is a genuine smell (a company with a null currency silently books Tunisian dinars) — worth a P3 ticket, and it belongs with the country-defaults authority (LEDGER O-25/O-20 family), not with a VO migration.

---
#### **DM-3** · HIGH · Unclear aggregate boundary: `StockLevel` quantity/reserved mutated across modules
**Re-verify: HOLDS, and the audit undercounted.** `StockReservationService.php:161-163` confirmed. **Eight external services across two modules, ~20 write sites**, plus the model writing itself:
`StockAdjustmentService.php:123,268,565,629,929,1375,1450` · `StockReservationService.php:161,283,407` · `WeightedAverageCostService.php:284,447,600` · `ResetOpeningBalanceService.php:137` · `OpeningBalancePostingService.php:139` · `POS/…/ReceiptCreationService.php:953` · `POS/…/ReceiptReturnService.php:1329` · `POS/…/PosCoreReceiptProjection.php:2034,2439` · `Inventory/Domain/StockLevel.php:204` (self).
`getAvailableQuantity()` at **`StockLevel.php:104-107`** — `bcsub($this->quantity, $this->reserved, 4)`, **no clamp, can return negative**.
**The ready-made fix pattern is already in-tree** — see DM-9/§4: `app/PHPStan/Rules/WorkOrderStatusWriteOnlyViaTransitionService.php` proves the repo already machine-enforces single-write-path for one aggregate. `StockLevel` has no such rule.
**Vertical:** stock reservation/adjustment is fully live for a parapharmacy — **launch-relevant**.
**→ (c) PROGRAM** (charter workstream C — the *aggregate write-path* companion to the state-machine workstreams; same technique, different noun).

---
#### **DM-4** · HIGH · Leaky layering: the 'Domain' layer is Eloquent Active Record; DDD base classes are dead code
**Re-verify: HOLDS; counts grew; one detail wrong; and the "maybe it's ratified" hypothesis resolves to NO.**
- Base classes exist: `app/Shared/Domain/{AggregateRoot,Entity,ValueObject}.php`.
- **WRONG:** *"zero classes extend them"* — `app/Shared/Domain/CurrencyScale.php:13` is `final class CurrencyScale extends ValueObject`. Nothing extends `AggregateRoot` or `Entity`.
- Counts grew, not shrank: **223** Domain files extend Eloquent `Model` (audit: ~195); **267** import Eloquent or the DB facade (audit: ~236).
- Query-in-entity sites confirmed at today's lines: `StockLevel::getReservationBreakdown()` `StockLevel.php:182-190`; `recalculateReserved()` SUM + `save()` `:196-206`.
- `Billing/Domain/Invoice.php:17,57` `CentralConnection` confirmed — but this is the **SaaS-billing Invoice on the central DB**, where a central-connection trait is semantically correct. Weakest of the DM-4 sites; drop it from the argument.
- **Ratification check: NOT ratified.** `apps/api/deptrac.yaml` (122 lines) enforces only hexagonal *direction* between layer globs (`:13-16`, ruleset `:92-94`); vendor code sits in no layer, so Eloquent is out of scope **by construction — silence, not blessing**. And `.claude/context/architecture.md` says the *opposite* of the audit's hypothesis: `:8` places Eloquent repositories in INFRASTRUCTURE, `:13` states *"Domain layer has ZERO dependencies on infrastructure"*, with only a descriptive concession at `:28-29` that "both patterns are used."
**→ (c) PROGRAM (doc-reconciliation half only).** The refactor half is unshippable — reinstating pure domain objects behind repositories across 223 files is not a lane, it is a rewrite. What IS actionable and belongs in the charter: **make the architecture doc tell the truth** — either amend `.claude/context/architecture.md:13` to state the ratified Active-Record-in-Domain reality with its boundary rules, or add a deptrac layer that actually forbids `Illuminate\Database` in `*/Domain` and baseline the 223. Today the doc claims a guarantee no guard provides, which is the same failure mode as DM-5's `@var numeric-string`.

---
#### **DM-5** · MED · `composite_items.tax_rate` stored as `string(10)` instead of a decimal
**Re-verify: HOLDS, and it is live in VAT math — the audit understated it.** `2026_02_19_100001_create_composite_items_table.php:23` — `$table->string('tax_rate', 10)->nullable()`. `Catalog/Domain/Entities/CompositeItem.php:41` types it `string|null` with **no cast**, and it is read straight into VAT computation at `ReceiptCreationService.php:215` — `/** @var numeric-string $taxRate */ $taxRate = (string) ($compositeItem->tax_rate ?? '0.00');` — and again at `:1283`. **The `@var numeric-string` annotation asserts a guarantee the `varchar(10)` column does not provide**: `"20%"` or `"abc"` would satisfy the column and reach `bcmul` in `RoundsVat`. The sibling column is typed correctly (`create_products_table.php:25` — `decimal('tax_rate', 5, 2)`). A successor FK exists (`2026_03_22_100001_add_default_tax_configuration_id_to_composite_items.php:14`, `after('tax_rate')`) but has not displaced the string column.
**Vertical:** `CompositeItems` is a **`compatible_extras` upgrade** for parapharmacy (`config/verticals.php:340`), **not a default module** (`:344-361`) — and the routes are properly gated (`Catalog/Presentation/routes.php:32`, `module:CompositeItems`). **Off for tenant #1 at launch.**
**→ (e) TICKET-ONLY — but elevate to P2 and hand it to the next Catalog/CompositeItems lane.** The false `numeric-string` guarantee on a fiscal path is worth more than the audit's MEDIUM; it is only *not* fold-now because the module is dark for tenant #1. If any lane opens on Catalog before F&B/upgrade launch, fold it there (migration to `decimal(6,3)` + value census + drop the lying annotation).

---
#### **DM-6** · MED · Float reintroduced on the money/quantity path
**Re-verify: HOLDS on the four casts; the "undetected" framing is WRONG; the `RoundsVat` half should be dropped.**
- Four casts, exact (audit's "404" is actually `:407`): `StockReservationService.php:275` `$batchStock->decrement('reserved_quantity', (float) $reservation->quantity)`; `:283` `$stockLevel->decrement('reserved', (float) $reservation->quantity)`; `:399` and `:407` the same pair again. Asymmetry with reserve confirmed — `:157` and `:162` use `bcadd(…, self::QUANTITY_SCALE)`. **Reserve with bcmath, release with float.** The drift mechanism the audit describes is real, and is exactly what `recalculateReserved()` exists to repair.
- **WRONG framing:** PHPStan did not miss it. `app/PHPStan/Rules/ForbidFloatCastOnDecimalProperty.php` fires on this exact shape (`StockReservation::$quantity` is `decimal:4`, `StockReservation.php:49`) — all four are **recorded in `phpstan-baseline.neon:441-445`, `count: 4`, identifier `precision.floatCastOnDecimalProperty`. This is accepted legacy debt with a suppression receipt, not an undetected regression.** That materially changes the fix: it is a *de-baseline*, and the ratchet will hold it closed afterward.
- **DROP the `RoundsVat` half.** `app/Modules/POS/Application/Concerns/RoundsVat.php:53` — `CurrencyScale::bcformat((string) round((float) $raw, $scale), $scale)` — is **deliberate and blessed**: `:45-50` documents bcmath at `scale + 4` for the intermediate, then PHP `round()` for half-away-from-zero to match PostgreSQL; the trait header `:10-22` explains it exists so sale and return paths round byte-identically **for fiscal-hash parity**; `precision-contract.md:27` names it. Touching it would break hash parity.
- **NEW find the audit missed:** `Inventory/Domain/StockReservation.php:171` — `return (float) $this->quantity * (float) ($this->product->cost_price ?? '0');` — float multiplication of quantity × money. Include in the same lane.
**Vertical:** stock reservations run in retail — **launch-relevant**.
**→ (b) FOLD-NOW · S** (lane F3) — the four `StockReservationService` casts + the `StockReservation.php:171` find; `RoundsVat` explicitly out of scope.

---
#### **DM-7** · MED · Order/Receipt/Invoice each own a separate totals-recalculation engine
**Re-verify: PARTIALLY-SUPERSEDED — multiplicity holds, "three divergent conventions" does not, and the seam that mattered is retired.**
- `OrderManagementService::recalculateOrderTotals` `:740-767` (span exact). Tax-**inclusive**: `:753` `$lineNet = bcsub($line->line_total, $line->tax_amount, $scale)` → `:759` `$total = bcadd($subtotal, $taxAmount, $scale)`; discount accumulated `:756` but not subtracted (already embedded in `line_total`).
- `Billing/Domain/Invoice::recalculateTotals` `:230-266` (span exact). `:254` `bcsub(bcadd($subtotal,$taxAmount,$scale), $discountAmount, $scale)` — tax-exclusive. **But this is the SaaS-subscription Invoice on the CENTRAL DB — a different bounded context from the tenant POS entirely.** Comparing it to `Order` overstates the coupling.
- `ReceiptCreationService.php:488-489`, written `:592-597` — **shares** Invoice's identity, and says so at `:486-487` (*"maintain the accounting identity: subtotal + tax_amount - discount = total"*), deriving tax by subtraction deliberately to dodge re-rounding drift (`:484-485`).
So it is a **2-way split, not 3-way**, and the genuine residual is Order (tax-inclusive) vs `ReceiptCreationService` (tax-exclusive) inside one module. **But the seam that made that dangerous is gone**: the audit's stated harm — *"an order's displayed total and the receipt/invoice total it becomes are produced by different code"* — required `closeOrder → convertToReceipt`, which is **410 Gone** (`routes_orders.php:43-50`). Orders no longer *become* receipts. Note this is also consistent with CLAUDE.md rule 19's warning that `unit_price` is tax-inclusive in the B2C POS and net/HT in B2B documents — the two conventions are *supposed* to differ.
**→ (e) TICKET-ONLY, low.** Record the corrected 2-way framing; no lane.

---
#### **DM-8** · LOW · Menu availability rules live in a resolution service, not on the Menu entity
**Re-verify: HOLDS.** `Menu/Application/Services/MenuResolutionService.php:49-75` `matchesRules()` reads `start_date`/`end_date` `:52-57`, `active_from`/`active_until` `:60-65`, `available_days` `:68-72` — textbook feature envy. `Menu/Domain/Entities/Menu.php:26-30` declares exactly those five fields; its methods `:86-140` are only relations and scopes — no `isAvailableAt()`.
**Vertical: F&B-only.** `Menu` appears in `default_modules` for `restaurant` (`config/verticals.php:86`) and `coffee_shop` (`:116`) only; the routes are properly gated (`Menu/Presentation/routes.php:12`, `module:Menu`). **Dark for tenant #1 on both layers.**
**→ (d) VERTICAL-DEFER** — clean, trivial refactor; hand to the F&B track. Dhouha's chain should inherit it as review input (the overnight-time-range MVP gap noted in the service is the kind of thing her floor/occupancy work will trip on).

---

## 4. (ii) PROGRAM charter sketch — "State machines, transitions & aggregate write-paths"

**Entry: spec-first, per the retro's G7 residual.** A NEW dedicated session; no code before an accepted spec.

### Findings in charter
| Workstream | Findings | Noun |
|---|---|---|
| **A — POS Orders** | SM-2, SM-4, SM-5 (order/line thirds), SM-6, DM-1 (transition-behaviour slice) | `pos_orders`, `pos_order_lines` |
| **B — Documents** | SM-3, DS-5 (FK/delete-policy half), C-8 residual (trigger widening) | `documents` |
| **C — Stock aggregate** | DM-3 | `stock_levels` |
| **D — DB constraint hardening** | SM-6, DS-2 | all enum-backed string columns |
| **E — Doc reconciliation** | DM-4 (doc half) | `.claude/context/architecture.md`, `deptrac.yaml` |

### The Workshop precedent — verified in-tree, and stronger than the audit knew
- `app/Modules/Workshop/WorkOrder/Domain/Services/StatusMachine.php` (88 lines) — `final`, zero deps beyond the enum; `isAllowed(from,to)` `:28-35` rejects self-loops at `:30`; adjacency map as a `match` returning `list<WorkOrderStatus>` from `:42`; docblock `:13-14` states it is deliberately side-effect-free.
- `app/Modules/Workshop/WorkOrder/Application/Services/WorkOrderTransitionService.php` (327 lines) — constructor-injects `StatusMachine` `:55-57`; `transition()` `:63` guards at `:71`; `loadForUpdate()` `:178` (locking + optimistic concurrency); `writeTransition()` `:223`; `dispatchTransitionEvent()` `:244`.
- `app/Modules/Workshop/WorkOrder/Domain/Exceptions/WorkOrderTransitionException.php` — `extends DomainException` `:17`, named constructors `forbiddenEdge()` `:19` / `vetoedByPolicy()` `:26`, HTTP 422 `:15`.
- `database/migrations/tenant/2026_04_19_130004_create_workshop_work_order_status_transitions_table.php:21-36` — append-only: uuid PK, `tenant_id`, `work_order_id` FK cascade, `from_status`/`to_status`, `reason_code`, `triggered_by_user_id` FK nullOnDelete, `triggered_at` timestampTz, `context` **jsonb**, index `(work_order_id, triggered_at)`.
- **Proven twice, not a one-off:** `2026_04_19_140005_create_scheduling_appointment_status_transitions_table.php:22-40` shares the exact column vocabulary.
- **The transferable lesson the audit missed:** the single-write-path is **machine-enforced** — `app/PHPStan/Rules/WorkOrderStatusWriteOnlyViaTransitionService.php`, one of eight sibling architectural rules (`DeliveryNoteBillingWritesOnlyViaClaimService`, `InventoryGlPostingViaBufferOnly`, …), each with a `tests/PHPStan/` fixture. **A state machine without its PHPStan rule is a suggestion.** That rule — not the adjacency map — is what makes the pattern hold, and it is precisely what `StockLevel` (workstream C) and `DocumentStatus` (B) lack.

### Composition with the enforcement layer (LEDGER, all three packages LANDED at `c6d6308ae`)
This program is a **fourth enforcement package in everything but name** and should inherit the machinery, not reinvent it:
- Guard shape: PHPStan rule + `tests/PHPStan/` fixture + `tests/Architecture/*RatchetTest.php` with a shrink-only baseline — the P1/P2/P3 idiom, and the one `docs/conventions/08-DETECTOR-LIVENESS.md` requires (every guard ships with its tamper test).
- Gate protocol: **S-14** applies verbatim if any candidate touches `.github/workflows/**`; **S-17** (CI-blind window, Actions quota out) means every merge lands CI-UNVERIFIED with local Docker + by-path verification until quota returns.
- Baseline arithmetic: workstream A/B will move deptrac counts (C-8 already records 176→182 acked) — pre-agree the ratchet deltas at spec time, never at merge.

### Spec skeleton (proposed)
1. **§1 Census (red-first).** Enumerate every status write per noun, machine-derived (not hand-listed) — the `feature-lane-manifest-check.php` idiom. Deliverable: a table of `file:line → from-set → to-set`, and the *implied* graph vs the *stated* graph.
2. **§2 Graph ratification.** Owner/expert signs each adjacency map, incl. which states are terminal. Non-obvious calls to surface explicitly: is `Received` terminal? may `Paid → Cancelled` exist (r2f4/credit-note interaction)? does POS `Ready → SentToKitchen` (un-bump) exist?
3. **§3 Machines + typed exceptions**, Workshop-shaped, one per noun.
4. **§4 Single write path** — `*TransitionService`, then **the PHPStan rule that forbids bypassing it**, with the fixture test. Rule lands in the SAME milestone as the service or the service is decorative.
5. **§5 Transition tables** — mirror the Workshop/Scheduling column vocabulary exactly (a third instance of one shape beats a third dialect); written inside the same DB transaction as the status write.
6. **§6 DB constraints** — see the standalone slice below.
7. **§7 Call-site migration**, per module, behind the ratchet.
8. **§8 Doc reconciliation** (workstream E).

### Can the DB CHECK constraints land as an EARLY migration-bearing slice, independent of the full program? — **YES, with three preconditions.**
The constraints encode the *enum*, not the *graph*; they need no transition service and no app change. Land them first as workstream D.
1. **Per-tenant distinct-value census before the migration** — the **S-16 precedent**, which is now a proven pattern (8 staging tenant DBs queried live, denominator 0, port re-closed). A CHECK aborts the whole per-tenant migration on the first non-conforming row, and `origin/dev` pushes run `tenants:migrate` **unattended** (memory: `feedback_push_dev_autodeploys_migrations`). Arm a LEDGER staging-owes row.
2. **`NOT VALID` + separate `VALIDATE CONSTRAINT`** so the additive DDL cannot fail closed mid-fleet.
3. **A `pg_constraint` parity test** — because **95 of 110 CHECK-bearing migrations are pgsql-guarded and SQLite feature tests see none of them**, a CHECK that only exists in production is a guard nobody tests. `pg_constraint` is currently queried by exactly three tests, none for CHECKs. The parity test (every column with a Domain enum has a CHECK covering exactly that enum's cases) is the audit's own suggestion and is the highest-value single artifact in this slice.

**Scope for slice D:** `pos_orders.status`, `pos_order_lines.status`, `documents.type`, `documents.status`, `products.type`, `composite_items.vertical_type`, `composite_items.production_type`. Size **M**. Gates: `tenancy-authz-reviewer` (fleet-migration safety) + `fiscal-pos-reviewer` (documents/POS columns). Independent of A/B/C — ship it first.

### Explicitly OUT of charter
De-anemifying the POS domain wholesale (DM-1 broad), splitting `ReceiptCreationService` (fiscal hash-chain surface), the Money-VO migration (DM-2 — contradicts the ratified precision contract), the bigint→uuid PK migration (DS-3), and the pure-domain/repository rewrite (DM-4 refactor half). Each is a rewrite, not a lane. Record as documented won't-fix.

---

## 5. (i) FOLD-NOW queue — ready to dispatch

| # | Scope | Size | Gate lens | Launch-relevant? | Migration? |
|---|---|---|---|---|---|
| **F1** | **POS terminal-state + module-gate micro-lane.** (a) Add `module:Menu` to `app/Modules/POS/routes_kitchen.php:15` and the F&B order-workflow routes in `routes_orders.php`, mirroring the FE (`routes/index.tsx:3226`, `Sidebar.tsx:238`) — closes the rule-12 both-layer gap and makes the whole cluster unreachable for tenant #1 by construction. (b) Guard `updateLineStatus` (`OrderManagementService.php:542`) with `$order->status->isActive()` and make `checkAndTransitionOrderToReady` (`:713`) refuse to transition out of a terminal state — change `$order->status !== OrderStatus::Ready` to also require `isActive()`. (c) Red-first test reproducing the Cancelled→Ready chain of §2. **Do NOT** build the adjacency map here — that is workstream A. | **XS-S** | `tenancy-authz-reviewer` (module gating, primary) + `fiscal-pos-reviewer` (POS state) | No (hardening) | No |
| **F2** | **`cascadeOnDelete` → `restrictOnDelete` on the inventory audit ledger.** `stock_levels.product_id` and `stock_movements.product_id` (`2025_11_30_110000_create_inventory_tables.php:19,:35`). Consider the other six cascading tables in the same pass or explicitly scope to two. Fix the three tests that hard-delete products in the same commit (`ParapharmacyProductMetadataTest.php:187`, `AutomotiveProductTest.php:439`, `ParapharmacyProductTest.php:256`). Optionally add a `forceDeleting` guard on `Product::booted()` (`Product.php:186-210`). | **S** | `inventory-costing-reviewer` + `stock-gl-interaction-reviewer` | **Yes** | Yes — pure DDL, **cannot fail on existing data** |
| **F3** | **De-baseline the four float casts on the reservation release path.** `StockReservationService.php:275,283,399,407` → `bcsub` at `QUANTITY_SCALE` + `update()`, mirroring the reserve path (`:157,:162`); remove the four entries from `phpstan-baseline.neon:441-445`. Include the new find `StockReservation.php:171` (float × float on quantity × cost_price). **Explicitly out of scope: `RoundsVat.php:53`** — deliberate, documented, fiscal-hash-parity-critical (`precision-contract.md:27`). | **S** | `inventory-costing-reviewer` | **Yes** | No |
| **F4** | **Index `documents.source_document_id`.** `CREATE INDEX CONCURRENTLY`, pgsql-guarded. Justification in-tree at `2026_08_08_120000_create_repository_adjustments_table.php:94-96`; 14 unindexed predicates listed in DS-5. FK/delete-policy explicitly deferred to the C-8 trigger-widening lane. | **XS** | parent + `treasury-reviewer` (r2f4/correcting-entry adjacency) | **Yes** (r2f4 hot path) | Yes — index only, **cannot be violated by data** |
| **F5** | **Treasury orphan census (read-only, no migration).** An artisan command in the `ReconcileTreasuryCommand` mould covering the nine bare-uuid columns of DS-1; run fleet-wide against staging (the S-16 execution pattern). Output feeds the DS-1 constraint lane's go/no-go. Zero write risk. | **S** | `treasury-reviewer` | **Yes** | No |
| **F6** | **Delete the five tracked `.bak` migrations.** `git rm` the five files under `apps/api/database/migrations/`; verified inert (fully commented bodies, live `tenant/` twins exist, migrator globs `*.php` only). Pair with F4 into one commit if convenient. | **XS** | parent-only | No | No |

**Suggested order:** F6+F4 (trivial, one commit) → F3 → F2 → F5 → F1. F1 last only because it wants a moment's thought about whether the F&B order-workflow routes want `module:Menu` or `module:Tables` — check against Dhouha's chain before choosing, since her P1 #4 already records a `Menu`-vs-`Tables` gating confusion at `HomePage.tsx:183`.

---

## 6. (iii) What the audit got WRONG — cross-session record for the VPS session

Ordered by how much the error changed a disposition.

1. **`closeOrder` is HTTP-dead and has zero production callers.** `routes_orders.php:43-50` returns 410 `NEW_SALE_AUTHORING_RETIRED` (fiscal Phase 1 §14.2); `OrderController::close` is unrouted. The #1 HIGH's headline — *"a terminal, receipt-backed/fiscally-closed order being reopened"* — is **unreachable**. Only the Cancelled branch is live. The finding survives; its severity narrative does not.
2. **`DocumentStatus::isTerminal()` EXISTS** — `DocumentStatus.php:48-53`, landed `785d2f8ee` (W-7 F-6), enforced via `Document::isWithdrawn()` (`Document.php:689`) at `DocumentAllocationStateGuard.php:40`. The audit asserted its absence.
3. **`DocumentPostingService` DOES guard its own edges** — post `:85`, cancel `:152,:163`, revert `:329,:336,:354`. The audit's illustrative transitions (`posted→draft`, `cancelled→posted`) are not unprevented at the primary service. Also: **31** writer files, not 37.
4. **`createReceipt` is ~614 lines (`:117-730`), not ~1100 (`:117-1259`).** The file is **byte-identical at `c6d6308ae`**, so this was an arithmetic error in the audit, not drift. A 2× overstatement on the headline metric of a HIGH.
5. **The Money-VO recommendation contradicts a ratified project contract.** `precision-contract.md:13,19,22-28` mandates numeric-string + bcmath + `CurrencyScale` as the canonical money representation; `Money` is named only for Billing. The cited "casts" at `Order.php:42-46` are the PHPDoc `@property` block, not casts (real casts `:124-127`, `decimal:3`). Promoting `Money` project-wide would *replace* the contract, not apply it.
6. **The float casts are not undetected — they are baselined.** `phpstan-baseline.neon:441-445`, `count: 4`, identifier `precision.floatCastOnDecimalProperty`; the rule `ForbidFloatCastOnDecimalProperty` fires correctly. And `RoundsVat.php:53`'s `round((float)…)` is **deliberate, documented (`:45-50`), and blessed by `precision-contract.md:27`** for fiscal-hash parity — lumping it with the reservation casts would have produced a harmful fix.
7. **Totals engines: 2 conventions, not 3** — `ReceiptCreationService.php:486-487` explicitly shares the Invoice identity. And `Billing/Domain/Invoice` is the **central-DB SaaS-subscription invoice**, a different bounded context from the tenant POS. The harm the finding describes required the Order→Receipt seam, which is retired (see #1).
8. **"Zero classes extend the DDD base classes"** — `CurrencyScale.php:13` extends `ValueObject`. And the counts grew rather than matching: **223** Domain files extend Eloquent (not ~195), **267** import Eloquent/DB (not ~236).
9. **UUID/bigint scope:** *"470+ tables"* → the tree has **277 `Schema::create` calls**; the bigint list is **nine tenant tables**, not two; `product_batches` carries a **uuid unique escape hatch** at `:15` that the audit missed; `stored_events` on bigint is arguably correct, not drift.
10. **The treasury "no FK for flexibility" comment governs only three of the nine columns** (`:74` covers `:75-77`). The other six carry no rationale at all — which strengthens the finding, but the audit attributed a deliberate decision where there was only silence. Also: the same migration constrains six sibling columns, and every treasury table built since mid-2026 is FK-clean.
11. **`documents.source_document_id` graded LOW is now wrong** — this session's own r2f4 lane made it the sole join key of the correcting-entry lifecycle (`CorrectingEntryService.php:23,80,215`), with 132 references and 14 unindexed predicates. Timing, not error — but the VPS session should know the column moved.
12. **Missed: the backend kitchen/order routes carry NO `module:` middleware while the FE `ModuleGuard`s them** (`routes_kitchen.php:15` / `routes_orders.php:15` vs `routes/index.tsx:3226` + `Sidebar.tsx:238`). This is a standing CLAUDE.md rule-12 violation on the very surface finding #1 lives on, and it is the *cheapest* mitigation for that finding. A state-machine review that does not check reachability will keep proposing the expensive fix.
13. **Missed: the Workshop pattern's load-bearing part is the PHPStan rule, not the adjacency map** — `app/PHPStan/Rules/WorkOrderStatusWriteOnlyViaTransitionService.php`, one of eight sibling architectural rules with fixture tests. "Propagate the Workshop pattern" without propagating its enforcement rule would produce a `StatusMachine` that new callers simply route around — which is exactly how `StockLevel` (DM-3) got 20 writers.

**Methodological note for the next remote review:** the two systematic error classes here are (a) **arithmetic asserted rather than measured** (#4, #8, #9) and (b) **premises about reachability and ratification not checked against the repo's own docs and route middleware** (#1, #5, #6, #12). Both are cheap to close: derive counts with a command whose output you paste, and before recommending a pattern change, grep `docs/architecture/`, `.claude/context/`, `deptrac.yaml`, and `phpstan-baseline.neon` for whether the current shape is already ratified or already ticketed.

---

## 7. Overlap map — consolidated

| Finding | LEDGER | In-flight lanes | B-4 party/contact | Retro §5 | Dhouha #201-206 |
|---|---|---|---|---|---|
| SM-1 | — | — | — | — | **inherits** (same class as her P2 ungated-`module:Tables` routes) |
| SM-2 / SM-4 | — | — | — | G7 residual (domain-model reviewer) | **inherits** |
| SM-3 | **C-8 residual = the same trigger-widening lane**; O-26 same seam, complementary axis | **P1 auto-save** = SM-3's thesis instantiated; proceeds independently | — | G7 | — |
| SM-5 | — | **R-8 / SV** owns the shift third | — | — | **inherits** (order/line) |
| SM-6 / DS-2 | S-16 census precedent; S-17 CI-blind | — | — | conv. 08 liveness | — |
| SM-7 | — | — | — | — | **inherits** (concurrent order creation is her flow) |
| DS-1 | B-2/B-6(i) closeout `076df8e0e` (same subsystem) | b2-b6i guard (adjacent) | — | — | #205 treasury carve-out (parked) |
| DS-3 | — | — | — | **G2** canonical-entity manifest | — |
| DS-4 / DM-3 / DM-6 | — | — | — | G1/G3 guard idioms | — |
| DS-5 | **C-8** (r2f4 correcting entries) | — | tangential (`documents.partner_id`) | — | — |
| DM-2 | O-20/O-25 country-defaults family (`'TND'` fallback) | — | — | — | — |
| DM-4 | — | — | — | **G2/G7** — same "doc claims a guarantee no guard provides" failure mode | — |
| DM-5 / DM-8 | — | — | — | **G6** verticals↔modules reconciliation | **inherits** (DM-8) |

**B-3 `pos_enabled`** and **X/Z refund VAT (C-2/C-6)** do not intersect any of the 21 — noted for completeness. `pos_enabled` appears in the retro's **G3** dead-column proposal, which is a different axis from anything the audit raised.

---

*Prepared by the triage session, 2026-08-23, against local dev `bdf9ce5e9`. Read-only: no production file was modified. All dispositions are proposals — none is authorized to execute without the normal lane/gate entry.*
