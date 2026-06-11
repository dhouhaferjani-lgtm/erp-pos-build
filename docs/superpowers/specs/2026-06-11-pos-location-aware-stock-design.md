# POS Location-Aware Stock — Design Spec

**Date:** 2026-06-11
**Status:** Approved by owner (design review 2026-06-11); pending Codex adversarial review
**Branch:** `feat/pos-location-aware-stock-spec` (off `origin/dev` at `c2c92007b`, post-PR-#186/#187)
**Scope:** Tauri POS (`apps/pos`) + Laravel API (`apps/api`). Web admin untouched except where noted.

---

## 1. Problem

The POS terminal is location-bound (server: `Terminal.company_id` + `Terminal.location_id` + `scopeForLocation()`; client: location embedded in `apps/pos/src/stores/terminalStore.ts`), but everything it sells is company-grain:

- The catalog pull (`GET /products`, `ProductController::index` scoping by `company_id` only) carries **no stock data at all** — `ProductData` has zero stock fields. The local SQLite `products.stock_quantity` column receives `undefined` for retail tenants; the `999` value is hardcoded only in the Menu/F&B flatten path (`apps/pos/src/api/productApi.ts:123`).
- The POS client never calls a stock endpoint and never sends a location identifier (`apps/pos/src/lib/api.ts` sends `X-Company-Id` only).
- `ProductCard` (`apps/pos/src/components/molecules/ProductCard/ProductCard.tsx:32-52`) already has out-of-stock blocking UI (`stock_quantity <= 0` → not clickable, grayed) — dormant for retail because the data never arrives.
- Stock **is** location-capable server-side: `stock_levels` is keyed per (tenant, company, product, variant, location) with `quantity` + `reserved` (migrations `2025_11_30_110000`, `2026_06_02_100005`).

### Owner requirements (2026-06-11)

1. The POS must NOT allow selling stock the location doesn't carry — per-location availability enforced at the point of sale.
2. The terminal/branch must use ITS OWN fiscal data (location `tax_id` / VAT / legal identifiers) when it differs from the company's.
3. The POS still pulls the ENTIRE company catalog, including zero-stock items (intentional — enables initiating refill/replenishment from the POS later). Full catalog visible; availability/sellability location-scoped.
4. Stock awareness includes STOCK IN TRANSIT — incoming transfers toward this location must be visible.

### Owner decisions locked in design review (2026-06-11)

| Decision | Choice |
|---|---|
| Enforcement policy grain | **Company-level** `companies.pos_stock_policy` enum (`block` / `warn` / `off`), defaults derived from vertical (F&B/Menu → `off`; retail/parapharmacy/automotive → `block`) |
| Insufficient stock under `block` | **Hard block, no manager override** (override is additive later; G2/N1 PIN-audit machinery exists if wanted) |
| "Incoming" scope | **In-transit transfers + confirmed POs** (both terms, separately labeled) |
| Fiscal identity depth | **Complete the sourcing, keep the signed shape** — no event-version bump; display layers get full location identity |

---

## 2. Verified current state (investigation 2026-06-11, file:line anchors)

### 2.1 In-transit is implicit, not stored

- `stock_levels` has NO `in_transit` column. A transfer's `initiate()` immediately decrements the source via `StockAdjustmentService::issue()` and sets status `InTransit` (`StockTransferService.php:383-492`); `complete()` increments the destination (`:186-289`); `cancel()` restocks the source (`:298-376`). Statuses: `Draft → InTransit → Completed | Cancelled` (`TransferStatus.php`).
- The in-transit quantity toward a destination exists only as `stock_transfer_lines` rows whose parent transfer has `status = 'in_transit'` and `destination_location_id = X`. **No API exposes this per location**: `StockLevelData.incoming` defaults to `'0.00'` (`StockLevelData.php:30-35`), and `ProductController::stockLevels` (`ProductController.php:567-665`) computes incoming from **confirmed purchase orders only** (`DocumentLine` join, `quantity > COALESCE(quantity_received, 0)`, grouped by `location_id`).
- Transfer lines are variant-aware (`stock_transfer_lines.variant_id`, PR #182).

### 2.2 POS client stock path

- Local SQLite `products` table has a single `stock_quantity INTEGER NOT NULL DEFAULT 0` column (migration v1, `apps/pos/src/lib/db/migrations.ts:9-31`); no location dimension. `terminal_state.location_code` exists (migration v13) for receipt numbering only.
- `pullProductsCore()` (`apps/pos/src/lib/sync/syncService.ts:512-590`) pulls `/products` paginated 500/page with `updated_since` + tombstones; no location parameter.
- Sale completion: `createOfflineReceipt()` (`apps/pos/src/lib/offline/receiptService.ts`) — terminal-scoped, no stock validation, queues to `offline_receipts`, drains to the server fiscal-event sync.
- `CompanyConfig` (`apps/pos/src/types/companyConfig.ts`) delivers `all_enabled_modules` + `vertical` at boot via `GET /company/config` — the natural carrier for the policy flag.

### 2.3 Server-side sale decrement (already location-correct)

- `ReceiptCreationService::decrementStock` (`ReceiptCreationService.php:868-948`) — online draft path — decrements at **`$terminal->location_id`**, variant-aware, `lockForUpdate`, and **throws `RuntimeException`** on insufficient available (`:896-912`). Composite items deduct leaf components recursively (`deductCompositeItemStock`, `:1158-1209`).
- `PosCoreReceiptProjection::decrementStock` (`PosCoreReceiptProjection.php:907-978`) — fiscal-event path — same location source, but **warns and continues** (negative stock allowed) (`:935-946`). Correct: a signed fiscal event must always land.
- Known deferred issues (separate tickets, NOT in scope): draft-path + projection-path **dual-decrement** for variant sales (`PosCoreReceiptProjection.php:854-865`) and the WAC divisor-basis inconsistency.

### 2.4 Location fiscal identity (~70% done)

- `locations.tax_id` / `vat_number` / `legal_identifiers` exist (migration `2026_06_05_000000_add_tax_fields_to_locations.php`).
- `TaxIdentityResolver` (`apps/api/app/Modules/Company/Application/Services/TaxIdentityResolver.php:12-25`) resolves location-over-company (tax_id, vat_number, merged legal_identifiers, country) and feeds `ReceiptPdfService` (`:119,156`).
- `TerminalResource` embeds `location.tax_id/vat_number/legal_identifiers` (`TerminalResource.php:33-35`) — but **not the location address**.
- POS client: `branchTaxNumberFromTerminal()` (`apps/pos/src/stores/paymentStore.ts:477-484`) feeds the signed seller block for **SALE_RECEIPT** (`:586`) and **ACCOUNT_PAYMENT** (`:698`). Gaps:
  - **ACCOUNT_CHARGE** seller uses company tax_id only (`paymentStore.ts:788`).
  - Seller **address** always sources from company fields (all three paths).
  - Printed receipt header uses `receipt.company.tax_id` (`apps/pos/src/lib/buildReceiptData.ts:132`).
  - Z-report signed `seller` is `null` today (`apps/pos/src/lib/offline/zReportService.ts:632`).
- `SaleReceiptSellerInput` shape (`SaleReceiptPayload.ts:60-67`): `name, taxNumber, countryCode, street, city, postalCode` — **no vat_number/legal_identifiers** in the signed shape.

### 2.5 Enforcement configurability

- No `companies.settings` jsonb; precedent is dedicated policy columns (`pos_terminals.max_discount_percent` etc.). No `enforce_stock_at_pos`-like flag exists; the draft-path throw is unconditional today.

---

## 3. Approaches considered

**A. Dedicated location-stock sync layer — CHOSEN.** Catalog untouched (company-grain, incremental). A separate location-scoped stock feed (new POS endpoint + new local SQLite table) on its own faster cadence. Availability computed client-side as snapshot − unsynced local sales, so enforcement works offline.

**B. Embed stock in `/products` via `X-Location-Id` — rejected.** Stock changes don't bump `products.updated_at`, so incremental catalog sync would never refresh stock; freshness would force full catalog re-pulls (5K+ products). Couples catalog grain to location against the guardrail.

**C. Server-only enforcement — rejected standalone.** Offline-first POS: offline sales would bypass enforcement entirely, and the projection path correctly refuses to reject signed events. (Its server-side piece survives as the online backstop in A.)

---

## 4. Design

### 4.1 Server — location stock read API

New terminal-authenticated endpoint in the POS module:

```
GET /api/v1/pos/stock-levels?updated_since=<iso8601>&page=N
```

- **Location is resolved from the authenticated terminal** (`$terminal->location_id`), never from a client-supplied parameter — a device cannot read another branch's stock. Route follows Rule 12 middleware (`['api', 'auth:sanctum', SetPermissionsTeam::class]` + the POS terminal guard pattern used by sibling POS routes).
- Response rows, per `(product_id, variant_id)` at the terminal's location — all quantities as **decimal strings at scale 4** (precision contract; no floats anywhere):

```json
{
  "data": {
    "stock": [
      { "product_id": "…", "variant_id": null, "quantity": "12.0000",
        "reserved": "2.0000", "available": "10.0000", "updated_at": "…" }
    ],
    "incoming": [
      { "product_id": "…", "variant_id": null,
        "incoming_transfer": "6.0000", "incoming_po": "24.0000" }
    ],
    "as_of": "<server iso8601>"
  },
  "meta": { "pagination": { … } }
}
```

- **Delta semantics:** `updated_since` filters the `stock` array on `stock_levels.updated_at`. The `incoming` array is returned **complete on every pull** (never delta-filtered): incoming changes do not touch the destination's `stock_levels.updated_at` (transfer initiate only updates the SOURCE row), so a delta on stock rows would silently miss arrival/initiation of transfers. The incoming set is bounded small — only products with active in-transit transfers or open confirmed POs toward this location. The client replaces its incoming columns wholesale each pull (missing = zero).
- **Incoming computation:**
  - Transfers term: `SUM(stock_transfer_lines.quantity)` joined to `stock_transfers` where `destination_location_id = :location` AND `status = 'in_transit'`, grouped by `(product_id, variant_id)`.
  - PO term: the existing confirmed-PO computation from `ProductController::stockLevels:612-626`, additionally scoped to `document_lines.location_id = :location`, grouped by product. (PO lines are not variant-aware today; the PO term lands on `variant_id = null` rows. Documented asymmetry.)
- **Module boundary:** the POS controller depends on a new `App\Shared\Contracts\LocationStockReader` interface (constructor-injected), implemented in `Inventory/Application/Services` and bound in Inventory's service provider. No cross-module model imports. The DTO it returns is a Shared/POS-owned DTO, PHP-typed (no `mixed`), with `php artisan typescript:transform` regenerating shared types.
- Pagination 500/page mirroring the products pull.

### 4.2 Server — enforcement policy

- Migration (tenant): `companies.pos_stock_policy` `string` column, NOT NULL, with a `PosStockPolicy` PHP enum (`Block = 'block'`, `Warn = 'warn'`, `Off = 'off'`) — Rule: enums for all status/type columns.
- **Backfill in the same migration:** companies whose tenant vertical is F&B / has the Menu module → `off`; all others → `block`. New-company creation derives the same default from vertical.
- Exposed in the `/company/config` payload (`CompanyConfig.pos_stock_policy`) so the POS caches it at boot with the existing config fetch.
- `ReceiptCreationService::decrementStock` becomes policy-aware: `Block` keeps today's `RuntimeException`; `Warn` and `Off` log (`Log::warning`) and proceed with the decrement (negative allowed). The composite leaf deduction path (`deductCompositeItemStock`) respects the same policy.
- `PosCoreReceiptProjection` is **unchanged**: warn-and-continue unconditionally — signed fiscal events always land.
- Web admin: a settings control to change the policy is OUT of scope for v1 (the default-by-vertical covers launch); changing it is a DB-level operation until a follow-up adds UI.

### 4.3 Client — local schema + sync

- New SQLite migration (next version after current head in `apps/pos/src/lib/db/migrations.ts`):

```sql
CREATE TABLE location_stock (
  product_id TEXT NOT NULL,
  variant_id TEXT NOT NULL DEFAULT '',   -- '' = product-grain row (SQLite PK can't have NULL)
  quantity TEXT NOT NULL DEFAULT '0',
  reserved TEXT NOT NULL DEFAULT '0',
  available TEXT NOT NULL DEFAULT '0',
  incoming_transfer TEXT NOT NULL DEFAULT '0',
  incoming_po TEXT NOT NULL DEFAULT '0',
  updated_at TEXT,
  PRIMARY KEY (product_id, variant_id)
);
```

  Quantities stored as TEXT decimal strings (precision contract — the POS already carries money/qty as strings; no `parseFloat` on these columns, comparisons via the existing bc-style helpers).
- `pullLocationStock()` in `syncService.ts`: pulls the endpoint, upserts stock rows (delta), wholesale-replaces the two incoming columns from the `incoming` array (set-to-zero for rows absent from it), records `stock_last_sync` + the server `as_of` in `sync_metadata`.
- **Cadence:** terminal claim/boot → shift open → every periodic sync tick (alongside, not gated on, the catalog pull) → **immediately after each successful offline-receipt drain** (the server snapshot now includes our own sales; re-baselining right then collapses the local-pending adjustment to zero). Skipped entirely for Menu tenants (`hasModule(config, 'Menu')`).
- Failure handling mirrors `pullProductsCore`'s typed-error discipline (FetchTimeoutError propagates; 5xx/parse/network classified): a failed stock pull NEVER blocks selling — the POS keeps enforcing against the last snapshot (offline-first; positive-evidence-only downgrade philosophy applies to auth, not stock; stock staleness is surfaced, not fail-closed).

### 4.4 Client — availability computation + enforcement

Single source of truth selector (new module, e.g. `apps/pos/src/lib/stock/availability.ts`):

```
effectiveAvailable(productId, variantId) =
    serverAvailable(location_stock row; missing row ⇒ 0)
  − Σ quantity of (productId, variantId) lines in offline receipts not yet synced/acked
  − Σ quantity of (productId, variantId) lines already in the current cart
  clamped at ≥ 0
```

- Pending **refund/return** records are ignored (they only add stock back; ignoring is the conservative direction).
- Consumers: `ProductCard` (badge + the existing out-of-stock gating, now fed real data), barcode-scan add path, cart quantity increment, and any "+1" repeat-line path. Every ingress to the cart goes through one guard (lesson L9: canonicalize before state-machine input — enumerate ingress sites).
- Behavior by policy (from cached `CompanyConfig.pos_stock_policy`):
  - `block`: refuse add/increment beyond `effectiveAvailable`; toast with `t()` key explaining branch availability. **No override.**
  - `warn`: allow; toast + persistent line badge.
  - `off`: current behavior (no checks).
- **Exemptions even under `block`:** non-physical/service products (`is_physical = false` / service type) and Menu/composite sellables (client cannot resolve recipe leaves offline; server draft path enforces leaves when online). Exemption logic lives inside the selector so consumers can't diverge.
- Retail product tiles stop reading `products.stock_quantity` (dead column for retail; Menu keeps its 999 path untouched). Display joins `location_stock`.

### 4.5 Client — in-transit visibility

- Product tile/detail shows availability plus an "arriving" indicator: `10 (+6 ↗)` where the ↗ term is `incoming_transfer` (+ `incoming_po` distinguishable in the detail view: "from branch transfer" vs "on order" — `t()` keys, design tokens).
- Incoming is **display-only and never sellable** (locked sell-against-incoming decision: ATP visibility only; backorder flow remains fiscal-gated). This is the visibility seed for later POS-initiated replenishment; ordering is out of scope.

### 4.6 Fiscal identity completion (values change, shape doesn't)

- **ACCOUNT_CHARGE gap:** `paymentStore.ts:788` gets the same `branchTaxNumber ?? companyField(…)` sourcing as the SALE_RECEIPT/ACCOUNT_PAYMENT paths.
- **Seller address:** add the location's address fields (`address_street`, `address_city`, `address_postal_code`, `address_country`) to `TerminalResource` and the client `Location` type (`terminalStore.ts`); all three seller-block builders source address from the location **when present**, company fallback per-field. The signed seller SHAPE is unchanged (`name, taxNumber, countryCode, street, city, postalCode`) — values authored going forward differ; historical events untouched; **no event-version bump** (same class of change as the already-shipped branch tax_number sourcing).
- **Printed receipt header** (`buildReceiptData.ts:132`) and the printed **Z header**: switch from `company.tax_id` to the resolved location identity — tax_id, vat_number, legal_identifiers — sourced from the terminal store (display layers get the FULL identity; the signed payload keeps its narrower shape).
- **Z-report signed `seller` stays `null`** (current behavior). Populating it is a flagged follow-up requiring server-side canonical Z validation review — not this workstream.
- Server PDF path already correct via `TaxIdentityResolver`; no change.
- `vat_number`/`legal_identifiers` inside the signed seller block: deferred to the next event-version revision (V2 just shipped; a V3 for this alone is unjustified fiscal risk).

### 4.7 Accepted limitations (v1)

1. **Multi-terminal blind spot:** two terminals at one location each subtract only their own pending sales; between syncs they can jointly oversell. Mitigations: post-drain re-pull collapses the window; the online draft path (`block`) is the authoritative backstop; the projection path records reality (negative stock visible in back office). Documented, accepted.
2. **Stale snapshot while offline:** enforcement uses the last pulled snapshot; a `stock as of <time>` staleness hint appears in the existing sync-status surface. A stock pull failure never blocks selling.
3. **PO incoming is product-grain** (document_lines lack variant_id) — lands on the product-grain row; transfer incoming is variant-grain.
4. Dual-decrement + WAC divisor tickets remain open and are not worsened: this work adds NO new server-side decrement writers (read-only endpoint + policy gate on existing writers).

### 4.8 Testing strategy (TDD)

- **PHPUnit:** `LocationStockReader` contract (variant grain, transfers term excludes Draft/Completed/Cancelled, PO term scoped to location + unreceived remainder, delta on stock + always-complete incoming); endpoint auth (terminal-bound location, no cross-location reads, middleware pattern); `PosStockPolicy` enum + migration backfill by vertical; policy-aware draft enforcement (`block` throws / `warn`+`off` proceed; composite leaves; projection untouched). Valid UUIDs for FKs; PG-gated where partial indexes matter.
- **Vitest:** availability selector (missing row ⇒ 0; pending-receipt subtraction; cart subtraction; clamp; '' variant key normalization; exemptions); `pullLocationStock` (delta upsert; wholesale incoming replace incl. zeroing absent rows; typed errors; Menu skip); ProductCard states with real data; policy gating from companyConfig; ingress guards (tile, barcode, increment).
- **Tauri manual smoke** (browser cannot run the SQLite layer): sell-to-block flow, offline sell + drain + re-pull, in-transit badge after initiating a transfer toward the terminal's branch, ACCOUNT_CHARGE branch tax_id on the signed payload, printed header identity. Checklist ships with the implementation plan.

### 4.9 Rollout

Everything is additive: one new company column (with backfill), one new read endpoint, one new client table + sync path, policy gate on an existing throw. No destructive schema change, no event-version bump, no data migration beyond the enum backfill. Safe to ship behind the vertical defaults (F&B unaffected by construction).

---

## 5. Out of scope (explicit)

- POS-initiated replenishment/refill orders (this design only lays the visibility groundwork).
- Manager-PIN stock override (additive later; G2/N1 machinery exists).
- Back-office UI for changing `pos_stock_policy`.
- Backorder / sell-against-incoming sale flow (fiscal-gated, locked decision).
- Fixing the dual-decrement / WAC divisor tickets.
- `vat_number`/`legal_identifiers` in signed payloads; signed Z seller population.
- Per-location WAC/costing (company-grain is the locked correct model).
