# Demo Pharmacy Account — Design Spec

> **Date:** 2026-06-19
> **Branch:** `feat/demo-pharmacy-account` (off `dev`)
> **Status:** Design — awaiting owner review before implementation plan
> **Author context:** Prep for a possible parapharmacy-client demo. Build a permanent, reusable demo account on the **deployed dev** environment, run a live Tauri-POS demo against it end-to-end, and produce a concrete **reporting/feature gap list** that drives the "what to build next" decision.

---

## 1. Goal

Stand up enough realistic data and a working live-demo path so a parapharmacy client can be shown the full retail + POS + enrichment story end-to-end. The **primary deliverable beyond the demo itself is §8 — a gap report** telling us what additional development (especially reporting) is needed before this is client-ready.

The account is **kept as a permanent demo account**; data grows naturally as sales are rung.

## 2. Decisions locked (from brainstorming)

| # | Decision | Choice |
|---|---|---|
| D1 | Demo surface / backend | **Deployed dev (Dokploy/AX42).** Web-admin viewed remotely; Tauri POS on a laptop points at the remote API. |
| D2 | Seeder strategy | **Reusable** `DemoPharmacySeeder` extending the existing `ParapharmacyMultiBranchSeeder`; additive/idempotent. |
| D3 | Sales history | **No fiscal-event-authoring seeder.** Seed everything a cashier needs, then **hand-ring ~20–30 real sales** through the live POS. History grows organically thereafter. |
| D4 | Enrichment | **GO (real, not stubbed).** Platform is deployed with ~13k products; connection mostly works. Owner is fixing image/barcode issues on the platform side. |
| D5 | Platform data cleaning | **Parallel Track B**, executed from the **Synerivia repo** (not this repo). |

## 3. Scope: two tracks

- **Track A — ERP side (THIS repo, `apps/erp`).** Seeder, POS build/deploy config, demo runbook, gap report. This spec's main body.
- **Track B — Synerivia platform side (OTHER repo, `/Users/houssamr/Projects/syneriva`).** Clean ~13k product catalog data (images, barcodes) so enrichment lookups return clean results. Owned by a Synerivia-repo session; captured here only as a dependency + handoff (§6.4, §11).

> Repo boundary: per project rules, ERP is not modified from the Synerivia context and vice versa. Track B work must run from the Synerivia repo.

## 4. Target environment & hard constraints

- **Likely target = the IziPOS staging deploy at `erp.otospex.dev`** (db-per-tenant from `dev`, brought live 2026-06-19 by the parallel session; new PG `erp-staging-postgres-8x7pbx:5434`, central DB `iziposcentral`, **`AUTO_SEED=false`**). **To confirm with owner.** Because `AUTO_SEED=false`, `DemoPharmacySeeder` is **run manually** against the staging container (`php artisan db:seed --class=DemoPharmacySeeder --force`).
- **Tauri POS bakes its backend URL at build time** (`VITE_API_URL`, read in `apps/pos/src/stores/authStore.ts:134`). There is **no runtime server-picker**. Demoing against deployed dev requires **rebuilding/running the POS pointed at the deployed URL**.
- Backend must allow the POS origin via **CORS**; HTTPS cert must be valid (Dokploy Let's Encrypt is fine); `VITE_REVERB_HOST`/`VITE_REVERB_PORT`/`VITE_REVERB_SCHEME` must point at the deployed WebSocket (Reverb) for broadcast (terminal activation, etc.).
- POS↔backend auth is **Sanctum Bearer + `X-Company-Id` header**; all sync endpoints are relative to `/api/v1` (no localhost assumptions beyond the build-time fallback). So once the base URL is correct, claim/auth/sync work remotely unchanged.
- **DB-per-tenant**: tenant deletion currently **500s** (gap G1, `TenantObserver` queries the wrong connection). The seeder must therefore be **additive/idempotent** or reprovisioned via a manual DB drop (see §9).
- **POS is Tauri-only** for sales/refunds/Z-reports — the browser cannot author sales. Web-admin is read-only for POS data.

## 5. Component design (Track A)

### 5.1 `DemoPharmacySeeder` — topology (warehouse + 3 shops)

Extend `ParapharmacyMultiBranchSeeder` (which already builds 1 tenant + 1 company + warehouse `WH-01` + Paris + Lyon, ~1000 products, variant SKUs, 180 partners, house account, 2 terminals, location-scoped cashiers).

**Add a 3rd shop:**
- New `Location` (type `shop`, `pos_enabled=true`), code `STORE-MAR` (Marseille), with its own SIRET in `tax_id` + `legal_identifiers` (siren/siret/nic), matching the Paris/Lyon pattern.
- One `POS01` terminal at the new shop (terminal code is unique per location; CHECK constraint `^POS[0-9]{2}$`).
- Stock distribution to the new shop (~60% of catalog, small front-of-house quantities), matching existing shops.
- A location-scoped cashier (`marseille.cashier@pharmabio.fr`, PIN, `allowed_location_ids=[marseilleShop.id]`).
- Owner/manager remain cross-branch (`allowed_location_ids=NULL`).

**B2C framing:** keep the 150 individual customers as the headline; trim or de-emphasize the 20 B2B (retain the clinic house account + maybe 1–2 B2B for the account-charge demo).

### 5.2 Customers, suppliers, accounts & balances

- Reuse the inherited 150 B2C customers + ~10 suppliers + house account.
- **Seed GL-consistent balances** (NOT raw columns — `partners.receivable_balance`/`credit_balance` are cached projections of the GL):
  - A few B2C customers with an **outstanding receivable** (invoice JE debit to `CustomerReceivable`, partial payment credit).
  - A few customers with **store credit** (`CustomerAdvance`).
  - The clinic house account with both a credit limit (already €5000) **and** a real balance.
  - Optionally 1–2 suppliers with a **payable balance** (`SupplierPayable`).
- Pattern: create `JournalEntry` + `JournalLine`s against the system-purpose accounts, then call `PartnerBalanceService::refreshPartnerBalance($companyId, $partnerId)` to populate the cached columns. **Template:** `CoffeeShopSeeder.php:985–1116`.
- Result: partner statements and balance views reconcile against the GL (no faked numbers).

### 5.3 Purchase orders

POs are `Document` rows (`type=purchase_order`; `DocumentStatus`: `draft → confirmed → received`; partial receipt stays `confirmed` with per-line `quantity_received`).

Seed a realistic pipeline from suppliers → **warehouse**:
- 1 **draft** PO (editable).
- 1 **confirmed** PO awaiting receipt.
- 1 **partially received** PO (confirmed + some lines received → warehouse stock incremented for those lines).
- 1 **fully received** PO (`received`).

Entry points:
- Create: `Document::factory()->purchaseOrder()->create([...])` + `document_lines`.
- Confirm: `PurchaseOrderService::confirm($po)` (allocates landed costs, fires `PurchaseOrderConfirmed`).
- Receive: `GoodsReceiptService::receiveGoods($po, [lineId => qty])` or `receiveAll($po)` (increments stock + updates WAC; variant-aware).

### 5.4 Stock movement between locations

Stock transfers are **variant-aware** (confirmed: `stock_transfer_lines.variant_id` + service threads it through initiate/complete/cancel, batch-scoped). Seed:
- 1–2 transfers **warehouse → shop**, **completed** (stock visibly moved; can include a sized-goods variant SKU).
- 1 transfer **in transit** (so the "incoming stock" badge shows at the destination shop).

Entry point: `StockTransferService` (`initiate` → `moveSourceToInTransit` → `complete`).

### 5.5 Sales history — operational, not seeded (per D3)

No code. The seeder guarantees the **preconditions for manual sales**:
- Products are priced and in stock at each shop's location.
- Terminals are claimable; cashier PINs exist.
- Customers exist for customer-attached / account-charge sales.

Then, as a setup step, **hand-ring ~20–30 sales** through the live Tauri POS across the shops, with a realistic mix:
- simple cash sales, card sales, **split payments**;
- a couple of **account-charge** sales (house account / B2C with credit);
- a couple of **refunds / partial refunds / return notes**;
- close a shift with a **Z-report** on at least one terminal.

This yields genuine fiscally-valid history that populates shift-history, Z-reports, and the analytics dashboard, and continues to grow during/after the demo.

### 5.6 Enrichment integration (per D4)

ERP side is implemented (`PlatformIntegration` + `Product` modules): barcode lookup, submit, upload-url, status, webhook receiver, and review/accept/reject endpoints + UI (UI currently on `feat/parapharmacy-enrichment-erp` — **must confirm whether it is on `dev`; if not, merge/cherry-pick** — see §11). Platform side is deployed with ~13k products.

Demo path: open a product (or scan a barcode) → **lookup** against the deployed platform → **submit** for enrichment → enrichment result lands in the **review queue** → **accept** selected fields → product is enriched (name/brand/description/ingredients/images merged).

Prerequisites: the demo tenant has a valid platform **`X-API-Key`**; the platform's barcode/lookup/submit endpoints + webhook are reachable from the deployed ERP; clean-enough catalog data (Track B).

## 6. POS deployment & build (Track A prerequisite for the live demo)

1. Determine the deployed-dev API base URL + Reverb host.
2. Build/run the POS pointed at it:
   - Quick path: on the laptop, `cd apps/pos && VITE_API_URL=https://<deployed> VITE_REVERB_HOST=<host> pnpm tauri dev`.
   - Packaged path: `VITE_API_URL=https://<deployed> … pnpm build && pnpm tauri build` → install the binary.
3. Confirm backend **CORS** allows the POS origin and HTTPS cert is valid.
4. **Claim the 3 terminals** against deployed dev: login (owner/manager) → Terminal Setup → claim the available `POS01` at each shop (or request+activate).
5. Verify a **sync round-trip** (stock pull, a test receipt push, PIN sync) before the demo.

## 7. Live demo runbook (high level)

On the Tauri POS (desktop): open shift → **simple sale** → **split-payment sale** → **refund / partial refund / return note** → (optional) **account-charge sale** → run **X-report** → close shift with **Z-report**. Then in **web-admin** (remote): show shift history, the Z-report (+ **hash-chain verify**), the **analytics dashboard** (sales/products/cashiers/discounts/customers), partner balances, the PO pipeline, and a stock transfer. Finally, the **enrichment** flow (§5.6). Detailed click-steps produced with the implementation plan.

## 8. Reporting / feature gap report — PRIMARY DELIVERABLE

A report-only artifact (`docs/superpowers/audits/2026-06-…-demo-pharmacy-gap-report.md`) capturing what's missing/weak for a client, to drive the build-next decision. Seeded with **known gaps already found**, to be confirmed/extended during the dry run:

- Web-admin **`/pos/transactions` is disabled** — no transaction/receipt list for the owner in web-admin (only via Z-report detail / shift receipts). Likely the biggest reporting gap.
- **X-report** `expected_cash` does not subtract refunds (display gap, G6).
- Per-terminal **Z-report excludes cross-terminal refunds** (by design, G8) — confirm acceptable for client.
- Web-admin **returns surface quarantined** (G9); refunds desktop-only.
- **Typed refund-exception mapping** missing (Phase H) — manager-override modal doesn't trigger correctly for override-required refunds.
- **Cross-terminal refunds** require a receipts mirror (Phase 1 backlog, ~1–2 days).
- **Customer deposit top-up** flow pending (A8/G4).
- **Pre-existing product-form 422** on staging (tax_rate 4dp + `parapharmacy_metadata` on retail vertical) blocks web product-create — verify it does NOT bite the parapharmacy demo tenant (the seeder bypasses the FormRequest, so seeded products are unaffected; only manual web product-create during the demo would hit it).
- Anything the client asks for that we can't show.

## 9. Reusability & reprovision (G1 workaround)

- `DemoPharmacySeeder` is **additive/idempotent**: guard each section (e.g., skip if the tenant/3rd-shop/POs already exist) so re-running is safe despite G1.
- Clean reprovision fallback (when a from-scratch reset is wanted): `tenant:deprovision <slug> --force`, or manual `dropdb tenant_<uuid> && createdb … && php artisan migrate --force && php artisan db:seed --class=DemoPharmacySeeder`.
- Document the exact tenant slug + DB name + credentials in the implementation plan.

## 10. Tiering & sequencing

- **Tier A (must-have for a live demo):** §5.1 topology (3rd shop), §5.2 balances, §6 POS build/deploy + terminal claim, §7 runbook, §8 gap report.
- **Tier B (high value):** §5.3 POs, §5.4 transfers, §5.5 manual sales history.
- **Tier C (parameterized):** §5.6 enrichment + Track B platform cleaning.

Execution begins **after the deploy completes** (owner gate).

## 11. Open items / dependencies

- **Deploy must finish** (parallel session) before §6/§7.
- **Enrichment UI branch status:** confirm whether `feat/parapharmacy-enrichment-erp` is merged to `dev`; if not, decide merge vs cherry-pick. (Investigation reported it as implemented but possibly not on `dev`.)
- **Platform `X-API-Key`** provisioned for the demo tenant.
- **Track B** (Synerivia repo): image/barcode cleanup on the ~13k catalog — owner is fixing some; remainder via a Synerivia-repo session. Handoff prompt to be produced.
- Confirm the exact deployed-dev API + Reverb URLs and that CORS is configured for the POS origin.

## 12. Out of scope / YAGNI

- Fiscal-event-authoring history seeder (explicitly dropped, D3).
- Building new reports — this cycle **identifies** gaps; building is a follow-up decision.
- Tenant-deletion (G1) fix — worked around, not fixed here.
- Any modification of ERP from the Synerivia context, or vice versa.
