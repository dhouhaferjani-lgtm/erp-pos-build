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

### Flow 1 — Login & branch context (web admin) — ✅ Verified in-browser (Playwright)

Walked through during verification: owner login → dashboard renders (181 partners = 150+20+10+1, **Owner Dashboard "Sales, stock, payments, and cash controls across locations"** present = T5 cross-branch reporting), and **Inventory → Stock Levels** renders (warehouse 922 products, location selector, per-row "Transfer Stock", "View Movements").

| # | Action | Expected | ✓ |
|---|---|---|---|
| 1.1 | Open `/login`, sign in as `owner@pharmabio.fr` / `password` | Lands on dashboard; no 500/503; partner count 181; owner cross-location dashboard visible | ☐ |
| 1.2 | Open **Inventory → Stock Levels**, use the location selector | All 3 locations selectable (Warehouse, Paris, Lyon); counts differ per branch | ☐ |
| 1.3 | Open a product (e.g. search `PB-ORT-SHOE`) → product detail | Detail renders (was crashing for ALL products pre-fix — see Stabilization #4) | ☐ |
| 1.4 | Sign out; sign in as `paris.cashier@pharmabio.fr` / `password` | Sees Paris only; cannot view Lyon stock (location scope enforced) | ☐ |

### Flow 2 — Sale at Branch A (Paris) incl. a variant SKU → receipt — ⚠️ Manual (Tauri POS)

| # | Action | Expected | ✓ |
|---|---|---|---|
| 2.1 | Launch POS, set up terminal `POS01` at **Paris**, PIN-login as `1111` | POS home for Paris | ☐ |
| 2.2 | Add a normal product (scan/search) to cart | Line added, price + 5.5%/20% VAT correct | ☐ |
| 2.3 | Add the orthopedic shoe `PB-ORT-SHOE`; pick **size 40** in the variant picker | Variant `PB-ORT-SHOE-40` added (not the parent) | ☐ |
| 2.4 | Checkout → Cash → tender → confirm | Sale completes; receipt prints; **seller SIRET = `12345678900015`** (Paris) | ☐ |
| 2.5 | Verify Paris stock for the sold size decremented | Paris `stock_levels` row for the variant −1 | ☐ |

### Flow 3 — Full refund flow at Branch A (Paris) — ⚠️ Manual (Tauri POS)

> All sub-flows below require the Tauri desktop app. The POS offline layer (downgrade to weak factor, anti-downgrade audit) is Tauri-only and cannot be exercised in a plain browser.

**Pre-requisite:** complete Flow 2 first so a synced sale receipt exists for this terminal.

#### 3A — Locate the receipt and hydrate the return cart

| # | Action | Expected | ✓ |
|---|---|---|---|
| 3A.1 | Open **ReceiptLocatorScreen**; enter the receipt number from Flow 2.4 | Receipt found; negative return lines hydrate the cart — each line shows the product name; variant lines show the variant name (e.g. `Chaussure Orthopédique Confort — Taille 40`) | ☐ |
| 3A.2 | Alternatively, scan the receipt QR code | Same hydration result via QR path | ☐ |
| 3A.3 | Inspect the hydrated cart | All lines are negative qty; totals are negative (return amounts) | ☐ |

#### 3B — Partial refund (edit return line qty)

| # | Action | Expected | ✓ |
|---|---|---|---|
| 3B.1 | On a line with qty ≥ 2 (e.g. sell 2 of the same product in Flow 2), edit the return qty down to **1** | Qty field accepts `1`; line total updates in real-time; scale-4 qty preserved (e.g. `1.0000`) | ☐ |
| 3B.2 | Press **Pay** to proceed to checkout | Checkout uses the edited qty — only the 1-unit refund is submitted, not the full 2 | ☐ |

#### 3C — Refund checkout: spinner → destination picker → confirm modal → manager PIN

| # | Action | Expected | ✓ |
|---|---|---|---|
| 3C.1 | Press **Pay** on the all-return cart | **Preparing…** overlay/spinner appears briefly while the return is staged | ☐ |
| 3C.2 | Spinner clears → **destination picker** appears | Three options shown: **Cash**, **Store voucher**, **Original payment method** | ☐ |
| 3C.3 | Select **Cash** | Picker dismisses; proceed to confirm modal | ☐ |
| 3C.4 | Inspect the **confirm modal** | Totals are frozen (cannot be edited); chosen destination ("Cash") is displayed alongside the refund amount | ☐ |
| 3C.5 | Confirm → **manager PIN** approval screen appears | Reason field + PIN pad shown; fields are editable | ☐ |
| 3C.6 | Enter reason (e.g. "Produit retourné intact") and manager PIN `5678` | After authorization is cached, the reason + PIN inputs **freeze** (cannot be re-entered) | ☐ |
| 3C.7 | Submit the refund | Refund completes; success toast shows ticket number; refund cart clears | ☐ |

#### 3D — AVOIR print and voucher print (Tauri + receipt printer required)

| # | Action | Expected | ✓ |
|---|---|---|---|
| 3D.1 | After refund settlement, observe the printer | **AVOIR** receipt prints automatically — header reads `REMBOURSEMENT`; original ticket reference is printed on the AVOIR | ☐ |
| 3D.2 | Check AVOIR monetary values | Amounts shown with **3 decimal places** (TND 3-decimal format: e.g. `12.500`) — note: fixture is EUR; verify the scale matches configured currency | ☐ |
| 3D.3 | Repeat 3C for a **Store voucher** destination | After the AVOIR prints, a **second ticket** prints automatically — the voucher ticket shows code, amount, and expiry date | ☐ |
| 3D.4 | On a subsequent sale (new cart), add the voucher code at payment | Voucher accepted; amount deducted from the total | ☐ |

> **Tauri-manual:** printing requires a connected receipt printer in the Tauri environment. If the printer is absent, the AVOIR print failure shows a toast with the ticket number — use the ticket number to retrieve a server PDF reprint (see Known Limitations below).

#### 3E — Stock: correct variant row restored; batch restitution

| # | Action | Expected | ✓ |
|---|---|---|---|
| 3E.1 | After the refund in 3C (variant `PB-ORT-SHOE-40`), check `stock_levels` for Paris | **Only the size-40 row** is incremented — size 38/39/41/42 rows are unchanged; the refunded variant A restores only variant A stock | ☐ |
| 3E.2 | (Optional) Use a batch-tracked product in a sale + return | On return, batch quantities are proportionally restituted via FEFO; the correct batch row is updated | ☐ |

#### 3F — Drawer and Z-report accounting

| # | Action | Expected | ✓ |
|---|---|---|---|
| 3F.1 | Cash-destination refund completed (3C with **Cash**); open cash drawer summary | `expected_cash` for the shift is **reduced** by the cash refund amount | ☐ |
| 3F.2 | Close the shift (Z-report) after a refund day | Z-report shows **non-zero `refunds_count` and `refunds_amount`**; these figures are part of the **signed Z** (sealed in the v3 hash chain) | ☐ |
| 3F.3 | Check the X report (server-side, pre-close preview) | `endOfDayPreview` expected cash does **not** yet subtract cash refunds — this is a known gap (B1 session handover); do not fail here | ☐ |

#### 3G — Fiscal chain integrity (server-side verification)

| # | Action | Expected | ✓ |
|---|---|---|---|
| 3G.1 | After the return, run `php artisan pos:verify-chains` for this terminal | Passes; the `pos_receipts` return row is sealed in the v3 chain with no gaps | ☐ |
| 3G.2 | Run `php artisan fiscal:verify-event-chain` for the terminal | Passes; no hash-chain errors for the return event | ☐ |

#### 3H — Idempotency and anti-double-refund

| # | Action | Expected | ✓ |
|---|---|---|---|
| 3H.1 | Submit the same refund request twice (simulate a retry / double-tap) | The **same ticket number** is returned — the refund is **not applied twice** (idempotent) | ☐ |
| 3H.2 | Simulate a network timeout on first submit, then retry | Retry returns the already-created refund receipt; stock and cash are not doubled | ☐ |

#### 3I — Online-only enforcement and error messages

| # | Action | Expected | ✓ |
|---|---|---|---|
| 3I.1 | Disconnect the device from the network (genuine offline); attempt a refund | Error: **"refunds require a connection"** — refund is blocked; no offline downgrade to weak factor | ☐ |
| 3I.2 | Keep network connected but simulate server 5xx (e.g. stop the API); attempt a refund | After bounded retries: **"server error, try again"** toast — does NOT show "offline" message | ☐ |
| 3I.3 | Attempt to refund a receipt that was created on a different terminal (cross-terminal) | Error: **"process at the original terminal or sync first"** — cross-terminal refund blocked | ☐ |
| 3I.4 | Attempt to refund a receipt that has not yet synced to the server | Same "process at the original terminal or sync first" error | ☐ |

> **Tauri-manual (3I.1):** genuine offline detection relies on Tauri's network layer — cannot be triggered from a browser. Steps 3I.2–3I.4 can be tested by pointing the API base URL to a non-responding host.

#### 3J — Mixed cart and charge-to-account blocking

| # | Action | Expected | ✓ |
|---|---|---|---|
| 3J.1 | Build a cart with **both** a return line and a new sale line (mixed cart) | **Pay is blocked** — a toast appears: "complete the return first" (or equivalent); no payment screen reached | ☐ |
| 3J.2 | Attempt to exit via **charge-to-account** on a return cart | Pay is also blocked on the charge-to-account exit path | ☐ |

#### 3K — Voiding a RETURN receipt (API-level guard)

| # | Action | Expected | ✓ |
|---|---|---|---|
| 3K.1 | Attempt `POST /api/pos/receipts/{return_receipt_uuid}/void` (via curl or web-admin API) | **HTTP 422** with error code `CANNOT_VOID_RETURN_RECEIPT` — return receipts cannot be voided | ☐ |

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
| **G5** | Minor i18n: a few raw keys render — browser tab title `stockLevels.title`, Stock Levels table header `actions.actions`, and the **error-boundary** shows `errors.unexpectedError` / `…Details` instead of a friendly message. | Cosmetic; error-boundary copy worth fixing for graceful degradation. | _logged here; not yet ticketed_ |
| **G6** | `endOfDayPreview` **expected_cash does not subtract cash refunds** (X-report pre-close preview). The signed Z is correct — this is a data-display gap only (B1 session handover). | Cashier sees a discrepancy between the X preview and the actual drawer at close; the sealed Z is the authoritative figure. | _B1 handover — not yet ticketed_ |
| **G7** | **No reprint surface** for a settled refund. If the AVOIR print fails at settlement time, a toast shows the ticket number. Reprinting requires retrieving a server-side PDF — no in-POS reprint button yet. | Operator must reprint from the server/web-admin. | _logged here; not yet ticketed_ |
| **G8** | **Refunds at other terminals do not appear in this device's Z.** Each device Z covers only its own settled transactions; global refund reconciliation is server-owned. | Per-terminal Z figures will diverge from the server-side global figure on refund days; expected and correct. | _by design — documented here_ |
| **G9** | **Web-admin return surface removed.** The web-admin POS return/refund UI was quarantined in Phase 6 (desktop-only refund policy). A placeholder page exists; server endpoints remain active. | Returns must be processed from the Tauri desktop POS. | _by design — documented here_ |

---

## Stabilization fixes shipped on this branch (`verify/parapharmacy-e2e`)

These were found and fixed during verification (gates green; see PR):

1. **T1 stock-transfer migrations made DB-per-tenant-correct.** `create_stock_transfers_table` + `create_stock_transfer_line_batch_allocations_table` were in the **central** migrations dir (FK to `companies`, a tenant table) → central migrate failed under the flip AND the tables never reached tenant DBs. Moved both to `database/migrations/tenant/` and changed `tenant_id` from a cross-DB FK (`->constrained('tenants')`) to a plain indexed uuid, matching every sibling tenant table. **T1 transfers now work under DB-per-tenant** (Flow 5 verified).
2. **`stock_levels` variant-uniqueness parity on SQLite.** The variant migration only swapped the legacy `(tenant,product,location)` unique for variant-aware partial indexes on pgsql; on SQLite the legacy unique survived, making multi-variant-per-location stock impossible (untestable on the CI driver). Mirrored the partial-index semantics on SQLite.
3. **Extended `ParapharmacyMultiBranchSeeder`** with per-branch tax IDs, sized-goods variant products, and an explicit house-account customer (+ 3 new tests; full class 9/9 green).
4. **Product detail page crashed for EVERY product** (`ProductDetailPage.tsx`): the `useTaxConfigName` hook was called after the loading/error early returns → "Rendered more hooks than during the previous render" → whole view fell to the error boundary. Moved the hook above the guards. Verified in-browser: the page renders. Also guarded the margin `(Infinity%)` shown when cost is 0.
5. **Hardened `react-hooks/rules-of-hooks` to `error`** (web + pos). It had caught #4 but only as a *warning*, so the ratchet let a guaranteed crash ship. Repo is clean (0 violations), so CI now blocks the class with no blast radius.

## Refund-flow fixes shipped on this branch (`feat/refund-flow-completion`)

These fixes complete the refund path that was previously dead-ended at payment (Task 53 was never wired before this branch):

6. **Task 53 settlement wiring.** `RefundConfirmModal` and `DestinationPicker` existed but were imported nowhere — the refund path dead-ended after the manager PIN screen. Wired the full checkout interceptor: preparing spinner → destination picker → confirm modal (frozen totals) → manager PIN (reason + PIN; inputs freeze post-authorization) → settlement.
7. **Refund checkout store hardening.** Epoch guard prevents stale-cart submission; author/sync split ensures the settlement payload carries the correct identities; fail-closed guard rejects payment-confirm if return lines enter the cart mid-modal.
8. **Charge-to-account + mixed-cart blocking.** A return cart blocks Pay on both the cash/card exit and the charge-to-account exit with a "complete the return first" toast. Mixed carts (return + sale lines) are blocked at the Pay intercept.
9. **AVOIR print + voucher ticket.** Phase 3: `AVOIR` receipt prints automatically on refund settlement (`REMBOURSEMENT` header, original-ticket reference, TND 3-decimal amounts). When destination = Store voucher, a second voucher ticket prints immediately after the AVOIR (code, amount, expiry). Print failure shows a toast with the ticket number.
10. **Z refund accounting (fiscal audit B2).** Settled refunds are recorded locally in a `local_refund_records` mirror table at settle time; the device Z folds them in — `refunds_count` and `refunds_amount` in the signed Z are no longer hardcoded zeros.
11. **Variant-aware restock + batch restitution (Phase 5).** Returns correctly increment the specific variant's `stock_levels` row (not the parent product row). Batch-tracked products get proportional FEFO batch restitution on return.
12. **Void-of-return guard.** The API rejects `POST /receipts/{uuid}/void` on a RETURN receipt with HTTP 422 `CANNOT_VOID_RETURN_RECEIPT`. `VoidReturnModal` was deleted from the POS (dead code post-guard). Web-admin POS return surface quarantined (placeholder page; server endpoints remain active for potential future API consumers).
