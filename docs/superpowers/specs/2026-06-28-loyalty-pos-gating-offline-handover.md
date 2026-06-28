# Handover — Loyalty in the POS (upgrade module, gating + offline-first)

> Status: **PLAN for review**. Pairs with the POS redesign (customer surfaces P5/P8, checkout P4) and **coordinates with the in-flight loyalty earn work** — do NOT rebuild the engine.
> Owner intent (2026-06-28): simplest loyalty = **points per money unit**, delivered as an **upgrade module**, gated in the POS, working **offline-first**.

## 0. TL;DR — most of the backend already exists

- **Engine COMPLETE:** `Loyalty` module has the full points engine; the **Spend rule IS "points per money unit"** (`PointEarningService::calculateSpendPoints` = `amount × reward_value`, bcmath at currency-scale+4). Programs, members (polymorphic contact/partner), enrollments, transactions, tiers, rewards, redemption — all built.
- **Gating COMPLETE:** `config/verticals.php` lists **Loyalty as a `compatible_extra` for parapharmacy** (and retail/fashion/coffee_shop) — not a default. Backend `RequireModule` middleware (`module:Loyalty`) on all loyalty routes (403 if not enabled). Web `hasModule('Loyalty')`. POS already fetches `GET /company/config` → `productStore.companyConfig.all_enabled_modules`.
- **Earn wiring DONE on a branch:** worktree `../erp.loyalty-earn` (`feat/loyalty-earn-per-product@28de62f90`) adds the missing fiscal-event earn hook in `PosCoreReceiptProjection`, `SaleEarningService` (module-guarded, member resolution, idempotent), default 1 TND = 1 pt Spend-rule seeding on program activation, and a POS **earn-rate preview API** — "ready to merge" (Codex-reviewed; see `project_loyalty_earn_demo_cutoff`).

**So the remaining work is POS-side: gating the UI, showing points + earn, and the offline mirror — NOT the engine.**

## 1. Offline-first architecture (decision)

The device authors the `SALE_RECEIPT` fiscal event; on sync the server's `PosCoreReceiptProjection` applies earning (idempotent via `loyalty_transactions.findBySourceDocument('pos_receipt', id)` + `pos_receipts.fiscal_event_id` UNIQUE). The device does **not** hold earn rules today.

- **Phase 1 — read-only balance mirror + static earn preview (RECOMMENDED, matches device-authored-fiscal pattern):**
  - **Earn accrues server-side on sync** (already idempotent). The device never double-counts; refunds/voids/training credit nothing (guards already in the branch).
  - **Display the member's points balance offline** as a **read-only mirror** on the customer: add `loyalty_balance` (+ `loyalty_tier`, `loyalty_balance_updated_at`) to the device `customers` table and `CustomerMirrorRow`; server includes it in `/pos/customers/sync`. Pulled on next sync (eventually-consistent — acceptable; show "à jour au …" if stale).
  - **Earn preview at checkout** = `floor(saleTotalTTC × rate)`, where `rate` comes from the active program's Spend rule, **synced to the device at login** (cache `{ rate, points_name }` from the earn-rate API into `companyConfig` or a small `loyalty_config`). Pure local arithmetic → works offline, no per-sale server call. Label it "points estimés" since the server is the source of truth.
- **Phase 2 (future, optional) — device-side accrual queue:** sync `earning_rules` to the device, queue `earning_events` like fiscal events, server reconciles on sync with the same idempotency guard. Only needed if we want authoritative real-time balances offline. **Defer.**

**Crucial:** the redeem/pay-with-points tender is OUT of demo scope (engine supports redemption, but offline redemption needs the balance to be authoritative → Phase 2). Demo = **earn + display only**.

## 2. POS gating (the upgrade-module surface)

The POS already knows enabled modules (`companyConfig.all_enabled_modules`). Add a tiny helper and gate every loyalty surface with it:
- `hasModule(config, 'Loyalty')` (mirror the existing `'Menu'` check in `syncService`). Add a `useHasModule('Loyalty')` selector over `productStore.companyConfig`.
- **Gate, both for render AND for sync/compute:**
  - Customer panel / detail: points balance + tier (only if enabled).
  - Checkout: "points estimés" earn line + post-sale earn confirmation.
  - Customer mirror sync of `loyalty_balance`: only request/store when enabled (avoid syncing loyalty for non-loyalty tenants).
- Case-sensitive `'Loyalty'`. When the module is OFF, the POS shows zero loyalty chrome and computes nothing — same discipline as backend `module:Loyalty`.

## 3. POS UI work (redesign phases)

- **P8 Customer detail / P5 customer panel:** points balance + tier badge (read-only mirror), recent earn from history (optional, online-only via `GET /loyalty/members/{id}`). Use `Badge`/`KpiCard` atoms, accent for the points highlight.
- **P4 Checkout / CheckoutSuccess:** "Points estimés: N" line (local `floor(total × rate)`) before payment; on success, confirm "N points gagnés" (estimate; reconciled server-side). i18n FR, tokens, both themes.
- **Add-customer:** enrollment is server-side; in the POS, attaching a customer with a loyalty member surfaces their balance. (Auto-enroll-on-first-sale is a backend policy question — see open Qs.)

## 4. Build order
1. **Merge `feat/loyalty-earn-per-product`** first (engine→POS earn hook, earn-rate API, default rule, gating) — it's the foundation; everything POS-UI builds on it. Verify its Codex review + tests, fast-forward per branch discipline.
2. POS `useHasModule('Loyalty')` helper + gate scaffolding.
3. Offline mirror: device `customers` loyalty columns + `CustomerMirrorRow` + `upsertCustomer` + server `/pos/customers/sync` payload (and **wire `pullCustomers` into `runFullSync`** — shared prerequisite with the parapharmacy handover §3).
4. Cache loyalty `{rate, points_name}` at login (from earn-rate API) for offline preview.
5. POS UI: customer points display + checkout earn preview/confirmation (redesign phases).
6. Tests: gating (off → no UI, no sync), offline preview math, replay idempotency already covered server-side.

## 5. Open questions for owner
- **Auto-enrollment:** on attaching/creating a customer at the POS, auto-create a loyalty member/enrollment, or only earn for already-enrolled members? (Demo likely wants auto-enroll so every sale shows points.)
- **Earn base:** receipt **total TTC** (the branch uses this) — confirm for Tunisia (vs HT). 
- **Stale-balance UX:** show a "last updated" timestamp on the offline balance, or hide staleness? (Recommend show.)
- **Redeem/pay-with-points:** confirm OUT for now (needs Phase-2 authoritative offline balance).
- Coordinate merge timing of `feat/loyalty-earn-per-product` with the redesign branch (rebase order on dev).

## 6. References
- In-flight: `project_loyalty_earn_demo_cutoff` memory; worktree `../erp.loyalty-earn` (`@28de62f90`); spec `docs/superpowers/specs/2026-06-27-loyalty-earn-per-product-design.md`.
- Gating SoT: `apps/api/config/verticals.php`, `docs/architecture/vertical-module-gating.md`, `RequireModule` middleware.
- POS config: `apps/pos/src/types/companyConfig.ts`, `productStore.companyConfig`, `syncService` module check.
- Engine: `app/Modules/Loyalty/Application/Services/PointEarningService.php` (Spend), `EarningProcessingService.php`, `app/Shared/Contracts/LoyaltyServiceInterface.php`.
