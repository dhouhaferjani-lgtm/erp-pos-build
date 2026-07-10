# Replenishment Requests ("Refill Requests") — Design Spec

- **Date:** 2026-07-10 (brainstormed 2026-07-09)
- **Revision:** **Rev 3** — adds the **capture-receipts idempotency ledger** (plan adversarial review found the bump path non-idempotent: a POS retry after a lost response re-bumped the line, double-counting qty/request_count; fixed by recording every applied `client_request_uuid` in `replenishment_capture_receipts` and replaying from the ledger). Rev 2 was post-spec-adversarial-review (3 Claude domain reviewers, all APPROVE-WITH-CONDITIONS; findings + resolutions in `docs/superpowers/specs/reviews/2026-07-10-replenishment-requests-adversarial-review.md`). Rev 2 changes: settlement reads lines via a new `TransferLineReader` contract (event is header-only); cancelled-transfer compensating re-open; quantities finalized in the review UI before `initiate()` (no draft transfer stage); two-partial-index dedupe + atomic bump returning 200 (never 409); terminal_id-validated location contract; role grants enumerated; web location-picker authorization; downstream-permission gates; composite claim qualified.
- **Status:** Review conditions applied; awaiting owner read → writing-plans
- **Verticals:** parapharmacy chain (first customer, onboarding ~3-4 weeks), designed to serve F&B (central kitchen → shops, franchises) next
- **Naming:** "Refill request" (EN UI) / "Demande de réassort" (FR UI); backend module `Replenishment`

## 1. Problem & goal

Cashiers/operators at a shop notice a product is out of stock or low (mid-day or end of day) and place a refill request. A back-office user (purchaser / warehouse manager) reviews accumulated requests and decides per line how to fulfill: transfer stock from another location (warehouse, central kitchen, another shop) or order from a supplier. The requester owns the *signal*; the fulfiller owns the *quantity* and the *path*.

Key business realities (owner + first customer):
- **POs go to the warehouse, not to shops.** The client's purchase orders always deliver to the warehouse; the warehouse then distributes to shops via stock transfers. Therefore **a PO can be *triggered by* a request but only a transfer to the requesting shop *fulfills* it.** (Direct-to-shop PO delivery is still supported for tenants that work that way.)
- **Shipment discipline**: shops may be resupplied on a fixed cadence (e.g., twice a week). One transfer settles demand accumulated over several days.
- Quantity on the request is **optional**.
- Any sellable product is requestable, composites included — the request is a demand *signal*. Fulfillment-side, the transfer path moves real finished-good stock rows only: **produced-into-stock composites transfer normally** (the central-kitchen case); made-to-order composites have no finished-good stock level and will surface as un-transferable at fulfillment time (`InsufficientStockException`). The F&B phase must pair this with production stocking (composite inventory work is deferred post-parapharmacy-launch).

Industry grounding (research 2026-07-09): closest prior art is D365's "purchase requisition with replenishment purpose" (fulfillment method decided downstream per line) and D365 Commerce's POS-authored stock requests. Requester edits nothing after submission; "fulfilled" means the fulfillment document exists — receiving is tracked on the transfer/PO, not the request.

## 2. Scope

**v1 in:**
- Capture from **POS** (offline-capable) and **web**.
- Web **review queue** with by-shop and by-product views (by-product = matrix with one column per shop).
- Per-line/batch fulfillment: create transfer, create/append draft PO, reject.
- Auto-settlement: transfers to a shop automatically fulfill matching open requests; cancelled transfers re-open them.
- Status feedback to the requesting shop (read-only list).

**v1 out (explicitly):**
- Mobile app (doesn't exist yet; the web capture page must stay phone-usable).
- Franchise / intercompany fulfillment (transfer service is intracompany-only today; the request model carries nothing that blocks adding it later).
- Automatic min/max replenishment (but open request lines are modeled so a future engine can net against them as planned inbound demand).
- Approval gate before review (review *is* the action; add approval only if a tenant asks).
- Partial-quantity settlement logic (any linked transfer settles the line; see §5).
- Composite production orchestration (see §1 qualification).

## 3. Domain model — line-grain, no header document

Grouping model C (owner-locked): cashiers submit lines instantly (no draft basket to forget mid-shift); the review queue does the per-shop/per-product rollup. A header document would add lifecycle noise with no value, so the core entity is a single line-grain table.

**Table `replenishment_requests`** (tenant DB):

| Column | Notes |
|---|---|
| `id` uuid PK | |
| `tenant_id`, `company_id` | from `CompanyContext` server-side — never from payload |
| `location_id` | the requesting shop; must belong to the active company (validated) |
| `product_id`, `variant_id` nullable | what is needed |
| `requested_qty` `decimal(15,4)` nullable | optional; `QuantityScale` rules; FormRequest regex `/^\d+(\.\d{1,4})?$/` |
| `note` nullable | free text from requester |
| `request_count` int default 1 | bumped on dedupe (see below) |
| `status` enum | `pending`, `in_progress`, `fulfilled`, `rejected`, `cancelled` — `in_progress` is optional (a direct transfer fulfills a `pending` line without passing through it) |
| `requested_by_user_id`, `first_requested_at`, `last_requested_at` | |
| `source_channel` enum | `pos` \| `web` |
| `client_request_uuid` nullable unique | POS idempotency key |
| `sourcing_document_id` nullable | the PO this line's demand was added to (trigger link) |
| `fulfillment_type` enum nullable | `transfer` \| `purchase_order` |
| `fulfillment_id` nullable | `stock_transfers.id` or `documents.id` |
| `processed_by_user_id`, `processed_at` | reviewer attribution |
| `rejection_reason` nullable | required when rejecting |

**Status semantics:**
- `pending` — submitted, awaiting reviewer action.
- `in_progress` — reviewer sourced it via a PO whose destination is NOT the requesting shop (e.g., PO → warehouse). The line stays open, awaiting the distributing transfer.
- `fulfilled` — a delivering document to the requesting shop exists and is linked: a stock transfer (the normal case), or a PO whose destination IS the requesting shop (direct-delivery tenants). Receiving/receipt status lives on the transfer/PO, never on the request.
- `rejected` — reviewer declined, with reason.
- `cancelled` — requester (own pending line) or reviewer withdrew it.

**Dedupe-by-design (repo-pattern indexes + atomic bump):** **two partial unique indexes** following the established variant-aware pattern (cf. `stock_transfer_lines` 2026_06_09 migration): one on `(company_id, location_id, product_id)` WHERE `variant_id IS NULL AND status IN ('pending','in_progress')`, one on `(company_id, location_id, product_id, variant_id)` WHERE `variant_id IS NOT NULL AND status IN ('pending','in_progress')`. A new request for the same open item **bumps** the existing line: adds `requested_qty` if both provided (else keeps whichever exists), appends the note, increments `request_count`, updates `last_requested_at`. The bump must be **atomic** (insert-or-bump upsert, with a unique-violation catch-and-retry fallback — PG `ON CONFLICT` inference against a partial index predicated on the mutable `status` column is finicky; the plan must treat this as a first-class task). Consequence (owner-confirmed): demand for the same product accumulated over several days lands in one line, and the twice-a-week transfer settles it in one action.

**Idempotency ledger (Rev 3):** companion table `replenishment_capture_receipts` (`tenant_id, company_id, client_request_uuid` unique, `request_id` FK, `applied_at`). EVERY applied POS capture — insert **or bump** — records its `client_request_uuid` here inside the same transaction. Replay lookup goes through the ledger first: a uuid already recorded returns the linked line unchanged (200, no re-bump). Without this, a bump whose HTTP response is lost in transit gets retried by the outbox and double-counts `requested_qty`/`request_count` (the natural key and the idempotency key diverge on the bump path — unlike the pending-customer template where they coincide). A uuid found under another company in the same tenant → 409 (permanent), mirroring the pending-customer alias conflict.

**Resurrection rule:** fulfilled/rejected/cancelled lines never resurrect — a new request opens a fresh line — with **one explicit exception**: when the fulfilling *transfer* is cancelled, the compensating listener re-opens the line (§5).

**Domain events:** `ReplenishmentRequested`, `ReplenishmentRequestBumped`, `ReplenishmentSourced` (PO linked), `ReplenishmentFulfilled`, `ReplenishmentReopened` (fulfilling transfer cancelled), `ReplenishmentRejected`. Events are immutable forever (rule 8).

## 4. Capture surfaces

**POS (offline-first, mirrors the pending-customer template):**
- "Request refill" button in the product detail drawer, adjacent to the cross-location stock section (`ProductDetailDrawer` / `CrossLocationStockSection`), plus on out-of-stock search results. One tap; optional qty + note in a small sheet.
- Local SQLite outbox table + repository + sync-push driver → `POST /api/v1/pos/replenishment-requests`, slotted into the `runFullSync` **push** phase (before pulls), gated by controller-level `Gate::authorize('pos.operate_terminal')`.
- **Location contract:** the capture payload carries `terminal_id`, **validated to belong to the authenticated company** (the auth token is not terminal-bound); the requesting `location_id` is **derived server-side from the terminal row** (`pos_terminals.location_id`) and never accepted directly from the payload (test: a payload `location_id` is ignored). This mirrors `PosStockLevelController`, not the pending-customer controller (which resolves no location).
- **Idempotency & dedupe HTTP contract:** `client_request_uuid` replay — checked against the **capture-receipts ledger**, so it covers both insert-path and bump-path retries — returns the already-linked row unchanged (200). A **natural-key dedupe collision (cross-device duplicate, fresh uuid) performs the bump, records the uuid in the ledger, and returns 200 with the bumped line — never 409**; a 409/4xx from a collision would land in the sync driver's transient-retry bucket and wedge the outbox forever. Only genuine contract violations (cross-tenant/cross-company) return 4xx, which the client marks failed (permanent) per the template's error taxonomy.
- **Outbox rules (rule 20):** exactly like the pending-customer outbox — JS-supplied ISO timestamps, **no `DEFAULT (datetime('now'))`**; any device-side timestamp comparison routes through `toSqliteUtc()`.
- "Already requested {date}" indicator: **a net-new pull feed** (open request lines for the terminal's location), same shape as the location-stock pull (`PosStockLevelController` / `pullLocationStock` precedent); budgeted as its own endpoint + pull step after the push phase.
- **No fiscal chain involvement.** This is a non-fiscal write path (verified: zero hash-chain side effects).

**Web:**
- Lightweight capture page: scan/search to add, requesting location defaults to the user's active location, instant submit per line. Must remain phone-usable (interim for the missing mobile app; precedent: counting's mobile-friendly endpoints).
- **Location authorization:** the picker is constrained to `LocationContext::getAllowedLocationIds()`, and the endpoint validates the submitted `location_id` via `LocationContext::validateLocationAccess()` (403/422 on failure) — same enforcement as `StockTransferController`. `company_id` comes from `CompanyContext`, and the location must belong to it.

## 5. Review queue (web)

**Navigation: lives under the Inventory section** (owner decision), e.g. `/inventory/replenishment`. (Future, out of scope: a "day-to-day operations" shortcut section grouping frequently used features.)

**Two pivots over open lines (`pending` + `in_progress`):**
1. **By shop** — all of one shop's needs together (the central-kitchen packing view).
2. **By product (matrix)** — one row per product, **one column per shop**, cells show requested qty (or a ✓ for quantity-less requests) — the aggregate-demand view: "3 shops need this; 80/70/50."

Each line/cell exposes decision context: requester's on-hand + min/max (`stock_levels`; served by `GET /products/{id}/stock-levels` incl. `min_quantity`/`max_quantity`/`is_below_minimum`), stock across all locations, `request_count`, notes, age.

**Actions (single line or multi-select):**
- **Transfer from…** — pick a source location (shown with its on-hand); **the reviewer confirms final quantities in the review UI** (prefilled from requested qty, else min/max suggestion) **before** the transfer is created: `StockTransferService::initiate()` flips Draft→InTransit and moves stock immediately — there is no post-initiate quantity edit. Selected lines are grouped into one transfer per (source → requesting shop) pair, with an idempotency key. Lines are settled by the auto-settlement listener (below), not by the button itself.
- **Add to PO for supplier…** — pick supplier + destination (defaults to the company's warehouse location); creates or appends a **draft** `purchase_order` Document (`partner_id` = supplier; multi-shop demand aggregates because `document_lines.location_id` is per-line). Linked lines get `sourcing_document_id` and move to `in_progress` — *not* fulfilled — unless the PO's destination is the requesting shop itself, in which case the line is `fulfilled` with `fulfillment_type = purchase_order`.
- **Reject** — with mandatory reason.

**Downstream authorization (no side-door):** `replenishment.process` authorizes the queue actions themselves, **not** the downstream documents. At the point of creation the controller additionally `Gate::authorize`s `inventory.transfers.create` (transfer) / `purchase-orders.create` (PO) — `StockTransferService::initiate()` has no internal permission check, so the Replenishment controller must not become a bypass around the Inventory/Document route gates.

**Auto-settlement (one mechanism for both queue-created and independent transfers):** the Replenishment module listens to Inventory's `StockTransferInitiated` event. The event is **header-only** (transfer id, company, source/destination) — per-line data comes from a new **`Shared/Contracts` `TransferLineReader`** (`linesForTransfer(transferId): [{product_id, variant_id, quantity}]`, mirroring `LocationStockReader`; implemented by Inventory). The listener matches open (`pending`/`in_progress`) request lines on **(company_id, destination location, product, variant)** and marks them `fulfilled` + linked. This means the client's routine twice-a-week warehouse shipment settles accumulated requests **even when the transfer is created outside the review queue**. v1 rule: any transfer of the product to the shop settles the open line regardless of quantity — the fulfiller owns quantities; a shop that still needs more simply requests again (new line).

**Compensating re-open:** a listener on `StockTransferCancelled` re-opens lines whose `fulfillment_id` is the cancelled transfer (status back to `pending`, fulfillment link cleared, cancellation noted, `ReplenishmentReopened` emitted). Without this, a cancelled shipment would leave the shop's demand signal dead (`initiate()` settles immediately; `cancel()` returns in-transit stock to source).

Cross-module boundaries: Replenishment talks to Inventory/Document only via events, public service classes, and `Shared/Contracts` (rules 6); stock reads via `LocationStockReader`, transfer-line reads via the new `TransferLineReader`.

## 6. Requester feedback

The requesting shop sees its own lines read-only (POS: fed by the new pull feed, **scoped to the terminal's location**; web: **scoped to `LocationContext::getAllowedLocationIds()`, fail-closed** — a location-restricted cashier sees only their shop's lines; unrestricted users, i.e. reviewers, see all locations by design): status, what fulfilled it, when. Edit-lock after submission (industry rule): requesters never mutate a submitted line; they can cancel their own `pending` lines only.

## 7. Permissions & module placement

- New un-gated `Replenishment` backend module (hexagonal), per-route permissions like Procurement — no `ModuleName` case needed. Standard route middleware `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]` + per-route `can:`; **the module's ServiceProvider must be registered in `bootstrap/providers.php`**. POS endpoint gating follows the POS pattern instead: controller-level `Gate::authorize('pos.operate_terminal')` (POS routes.php is one shared group without per-route `can:`).
- Permissions: `replenishment.view`, `replenishment.create` (web capture), `replenishment.process` (review-queue actions). **Role grants (explicit — silent-403 trap otherwise):** Administrator gets everything automatically (`syncPermissions(Permission::all())`); **Manager: view + create + process**; the web-capture staff role(s) used by the tenant (e.g. Operator, if present): view + create; **Cashier: nothing new** (POS path is terminal-gated by `pos.operate_terminal`, which cashiers already hold). Reviewers additionally need `inventory.transfers.create` / `purchase-orders.create` for the downstream actions (§5) — Manager holds both today.
- Seeded in **`RolesAndPermissionsSeeder` only** (`PermissionSeeder.php` exists but is not called during tenant provisioning — do not add there); deploy owes the usual `permission:cache-reset` per tenant (Spatie cache is tenant-blind).

## 8. Non-functional

- **Precision:** `requested_qty` `decimal(15,4)`, `QuantityScale`, `<QuantityInput>`, string payloads, FormRequest regex `/^\d+(\.\d{1,4})?$/`. No money fields on the request; Replenishment never does costing math (transfers created via `StockTransferService` handle their own scale).
- **Types:** DTOs `#[TypeScript]`; run `php artisan typescript:transform`.
- **i18n:** new `replenishment` namespace (EN/FR/AR), no hardcoded strings; FR uses "réassort".
- **POS SQLite:** outbox per §4 (JS-supplied ISO timestamps, no `datetime('now')` default); comparisons via `toSqliteUtc()` (rule 20).
- **No new queues:** sync is HTTP push/pull; no Horizon change.
- **Future-proofing notes:** open lines = planned inbound demand for a future min/max engine; `location_id`-keyed model is franchise-extensible (a franchise request would become a B2B order — different fulfillment path, same capture/review shape); if the French Rx vertical ever ships, the transfer path must be gateable per product class (rétrocession restriction) — parapharmacy unaffected.

## 9. Testing & verification (TDD)

- **Backend (PHPUnit, by path):**
  - Capture: `client_request_uuid` replay returns 200 + existing row; **cross-device natural-key collision performs bump and returns 200 (never 409)**; concurrent-bump atomicity (unique-violation fallback); qty-null merge semantics; **payload `location_id` ignored, terminal_id validated to company**; web capture rejects a location outside `allowed_location_ids` and a location not belonging to the active company; cross-tenant/company guards return permanent 4xx.
  - Review actions: transfer grouping per source→destination with reviewer-confirmed quantities; PO create/append + `in_progress` vs direct-delivery `fulfilled`; reject requires reason; **downstream `Gate::authorize` enforced (a user with only `replenishment.process` cannot create transfers/POs)**.
  - Settlement: listener matches on (company, destination, product, variant) via `TransferLineReader`; ignores closed lines and other companies; **`StockTransferCancelled` re-opens the linked line**; transfers with no matching requests are a no-op.
- **POS (Vitest):** outbox repository + sync-push driver (offline queue, permanent-vs-transient error taxonomy, retry), "already requested" pull feed integration, drawer action.
- **Web (Vitest):** matrix pivot rendering (by-shop / by-product with shop columns), multi-select action grouping, location-picker constraint.
- **E2E critical path before completion claim (rule 5):** POS request offline → sync → appears in review queue → reviewer confirms quantities → "Transfer from warehouse" → transfer initiated → line auto-fulfilled + linked → visible as fulfilled from the shop.

## 10. Future expansions (recorded, not v1)

- "Day-to-day operations" nav shortcut grouping (owner idea).
- Suggested quantities from min/max + sales velocity in the review queue; low-stock-driven pre-filled draft requests.
- Partial fulfillment tracking (settle by quantity rather than by line).
- Franchise/intercompany fulfillment (request → B2B order).
- Mobile app capture once the mobile app exists.
- Notifications (badge exists in v1 as a pending count; email/push later).
- Composite production orchestration for F&B (produce-to-stock pipeline feeding transfers).
