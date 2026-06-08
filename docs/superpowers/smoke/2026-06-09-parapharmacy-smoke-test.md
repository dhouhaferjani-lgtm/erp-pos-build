# Parapharmacy Multi-Branch — Manual Smoke Test

**Date:** 2026-06-09
**Author:** verify/parapharmacy-e2e session
**Goal:** A non-author teammate can follow this end-to-end tomorrow to exercise the parapharmacy launch surface (DB-per-tenant + multi-branch + per-branch tax IDs + variant SKUs + stock transfer + charge-to-account) and surface gaps.
**Mode under test:** `TENANCY_DB_PER_TENANT=true` (the live production tenancy model) on host Postgres.

> **Legend** — each flow step is tagged:
> - ✅ **Verified** — exercised end-to-end during the verification session (API/data layer or full stack). Re-confirm in the UI.
> - ⚠️ **Manual / terminal-only** — must be driven by a human; could not be auto-driven (see why).
> - ⛔ **Known gap** — skip; tracked in a ticket. Do not get blocked here.

---

## Setup

### 1. Backend (apps/api)

Host Postgres only — no Docker PG (it would bind-conflict on 5432). Recipe (from `project_db_per_tenant_e2e_validation`):

```bash
cd apps/api
cp .env.example .env
# Edit .env:
#   DB_HOST=127.0.0.1  DB_PORT=5432  DB_USERNAME=<your pg user>  DB_PASSWORD=
#   DB_CENTRAL_DATABASE=autoerp_central_parapharmacy_e2e
#   DB_DATABASE=autoerp_central_parapharmacy_e2e
#   DB_CONNECTION=central  TENANCY_DB_PER_TENANT=true
#   CACHE_STORE=array  QUEUE_CONNECTION=sync  SESSION_DRIVER=file
#   SCOUT_DRIVER=null  BROADCAST_CONNECTION=log
composer install
php artisan key:generate
createdb autoerp_central_parapharmacy_e2e
php artisan migrate --force            # central migrations only
php artisan db:seed --class=ParapharmacyMultiBranchSeeder --force
php artisan serve --port=8010
```

> ⚠️ **Re-seed caveat:** the seeder is **not** idempotent under DB-per-tenant (re-running it tries to delete the existing tenant, which 500s — see Known gap G1). To re-seed cleanly: `dropdb autoerp_central_parapharmacy_e2e && createdb autoerp_central_parapharmacy_e2e && php artisan migrate --force && php artisan db:seed --class=ParapharmacyMultiBranchSeeder --force`. (Orphaned `tenant<uuid>` databases from prior runs are harmless; drop them at leisure.)

The seeder prints a full credential/topology summary at the end — keep that terminal output.

### 2. Web admin (apps/web)

```bash
pnpm install            # from repo root (monorepo)
cd apps/web && pnpm dev # Vite; proxies /api → http://localhost:8010
```
Open the printed URL (**http://localhost:5173**, or :5174+ if another session holds 5173).

### 3. POS (apps/pos) — Tauri desktop app

```bash
cd apps/pos && pnpm tauri dev   # native desktop app
```
> ⚠️ The POS is a **Tauri desktop app**. Its sale / PIN-login / Z-report / offline-sync paths require the Tauri runtime and **cannot** be driven from a plain browser or headless Playwright (confirmed independently by the coffee-shop smoke session). All POS flows below are **manual on the Tauri app**.

### Fixture reference (what the seeder created)

**Tenant:** PharmaBio France · **Company:** PharmaBio France SAS · **Country:** FR · **Currency:** EUR

**Locations (one company, 3 branches):**
| Code | Name | Type | POS | Per-branch SIRET (`tax_id`) |
|---|---|---|---|---|
| WH-01 | PharmaBio Central Warehouse | warehouse | no | _(inherits company)_ |
| STORE-PAR | PharmaBio Paris | shop | yes | `12345678900015` |
| STORE-LYO | PharmaBio Lyon | shop | yes | `12345678900023` |

**POS terminals:** `POS01` @ Paris, `POS01` @ Lyon (physical).

**Logins** (password `password` for all; PIN for POS):
| Role | Email | PIN | Location scope |
|---|---|---|---|
| Owner (admin) | owner@pharmabio.fr | 1234 | all |
| Manager | manager@pharmabio.fr | 5678 | all (can transfer cross-branch) |
| Cashier | cashier@pharmabio.fr | 0000 | all |
| Paris cashier | paris.cashier@pharmabio.fr | 1111 | **Paris only** |
| Lyon cashier | lyon.cashier@pharmabio.fr | 2222 | **Lyon only** |

**Variant (sized-goods) products** — search SKU `PB-ORT-SHOE` (Chaussure Orthopédique Confort) or `PB-COMP-STOCK` (Bas de Contention Classe II). Each has one SKU per EU size: `…-38 / -39 / -40 / -41 / -42`; **size 40 is the default variant**. Stocked at the warehouse + both shops.

**House-account customer** (charge-to-account): **Clinique Saint-Louis (Compte Maison)** — `compte@clinique-saint-louis.fr`, account active, credit limit €5000.

**Catalog:** ~1000 parapharmacy products across 6 categories, ~90% stocked at the warehouse, ~60% at each shop.

---

## Flows

### Flow 1 — Login & branch context (web admin) — ✅ Verified

| # | Action | Expected | ✓ |
|---|---|---|---|
| 1.1 | Open `/login`, sign in as `owner@pharmabio.fr` / `password` | Lands on dashboard; no 500/503. (Login + authed `/auth/me` confirmed working under DB-per-tenant.) | ☐ |
| 1.2 | Open **Inventory → Stock** and the location selector | All 3 locations selectable (Warehouse, Paris, Lyon) | ☐ |
| 1.3 | Sign out; sign in as `paris.cashier@pharmabio.fr` / `password` | Sees Paris only; cannot view Lyon stock (location scope enforced) | ☐ |

### Flow 2 — Sale at Branch A (Paris) incl. a variant SKU → receipt — ⚠️ Manual (Tauri POS)

| # | Action | Expected | ✓ |
|---|---|---|---|
| 2.1 | Launch POS, set up terminal `POS01` at **Paris**, PIN-login as `1111` | POS home for Paris | ☐ |
| 2.2 | Add a normal product (scan/search) to cart | Line added, price + 5.5%/20% VAT correct | ☐ |
| 2.3 | Add the orthopedic shoe `PB-ORT-SHOE`; pick **size 40** in the variant picker | Variant `PB-ORT-SHOE-40` added (not the parent) | ☐ |
| 2.4 | Checkout → Cash → tender → confirm | Sale completes; receipt prints; **seller SIRET = `12345678900015`** (Paris) | ☐ |
| 2.5 | Verify Paris stock for the sold size decremented | Paris `stock_levels` row for the variant −1 | ☐ |

### Flow 3 — Return at Branch A (Paris) — ⚠️ Manual (Tauri POS)

| # | Action | Expected | ✓ |
|---|---|---|---|
| 3.1 | On Paris POS, scan the Flow 2 receipt number to start a return | Receipt hydrates; lines selectable | ☐ |
| 3.2 | Select a line (incl. the variant) and process the refund | Refund completes; refund receipt prints | ☐ |
| 3.3 | Verify Paris stock incremented back | Returned variant qty restored at Paris | ☐ |

### Flow 4 — Sale at Branch B (Lyon): branch-scoped stock + tax ID — ⚠️ Manual (Tauri POS) / ✅ tax+scope verified

| # | Action | Expected | ✓ |
|---|---|---|---|
| 4.1 | Launch POS, set up terminal `POS01` at **Lyon**, PIN-login as `2222` | POS home for Lyon | ☐ |
| 4.2 | Sell a product; complete cash sale | Receipt prints; **seller SIRET = `12345678900023`** (Lyon, distinct from Paris) — _per-branch tax IDs verified at API: `/locations` returns Paris `…0015`, Lyon `…0023`, warehouse inherits company_ | ☐ |
| 4.3 | In web admin, switch the stock location to Lyon vs Paris | Stock figures differ per branch (independent `stock_levels` rows) — _branch-scoped stock verified at data layer_ | ☐ |

### Flow 4b — Transfer a **variant** (sized) SKU between branches — ⛔ Known gap (G2)

Skip. T1 transfers are not variant-aware yet — see ticket `2026-06-09-stock-transfer-not-variant-aware.md`. Use a non-variant product for the transfer (Flow 5).

### Flow 5 — Branch transfer: Warehouse → Paris (web admin) — ✅ Verified (end-to-end)

Verified during the session via the T1 `stock-transfers` API under DB-per-tenant: created a transfer (status `in_transit`) → completed → **warehouse 553→503, Paris 0→50**. (This path was previously *impossible* under the flip; fixed in this branch — see Stabilization below.)

| # | Action | Expected | ✓ |
|---|---|---|---|
| 5.1 | As manager/owner, open **Inventory → Stock**, pick a **non-batch-tracked** product, choose "Transfer", source = Warehouse, dest = Paris, qty = 50 | Transfer created | ☐ |
| 5.2 | Complete/receive the transfer | Warehouse −50, Paris +50; movement shows under **Inventory → Movements** (`transfer_out` / `transfer_in`) | ☐ |
| 5.3 | (Optional) add a `transfer_cost` and confirm company-wide WAC recompute | `product.cost_price` updated via `recordCostAdjustment` | ☐ |

> Note: batch-tracked products require batch allocations on transfer (the API rejects them otherwise) — use a non-batch product, or supply `batch_allocations`.

### Flow 6 — Charge-to-account sale against the house account (spend side) — ⚠️ Manual (Tauri POS)

| # | Action | Expected | ✓ |
|---|---|---|---|
| 6.1 | On a shop POS, build a cart, then at checkout attach customer **Clinique Saint-Louis (Compte Maison)** | Customer attaches; account active, credit limit €5000 (chargeable — verified at data layer) | ☐ |
| 6.2 | Choose **Charge to account** as the payment method; confirm | Sale completes as an account charge; an `AccountChargeReceipt` / fiscal account-charge event is recorded | ☐ |
| 6.3 | Verify the charge is logged against the customer | `pos_account_charge_receipts` row for the customer; balance reflects the charge | ☐ |

### Flow 7 — Central/owner reporting aggregates both branches (web admin) — ⚠️ Manual (needs Flow 2/4 sales first)

| # | Action | Expected | ✓ |
|---|---|---|---|
| 7.1 | After sales at Paris (Flow 2) and Lyon (Flow 4), sign in as owner; open **POS → Analytics** | Sales summary reflects **both** branches' sales | ☐ |
| 7.2 | Filter/segment by location (if available) | Per-branch totals reconcile to the combined total | ☐ |
| 7.3 | Open **POS → Z-Reports** for each terminal | A Z-report per terminal/shift; figures match each branch's sales | ☐ |

> Reporting endpoints respond, but there is no sales data to aggregate until the POS sales (Flows 2/4) are run on a terminal. Run those first.

### Flow N — Customer-account **deposit** (top-up / money-in) — ⏳ Pending A8

Placeholder for the deposit top-up flow being built in parallel (A8). **Not part of this fixture's verification.** When A8 lands, add: top up the Clinique Saint-Louis account → confirm balance increase + `DEPOSIT_RECEIPT` event, then a charge (Flow 6) draws it down.

---

## Known gaps (skip these — tracked)

| ID | Gap | Impact | Ticket |
|---|---|---|---|
| **G1** | Seeder/tenant **deletion** 500s under DB-per-tenant (`TenantObserver` queries tenant-side `users` on the central connection). | Re-seed not idempotent; per-tenant deprovision (A4 ops) blocked; token-revocation invariant silently unmet on delete. | `tickets/2026-06-09-tenant-observer-deprovision-db-per-tenant.md` |
| **G2** | Stock **transfers are not variant-aware** (no `variant_id` on `stock_transfer_lines`). | Sized goods can't be transferred between branches at variant grain. | `tickets/2026-06-09-stock-transfer-not-variant-aware.md` |
| **G3** | POS (sale/return/PIN/Z/charge-to-account/sync) is **Tauri-only**; cannot be browser-driven. | All POS flows must be done manually on the desktop app. | _constraint, not a bug — documented here_ |
| **G4** | Customer-account **deposit top-up** flow. | Deferred to A8 (parallel session). | Flow N placeholder |

---

## Stabilization fixes shipped on this branch (`verify/parapharmacy-e2e`)

These were found and fixed during verification (gates green; see PR):

1. **T1 stock-transfer migrations made DB-per-tenant-correct.** `create_stock_transfers_table` + `create_stock_transfer_line_batch_allocations_table` were in the **central** migrations dir (FK to `companies`, a tenant table) → central migrate failed under the flip AND the tables never reached tenant DBs. Moved both to `database/migrations/tenant/` and changed `tenant_id` from a cross-DB FK (`->constrained('tenants')`) to a plain indexed uuid, matching every sibling tenant table. **T1 transfers now work under DB-per-tenant** (Flow 5 verified).
2. **`stock_levels` variant-uniqueness parity on SQLite.** The variant migration only swapped the legacy `(tenant,product,location)` unique for variant-aware partial indexes on pgsql; on SQLite the legacy unique survived, making multi-variant-per-location stock impossible (untestable on the CI driver). Mirrored the partial-index semantics on SQLite.
3. **Extended `ParapharmacyMultiBranchSeeder`** with per-branch tax IDs, sized-goods variant products, and an explicit house-account customer (+ 3 new tests; full class 9/9 green).
