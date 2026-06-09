# Coffee Shop (Tunisia, takeaway) — Manual Smoke Test

> **What this is:** the concrete runbook a teammate executes to smoke-test the **Cafe Tunis** takeaway coffee-shop POS before go-live. Authored 2026-06-09 by the `verify/coffeeshop-e2e` session against an `origin/dev` snapshot (`45b58a691`).
>
> **Scope:** takeaway-dominant retail. Open shift → cash sale (over-tender + change) → discount (with manager-PIN path) → void → refund → mixed/card tender → Z-report close → offline→online sync. Table management is **out of scope** (takeaway; verified hidden — see §Known gaps).
>
> **Related:**
> - `docs/superpowers/plans/2026-05-09-pos-phase-6-manual-smoke-checklist.md` (parapharmacy Slow-3G runbook — same shape).
> - `project_pos_go_live_checklist.md` (memory) — go-live TBDs; this doc maps which are code-verifiable vs physical-terminal-only (see §Go-live TBD mapping).

---

## ⚠️ Read first: browser vs Tauri desktop

The POS local store (`apps/pos/src/lib/db.ts`) hard-imports `@tauri-apps/plugin-sql` and calls `Database.load('sqlite:...')` — a **Tauri-runtime** API. The entire offline layer (offline receipts, cashier-PIN store, holds, local Z-report archive, sync queue) **cannot run in a plain browser**.

- **A plain browser** (`pnpm dev` → http://localhost:1420) reaches **login → company select → terminal claim** only. It breaks at the PIN-setup step (first DB access). Use it only to eye-check those early screens.
- **Genuine takeaway E2E** (sale → receipt → Z-report → offline → sync) **must run on the Tauri desktop build**: `pnpm tauri dev` (or a packaged build). That is also where the physical terminal / printer / cash drawer live.

Each step below is tagged:
- ✅ **code-verified** in the authoring session (backend service / data path exercised).
- ⚠️ **Tauri desktop** required (offline/DB layer) — not runnable in a browser.
- 🔲 **physical terminal** required (printer, cash drawer, card reader) — cannot be verified in software at all.

---

## Environment setup

### Backend (apps/api) — verified standup recipe

The seeder targets **shared-DB mode** (`TENANCY_DB_PER_TENANT=false`, the Phase-0a single public-schema reality). Tenant tables (`pos_terminals`, fiscal triggers, …) live in `database/migrations/tenant/` and are **only** folded into the single `migrate` path under **`APP_ENV=testing`** (`AppServiceProvider::loadTenantMigrationsInTestingEnvironment`). So the harness uses `APP_ENV=testing` throughout. This is a dev-harness convenience only; production runs real per-tenant DBs.

```bash
cd apps/api
composer install                      # fresh worktree has no vendor/

# .env — host Postgres (trust auth), shared single DB, no Redis/Meili:
#   APP_ENV=testing                   # loads tenant migrations into one schema
#   DB_CONNECTION=central
#   DB_HOST=127.0.0.1  DB_PORT=5432  DB_USERNAME=<you>  DB_PASSWORD=
#   DB_CENTRAL_DATABASE=syneriva_coffeeshop_e2e
#   DB_DATABASE=syneriva_coffeeshop_e2e
#   TENANCY_DB_PER_TENANT=false
#   CACHE_STORE=array  QUEUE_CONNECTION=sync  SESSION_DRIVER=file
#   BROADCAST_CONNECTION=log  SCOUT_DRIVER=null
# (A working copy was left at apps/api/.env in the verify/coffeeshop-e2e worktree.)

createdb syneriva_coffeeshop_e2e
php artisan key:generate
php artisan migrate --force           # builds full public schema + fiscal triggers
php artisan db:seed --class=CoffeeShopSeeder --force

php artisan serve --port=8002         # POS default VITE_API_URL points here
```

> **Note — distinct DB/port:** runs in parallel with the parapharmacy + deposits sessions. Use `syneriva_coffeeshop_e2e` (not `autoerp_central_e2e` / `syneriva_parapharmacy_*`).

> **Re-runnable:** the seeder deletes & recreates the `cafe-tunis` tenant on re-run. (Fixed in this session: it now guards the global `UomSeeder` call so a re-run no longer collides on `unit_categories.code`.) ✅

### Frontend POS (apps/pos)

```bash
cd apps/pos
pnpm install
cp .env.example .env                  # confirm VITE_API_URL=http://localhost:8002

# Browser (early screens only):
pnpm dev                              # http://localhost:1420

# Full takeaway E2E (offline + Z-report + sync):
pnpm tauri dev                        # Tauri desktop window
```

### Test data (seeded by CoffeeShopSeeder) ✅

- **Tenant/company:** Cafe Tunis (TN, **TND**, 7% VAT on F&B, `fr` locale, `Africa/Tunis`).
- **Location:** Cafe Tunis Centre (`CAFE-01`), `pos_enabled`.
- **Terminal:** `POS01` — "Front Counter", **active + unclaimed** → claim it from the device (added this session; see §Stabilization). ✅
- **Menu (default "All Day"):** Hot Drinks, Cold Drinks, Pastries, Specials (Daily Special + 3 combos), Shop (5 retail items). Drinks have Small/Medium/Large variants + 4 modifier groups (Milk, Extras, Sweetness, Temperature).
- **Promotion:** "Buy Frappuccino, Get Free Croissant" (active).
- **Payment:** 6 payment methods + 7 repositories (cash + card/other tenders configured). ✅

### Credentials

| Role | Email | Password | Notes |
|---|---|---|---|
| Owner (admin) | `owner@cafe-tunis.tn` | `password` | `can_discount`, max 25% |
| Barista (cashier) | `barista@cafe-tunis.tn` | `password` | `can_discount`, max 25% |

> **Cashier PIN:** there is **no seeded PIN**. The PIN is set **on first device boot** (`PinSetupPage`, 4–6 digits, stored bcrypt-hashed in the device's local SQLite — *not* a `users` column, *not* in the backend). On a fresh device: login (email/password) → claim terminal → **set PIN** → that PIN unlocks the cashier on subsequent boots. Pick e.g. `1234` for the smoke run and record it on the capture sheet.

---

## Pre-flight

- [ ] Backend up; `php artisan migrate:status` shows no pending. ✅ (verified clean this session)
- [ ] `php artisan db:seed --class=CoffeeShopSeeder` completes with "Coffee shop seeded successfully!" ✅
- [ ] POS: `pnpm typecheck` PASS, `pnpm test` PASS. (`pnpm lint` may be SKIPPED — pre-existing ESLint config carry-forward.)
- [ ] Device is the **Tauri desktop build** for everything below §Login (browser can't do offline/PIN/Z).

---

## Golden path

### 1. Login + claim terminal + set PIN  ⚠️ (Tauri desktop)
- [ ] Launch POS → **Login** with `barista@cafe-tunis.tn` / `password`.
- [ ] If prompted, select company **Cafe Tunis**.
- [ ] **Terminal setup** → "Claim existing terminal" → pick **POS01 / Front Counter** → claim.
  - *Expected:* `POS01` is listed as available (active, unclaimed). ✅ (`/pos/terminals/available` returns it — verified)
- [ ] **Set PIN** (e.g. `1234`), confirm.
- **Acceptance:** lands on the main POS (menu visible, Hot/Cold/Pastries/Specials/Shop categories).

### 2. Open shift  ✅ (service) / ⚠️ (UI)
- [ ] Open the shift with **opening cash = 100.000 TND**.
- **Acceptance:** shift status **OPEN**, opening cash 100.000.
- ✅ *Verified in session:* `ShiftManagementService->openShift(POS01, owner, "100.000")` → status `OPEN`. The exact opening-cash UI entry point — confirm on device and record the click-path.

### 3. Cash sale, over-tender → change  ⚠️ + 🔲 (receipt print)
- [ ] Add **Latte (Medium)** + **Croissant** to cart (tap from Hot Drinks / Pastries).
- [ ] Checkout → **Cash Payment** (`CashPaymentScreen`).
- [ ] Tendered amount **over** the total (e.g. total 8.500 → tender 10.000).
- [ ] Confirm → **"Complete & Print Receipt"**.
- **Expected:** a green **"Change Due"** box shows the correct change (10.000 − 8.500 = **1.500**); success modal shows the change line.
- **Acceptance:** receipt prints/previews with line items, 7% VAT, total, tendered, change. 🔲 (real print needs the terminal printer).

### 4. Discount + manager-PIN override  ⚠️
- [ ] Add a drink; open **Discount** (QuickActions) → `DiscountModal`.
- [ ] Apply a discount **within** 25% (e.g. 10%) → applies with no override.
- [ ] Apply a discount **over** 25% (e.g. 30%) → **Manager Approval Required**: PIN entry appears.
  - Enter the **owner's** PIN (owner is the manager) → authorize.
- **Acceptance:** ≤25% applies silently; >25% requires + records a manager-PIN override (`discount_limit_override`). Both seeded users cap at 25%, so 30% is the override trigger; the seeded terminal cap is 100% so the **user** limit is the binding gate. ✅ (limits seeded as designed)

### 5. Void  ⚠️
- [ ] **Void a line (everyday):** remove a line item from the cart before payment. ✅ (`cartStore.removeItem` exists)
- [ ] **Void a sealed receipt:** ⛔ **KNOWN GAP** — the `VoidReturnModal` is mounted in `HomePage` but **never triggered** (`setShowVoidReturnModal(true)` is never called on this dev snapshot). There is no UI button to void a *completed* sale. See §Known gaps.

### 6. Refund / return  ⚠️ + 🔲
- [ ] Open **"Returns / Exchange"** (`ReceiptLocatorScreen`).
- [ ] Search the receipt from step 3 by number (local lookup) → **"Refund this"**.
- [ ] Cart hydrates with the return items → choose refund destination (Original Payment / Cash / Store Voucher) → complete.
- **Acceptance:** refund completes; refund line appears in the shift totals. (Receipt lookup is **local SQLite only** — the receipt must have been rung on *this* device.)

### 7. Mixed payment + card/other tender  ⚠️ + 🔲
- [ ] Add items totalling e.g. 12.000; checkout → **"More Payments"** (`AdvancedPaymentsModal`).
- [ ] Add a **Cash** line (e.g. 5.000) + a **Card** line (remaining 7.000) → running balance reaches 0 → **"Complete Transaction"**.
- **Acceptance:** split tender accepted; both payment lines recorded. (Card capture is simulated in software; a real reader is 🔲.)

### 8. Z-report close  ⚠️ + 🔲
- [ ] Header → Reports → **End of Day** (`EndOfDayPreviewModal`): review opening/expected cash + variance preview.
- [ ] Enter **counted cash** → **Close Shift** → `ZReportModal` generates.
- **Expected Z-report shows:** Z-number, fiscal hash, opening/expected/variance, sales count, gross/net/tax, refunds, **VAT breakdown (7%)**, payment-method breakdown.
- [ ] View history at **`/reports/z`** (`ZReportListPage`).
- **Acceptance:** Z-report generates locally with a fiscal hash; variance reconciles against the counted cash.

### 9. Server archive matches local (Z-report)  ✅ (paths) / ⚠️ (drive)
- **Architecture (confirm, don't rebuild):** two paths converge on the server.
  - **Path A — archive:** the device generates the Z locally (`lib/offline/zReportService.ts` → local `z_reports` + `terminal_state` chain), then **uploads** it via `POST /pos/reports/z/sync`.
  - **Path B — recompute:** the server can recompute from `pos_receipts` (`ReportGenerationService::generateZReport`, idempotent per shift) → `pos_z_reports`.
- [ ] After sync, confirm the server `pos_z_reports` row matches the device: same `z_number`, `fiscal_hash`, `report_data` (gross/tax/payment_methods), `receipt_snapshots`, `grand_totals`, `synced=true`.

### 10. Owner web-admin Z view matches  ⚠️ (apps/web)
- [ ] In **apps/web** (admin), open the POS Z-report list (`GET /api/v1/pos/reports/z`, filter by terminal + date).
- **Acceptance:** the owner sees the same Z-number / totals the POS printed. Single-report + PDF + verify-chain endpoints exist (`/pos/reports/z/{z}`, `/pdf`, `/verify-chain`).

### 11. Offline → online sync  ⚠️
- [ ] Disconnect network. Confirm the **SyncButton** shows offline/amber and an offline banner appears.
- [ ] Ring 2–3 cash sales offline (queued in local `offline_receipts`, status `pending`).
- [ ] Reconnect. The sync scheduler (60 s tick, or tap SyncButton to force) drains the queue: `runFullSync` → `POST /pos/sync` (receipts + fiscal events) + `POST /pos/reports/z/sync`.
- **Acceptance:** pending badge clears, SyncButton goes green, all sales appear server-side.
- **Fail-closed note:** offline mode triggers **only** on positive offline evidence (timeout / `!isOnline`). A *reachable-but-erroring* server (5xx/408/429) retries then **fails closed** — it does **not** silently downgrade. Optionally verify by pointing the device at a 500-returning backend and confirming the sale fails rather than queuing. (See memory `feedback_pos_offline_first_priority`.)

---

## Known gaps (ticket, don't block the smoke run)

1. **Sealed-receipt void not wired to UI** — `VoidReturnModal` is mounted in `HomePage.tsx` but `setShowVoidReturnModal(true)` is never called → no way to void a *completed* sale from the POS. Everyday line-void (remove from cart pre-payment) works. *Impact:* a mis-rung completed sale must be handled via refund/return instead of void. **Ticket it** before go-live if void-after-seal is required for Tunisia.
2. **Refund lookup is device-local** — `ReceiptLocatorScreen` searches local SQLite only; a receipt rung on another terminal can't be refunded here. Expected for offline-first single-terminal takeaway; confirm it matches the shop's reality.
3. **PIN has no seeded default** — every fresh device requires a first-boot PIN setup. Fine, but the teammate must set + record it.

---

## Stabilization changes made this session

- **`CoffeeShopSeeder`**: now seeds a claimable `POS01` terminal (active, unclaimed, NF525 genesis seed mirroring `TerminalController::store`) so shift-open works without a manual admin step; and guards the global `UomSeeder` call so the seeder is re-runnable. Covered by `tests/Feature/Seeders/CoffeeShopSeederTerminalTest.php` (2 tests, green; pint + phpstan clean on the new code).
- Pre-existing (not introduced here): one PHPStan baseline note in `CoffeeShopSeeder::createTenant` (`TenantSubscription started_at`) — out of scope.

---

## Go-live TBD mapping (`project_pos_go_live_checklist.md`)

| Go-live TBD | Verifiable here? |
|---|---|
| Seeder + backend stand up cleanly | ✅ **Yes** — done this session |
| Shift open / claimable terminal | ✅ **Yes** — service-verified |
| Cash sale / change / discount / refund / mixed tender flows exist + labelled | ✅ code-mapped; ⚠️ drive on Tauri desktop |
| Z-report local→server archive parity | ✅ paths confirmed; ⚠️ drive on device |
| Offline backlog drain measurement | ⚠️ **Tauri desktop** (needs local SQLite + sync scheduler) |
| Receipt **printing** on the terminal | 🔲 **Physical terminal only** |
| Cash-drawer kick / card reader | 🔲 **Physical terminal only** |
| Restore drill on physical terminal | 🔲 **Physical terminal only** |
| Tunisia legal pack (NACEF/MDF) with accountant | ⬜ out of software scope |
