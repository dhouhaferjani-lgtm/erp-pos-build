# Demo Pharmacy Account — Design Spec

> **Date:** 2026-06-19
> **Branch:** `feat/demo-pharmacy-account` (off `dev`)
> **Status:** Design — Codex-reviewed + adjudicated (see §13); awaiting owner review before implementation plan
> **Author context:** Prep for a possible **Tunisia** parapharmacy-client demo. Build a permanent, reusable demo account on the **deployed dev** environment, run a live Tauri-POS demo against it end-to-end, and produce a concrete **reporting/feature gap list** that drives the "what to build next" decision.
>
> **LOCALE: Tunisia only.** No France anywhere — TND currency (3-decimal), Tunisia chart of accounts, Tunisian *matricule fiscal* (not SIRET/SIREN/NIC), Tunisian cities, Tunisian VAT rates. The existing `ParapharmacyMultiBranchSeeder` is France-specific; reuse its *structure*, swap every locale detail.

---

## 1. Goal

Stand up enough realistic data and a working live-demo path so a parapharmacy client can be shown the full retail + POS + enrichment story end-to-end. The **primary deliverable beyond the demo itself is §8 — a gap report** telling us what additional development (especially reporting) is needed before this is client-ready.

The account is **kept as a permanent demo account**; data grows naturally as sales are rung.

## 2. Decisions locked (from brainstorming)

| # | Decision | Choice |
|---|---|---|
| D1 | Demo surface / backend | **Deployed dev (Dokploy/AX42).** Web-admin viewed remotely; Tauri POS on a laptop points at the remote API. |
| D2 | Seeder strategy | **Reusable** `DemoPharmacySeeder` — **extends the non-final `ParapharmacySeeder`** (overriding locale) and **ports** the multi-branch topology from the `final` `ParapharmacyMultiBranchSeeder`; additive/idempotent (see §5.1 construction). |
| D3 | Sales history | **No fiscal-event-authoring seeder.** Seed everything a cashier needs, then **hand-ring ~20–30 real sales** through the live POS. History grows organically thereafter. |
| D4 | Enrichment | **GO (real, not stubbed).** Platform is deployed with ~13k products; connection mostly works. Owner is fixing image/barcode issues on the platform side. |
| D5 | Platform data cleaning | **Parallel Track B**, executed from the **Synerivia repo** (not this repo). Mostly done: enrichment URL + API key added, deploy ready. |
| D6 | Locale | **Tunisia only** — TND, Tunisia COA, *matricule fiscal*, Tunisian cities/VAT. No France. |
| D7 | Barcode demo | Seed a **mix** of products with and without barcodes; demo exercises both the "has barcode → platform lookup returns data" path and the "no barcode → platform performs the lookup/assignment" path. |

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

### 5.1 `DemoPharmacySeeder` — topology (Tunisia: warehouse + 4 shops)

Reuse the *structure* of `ParapharmacyMultiBranchSeeder` (multi-location + per-branch tax identity + location-scoped cashiers + terminals + stock distribution + variant SKUs) but build a **Tunisia** tenant: TND currency, country `TN`, **Tunisia chart of accounts** + Tunisia tax/withholding config, and **per-branch *matricule fiscal*** instead of SIRET. (The implementation pulls the Tunisia COA/tax/payment building blocks confirmed in `TunisianParapharmacySeeder` + the Tunisia seeders — see §11 verification.)

**5 locations** (1 warehouse + 4 shops):

| Code | Name (proposed, adjustable) | City | Type | POS |
|---|---|---|---|---|
| `WH-01` | Entrepôt Central | **Sousse** | warehouse | no |
| `STORE-TUN1` | Tunis — Lac | **Tunis** | shop | yes |
| `STORE-TUN2` | Tunis — Centre | **Tunis** | shop | yes |
| `STORE-SOU` | Sousse — Médina | **Sousse** | shop | yes |
| `STORE-SFA` | Sfax — Centre | **Sfax** | shop | yes |

- **Per-branch tax identity (Tunisian *matricule fiscal*) — format MUST satisfy the runtime validator.** `CountryTaxNumberRules` enforces TN as **`/^[0-9]{7,8}[A-Z]{2}[0-9]{3}$/`** *after* stripping `/` (verified). So the canonical stored form is **`1234567AM000`** — 7 digits + 2-letter key (control + tax-type) + **3-digit establishment code** — **no `TN` prefix, no slashes**. ⚠️ The existing `TunisianParapharmacySeeder` seeds `TN1234567ABC`, which **fails this regex** → editing that tax_id in the settings UI 422s; our seeder must use the validator-conformant form. Per-branch = vary the establishment code: warehouse/principal `000`, Tunis-Lac `001`, Tunis-Centre `002`, Sousse `003`, Sfax `004`. Each shop sets `tax_id` to its variant; warehouse leaves `tax_id` NULL → inherits company `…000` (`TaxIdentityResolver` reads location override, falls back to company, merges `legal_identifiers`).
- ⚠️ **Z-report renders company tax_id, ignoring the branch override** (`z-report.blade.php:239` uses `$company->tax_id` directly, not `TaxIdentityResolver`; the *receipt* blade is correct). So per-branch matricule fiscal shows on receipts but **not** on the Z-report, and there is **no "Matricule Fiscal" localized label**. Tracked as a fix/caveat in §8.
- One `POS01` terminal per shop (4 terminals; terminal code unique per location, CHECK `^POS[0-9]{2}$`). None at the warehouse. Seed terminals **`is_active=true` + `hardware_identifier=NULL`** so the POS claim flow works immediately with no admin activation step (the claim gate checks exactly those two — verified `TerminalController`); fiscal-chain `genesis_seed` is auto-set at terminal creation, so no separate chain-init step.
- Stock: warehouse holds bulk (~90% of catalog); each shop holds ~60% in small front-of-house quantities. Variant SKUs (sized goods) distributed across all locations.
- Location-scoped cashiers per shop (`tunis1.cashier@…`, `tunis2.cashier@…`, `sousse.cashier@…`, `sfax.cashier@…`, each `allowed_location_ids=[shop.id]`); owner/manager cross-branch (`NULL`).

**B2C framing:** ~150 individual Tunisian customers (`Partner::factory()->tunisia()`) as the headline; retain a clinic/house account + maybe 1–2 B2B for the account-charge demo.

**Seeder construction (verified architecture).** `ParapharmacyMultiBranchSeeder` is **`final`** (line 72) — it **cannot be extended**. Its parent `ParapharmacySeeder` is **not `final`** and exposes `protected` methods, so it **can** be subclassed. The honest plan:
- `DemoPharmacySeeder` **extends `ParapharmacySeeder`** (the non-final parent) and overrides its `protected` locale points; where the parent hardcodes France inline with no seam, **add a small protected hook** (a focused, locale-extraction refactor of the parent — note the parent is used in CI, so changes must keep France behaviour identical and be test-covered).
- The multi-branch topology logic (branch creation, per-branch tax identity, terminal seeding, location-scoped cashiers, multi-branch stock) lives in the **final** `ParapharmacyMultiBranchSeeder` and therefore must be **ported (copied)** into `DemoPharmacySeeder`, not inherited.
- Locale points to replace (France→Tunisia, **~12 inline sites verified**): country `FR→TN`, currency `EUR→TND`, COA `FranceChartOfAccountsSeeder→TunisiaChartOfAccountsSeeder::run($companyId,$tenantId)`, tax config `→TunisiaTaxConfigurationSeeder`, VAT default `20%→19%`, tax-id `SIRET/siren/nic→matricule fiscal` (validator-conformant, above), cities Paris/Lyon→Tunis/Sousse/Sfax, barcode prefix `300→619` + a null subset (§5.6), partner factory `->france()→->tunisia()`, emails/phones.
- **Tenant DB provisioning is inherited, not magic:** `ParapharmacySeeder::createParapharmacyTenant()` calls `provisionTenantDatabase()` which `Bus::dispatchSync`es `CreateDatabase` + `MigrateDatabase` (verified). So the fork gets tenant-DB create+migrate for free — but it must call that path and add a pre-flight that aborts if the tenant DB exists *half-migrated*.

### 5.2 Customers, suppliers, accounts & balances

- Reuse the inherited 150 B2C customers + ~10 suppliers + house account.
- **Seed GL-consistent balances** (NOT raw columns — `partners.receivable_balance`/`credit_balance` are cached projections of the GL):
  - A few B2C customers with an **outstanding receivable** (invoice JE debit to `CustomerReceivable`, partial payment credit).
  - A few customers with **store credit** (`CustomerAdvance`).
  - The clinic house account with both a credit limit (already €5000) **and** a real balance.
  - Optionally 1–2 suppliers with a **payable balance** (`SupplierPayable`).
- Pattern: create `JournalEntry` + `JournalLine`s against the **Tunisia COA** system-purpose accounts — **411 Clients** (CustomerReceivable), **419 Clients créditeurs** (CustomerAdvance/store credit), **401 Fournisseurs** (SupplierPayable) — then call `PartnerBalanceService::refreshPartnerBalance($companyId, $partnerId)` to populate the cached columns. **Template:** `CoffeeShopSeeder.php:985–1116`.
- **All monetary values in TND (scale 3)** via `CurrencyScale::bcformat($v, 3)` — never floats. Same for PO line amounts (§5.3) and product prices.
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

Then, as a setup step, **hand-ring ~20–30 sales** through the live Tauri POS across the shops. **Reporting acceptance criteria (coordination note from the reporting session, 2026-06-20) — the dashboards only land if the data has:**
- **≥2 locations with current-period sales** (ring on at least 2 shops);
- **≥1 return receipt**;
- **varied payment methods** (cash, card, split);
- **enough products for a top-SKU list** (✓ ~1000 seeded);
- **nonzero prior-period sales** (for period-over-period deltas) — ⚠️ **see §5.5a; conflicts with D3, owner decision pending.**

Concretely the manual mix: simple cash + card + **split payments**; a couple of **account-charge** sales; a couple of **refunds / partial refunds / return notes**; close a shift with a **Z-report** on at least one terminal.

### 5.5a Prior-period sales for report deltas — RESOLVED: B1 (owner, 2026-06-20)

The reporting deltas need **nonzero prior-period sales**, but sales rung today are all current-period. **Decision: Option B1 — multi-day manual sales.** Ring a handful of real sales across **2–3 days before the demo** so a prior period is nonzero. Preserves D3 (no fiscal-event-authoring seeder; no extra seeder tasks). **Dependency:** this only works if the report's comparison window is day-/short-window-based — **confirm the window with the reporting session**; if it's month-over-month, revisit (would need B2). Not chosen: B2 (minimal back-dated seeder), B3 (flat deltas + caveat).

This yields genuine fiscally-valid history that populates shift-history, Z-reports, and the analytics dashboard, and continues to grow during/after the demo.

⚠️ **Projections are async (Horizon-dependent).** POS-synced fiscal events become receipt/shift/Z-report rows only when the queued `ApplyFiscalEventProjectionJob` runs on the **`fiscal-projections`** queue (verified `ShouldQueue` + `onQueue`). So **Horizon must be running on staging**, or the web-admin will show nothing after sales. The dry-run (§7) must confirm this; recovery if a projection is stuck: `php artisan fiscal:enqueue-resolved-event-projections --actor-id=<USER uuid>` (the actor is a **User** id with the resolve permission — verified).

### 5.6 Enrichment integration (per D4)

ERP side is implemented **and already on this branch (= on `dev`)** — verified: `PlatformIntegration` routes (`barcode-lookup`, `submit-for-enrichment`, webhook) + the web `features/enrichment/` review panel are present. So the earlier "confirm if merged" hedge is resolved — **no merge/cherry-pick needed.** The client reads env `SYNERIVA_PLATFORM_URL` / `SYNERIVA_PLATFORM_API_KEY` / `SYNERIVA_WEBHOOK_SECRET` (note spelling: `SYNERIVA`, in `config/services.php`). The remaining gate is an **end-to-end staging smoke test**, not code. Platform side is deployed with ~13k products.

**Two demo paths (per D7 — seed a barcode mix):**
1. **Product WITH a barcode** → lookup against the deployed platform → match returns product data → submit → review queue → **accept** selected fields → product enriched (name/brand/description/ingredients/images merged).
2. **Product WITHOUT a barcode** → the platform performs the lookup/assignment (resolves identity and returns/assigns a barcode + data) → same review/accept flow.

So the seeder must leave **some products with EAN-13 barcodes and some with none** (`barcode` is nullable + non-unique). Override the France seeder's blanket-barcode behavior: give a subset realistic **Tunisia GS1 `619`-prefixed** EAN-13 barcodes (those become the "scan a barcode that resolves" demo set), and leave a deliberate subset **NULL** (the "no barcode → platform looks up/assigns" demo set).

Prerequisites (**ready** per D4/D5): platform enrichment URL + API key configured on staging (`SYNERIVA_PLATFORM_URL` / `SYNERIVA_PLATFORM_API_KEY` / `SYNERIVA_WEBHOOK_SECRET`); the platform's lookup/submit endpoints + webhook reachable from the deployed ERP; ~13k-product catalog (owner finishing image/barcode cleanup). **Staging smoke test** (part of the dry-run): trigger enrichment on 2–3 products (one with a `619` barcode, one with none) → confirm the review panel shows results → accept one → confirm the ERP product updates.

## 6. POS deployment & build (Track A prerequisite for the live demo)

1. Determine the deployed-dev API base URL + Reverb host. (Prod `.env.production` points at `api.riserpos.app` — **do not reuse the prod binary**; there is **no `apps/pos/.env.staging`** today.)
2. Add `apps/pos/.env.staging` with the staging `VITE_API_URL` + `VITE_REVERB_HOST`/`PORT`/`SCHEME`, then build/run the POS pointed at it:
   - Quick path: on the laptop, `cd apps/pos && VITE_API_URL=https://<staging> VITE_REVERB_HOST=<host> pnpm tauri dev`.
   - Packaged path: `VITE_API_URL=https://<staging> … pnpm build && pnpm tauri build` → install the staging binary.
3. **CORS:** set `CORS_ALLOWED_ORIGINS` on the staging API to include the POS/web origin (default is local Vite only — `cors.php` is env-driven). During the dry-run, confirm what `Origin` the Tauri app actually sends (native Tauri HTTP may bypass browser CORS; webview fetch does not — verify, don't assume). HTTPS cert must be valid (Dokploy LE).
4. **Horizon:** confirm Horizon is running on staging and consuming the `fiscal-projections` queue (`php artisan horizon:status`), else POS sales won't project to web-admin.
5. **Claim the 4 terminals** against staging: login (owner/manager) → Terminal Setup → claim the available `POS01` at each of the 4 shops. (Seeded terminals are `is_active=true` + unclaimed, so no admin activation needed.)
6. Verify a **sync round-trip** (stock pull, a test receipt push that projects to web-admin, PIN sync) before the demo.

## 7. Live demo runbook (high level)

**Mandatory dry-run the day before** (catches the async-projection trap): run the seeder → claim all 4 terminals → ring **1 sale per branch + 1 Z-report** → confirm every receipt/shift/Z-report **appears in web-admin** (proves Horizon is projecting) → run the enrichment smoke test (§5.6). If web-admin is missing rows, run the recovery command (§5.5) and check Horizon.

Demo flow — on the Tauri POS (desktop): open shift → **simple sale** → **split-payment sale** → **refund / partial refund / return note** → (optional) **account-charge sale** → run **X-report** → close shift with **Z-report**. Then in **web-admin** (remote): show shift history, the Z-report (+ **hash-chain verify**), the **analytics dashboard** (sales/products/cashiers/discounts/customers), partner balances, the PO pipeline, and a stock transfer. Finally, the **enrichment** flow (§5.6). Detailed click-steps produced with the implementation plan.

## 8. Reporting / feature gap report — PRIMARY DELIVERABLE

A report-only artifact (`docs/superpowers/audits/2026-06-…-demo-pharmacy-gap-report.md`) capturing what's missing/weak for a client, to drive the build-next decision. Seeded with **known gaps already found**, to be confirmed/extended during the dry run:

- Web-admin **transaction list is needed but currently absent.** The old `/pos/transactions` page was **intentionally removed** (web POS *authoring* is retired — POS is Tauri-only, by design), but a **read-only transaction/receipt list in web-admin is definitely wanted** (today receipts are only reachable via Z-report detail / shift receipts). **OWNED BY THE PARALLEL REPORTING SESSION** — do **not** plan or build it here; this spec only *records the need* and feeds observations to that session. Avoid scope overlap.
- **X-report** `expected_cash` does not subtract refunds (display gap, G6).
- Per-terminal **Z-report excludes cross-terminal refunds** (by design, G8) — confirm acceptable for client.
- Web-admin **returns surface quarantined** (G9); refunds desktop-only.
- **Typed refund-exception mapping** missing (Phase H) — manager-override modal doesn't trigger correctly for override-required refunds.
- **Cross-terminal refunds** require a receipts mirror (Phase 1 backlog, ~1–2 days).
- **Customer deposit top-up** flow pending (A8/G4).
- **Z-report ignores per-branch tax identity** (`z-report.blade.php:239` uses `$company->tax_id`, not `TaxIdentityResolver`), and no localized "Matricule Fiscal" label. For a Tunisia multi-branch demo the Z-report shows the company-level matricule, not the establishment's. **Small fix** (swap to the resolver with location context + TN label) — decide: fix (recommended, ~Tier B) or caveat to the audience.
- **Tunisia stamp duty (timbre fiscal) seeded but NOT applied to POS receipt totals** — `TunisiaTaxConfigurationSeeder` seeds `STAMP_FISCAL_RECEIPT` (0.100 TND) etc., but it isn't added to `pos_receipts` totals at sale time. Demo receipts will omit the legally-required stamp. Caveat to audience or a follow-up dev item; not a demo blocker.
- **Matricule-fiscal validator mismatch** (`TunisianParapharmacySeeder` seeds `TN…`, which fails `CountryTaxNumberRules` TN regex) — our seeder avoids it, but flag that the *existing* Tunisian seeder/value would 422 in the settings UI.
- **Pre-existing product-form 422** on staging (tax_rate 4dp + `parapharmacy_metadata` on retail vertical) blocks web product-create — verify it does NOT bite the parapharmacy demo tenant (the seeder bypasses the FormRequest, so seeded products are unaffected; only manual web product-create during the demo would hit it).
- Anything the client asks for that we can't show.

## 9. Reusability & reprovision (G1 workaround)

- **Don't inherit the destructive tenant bootstrap.** `ParapharmacySeeder::createParapharmacyTenant()` deletes+drops the tenant if the slug already exists (verified, conditional on slug `pharmabio-france`) — and that delete path is exactly what risks the G1 500. `DemoPharmacySeeder` must instead **guard additively**: if the demo tenant (our own slug) already exists, **skip the bootstrap and seed only missing pieces** (sentinel check per section: tenant, branches, POs, balances, transfers). Re-running is then safe.
- Clean from-scratch reset (when wanted) = **manual drop**, not the in-seeder delete: `dropdb tenant_<uuid> && createdb … && php artisan migrate --force && php artisan db:seed --class=DemoPharmacySeeder --force` (or `tenant:deprovision <slug> --force` if it avoids the G1 path — verify).
- Document the exact tenant slug + DB name + credentials in the implementation plan.

## 10. Tiering & sequencing

- **Tier A (must-have for a live demo):** §5.1 topology (5 locations), §5.2 balances, §6 POS build/deploy + terminal claim, §7 dry-run + runbook, §8 gap report.
- **Tier B (high value):** §5.3 POs, §5.4 transfers, §5.5 manual sales history.
- **Tier C (parameterized):** §5.6 enrichment + Track B platform cleaning.

Execution begins **after the deploy completes** (owner gate).

## 11. Open items / dependencies

- **Tunisia building blocks — RESOLVED** (TND/scale 3, country TN, `TunisiaChartOfAccountsSeeder` + `TunisiaTaxConfigurationSeeder`, accounts 411/419/401, VAT 19/13/7/exempt, `Partner::factory()->tunisia()`). Matricule-fiscal **format pinned** to the validator (§5.1). Only open detail: exact `legal_identifiers` key names (latitude — resolver merges arrays).
- **Enrichment — RESOLVED:** code is on this branch (= on `dev`); no merge needed. Remaining gate = staging end-to-end smoke test (§5.6).
- **Deploy must finish** (parallel session) before §6/§7. Likely `erp.otospex.dev` staging. Confirm exact API + Reverb URLs.
- **Staging config to set:** `CORS_ALLOWED_ORIGINS` (POS/web origin), `SYNERIVA_PLATFORM_URL`/`_API_KEY`/`_WEBHOOK_SECRET`, Horizon running on `fiscal-projections`.
- **Track B** (Synerivia repo): enrichment URL + API key DONE, deploy ready; owner finishing image/barcode cleanup on the ~13k catalog.

## 12. Out of scope / YAGNI

- Fiscal-event-authoring history seeder (explicitly dropped, D3).
- Building new reports / the web-admin transaction list — **owned by a parallel reporting-planning session.** This cycle only *identifies* gaps and feeds them over; it does not design or build reporting.
- Tenant-deletion (G1) fix — worked around, not fixed here.
- Any modification of ERP from the Synerivia context, or vice versa.

## 13. Codex adversarial review — adjudication (2026-06-19)

Full review: `docs/superpowers/reviews/2026-06-19-demo-pharmacy-spec-codex-review.md` (verdict NEEDS-REWORK). Every finding was verified against code. Net: **targeted corrections, not a rewrite** — both BLOCKERs softened on inspection. Outcome per finding:

| Codex finding | Verified verdict | Action |
|---|---|---|
| Tenant DB not auto-provisioned (BLOCKER) | CONFIRMED but **inherited** — `createParapharmacyTenant()→provisionTenantDatabase()` already does it | §5.1: state it's inherited + add half-migrated pre-flight. Not a blocker for us. |
| Hook extraction impossible — class `final`/private (BLOCKER) | **OVERSTATED** — parent `ParapharmacySeeder` is non-final/`protected`; only the multi-branch child is `final` | §5.1/D2: extend parent, **port** topology from the final child. |
| Additive vs inherited destructive delete | CONFIRMED (conditional on FR slug; risks G1) | §9: guard additively on our slug; manual drop for hard reset. |
| Projections need Horizon | CONFIRMED (`fiscal-projections` queue) | §5.5/§6/§7: Horizon check + recovery cmd `fiscal:enqueue-resolved-event-projections --actor-id=<user>`. |
| Z-report ignores branch tax_id + no TN label | CONFIRMED (receipt OK, **Z-report blade:239 bug**) | §5.1 ⚠️ + §8 gap (small fix or caveat). |
| TN matricule-fiscal regex mismatch → 422 | CONFIRMED (`TN1234567ABC` fails `[0-9]{7,8}[A-Z]{2}[0-9]{3}`) | §5.1: pin format `1234567AM000`, establishment in last 3 digits. |
| Stamp duty absent | **Refined** — seeded as config, **not applied** to receipt totals | §8 caveat. |
| France coupling ~12 inline sites | CONFIRMED | §5.1 locale-replacement checklist. |
| POS staging rebuild not mentioned | **OVERSTATED** — already in §4/§6 | §6: add `.env.staging` step (file doesn't exist). |
| CORS will reject Tauri | **OVERSTATED** — env-driven; Tauri Origin unverified | §6: set `CORS_ALLOWED_ORIGINS`, verify Tauri Origin in dry-run. |
| 4 shops vs "claim 3 terminals" | CONFIRMED typo | §6: fixed to 4 terminals. |
| Terminals must be active+unclaimed | CONFIRMED — existing seeder already does (`is_active=true`, `hardware_identifier=NULL`) | §5.1: preserve when porting. |
| Enrichment branch hedge stale | CONFIRMED — already on this branch | §5.6/§11: hedge removed; env `SYNERIVA_*`. |
| Recovery command absent from runbook | CONFIRMED (name `fiscal:enqueue-resolved-event-projections`, actor=**user** id) | §5.5/§7. |
| Chain genesis on terminal creation | CONFIRMED | §5.1: noted, no separate init. |

**My verdict:** APPROVE-WITH-EDITS (edits applied above). The two BLOCKERs do not hold as stated; the real must-fixes were the matricule-fiscal format, the additive-guard, the Horizon/recovery runbook, and the Z-report/stamp-duty caveats.
