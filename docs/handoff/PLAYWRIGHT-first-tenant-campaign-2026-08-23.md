# Playwright first-tenant campaign — 2026-08-23

**Purpose:** end-to-end rehearsal of tenant #1 (Tunisian parapharmacy) against the accumulated local `dev` tree,
driven entirely through the real UI with Playwright, with DB verification wherever money or stock moved.
Run as Session A's finishing deliverable before an owner-authorised promotion.

**Tree under test:** local `dev` @ `8a5bcc513` (`reviews: X/Z r2 + membership r2 gate records`) — the 12 lanes
merged 2026-08-23 stacked on the 15 merged 2026-08-21/22.
**Verdict:** ⛔ **NOT promotion-ready.** Two P0 correctness defects and three P1 blockers found, all NEW
(not on the known-defect carve-out list). Detail in §Verdict.

---

## 0. Environment — what was actually served

| Component | State | Evidence |
|---|---|---|
| API | `php artisan serve` **restarted** on the checkout under test; `config:clear` + `route:clear` + `cache:clear` first | `GET /api/v1/health` → `{"status":"healthy","timestamp":"2026-08-23T19:44:33+00:00"}` (200). Note: the health route is `/api/v1/health`, not `/api/health` — the probe 404 in the brief was a wrong path, not a dead server. |
| Queue worker | **restarted** on current code: `queue:work redis --queue=default,fiscal-projections,enrichment,images,imports` | new PID, `nohup` log clean |
| Web | vite dev on :5173, **restarted mid-campaign with `node_modules/.vite` cleared** (see N-14 — the restart materially changed one finding) | `VITE v7.3.3 ready` |
| Web (prod) | `npx vite build --mode production` → **succeeded** (18.77s), served on :4173 through an ad-hoc proxy for a control test | see N-14 / R-1 |
| Central migrations | `php artisan migrate --force` → **"Nothing to migrate"** — central schema already current | — |
| Tenant migrations | `php artisan tenants:migrate-rolling --force` → **"No tenants to migrate"** — the central DB had **zero** tenants, so this was a genuinely clean first-tenant run. All tenant migrations (including today's self-guarding ones) were applied by the provisioning path during signup, which is stronger evidence than a retrofit. | `select count(*) from tenants` = 0 pre-signup |
| Seed | `PlansSeeder` only (`plans` was empty; signup needs a plan). No demo data. | 6 plans |

Tenant provisioned by the campaign: `01a03028-9470-70e6-83ca-cdc354f17cf1`,
DB `tenant01a03028-9470-70e6-83ca-cdc354f17cf1`, domain `parabio-tunisie-sarl-sklvg9.synerivia.tn`.
Screenshots: `.playwright-mcp/campaign/` (git-excluded via `.git/info/exclude`).

---

## 1. Flow-by-flow

### F1 — Self-service signup (owner ruling B-1) — **PASS**

`/register`, 4 steps: account → country **Tunisia** + business type **Parapharmacy** → company
`ParaBio Tunisie SARL` + phone → review + ToS.
`POST /api/v1/auth/register` → **201**, then `auth/me` 200, `user/companies` 200, `locations` 200,
`company/config` 200 — landed on `/reports` (Owner Dashboard), fully rendered.

DB verification:

```
locations:  Main Location | MAIN | is_active=t | pos_enabled=t | shop      <- B-3 merge VERIFIED
companies:  ParaBio Tunisie SARL | TN | TND | fr | Africa/Tunis | default_tax_rate 19.00
            pos_stock_policy=block | inventory_costing_method=weighted_average
country_tax_rates (TN): TVA 19% (default) / TVA 13% / TVA 7% / Exonéré
payment_repositories: Caisse principale (cash_register), Coffre-fort (safe) — both balance 0.000
accounts: 141 rows (TN chart), incl. 53 Caisse and 119 Solde d'ouverture
```

Screenshots: `f01-signup-review.png`, `f01-post-signup-landing.png`.

### F2 — Onboarding surfaces — **PASS (with N-9, N-10, N-12)**

- **Setup checklist** `/settings/setup`: 3 of 7 at signup (Tax Configuration ✓, Payment Methods ✓,
  Payment Repositories ✓; Company Information ⚠). After the campaign's work: **5 of 7, "Setup complete!
  You're ready to start selling."** `f02-setup-checklist.png`, `f02-setup-checklist-complete.png`.
- **Company identity** `/settings/company`: Country and Currency are **disabled** with the explanatory note
  ("fixed after provisioning") — the settings-identity-guards lane behaves as designed. Editing the
  Matricule Fiscal raised a **"Confirm fiscal identity change"** modal before saving;
  `PATCH /api/v1/settings/company` → **200** only after confirming. PASS.
- **Locations** `/settings/locations`: Main Location shows the **POS Enabled** badge. Created
  `Boutique Lac 2` (LAC2) with the *Enable Point of Sale* checkbox ticked → `POST /locations` **201**,
  second card renders with its own **POS Enabled** badge. `f02-locations.png`. PASS.
- **Taxes** `/settings/tax`: VAT Registered (Assujetti), default 19,00 %. *Tax Types* tab lists the full
  TN set including stamp duty at correct TND amounts: `TVA 19/13/7`, `Exonéré TVA 0%`,
  `Timbre Fiscal - Facture 1.000` (active), `- Avoir 0.600` (active), `- Ticket 0.100` (inactive).
  `f02-tax-settings.png`. PASS.
- **Payment methods** `/treasury/payment-methods`: 7 seeded in French — Espèces, Chèque, Virement
  Bancaire, Traite, Carte Bancaire, Portefeuille Digital, Points de Fidélité, with correct capability
  flags (Physical / Has Maturity / Third Party / Deducted Fees). PASS — but see **N-10**.
- **Units of measure** `/settings/units`: **"No units found"** — see **N-9**.

### F3 — Catalog — **PASS (with N-1 discovered here, N-13)**

Four products created through the full product form. No 0 %-VAT product was created (per B-19/7b).

| SKU | Name | Sale price HT | VAT selected in UI | `products.tax_rate` persisted |
|---|---|---|---|---|
| VITC1000 | Vitamine C 1000mg — 20 comprimés | 12.500 | TVA 19 % | 19.00 ✅ |
| CREM-ALOE200 | Crème hydratante Aloe Vera 200ml | 24.900 | TVA 19 % | 19.00 ✅ |
| SERU-PHY20 | Sérum physiologique 5ml x20 | 6.200 | **TVA 7 %** | **19.00 ❌** |
| LAIT-INF400 | Lait infantile 1er âge 400g (batch-tracked) | 32.000 | **TVA 13 %** | **19.00 ❌** |

`requires_batch_tracking = t` on LAIT-INF400 ✅. `default_tax_configuration_id` is correct on all four
(TVA 7 %, TVA 13 % resolve properly) — only the denormalised `tax_rate` column is wrong. → **N-1**.

### F4 — Partners — **PASS (known defect a3 re-verified, not re-reported)**

- Customer `Sonia Trabelsi`, category *Individual*, phone `+21698123456`, TN. Created, listed.
- Supplier `Laboratoires Médis SA`, *Business*, VAT number `0912345BAM000`, TN, via the **full** form.

DB: `vat_number` persists correctly; **`tax_id` is empty** because the full form has **no `tax_id` field at
all** (only `vat_number`), while the list's TAX ID column reads `tax_id` → renders `-`. This is known
defect **a3**, and the campaign extends it: the defect is not confined to the inline modal — the full form
cannot populate that column either.

### F5 — Opening balances runbook rehearsal — **⛔ BLOCKED in the UI (N-3); guards PASS below the UI**

Following `RUNBOOK-opening-cash-float-2026-08-23.md` §1: ACCOUNTING batch, 500 TND float,
Dr `53 Caisse` / Cr `119 Solde d'ouverture`.

| Step | Result |
|---|---|
| 1 Setup (name + cutover 2026-08-23) | ✅ `POST …/opening-batches` **201** |
| 2 Upload CSV (`account_code,debit,credit,reference`) | ✅ parsed, 2 rows, `…/import` **200** — but the whole step renders raw i18n keys (**N-7**) |
| 3 Validate | ✅ `…/validate` **200**, both rows VALID |
| 4 **Preview** | ⛔ **PAGE CRASHES** into the ErrorBoundary — `TypeError: Cannot read properties of undefined (reading 'cutover_date')` → **N-3**. Reproducible: reload lands on Upload, Next → Validate → Preview → crash again. |
| 5 Post | not reachable through the UI |
| 6 Lock | not reachable through the UI |

Because the wizard is dead at step 4, the remaining runbook assertions were exercised **against the same
API from the authenticated browser session** (labelled as such, not claimed as UI coverage):

```
POST …/opening-batches/{id}/post      -> 200  status LOCKED, locked_at set, hash set,
                                              journal_entry OB-2026-000001 (2 lines)   <- auto-lock in the same txn ✅
POST …/post      (again)              -> 422  POST_FAILED         "Cannot post batch in Locked status."   ✅ double-post guard
POST …/validate  (on locked batch)    -> 422  VALIDATION_FAILED   "Cannot validate batch in Locked status." ✅ H-2 guard
POST …/lock      (on locked batch)    -> 422  BATCH_LOCK_FAILED                                            ✅
```

DB: `opening_balance_batches` → `LOCKED`, hash present. `journal_lines` → `53 Caisse` Dr 500.000 /
`119 Solde d'ouverture` Cr 500.000, balanced.

**b2-b6i guard — PASS, message reaches the operator.** Treasury → *Caisse principale* → **Adjust balance**,
500 TND, reason "Count variance" → `POST /payment-repositories/{id}/adjustments` **422**:

```
{"error":"Caisse principale (CASH-01) has never held money, so there is no balance to adjust.
 If you are entering an opening cash float, it is an accounting opening balance: enter it in
 Settings → Opening balances. Recording it here would book it as income instead.",
 "code":"REPOSITORY_NOT_SEEDED"}
```

> **Retracted mid-campaign.** My first two attempts showed no visible error and I nearly filed "the guard's
> message never reaches the operator" as a defect. That was a screenshot-timing artifact — the sonner toast
> had already auto-dismissed. Re-run with a `MutationObserver` armed on the toaster **before** the click, the
> full guard message is captured verbatim in the toast. **No defect.** `f05-repository-not-seeded-refusal.png`
> shows the dialog after dismissal, not an absence of feedback.

### F6 — Documents (quote → order → invoice → payment) — **PARTIAL: N-1 confirmed live, N-2 blocks the order arm**

**Quote.** `QT-2026-0004`, customer Sonia, 3 lines. The per-line tax dropdown correctly shows **TVA 7 %**
and **TVA 13 %** for those products — **and the money is computed at 19 % anyway**:

| Line | HT | Line total shown | Implied rate |
|---|---|---|---|
| Vitamine C (19 %) | 12.500 | 14,875 | 19 % ✅ |
| Sérum physiologique (**7 %**) | 6.200 | **7,378** | **19 % ❌** (7 % → 6,634) |
| Lait infantile (**13 %**) | 32.000 | **38,080** | **19 % ❌** (13 % → 36,160) |
| | Subtotal 50,700 | **Tax 9,633** = 50,700 × 0,19 | |

`document_lines.tax_rate = 19.00` on all three rows in the DB. `f06-quote-lines-tax.png`. → **N-1**.
The operator sees the *correct* rate selected and the *wrong* tax charged; nothing warns them.

**Confirm quote** → 200 ✅. **Convert to Order** → `SO-2026-0001` created ✅ with a correct document chain
(Quote → Sales Order shown in Related Documents).

**Confirm sales order** → ⛔ **404** `No query results for model [App\Modules\Inventory\Domain\StockLevel]`
with a raw Laravel debug stack. → **N-2**.

**Invoice** (created directly): `INV-2026-0003`, 2 lines, Subtotal 37,400 + TVA 19 % 7,106 +
**Stamp Duty 1,000** = 45,506 TND — the TN *Timbre Fiscal - Facture* is applied correctly ✅.
Confirm → 200 ✅.

**Post invoice** → **422**, correctly:

```
DELIVERY_REQUIRED_BEFORE_INVOICE — "This invoice contains physical goods that have not been delivered.
Under this country's accounting rules a definitive goods invoice cannot be issued before delivery…"
policy: require_delivery_first, policy_source: country
alternatives: create_and_confirm_delivery_note | order_with_advance_payment
```

This guard is **working as designed**. Its offered remedy is not: clicking through to
`POST …/create-delivery-and-post` → **404**, same missing-`StockLevel` failure (**N-2** again), so the
operator is offered an exit that is itself broken.

**Record payment** → `POST /payments` **201**, cash 45,506 into *Caisse principale*.
DB: repository balance `45.506`, 1 `repository_movements` row, `JE-2026-000001` posted and balanced —
**Dr 53 Caisse 45.506 / Cr 411 Clients 45.506** ✅. But the invoice was never posted, so nothing ever
debited 411 → **AR is now −45,506 TND** and no revenue or output VAT was recognised. → **N-6**.

**Purchase arm — PASS.** `PO-2026-0003` (50 × Vitamine C @ 8.000) → confirm ✅ → *Receive Goods*
(destination Main Location, qty 50) → `GRN-2026-0001` posted:

```
stock_levels:  VITC1000 | 50.0000 | Main Location            ✅
JE-2026-000002 (goods_receipt):  Dr 37 Stocks 400.000 / Cr 408 Fournisseurs FNP 400.000   ✅
```

Supplier invoice `SI-2026-0001` from the receipt (3-way match: ordered 50 / received 50 / matchable 50),
posted:

```
JE-2026-000003 (supplier_invoice):  Dr 408  400.000
                                    Dr 4456 TVA déductible 76.000
                                    Cr 401  Fournisseurs 476.000                          ✅ balanced
```

So supplier-invoice VAT **does** reach `4456` in the GL. It does **not** reach the VAT declaration — see F8.
That is the known **B-19** hole; recorded, not fixed.

**Final trial balance (whole tenant): 1421.506 Dr = 1421.506 Cr — in balance.**

| Account | Dr | Cr | Net |
|---|---|---|---|
| 119 Solde d'ouverture | 0.000 | 500.000 | −500.000 |
| 37 Stocks de marchandises | 400.000 | 0.000 | 400.000 |
| 401 Fournisseurs | 0.000 | 476.000 | −476.000 |
| 408 Fournisseurs FNP | 400.000 | 400.000 | 0.000 |
| **411 Clients** | 0.000 | 45.506 | **−45.506** ← N-6 |
| 4456 TVA déductible | 76.000 | 0.000 | 76.000 |
| 53 Caisse | 545.506 | 0.000 | 545.506 |

**Document numbering.** Sequences allocate correctly per family (`QT-`, `SO-`, `INV-`, `PO-`, `GRN-`, `SI-`,
`OB-`, `JE-`) — but auto-save consumed numbers ahead of the user in the dev bundle; see **N-14**.

### F7 — Auto-save hardening probe (today's P1) — **known R-1 REPRODUCED; new dev-only N-14**

Keystroke auto-save fires and succeeds: `POST /api/v1/documents/auto-save` → **200**
`{"draft_id":"01a03047-…","saved_at":"…","line_count":1}`.

**What actually happens on the second auto-save — two different answers, and the difference matters:**

| Bundle | 2nd auto-save request | Outcome |
|---|---|---|
| **dev** (vite, StrictMode), even after a full restart with `.vite` cleared | `"draft_id": null` | a **new** draft document per auto-save; `QT-2026-0001/0002/0003` were three orphan drafts with 0 totals, `INV-2026-0001/0002` likewise, before the user ever pressed Save → **N-14** |
| **production build** (`vite build`, served on :4173) | `"draft_id":"01a03052-…"` ✅ round-trips | one draft — and that draft ends **lineless**: `QT-2026-0007` → **0 lines**, total 0.000 → **R-1, exactly as ticketed** |

So the honest answer to "does the SECOND auto-save empty the lines?" is **yes — on the artifact that ships**.
R-1 (`docs/superpowers/tickets/2026-08-23-autosave-residuals.md:23-69`, marked `[P1, LIVE]`) is confirmed
live on the production build of this tree. It is on the known-defect list and is **not** re-reported as new.
The dev-only masking is new and is filed as N-14 because it hides R-1 from anyone testing in dev.

Line payloads carry client-minted ids (`line-1787516370341-jyjsj4da5`) — the R-1 mechanism — and
`tax_rate: "19.00"`, which is the client half of **N-1**.

### F8 — Reports — **owner dashboard PASS; VAT declaration ⛔ CRASHES (N-4)**

- **Owner dashboard** `/reports`: renders fully, headline is **"Net sales (excl. refunds)"** — O-28 verified.
  All 20+ report calls 200. Tiles: Transactions, Average basket (gross), Items sold, Returns; Sales by
  Location, Sales over time, Branch leaderboard, Live sales, Top SKUs, Revenue by Category, Payment Method
  Breakdown, Low Stock Alerts, Cash Register Reconciliation, Cash across stores, Due this week, Rebalance
  alerts. `f08-owner-dashboard.png`.
- **Dashboard** `/dashboard`: renders; cash position reads `1 cash register / 0 bank accounts / 1 safe`.
- **VAT reporting** `/finance/vat-periods`: *Generate Periods* → **201**, 12 monthly 2026 periods created ✅.
  Opening the August 2026 period → ⛔ **PAGE CRASHES** (`VatReportPage.tsx:55`,
  `Cannot read properties of undefined (reading 'label')`) → **N-4**. `f08-vat-report-crash.png`.

  The **data underneath is correct** and does include `document_count` — from the raw
  `GET /vat/reports/{id}/summary` (200):

  ```json
  {"output_vat":{"total_base":"37.400","total_vat":"7.106",
     "breakdowns":[{"direction":"OUTPUT","tax_rate":"19.00","base_amount":"37.400",
                    "vat_amount":"7.106","document_count":1,"is_recoverable":true, …}]},
   "input_vat":{"total_base":"0.000","total_vat":"0.000","breakdowns":[]},
   "amount_payable":"7.106",
   "special_items":{"stamp_duty_count":1,"stamp_duty_total":"1.000","retenue_source_total":"0.000"},
   "declaration":{"form_reference":"DGI","fields":{"base_19":"37.400","vat_19":"7.106", …}}}
  ```

  `document_count: 1` renders in the payload but **cannot be seen by a human** because the page dies first.
  Two further observations from this payload: **input VAT is 0.000 despite `4456` carrying 76.000 TND** from
  the posted supplier invoice (this is **B-19**, now demonstrated end-to-end); and the declaration counts a
  **confirmed-but-unposted** invoice in output VAT.
- **General Ledger** `/finance/ledger`: renders all 4 journal entries correctly — but formats every amount
  as `$45.5060`. → **N-8**. `f10-ledger-usd-formatting-defect.png`.
- **Finance overview** `/finance/overview`: renders; Assets 976,000 / Liabilities 476,000, AR −45,506
  (N-6 again), Net Income 0 (correct — no revenue recognised).

### F9 — Offboarding / PIN revocation (today's membership lane) — **PASS, with N-5**

Created cashier `Karim Jebali` (role Cashier) via *Add User* → user row `pending_verification`, membership
`active`. Set a POS PIN via `PATCH /users/{id}/pos-pin` (payload key is `pin`, not `pos_pin`).

While `pending_verification` the user is **correctly absent** from `/pos/auth/pin-data` — the belt requires
membership Active **and** user Active. Test fixture: flipped `users.status` to `active` in psql, at which
point pin-data correctly returns him with `pin_hash`, `roles:["cashier"]` and his permission set.

**Deactivate** via *Users → Actions → Deactivate* (confirm dialog):

```
users:                     Karim Jebali | inactive
user_company_memberships:  status = revoked, revoked_at = 2026-08-23 20:48:36   ✅ cascade
GET /pos/auth/pin-data  -> {"data":[]}                                          ✅ vanished
GET /pos/auth/has-pins  -> {"data":{"has_pins":true}}                           ❌ N-5
```

### F10 — Everything else reachable

| Surface | Result |
|---|---|
| `/settings/import` (B-4) | **PASS** — exactly two primary cards, *Business partners* and *Products*, with correct ordering guidance and the "stock only via Opening Balances / GRN" note. No duplicate parties-vs-partners cards. |
| `/treasury/repositories` + detail | PASS — 2 repositories, GL account `53 - Caisse` shown, movements tab, Transfer cash |
| `/treasury/payments` | PASS — 1 payment, "Payment 1 for INV-2026-0003", Espèces, Completed, 45,506 TND |
| `/finance/overview`, `/finance/ledger` | render (ledger has N-8) |
| `/pos/terminals` | PASS — "No active terminals found" empty state |
| Customer detail | PASS — Overview / Documents (10) / Payments (1) / Deposits / Delivery notes tabs. **No Statement tab** → known "customer-statement UI missing", confirmed in path. |
| i18n **FR** | **PASS** — full nav + page translated (Tableau de bord, Devis, Avoirs, Bons de livraison, Partenaires commerciaux…). `f10-french-locale.png` |
| i18n **AR** (smoke only) | RTL plumbing correct: `<html dir="rtl" lang="ar">`; content falls back to English on most namespaces — expected, **B-7 deferred**. `f10-arabic-rtl-smoke.png` |

---

## 2. PASS / FAIL / BLOCKED table

| # | Flow | Result | Note |
|---|---|---|---|
| 1 | Self-service signup + tenant provisioning | **PASS** | B-3 `pos_enabled=true` verified in DB |
| 2a | Setup checklist | **PASS** | 3/7 → 5/7 → "ready to start selling" |
| 2b | Company identity (+ fiscal-change confirm) | **PASS** | country/currency locked as designed |
| 2c | Locations (2nd location, POS toggle + badge) | **PASS** | |
| 2d | Taxes (TN rates + stamp duty) | **PASS** | |
| 2e | Payment methods | **PASS** | N-10 (raw i18n key in Actions column) |
| 2f | Units of measure | **FAIL** | **N-9** — none seeded |
| 3 | Catalog — 4 products, varied VAT, batch tracking | **FAIL** | **N-1** — non-default VAT silently dropped |
| 4 | Partners (customer + supplier, full form) | **PASS** | known a3 re-verified (full form has no `tax_id` field) |
| 5a | Opening-balance batch through the wizard | **BLOCKED** | **N-3** — crash at Preview; wizard cannot reach Post |
| 5b | Post → auto-lock, double-post refusal, H-2 re-validate refusal | **PASS** (API-level) | 200 / 422 / 422 / 422; GL balanced |
| 5c | `REPOSITORY_NOT_SEEDED` on virgin repository | **PASS** | 422 + message reaches the operator via toast |
| 6a | Quote create / confirm / convert to order | **PASS** | |
| 6b | Quote/invoice line tax resolution | **FAIL** | **N-1** live: 7 % and 13 % products taxed at 19 % |
| 6c | **Sales-order confirm** | **FAIL** | **N-2** — 404 missing StockLevel |
| 6d | Invoice create + confirm + stamp duty | **PASS** | Timbre Fiscal 1,000 TND correct |
| 6e | Invoice post | **BLOCKED (by design)** | `DELIVERY_REQUIRED_BEFORE_INVOICE` correct… |
| 6f | …its offered remedy `create-delivery-and-post` | **FAIL** | **N-2** — 404, same cause |
| 6g | Record payment + GL | **PASS / FAIL** | JE balanced (Dr 53 / Cr 411) but **N-6**: AR goes −45,506 |
| 6h | Purchase order → goods receipt → stock + GL | **PASS** | stock 50, Dr 37 / Cr 408 |
| 6i | Supplier invoice post + 3-way match | **PASS** | Dr 408 + Dr 4456 / Cr 401, balanced |
| 6j | Trial balance | **PASS** | 1421.506 = 1421.506 |
| 7 | Auto-save | **FAIL** | **R-1 (known) reproduced on prod build** — draft ends lineless; **N-14** masks it in dev |
| 8a | Owner dashboard (O-28 "Net sales excl. refunds") | **PASS** | |
| 8b | VAT period generation | **PASS** | 12 periods |
| 8c | **VAT declaration screen** (`document_count`) | **FAIL** | **N-4** — page crashes; data correct underneath |
| 8d | General Ledger | **FAIL** | **N-8** — `$` + 4dp on a TND tenant |
| 9 | Offboarding: user + PIN → deactivate → revoke | **PASS** | cascade + pin-data belt work; **N-5** on `has-pins` |
| 10a | Imports dashboard (B-4) | **PASS** | |
| 10b | Treasury screens | **PASS** | |
| 10c | Customer statement | **BLOCKED (known)** | no UI, as documented |
| 10d | i18n FR | **PASS** | |
| 10e | i18n AR | **DEFERRED (B-7)** | RTL correct, strings fall back to EN |

---

## 3. NEW defects (none on the known-defect carve-out)

### N-1 — **P0** — A product's non-default VAT rate is silently discarded; everything is taxed at the company default

**Symptom.** Select *TVA 7 %* on a product; `products.default_tax_configuration_id` stores TVA 7 % correctly,
but `products.tax_rate` keeps the company default `19.00`. Documents and POS read the latter. The UI shows
the right rate selected and charges the wrong one.

**Mechanism (verified end to end):**
- `apps/web/src/features/inventory/productPayload.ts:34-48` destructures `tax_rate` **out** of the create
  payload, on the premise (stated in its header comment, lines 10-12) that the API *"re-derives anyway from
  `default_tax_configuration_id`"*. **It does not.**
- `apps/api/…/Product/Presentation/Controllers/ProductController.php:414-419` falls back to
  `TaxResolutionService::getDefaultTaxForNewProduct()`, which reads only `categories.default_tax_rate` then
  `companies.default_tax_rate` (`TaxResolutionService.php:77-91`) — it never consults
  `tax_configurations.percentage_rate`. `ProductController::update()` has **no tax handling at all**, so the
  stale value never self-heals.
- **POS is unconditional:** `POS/Application/Services/ReceiptCreationService.php:224-225` reads
  `$product->tax_rate` directly; `StoreReceiptRequest` has no `lines.*.tax_rate` rule, so the client cannot
  override it. Wrong VAT lands in `pos_receipt_lines` / `pos_receipt_vat_details` and is **hash-chained**.
- **Documents:** `DocumentLineTaxResolver.php:41-75` *would* prefer the configuration (branch 3) — but
  `DocumentForm.tsx:504-507` sends `tax_rate` explicitly and omits `tax_configuration_id`, so branch 1
  short-circuits. Captured in the live auto-save payload: `"tax_rate":"19.00"`.
- **Confirmed in the UI:** 6.200 HT @ "TVA 7 %" → line total **7,378** (= ×1,19). 32.000 HT @ "TVA 13 %" →
  **38,080** (= ×1,19). Document total tax 9,633 = 50,700 × 0,19 exactly. `document_lines.tax_rate = 19.00`.

**Why this is P0 for tenant #1.** A Tunisian parapharmacy sells 7 % (medical devices) and 13 % goods
routinely. Every such sale over-charges VAT and mis-declares it, and on the POS arm the error is sealed into
the fiscal chain. Fixing `ProductController::store`/`update` to derive `tax_rate` from the chosen
configuration (plus a backfill) repairs both arms; fixing only the frontend document payload repairs
documents alone.

### N-2 — **P0** — Sales-order confirm (and delivery-note confirm) 404 for any product with no `stock_levels` row

**Symptom.** `POST /api/v1/orders/{id}/confirm` → **404**
`No query results for model [App\Modules\Inventory\Domain\StockLevel]`, raw Laravel exception body, no
user-facing message. Order stays Draft.

**Mechanism.** `Inventory/Application/Services/StockReservationService.php:113-119` does
`StockLevel::where(product, location, company)->lockForUpdate()->firstOrFail()`. Reached from
`SalesOrderService.php:144` on confirm. `ModelNotFoundException` extends `RuntimeException`, so the
controller's `catch (\DomainException)` at `SalesOrderController.php:523` does not catch it.

**No configuration spares a new tenant.** `ReservationSettings::$autoReserveOnSalesOrder` defaults `true`
(`ReservationSettings.php:28`, `:110`), `companies.reservation_settings` is left NULL by
`TenantProvisioningService.php:156`, and `Company::getReservationSettings()` (`Company.php:553-562`) returns
the reserve-on default for NULL. `pos_stock_policy` is irrelevant here. Products default `is_physical = true`
and nothing seeds a `stock_levels` row at product creation.

**Blast radius.** The same class of bug sits at `WeightedAverageCostService.php:392-397`, reached from
delivery-note confirm (`DeliveryNoteService.php:278`) and therefore from
`invoices/{id}/create-delivery-and-post` and `invoices/{id}/confirm-deliveries-and-post` — which is exactly
the remedy the (correct) `DELIVERY_REQUIRED_BEFORE_INVOICE` guard offers the operator. Confirmed live: that
call also 404'd. The goods-movement lane is defensive by contrast
(`StockAdjustmentService.php:1607-1670` uses `firstOrCreate`); the reservation and WAC-sale lanes are not.

**Why no test caught it:** `SalesOrderDocumentTest.php:109-126` confirms an order with **zero lines**;
every other test pre-creates `StockLevel` rows. The empty-`stock_levels` state is never exercised.

**Verified after receiving goods:** with `VITC1000` stocked (50 units) the confirm still 404s, because the
other two lines still have no stock level — consistent with the diagnosis.

### N-3 — **P1** — The opening-balances ACCOUNTING wizard crashes at Preview, so today's own runbook cannot be executed through the UI

`BatchPreview.tsx:306` reads `preview.batch.cutover_date`; the API's
`GET …/opening-batches/{id}/preview` returns `{entry:{entry_date, description, is_historical, source_type},
lines:[…], totals:{…}}` — **there is no `batch` key**. `preview.batch` is `undefined` → TypeError →
ErrorBoundary takes the whole page. The FE type `PostPreview` (`features/opening-balances/types/index.ts:89-96`)
declares a `batch` object the API never sends.

Consequence: `RUNBOOK-opening-cash-float-2026-08-23.md` §1 instructs the operator to post the opening cash
float here, and step 2 now *mechanically refuses* the only alternative (`REPOSITORY_NOT_SEEDED`, N/A-by-design).
An operator following the runbook to the letter has **no working path** to book the opening float.
`f05-opening-balances-preview-crash.png`.

### N-4 — **P1** — The VAT declaration screen crashes; `document_count` is computed but unviewable

`VatReportPage.tsx:55` reads `report.period.label` (and `.status`, `.id`); the summary payload from
`GET /vat/reports/{id}/summary` contains **no `period` key**. Same API↔FE contract class as N-3. The whole
page goes to the ErrorBoundary, so the declaration — including the `document_count: 1` this campaign was
asked to verify, and the DGI field block — is unreachable for a human.
`f08-vat-report-crash.png`.

### N-5 — **P1** — `GET /pos/auth/has-pins` ignores the offboarding belt (and company scoping)

`PosAuthController.php:321-323`:

```php
$hasPins = User::where('tenant_id', $currentUser->tenant_id)
    ->whereNotNull('pos_pin')
    ->exists();
```

No `company_id`, no `users.status = active`, no membership check — unlike `pinData()` at `:61-68`, which
applies all three. The file's own comment at `:55` asserts the surfaces *"admit precisely the same
population"*; they do not.

**Observed:** after the fired cashier's cascade (membership `revoked`, `pin-data` → `[]`), `has-pins` still
returns `true`, because deactivation leaves the `pos_pin` hash on the row. A device that asks "are PINs
configured?" is told yes and then syncs an empty PIN set → PIN screen with zero valid PINs. The missing
`company_id` filter is a second, independent leak in a multi-company tenant.

### N-6 — **P1** — A payment can be taken against an invoice the pre-delivery policy forbids posting, leaving AR negative and revenue unrecognised

`POST /invoices/{id}/post` correctly refuses with `DELIVERY_REQUIRED_BEFORE_INVOICE`, but *Record Payment* on
the same confirmed invoice succeeds (`POST /payments` 201). Cash and the GL move
(`JE-2026-000001`: Dr 53 / Cr 411) while the invoice side of 411 is never debited.

Result on a tenant with exactly one sale: **`411 Clients` = −45.506**, output VAT 7.106 never recognised in
the GL, Net Income 0. The customer detail page shows *"Total Receivable −45,506 TND"* and Finance Overview
shows *"Accounts Receivable −45,506 TND"* — the wrong number is prominently displayed to the owner. Either
payment must be gated by the same policy, or it must land as a customer advance rather than an AR clearing.

### N-7 — **P2** — The opening-balances wizard renders raw i18n keys from step 2 onward

`openingBalances.types.accounting.uploadHelp`, `openingBalances.upload.expectedColumns`,
`…downloadTemplate`, `…dragDropText`, `…supportedFormats`, `…rowsFound`, `…uploadButton`,
`openingBalances.validation.*`, `openingBalances.wizard.validate.validateButton`. Verified absent from
`src/locales/{en,fr,ar}/common.json` (which has `openingBalances` but no `upload` sub-tree and no
`types.accounting.uploadHelp`). Rule-11 violation on the documented go-live path.
`f05-opening-balances-raw-i18n-keys.png`.

### N-8 — **P2** — General Ledger prints `$` and 4 decimals for a TND tenant

`features/finance/components/LedgerTable.tsx:12-15`:

```ts
function formatAmount(value: string): string {
  if (value === '0.00' || value === '0') return ''
  return `$${value}`
}
```

Hardcoded dollar sign, no currency-aware formatter, no scale. Renders `$45.5060`, `$400.0000` on a
`decimal(N,3)` TND company where every other screen correctly shows `45,506 TND`. The zero-check also
compares against 2-decimal strings while the API returns 3-4 decimals, so zeroes print as `$0.0000` instead
of blank. `f10-ledger-usd-formatting-defect.png`.

### N-9 — **P2** — No units of measure are seeded on tenant provisioning

`units` table is **empty** after signup. `/settings/units` says *"System units are available to all tenants
and cannot be modified"* and then *"No units found"*. The product form's **Unit of Measure** select offers
only "Select a unit". All four products persisted `unit = 'pcs'` (a bare string) with `unit_id = NULL`.

Downstream: the UoM display-precision contract (rule 19) reads `units.decimal_places`; with no unit row
every quantity falls back to 4 decimals — visible throughout as `1.0000`, `50.0000`, and *"Items sold
0.0000"* on the owner dashboard.

### N-10 — **P2** — `common:actions.deactivate` / `activate` missing; Payment Methods shows the raw key

`features/treasury/PaymentMethodsPage.tsx:181` calls `t('common:actions.deactivate')`. Neither key exists in
`src/locales/{en,fr,ar}/common.json` under `actions` (verified by parsing all three files). The Actions
column literally reads **`actions.deactivate`** on all seven rows.
`f02-payment-methods-i18n-defect.png`.

### N-11 — **P3** — `status.paid` raw key in the payment-success dialog

After recording a payment the confirmation panel reads *"Document Status — INV-2026-0003 — `status.paid`"*.
`f06-payment-recorded-raw-i18n-key.png`.

### N-12 — **P3** — Seeded payment repositories have `location_id = NULL`

`Caisse principale` and `Coffre-fort` are provisioned without a location, so the owner dashboard's
*"Cash across stores"* widget files all cash under **"Unattributed"** and the Repositories table shows `-`
in the Location column, from day one on a single-location tenant.

### N-13 — **P3** — Product form: required-field failure is invisible above the fold

Saving a product without the required *Parapharmacy → Product Category* does nothing visible: no toast, no
scroll, `window.scrollY` stays 0. The inline *"This field is required"* is rendered far below the fold in a
section the user may never have opened. Reads as a dead Save button.
`f03-product-save-silent-noop.png`.

### N-14 — **DEV-ONLY, but latent** — `useDraftAutoSave` never resets `isUnmountedRef`; under StrictMode every auto-save authors a new document

`hooks/useDraftAutoSave.ts:253-260` sets `isUnmountedRef.current = true` in an unmount cleanup and never
resets it to `false` on mount. React StrictMode (`main.tsx:20`) double-invokes effects **in development**, so
the cleanup fires once during the initial mount and the hook is permanently "unmounted": the
`if (!isUnmountedRef.current)` guard at `:166` blocks `setDraftId(body.draft_id)` at `:167`, every subsequent
auto-save posts `draft_id: null`, and the backend's create-on-miss fallback
(`DraftPersistenceService.php:93-95`, ticketed as **R-7**) authors a fresh document **and burns a document
number** (`DocumentNumberingService.php:63-64` via `DraftPersistenceService.php:193-197`).

**Measured in dev:** `QT-2026-0001/0002/0003` (0 totals, 1/2/3 lines) orphaned before `QT-2026-0004`;
`INV-2026-0001/0002` orphaned before `INV-2026-0003`; `PO-2026-0001/0002` orphaned before `PO-2026-0003`.
Reproduced after a full vite restart with `node_modules/.vite` cleared, so it is not a stale bundle.

**Not reproduced on the production build** (`draft_id` round-trips correctly on :4173) — so this is **not** a
production number-burn risk today, and I am not claiming it as one. It matters for two reasons: it is a
latent bug for any genuine remount, and — more importantly — **it masks R-1 from everyone who tests in dev**,
which is presumably why R-1 was characterised as theoretical. On the production artifact the draft ends
with **0 lines**.

---

## 4. Known defects encountered (recorded, not re-reported as new)

| Known item | Encountered? | What was seen |
|---|---|---|
| **R-1** auto-save line ids empty the draft | **YES — reproduced** | `QT-2026-0007` left with 0 lines on the production build |
| **B-19** supplier-invoice VAT hole | **YES — demonstrated** | `4456 TVA déductible` 76.000 posted in the GL, yet the declaration reports `input_vat.total_vat = "0.000"` with an empty breakdown |
| **a3** Tax ID column blank | **YES — and wider** | the *full* partner form has no `tax_id` field at all, only `vat_number`; the list column reads `tax_id` → `-` |
| customer-statement UI missing | **YES** | customer detail has Overview / Documents / Payments / Deposits / Delivery notes, no Statement |
| **B-7** Arabic deferred | **YES** | `dir="rtl" lang="ar"` applied correctly; strings fall back to English |
| buyer:null on device sales, **B-13** X-report gating | not reachable (device-only) | — |

---

## 5. Verdict

**The accumulated tree does not meet the owner's condition — "everything is correct, works fine, and has been
tested using Playwright". It has now been tested using Playwright, and it is not all correct.**

**Blocks promotion (must fix first):**

1. **N-1 (P0)** — every product sold at a non-default VAT rate is taxed at the company default. For a
   Tunisian parapharmacy this is routine, not an edge case, and on the POS arm the wrong VAT is sealed into
   the fiscal hash chain and flows to the DGI declaration. This is a *silent wrong number*, the worst class.
2. **N-2 (P0)** — sales-order confirm and delivery-note confirm 404 for any product without a
   `stock_levels` row, i.e. for every product on day one. It also breaks the escape hatch that the
   (otherwise correct) pre-delivery-invoicing guard offers, so an operator can be cornered with no legal path
   forward.
3. **N-3 (P1)** — the opening-balances wizard cannot reach Post. A runbook merged *today* instructs the
   operator to use precisely this screen, and the same day's hardening now refuses the workaround it warns
   against. The guards are right; the only sanctioned path is broken.
4. **N-4 (P1)** — the VAT declaration screen cannot render. A tenant that must file with the DGI cannot see
   its declaration.
5. **N-5 (P1)** — `has-pins` bypasses the offboarding belt shipped in the same lane. The lane's own comment
   claims parity between the two surfaces; there is none. This is a small, contained fix and it belongs with
   the lane it contradicts.

**Should be ruled on before go-live, not necessarily before promotion:**

6. **N-6 (P1)** — money can be taken against an invoice that cannot be posted, producing a negative AR the
   owner sees on two dashboards. This may be a deliberate posture (the guard's own text suggests recording an
   advance instead), but as built it books a *receivable clearing*, not an advance. Needs an owner ruling,
   not a guess.

**Accepted / already known, not blocking on their own:** R-1, B-19, a3, missing customer-statement UI, B-7.
R-1 is worth re-reading in light of N-14: it is live on the shipping artifact, and the dev environment hides it.

**What is genuinely solid, and worth saying plainly.** Self-service provisioning is clean end to end —
tenant DB creation, TN chart of accounts (141 rows), TN VAT rates and stamp duty, French payment methods,
`pos_enabled` on the auto-created Main Location, working setup checklist. The TN fiscal specifics are right:
Timbre Fiscal 1,000 on the invoice, `Dr 53 / Cr 411` on the payment, `Dr 37 / Cr 408` on the goods receipt,
`Dr 408 + Dr 4456 / Cr 401` on the supplier invoice, three-way match, and a trial balance that closes at
1421.506 both sides. The new guards behave exactly as designed and their messages are well written and
actually reach the operator: `REPOSITORY_NOT_SEEDED`, `DELIVERY_REQUIRED_BEFORE_INVOICE`, opening-batch
double-post and H-2 re-validate, the fiscal-identity confirmation, the country/currency lock, the membership
offboarding cascade, and the O-28 "Net sales (excl. refunds)" headline. The B-4 imports dashboard is clean
and the French locale is complete.

The pattern in the five blockers is worth naming: **three of them (N-3, N-4, and N-1's frontend half) are
API↔frontend contract drift** — the backend computes the right thing and the frontend reads a key that isn't
there, or sends a field it shouldn't. None of them would survive a contract test against a real payload.
That is the same failure mode the OpenAPI contract lane exists to catch, and it is currently catching none of
them.

---

*Campaign artifacts: `.playwright-mcp/campaign/` (18 screenshots, git-excluded). Tenant DB
`tenant01a03028-9470-70e6-83ca-cdc354f17cf1` left in place for inspection. No production code was modified;
the only writes were this file, the campaign's own tenant data, and one `users.status` fixture flip for F9.*
