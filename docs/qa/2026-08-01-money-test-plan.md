# Pre-Launch MONEY TEST PLAN — Web (Playwright) Campaign — 2026-08-01

> **Status:** AUTHORED, NOT EXECUTED. Every Status / Actual / Evidence cell is intentionally empty
> and stays empty until real execution on staging.
> **Scope owner:** first-tenant launch program (`docs/handoff/DISPATCH-PLAN-v5-first-tenant-2026-07-31.md`).
> **Companion gate sheet:** `docs/handoff/OWNER-manual-launch-gates-2026-07-31.md`.
> **Companion protocols:** `docs/qa/2026-05-12-first-tenant-smoke.md` (day-of smoke, narrower),
> `docs/qa/desktop-protocols/` (tester-facing desktop protocols).

## 0. What this document is

This is the **execution script for a Playwright-driven staging test campaign covering everything
money-related that is reachable through the WEB interface** — the `apps/web` React SPA talking to
`apps/api`. It is deliberately exhaustive on fiscal and cash surfaces and deliberately shallow on
cosmetics.

**Out of scope for Playwright:** the offline-first POS desktop application (`apps/pos`, Tauri 2).
It cannot be driven by Playwright (native shell, local SQLite, device-authored fiscal chain) and
gets a **separate computer-use campaign**. §Z of this document enumerates exactly what that
campaign must cover so that *web campaign ∪ POS campaign = complete money coverage*. Nothing in
§Z is duplicated in the web tables; nothing in the web tables is assumed to be covered by §Z.

### 0.1 Branch premise — write and run against POST-MERGE behavior

The branch **`feat/v3-refund-chain`** (v4 refund chain + receipt_type-aware aggregate fixes) is
about to merge to `dev` → staging. **This plan is written against post-merge behavior.** Do not
run the refund sections against a staging build that predates the merge; verify the deployed
commit first (§1.4).

Behavior this plan assumes as landed (spec: `docs/superpowers/specs/2026-07-31-v3-refund-chain-integration.md`
Revision 4.2 FINAL, on that branch):

| Ref | Post-merge rule |
|---|---|
| §3.1 | **v4 refunds carry POSITIVE magnitudes everywhere**; direction lives in `invoice_type_code` (`REFUND`/`VOID`) and `receipt_type='return'`. **Legacy** returns are stored NEGATIVE. Both populations can coexist in one reporting window. |
| §3.4 | `refund_destination` is the single literal **`cash`** for launch; `settlement_allocation` is present-and-null. |
| §3.5 | **Refusal:** any refund — partial *or* full — of an original whose `transaction_discount_amount` is non-zero is REFUSED (whole-receipt discount). **Per-line discounts:** a **FULL** refund of a per-line-discounted line is refundable (quantity unedited, discount carries through as a negative-signed `discount_amount` on the refund row); a **PARTIAL** refund (quantity edited) of a per-line-discounted line is **REFUSED pre-PIN** — launch does not prorate discounts (typed refusal `refundFlow.discountedPartialRefundRefused`, `refundCheckoutStore.ts`, wave-2 finding 10). *(This is in addition to, and distinct from, the WHOLE-RECEIPT discount refusal above — the two are frequently confused.)* |
| §3.7 | **Refusal:** refund against a TRAINING original. Distinct from "session is in training mode". |
| §9.6 | **Refusal:** refund of an original that was not cash-tendered (e.g. card-original) — the cash-only launch payload cannot represent it, so it is refused by the same typed mechanism as any unsupported destination. |
| §4.4 erratum | Device-side **cumulative-quantity backstop** per `original_line_index`, and a **receipt-level VALUE bound**: Σ\|refunded\| + this attempt ≤ original's **exact** total (`total − cash_rounding_adjustment`). Fails **closed** on unreadable data. |
| §12 | **Server-side per-original quantity cap** is the sole authority; aggregates prior refunds with `SUM(ABS(quantity))` so legacy-negative and v4-positive both count. |
| §9.3/§9.4 | Two-phase **enable/acknowledge** capability rollout; **>1 device terminal ⇒ refusal** (single-terminal preflight). |
| §7.3 | Device Z aggregation: refunds do **not** touch gross/net/tax; `refunds_amount` is its own field; VAT breakdown and payment-method breakdown are **subtracted**; `expected_cash` no longer subtracts a separate `cashRefundImpact` term. |

**M2 / M3 tenant settings — LANDED on `feat/v3-refund-chain` (about to merge, see §0.1 branch
premise above).** `CompanyFraudSettings` (`apps/api/app/Modules/Compliance/Domain/CompanyFraudSettings.php`)
gains three seeded columns, shipped over the **existing** fraud-settings sync channel
(`/api/v1/pos/fraud-settings` → device `company_fraud_settings_cache`) — no new sync channel. Both
policies are enforced **pre-PIN, pre-intent** in `beginV4()` (`refundCheckoutStore.ts`), i.e. before
anything is signed, and **fail closed** on an unreadable row/window/prior-total. Precedence for a
genuinely absent cached row is the seeded default (byte-identical device fallback constants), never
zero/blocked — a never-synced terminal must not be bricked by the rollout of this control.

- `{M2_COUNT}` = **5** — `offline_refund_count_ceiling`: max v4 refunds this **terminal** may
  author in the **current shift** while it holds unsynced fiscal events.
- `{M2_VALUE}` = **300.000 TND** — `offline_refund_value_ceiling` (stored `300.0000`, displays/
  compares at the tenant currency scale, 3 for TND): max cumulative v4 refund payout for that same
  terminal/shift/unsynced window.
- `{M2_WINDOW}` = **current shift while the device holds unsynced fiscal events** — mirrors
  `zReportService`'s shift-boundary anchor (`hash_sequence`, wall-clock only for pre-anchor legacy
  shifts) so a clock rollback cannot shrink the window and raise the ceiling. A drained sync queue
  stands the ceiling down entirely (§12's server cap is blind only while the queue is non-empty).
  **Not** a per-cashier ceiling — it is per terminal.
- `{M3_THRESHOLD}` = **100.000 TND** — `online_required_refund_threshold`: a single v4 refund above
  this value started while the device is **offline** is refused at `begin()`. A refund **equal** to
  the threshold is allowed. The same refund is permitted once the terminal is online (proves the
  refusal is offline-specific, not a blanket cap).
- **M3 is also a PIN-verification requirement, not only a start-time gate.** Above the threshold,
  the manager PIN must be **server-verified** (B7's primary path), never approved from the local PIN
  cache — this closes a TOCTOU gap found in the same wave: connectivity was read once at `begin()`,
  but the PIN is verified later, so a link drop **during PIN entry** could downgrade to the offline
  cache for an above-threshold payout. Fixed via a `requireServerVerifiedPin` flag computed from the
  amount alone at `begin()` (independent of the connectivity reading), threaded to
  `verifyScopedManagerPin()`, which throws `ServerVerifiedPinRequiredError` instead of falling back —
  refusal happens **before** the approval/override events are authored, so nothing is signed on the
  refused path. See the new edge case in §Z (POSC-25a).

Numbers are tenant settings, not fixed constants — no citable industry-standard figures exist for
this exact mechanism (justification: `docs/sessions/LANE-C-m2-m3-report.md`, analogous to Oracle
NetSuite/Shopify/Lightspeed/Square offline-refund controls, magnitudes anchored on the 2026 Tunisian
SMIG). Confirm the deployed values still match the defaults above before running MTP-RFD-24..27 —
if a tenant-specific override was set on the target company, record the actual value in §1.5 instead.

Note the **already-shipped** refund-policy settings which are NOT M2/M3 and which DO exist today
(`ReservationSettings`, exercised via Settings → POS Refund Policies): `daily_refund_cap_per_cashier`
(nullable, default `null` = no cap), `daily_refund_cap_override_allowed` (default `true`),
`manager_override_threshold_amount` (default `'50.00'`), `manager_override_threshold_percent`
(default `'10.00'`), `customer_history_window_days` (default `14`), `out_of_window_policy`
(default `voucher_only`).

### 0.2 Precision contract — the arithmetic every expected value obeys

Source of truth: `docs/architecture/precision-contract.md`. Condensed rules the tester must apply
when computing an expected number:

1. **Currency storage scale is 3** (`decimal(N,3)`). **Display** scale is per currency:
   TND/LYD/JOD/KWD/OMR/BHD → **3**, EUR/USD/GBP/MAD/DZD → **2**, JPY/KRW → 0
   (`apps/web/src/lib/currencyMeta.ts`).
2. **Quantity storage scale is 4**; quantities shown to a human render at the **product unit's**
   `units.decimal_places` (pieces → `0`, weight → `3`). A cell showing `7.0000` pieces is a defect.
3. **Percentages are NOT currency-scaled** — fixed 2-dp ceiling. Withholding *rate* is a 0–1
   fraction at `decimal(5,4)`.
4. **`CurrencyScale::bcformat` TRUNCATES toward zero** at the write boundary. Intermediates run at
   `scale+1` (cart/tax) or `scale+4` (WAC, landed cost) and round **once**. So WAC/landed cost
   carry a ≤1-millième **downward** bias — expected values must be computed by truncation, not
   by half-up, or the test will false-fail.
5. **`unit_price` is context-overloaded.**
   - **B2B documents** (quotes / orders / invoices / credit notes / supplier invoices):
     `unit_price` is **net / HT**.
   - **B2C POS** (device receipts and their server projections): `unit_price` is
     **tax-INCLUSIVE / TTC**; the net is `line_subtotal`; VAT is `line_vat`.
   - **Never** assert `line_subtotal == unit_price × qty − discount` on a POS line. Enforce fiscal
     integrity at the **aggregate** level only.
6. **POS aggregate fiscal identity** (v3+, with cash rounding):
   `subtotal + vat_total == (total − cash_rounding_adjustment) + transaction_discount`.
   Absent fields mean zero, so v1/v2 reduce to the classic identity.
   `pos_receipts_totals` CHECK: `total = subtotal + tax_amount − discount_amount + COALESCE(cash_rounding_adjustment, 0)`.
7. **Two payment semantics — read before summing any cash column:**
   `pos_receipt_payments.amount` = **TENDERED** (what the customer handed over);
   Treasury `payments.amount` = **RETAINED** (tendered − change; a leg netting to zero writes no
   row). A report summing tendered cash as banked cash **overstates**. Subtract
   `pos_receipts.change_due`, or read Treasury `payments`.
8. **`cash_rounding_adjustment` is the one sanctioned signed money field.** `-0` is illegal;
   canonical zero is `'0.000'`. `cash_rounding_denomination` is non-negative and must survive every
   hop **unmutated** (`'0.050'` becoming `0.05` anywhere is a quarantine-class defect). Sanctioned
   maxima by currency scale (`CashRoundingCaps`): scale 0 → `10`, scale 2 → `1.00`, scale 3 → `1.000`.
   An unlisted scale disables rounding (fail-closed).
9. **Rounding is VAT-neutral** and never allocated across VAT buckets — `total ≠ Σ vat gross` by
   exactly one adjustment is **legal** on a v3 receipt.

### 0.3 Known-defect register — expected FAILURES that are already ticketed

These are open, ticketed defects at authoring time. If the campaign reproduces one, record
`FAIL (known — <ticket>)`, do **not** open a duplicate, and do **not** silently pass it.

| Ticket | Defect | Campaign impact |
|---|---|---|
| `docs/superpowers/tickets/2026-08-01-positive-refund-total-consumers.md` | 5 aggregates `SUM(pos_receipts.total)` with **no `receipt_type` filter**: `PosAnalyticsService.php:37` (net_sales), `:165` (getSalesByTimePeriod), `:194-195` (getCashierPerformance), `:312` (getCustomerAnalytics); `GrandtotalService.php:174` (lifetime_sales). Same class, different column: `ReportGenerationService.php:500` (`pos_receipt_payments.amount`, shift-close reconciliation). With v4 POSITIVE refunds these **ADD** instead of subtracting. **HARD PRE-ENABLE GATE.** | Directly targeted by `MTP-AGG-*`. If v4 authoring is still inert on staging, these read correct — the mixed-window cases are the only ones that expose them. |
| `docs/superpowers/tickets/2026-07-31-cashdrawer-v3-expected-cash-blind.md` | `CashDrawerService::calculateExpectedCash()` (`:387-413`) sums only `pos_cash_drawer_operations`; **no v3 path writes those rows** → opening-float-only expected cash, and `ShiftManagementService.php:168-182` **persists** that wrong variance on close. Also `scale()` uses a no-arg `getScale()` (`:48-51`) — fatal if queued. | `MTP-CASH-*` will show expected_cash ≈ opening float on v3 shifts. Expected known-fail. |
| `docs/superpowers/tickets/2026-08-01-device-z-sale-branch-gross-as-net.md` | Device Z/EOD/X **SALE** branch treats gross `line_total` as net → net overstated by the VAT amount per taxed line inside signed Z_REPORT/X_REPORT events. **Totals are correct; the net/VAT decomposition is not.** Refund branch is now correct, so a fully-refunded taxed sale leaves a `+VAT / −0` residue. | `MTP-ZRP-*` decomposition cases. Assert totals strictly; record decomposition mismatch as known-fail with the exact residue. |
| `docs/superpowers/tickets/2026-07-31-treasury-bridge-training-money-legs.md` | `TreasuryReceiptBridge.php:401-402` — only the cash-rounding / tolerance write-off entries sit behind the training gate. The **tender-leg loop above it is NOT training-gated**, so a TRAINING sale creates real Payment rows, GL entries and repository movements. | `MTP-TRN-01`. **Do not take training receipts during the rest of the campaign** unless the fix landed. |
| `docs/superpowers/tickets/2026-08-01-refund-disposition-ui.md` | No per-line refund disposition UI at launch; every refund line defaults to `restock`. Regulated items still honor `RestockPolicyResolver`'s never-restock override. | `MTP-RFD-*` assert restock-by-default; damaged-goods handling is out of scope. |

### 0.4 Web POS is gated — read before writing any "sell from the browser" case

`docs/superpowers/tickets/2026-06-11-web-pos-demo-only-gate.md`: the **mutating** browser POS
flows (open/close shift, web sale, table/kitchen) are wrapped by `EnsureWebPosDemoTenant` and
return **403 `WEB_POS_DEMO_ONLY`** for any tenant without central `tenants.is_demo = true`.
**Read-only** POS back-office surfaces (Z-report list/detail, shift history) stay available to
everyone. Consequences for this campaign:

- Selling from the browser is only a valid test path on a **demo** tenant.
- On the real first-tenant staging tenant, the correct expected result for a browser sell/shift
  mutation is the **403 refusal** (`MTP-GATE-01/02`).
- All authored POS money data on the non-demo tenant must originate from the **device** (or from
  a seeder), which is why §Z is not optional for completeness.

---

## 1. Environment — FILL BEFORE EXECUTION

> Every `{{PLACEHOLDER}}` below must be resolved from
> `docs/handoff/STAGING-RUNBOOK-first-tenant-2026-07-31.md` (gate **E-9**) and
> `docs/operations/STAGING-SETUP.md`, and recorded here **before** the first case runs. Do not
> guess. Secrets go through the owner's secret channel (gate **E-1**), never into this file.

### 1.1 Endpoints

| Key | Value |
|---|---|
| Web SPA base URL | `{{STAGING_WEB_URL}}` |
| API base URL | `{{STAGING_API_BASE}}` (`.../api/v1`) |
| Horizon dashboard (queue health) | `{{STAGING_HORIZON_URL}}` |
| Deployed commit SHA (record at start AND end) | `{{DEPLOYED_SHA}}` |
| Staging PG host/port | `157.180.71.252:5434` (creds via Dokploy `postgres-one`; **not** on AX42) |

### 1.2 Accounts

| Role | Login | Password | Notes |
|---|---|---|---|
| Tenant owner / admin | `{{OWNER_EMAIL}}` | `{{SECRET}}` | full money permissions |
| Manager | `{{MANAGER_EMAIL}}` | `{{SECRET}}` | approval-threshold cases |
| Cashier (restricted) | `{{CASHIER_EMAIL}}` | `{{SECRET}}` | **required** for every permission-denied case |
| Second-tenant user (isolation) | `{{TENANT_B_EMAIL}}` | `{{SECRET}}` | **required** for `MTP-ISO-*` |
| Super-admin (central) | `{{SUPERADMIN_EMAIL}}` | `{{SECRET}}` | `/admin/*` billing surfaces only |

### 1.3 Tenants / companies under test

| Key | Value |
|---|---|
| Tenant A (primary, non-demo) UUID | `{{TENANT_A_UUID}}` |
| Tenant A company UUID | `{{COMPANY_A_UUID}}` |
| Tenant A currency / country | `{{CURRENCY_A}}` / `{{COUNTRY_A}}` — **assumed TND / TN** for all worked numbers below |
| Tenant B (isolation control) UUID | `{{TENANT_B_UUID}}` |
| Demo tenant (`tenants.is_demo = true`) UUID | `{{DEMO_TENANT_UUID}}` |
| Device terminal UUID (for §Z + chain verifiers) | `{{TERMINAL_UUID}}` |
| Locations under test | `{{LOCATION_1}}`, `{{LOCATION_2}}` |

### 1.4 Pre-flight gates (all must be green before case 1)

| # | Check | Expected |
|---|---|---|
| PF-1 | `{{DEPLOYED_SHA}}` contains the `feat/v3-refund-chain` merge | commit present in `git log dev` |
| PF-2 | `php artisan tenants:migrate --force` | "Nothing to migrate" for every tenant |
| PF-3 | `php artisan tenants:run db:seed --option='class=Database\Seeders\RolesAndPermissionsSeeder' --option='force=1'` then `php artisan tenants:run permission:cache-reset` | both exit 0 **inside every tenant** — the Spatie permission cache is tenant-blind |
| PF-4 | Horizon has a consumer for every named queue | no idle/unlisted queue (`HorizonQueueCoverageTest` covers CI; verify runtime) |
| PF-5 | Server clock / timezone on staging matches the tenant's | fiscal windows and same-day report boundaries depend on it |
| PF-6 | Baseline chain verification (see §Y) run **before** any test authoring, output archived | clean — so any breakage found at the end is attributable to the campaign |
| PF-7 | Cash rounding configuration recorded: `country_payment_settings.cash_rounding_denomination` for `{{COUNTRY_A}}` | record exact string, e.g. `0.0500`; note the policy-resolved value at company scale |
| PF-8 | v4 refund authoring capability state recorded (enabled / acknowledged / inert) per terminal | determines whether `MTP-AGG-MIX-*` can be authored at all |

### 1.5 Parameters resolved at execution time

| Parameter | Resolved value | Source |
|---|---|---|
| `{M2_COUNT}` | `5` (seeded default — confirm not overridden on the target tenant) | `company_fraud_settings.offline_refund_count_ceiling` |
| `{M2_VALUE}` | `300.000` TND (seeded default `300.0000`) | `company_fraud_settings.offline_refund_value_ceiling` |
| `{M2_WINDOW}` | current shift, only while the terminal holds unsynced fiscal events (per-terminal, not per-cashier) | `beginV4()` shift-boundary logic, mirrors `zReportService` |
| `{M3_THRESHOLD}` | `100.000` TND (seeded default `100.0000`) | `company_fraud_settings.online_required_refund_threshold` |
| Cash rounding denomination | | PF-7 |
| Standard VAT rate(s) in use | | Settings → Tax |
| Company currency scale | | `getDecimals({{CURRENCY_A}})` |

---

## 2. Seeded data

Three demo seeders exist and are the intended source of deterministic figures:

| Seeder | Tenant slug / currency / VAT | Deterministic money fixtures |
|---|---|---|
| `DemoTenantSeeder` | `demo-unlimited` (+ 8 skeleton tenants) · TND · 19% | **Automotive catalog with FIXED prices:** `OIL-5W30-5L` `85.000`, `FILT-OIL-STD` `25.000`, `BRAKE-PAD-FRONT` `95.000`, `TIRE-195-65-R15` `210.000`; service `LAB-OIL-CHANGE` `45.000`/hr. One **completed work order** totalling **`171.062` TTC** = parts `85.000 + 25.000 = 110.000` + labour `33.750` = net `143.750`, VAT 19% = `27.312` (**truncated** from `27.3125` — a ready-made truncation vector). Admin `admin@demo.local` / `password`. The other 8 tenants are skeletons: no products, **no POS shifts/receipts, no GL**. |
| `CoffeeShopSeeder` | `cafe-tunis` · TND · **7%** (TN reduced F&B rate) | Fixed drink/pastry prices: Espresso `3.500`, Americano `4.000`, Latte `5.500`, Cappuccino `5.500`, Mocha `6.500`, Frappuccino `7.500`, Croissant `3.000`, Cheesecake Slice `7.000`, Bottled Water `1.500`. Modifiers: Oat/Almond/Soy milk `+0.500`, Whole/Skim free. **Hardcoded GL (entry prefix `SEED-9000+`):** `CUST-001` Cafe Central invoice `500.000` (net `467.290` + VAT `32.710`), paid `200.000` → **outstanding `300.000`**; `CUST-002` Restaurant Le Jardin invoice `1200.000` (net `1121.495` + VAT `78.505`) unpaid → **outstanding `1200.000`**; `CUST-003` Hotel Meridien invoice `800.000` (net `747.664` + VAT `52.336`) fully paid → **outstanding `0`**; `SUPP-001` Coffee Bean Wholesale purchase `2500.000` unpaid → **payable `2500.000`**. Terminal `POS01` exists **unclaimed**; **no shifts/receipts seeded**. Users: `owner@cafe-tunis.tn` (Owner+admin, `max_discount_percent 25.00`), `barista@cafe-tunis.tn` (Cashier), both `password`. |
| `DemoPharmacySeeder` (extends `ParapharmacySeeder`) | `demo-pharmacy-tn` · TND · 19% · COA `TunisiaChartOfAccountsSeeder` | Warehouse `WH-01` (non-POS) + **4 POS shops** (Tunis Lac, Tunis Centre, Sousse Médina, Sfax Centre), each with its own establishment matricule. **Hardcoded GL (`DEMO-BAL-7000+`):** `CUST-DEBTOR-01` Clinique Al Amal invoice `1500` − payment `600` → **outstanding `900` TND**; `SUPP-PAYABLE-01` Medis Distribution purchase `3200` unpaid → **payable `3200` TND**. Users (`password`): `owner@pharmabio.tn` PIN `1234` (disc 100%), `manager@pharmabio.tn` PIN `5678` (disc 25%), `cashier@pharmabio.tn` PIN `0000` (disc 10%), plus location-pinned cashiers `tunis1.` PIN `1111`, `tunis2.` PIN `2222`, `sousse.` PIN `3333`, `sfax.` PIN `4444`. An ACTIVE loyalty program + default Spend rule is bootstrapped. **Staging owes a rerun** (runbook step 8.1); known calendar flake `docs/superpowers/tickets/2026-07-31-demo-pharmacy-seeder-calendar-flake.md`. |
| `DatabaseSeeder` (default `db:seed`) | `demo-garage`, two companies FR/EUR + TN/TND | 95 partners + 1000 products per company, **Faker-random prices**. Users `test@example.com`/`password` (Manager), `admin@example.com`/`admin123` (Owner+admin). Good for **multi-currency** and **cross-company** cases; useless for exact numbers. |
| `TwoTenantIsolationDemoSeeder` | `demo-tenant-a` / `demo-tenant-b` | Bare tenants for **`MTP-ISO-*`**. Requires PostgreSQL + `TENANCY_DB_PER_TENANT=true`. No money data by design. |

> ⚠️ **Three hard facts that shape this campaign:**
> 1. **`ParapharmacySeeder` / `DemoPharmacySeeder` product prices are RANDOMIZED** (`rand()` within
>    per-category ranges) and its `DEMO-INV-0001..0010` sales invoices are built from those random
>    prices. **Never hardcode a pharmacy product price or invoice total.** Only the two GL balances
>    above are deterministic there.
> 2. **NO seeder creates `pos_shifts` / `pos_receipts`.** Every POS money figure this campaign
>    asserts must be authored — on the **device** (§Z) for the non-demo tenant, or via the browser
>    POS on the **demo** tenant only (§0.4). Schedule accordingly.
> 3. `DemoTnSeeder` **does not exist**. The TN fixture is `DemoPharmacySeeder` (`demo-pharmacy-tn`).

**Rule for this campaign: re-verify seeder figures on the deployed build.** The table above is
read from the seeders at authoring time; a seeder edit between authoring and execution is the
single most likely cause of a spurious FAIL. Record actuals below before asserting.

| Fixture key | Seeded value on `{{DEPLOYED_SHA}}` | Where read |
|---|---|---|
| `{{PRODUCT_A}}` name / net price / VAT rate / unit decimals | | Inventory → Products |
| `{{PRODUCT_B}}` (different VAT rate) | | |
| `{{PRODUCT_C}}` (fractional unit, e.g. kg, 3 dp) | | |
| `{{PRODUCT_D}}` (regulated / never-restock) | | |
| `{{CUSTOMER_A}}` / `{{CUSTOMER_B}}` | | Sales → Customers |
| `{{SUPPLIER_A}}` | | Purchases → Suppliers |
| Opening cash float per shift | | POS → Shift history |
| Pre-existing legacy (negative) return receipts, if any | | POS → Transactions |

**Self-authored fixtures.** Where a case needs data no seeder provides (a whole-receipt-discounted
original, a card-tendered original, a mixed legacy/v4 window), the case's Preconditions column
says how to author it and which surface authors it. Anything that must be authored on the device
is flagged `[DEVICE]` and belongs to §Z.

---

## 3. Case-table conventions

**ID scheme:** `MTP-<SURFACE>-<NN>` — `SURFACE` codes are listed in §4.

**Type:** `HAPPY` (the intended path with correct inputs) · `EDGE` (boundary, refusal, empty,
concurrency, isolation, formatting).

**Priority:**

| P | Meaning |
|---|---|
| **P0** | **Launch-blocking.** Failure means money is wrong, unrecoverable, mis-attributed, leaks across tenants, or a fiscal artefact is invalid. No risk-acceptance path. |
| **P1** | Should pass. Failure is a real defect but the launch can proceed with a recorded owner risk-acceptance and a revisit milestone. |
| **P2** | Nice to have. Cosmetic, non-fiscal, or a low-traffic convenience path. |

**Expected result discipline.** Where a number is computable it is written **exactly, at the
storage/display scale**. Where the exact value depends on a fixture recorded in §2 it is written
as a formula over that fixture. **"Looks right" is never an expected result.** Every table row's
expected result must be checkable by reading a number off the screen or the API response.

**Evidence.** Each executed case records: screenshot of the asserted number, the API response body
(Playwright `browser_network_requests`), and — for fiscal cases — the relevant DB row. File under
`docs/sessions/money-campaign-2026-08-01/<case-id>/`.

---

## 4. Campaign order (cheap → expensive)

Run in this order. Each wave's fixtures feed the next; running out of order forces re-seeding.

| Wave | Surfaces | Why here |
|---|---|---|
| **W0** | §1.4 pre-flight, §Y baseline chain verification, §2 fixture recording | Establishes the clean baseline; cheapest, and everything else is attributable against it. |
| **W1** | `CFG` VAT/tax configuration · `CFG` cash-rounding config · `PMT` payment methods · `PRC` price lists & margin overrides | Read-mostly configuration. Determines the arithmetic every later wave asserts. Cheap, no fixtures consumed. |
| **W2** | `DOC` documents (quotes → orders → invoices → credit notes) · `TAX` VAT decomposition · `DSC` discounts | The densest exact-arithmetic surface, and self-contained (no device, no cash). |
| **W3** | `PUR` purchasing (supplier invoices, receipts, matching, bonuses) · `INV` inventory valuation (WAC, opening balances, landed cost) | Depends on W1 pricing; feeds W6 GL. |
| **W4** | `TRE` treasury (instruments, expenses, outbound payments, remittances, statements/reconciliation, repositories, cash movements) · `WHT` withholding | Multi-step, stateful, the most expensive to re-run — but independent of POS. |
| **W5** | `ZRP` Z/X report views · `SHF` shift & cash reports · `CASH` expected-cash · `RFP` refund policies · `RFD` refund admin/observation · `AGG` receipt_type-aware aggregates · `OWN` owner reports | The fiscal core. Depends on §Z device data existing, so schedule the device campaign to run **before or concurrently with** W5. Highest P0 density. |
| **W6** | `GL` journal entries, trial balance, P&L, balance sheet, aged AR/AP · `LOY` loyalty money legs | Consumes everything above; only meaningful once W2–W5 have moved money. |
| **W7** | `MLC` multi-location money views · `ISO` cross-tenant isolation · `I18N` fr number formatting · `PERM` permission-denied paths · `CONC` concurrency/stale edits · `EMPTY` empty-state reports | Cross-cutting sweeps; cheap once the data exists, and they need the data from W2–W6 to be meaningful. |
| **W8** | §Y **last-gate fiscal re-run** | Final pre-production gate. Must be the last thing that happens. |

**Parallelization note.** W1–W4 can run in parallel with the §Z device campaign. W5 cannot start
until the device campaign has produced at least: 1 closed shift with sales, 1 v4 refund, 1 legacy
refund (if any exists), and 1 cash-rounded receipt.

---

## 5. Surface codes

| Code | Surface | Wave | Section |
|---|---|---|---|
| `CFG` | Tax / VAT configuration, cash-rounding configuration, company money settings | W1 | §A |
| `PMT` | Payment methods & routing | W1 | §A |
| `PRC` | Price lists, margin overrides, cost-price visibility | W1 | §A |
| `DOC` | Documents: quotes, orders, invoices, credit notes, delivery/return notes | W2 | §B |
| `TAX` | VAT decomposition on documents | W2 | §B |
| `DSC` | Discounts (per-line vs whole-document), promotions, coupons, vouchers | W2 | §B |
| `PUR` | Purchasing: PO, goods receipt, supplier invoice, 3-way matching, bonuses, landed cost | W3 | §C |
| `INV` | Inventory valuation: WAC, opening balances, counting variance value, write-offs, transfers | W3 | §D |
| `TRE` | Treasury: payments, instruments, remittances, statements/reconciliation, repositories, expenses, cash movements | W4 | §E |
| `WHT` | Withholding: certificates, sales withholding tracking | W4 | §E |
| `ZRP` | Z-report / X-report web views | W5 | §F |
| `SHF` | Shift history, shift cash reports | W5 | §F |
| `CASH` | Expected cash, counted cash, variance | W5 | §F |
| `RFP` | Refund policy configuration | W5 | §F |
| `RFD` | Refund observation & admin surfaces | W5 | §F |
| `AGG` | receipt_type-aware aggregates (the v4-positive-refund consumer gate) | W5 | §F |
| `OWN` | Owner reports dashboard | W5 | §F |
| `GATE` | Web-POS demo-only gate | W5 | §F |
| `TRN` | Training-mode money isolation | W5 | §F |
| `GL` | Journal entries, trial balance, P&L, balance sheet, ledger, aged AR/AP | W6 | §G |
| `LOY` | Loyalty earn/redeem money legs | W6 | §H |
| `MLC` | Multi-location money views | W7 | §I |
| `ISO` | Cross-tenant / cross-company isolation | W7 | §I |
| `I18N` | fr-locale number formatting | W7 | §I |
| `PERM` | Permission-denied paths for money mutations | W7 | §I |
| `CONC` | Concurrency / stale edits | W7 | §I |
| `EMPTY` | Empty-state reports | W7 | §I |

---

## 6. Coverage totals and execution tiers

| Section | Surface(s) | Cases | HAPPY | EDGE | P0 | P1 | P2 |
|---|---|---:|---:|---:|---:|---:|---:|
| §A | `CFG` `PMT` `PRC` | 35 | 6 | 29 | 14 | 21 | 0 |
| §B | `DOC` `TAX` `DSC` | 54 | 10 | 44 | 18 | 33 | 3 |
| §C | `PUR` | 27 | 5 | 22 | 15 | 10 | 2 |
| §D | `INV` | 24 | 4 | 20 | 11 | 10 | 3 |
| §E | `TRE` `WHT` | 82 | 18 | 64 | 26 | 54 | 2 |
| §F | `ZRP` `SHF` `CASH` `RFP` `RFD` `AGG` `OWN` `GATE` `TRN` | 113 | 12 | 101 | 71 | 38 | 4 |
| §G | `GL` | 25 | 7 | 18 | 13 | 11 | 1 |
| §H | `LOY` | 16 | 3 | 13 | 1 | 12 | 3 |
| §I | `MLC` `ISO` `I18N` `PERM` `CONC` `EMPTY` | 51 | 4 | 47 | 23 | 25 | 3 |
| **TOTAL (web / Playwright)** | | **427** | **69** | **358** | **192** | **214** | **21** |
| §Z | POS desktop (computer-use) | **64 items** | — | — | — | — | — |

**On the size.** This came out larger than a feature-list estimate would suggest, because coverage
was derived from the code: treasury instruments, bank reconciliation, and the receipt_type-aware
aggregate consumers each carry many distinct, individually-falsifiable money behaviors. Rather than
cut real coverage, execute in tiers:

| Tier | Contents | Cases | Use when |
|---|---|---:|---|
| **T1 — Minimum launch gate** | every **P0** | **192** | Non-negotiable; this is the set that gates go-live |
| **T2 — Recommended** | T1 + every **P1** | 406 | The intended full campaign if time allows |
| **T3 — Complete** | T2 + **P2** | 427 | Post-launch hardening / regression baseline |

**P0 density.** W5 (the fiscal core, §F) alone carries **71 of the 192** P0 cases; §I's
isolation + permission sweeps carry **23**. If the schedule compresses, protect §F and §I.2/§I.4
first — §H (loyalty, 1 P0) and §B's discount depth compress most safely.

---

## §A — `CFG` tax & cash-rounding configuration · `PMT` payment methods · `PRC` pricing (W1)

### A.1 `CFG` — tax configuration

**Entry point.** `/settings/tax` (`TaxSettingsPage.tsx`), two tabs.
**Profile tab:** tax status (`REGISTERED` / `NON_REGISTERED`), VAT registration number (shown only
when registered), **default tax rate** (numeric, `step=0.01`, `min=0`, `max=100`), fiscal-year start
month. The company's `default_tax_rate` is saved via `PATCH /companies/{id}`.
**Taxes tab:** the `TaxConfiguration` list (name/code, `tax_type` `PERCENTAGE|FIXED_AMOUNT`, rate,
`applies_to` `LINE_ITEMS|DOCUMENT_TOTAL`, active) via `TaxConfigFormModal` — create/edit/delete/reorder.
Backend `TaxConfigurationController`; writes gated by `can:taxation.tax_configurations.manage`.

> **Two structural facts:** (1) tax configurations are **country-scoped global reference data**
> keyed on `country_code`, not per-company — so an edit is visible to every company in that country;
> (2) there is **no tax-inclusive/exclusive toggle** on this page. The HT/TTC basis lives in the
> pricing layer, and the POS is unconditionally tax-inclusive (precision contract §5).

Validation to assert: `percentage_rate` — `numeric, min:0, max:100, regex:/^\d+(\.\d{1,2})?$/`
(**2 dp**, percentages are not currency-scaled); `fixed_amount` — `min:0, regex:/^\d+(\.\d{1,3})?$/`
(**3 dp**); `applies_to in:LINE_ITEMS,DOCUMENT_TOTAL`;
`stacks_on in:SUBTOTAL,TOTAL_INCLUDING_PREVIOUS`; `effective_to after_or_equal:effective_from`.
Tunisia stamp duties seeded by `TunisiaTaxConfigurationSeeder`: `STAMP_TAX_INVOICE` fixed
**`1.000`** TND, `STAMP_CREDIT_NOTE` **`0.600`**, `STAMP_FISCAL_RECEIPT` **`0.100`** (seeded inactive).

| ID | Type | P | Preconditions | Steps | Expected result (exact) |
|---|---|---|---|---|---|
| MTP-CFG-01 | HAPPY | P0 | TN tenant | `/settings/tax` → Taxes tab | The 19% line-items VAT config and `STAMP_TAX_INVOICE` (`1.000`, `DOCUMENT_TOTAL`, `is_stamp_duty`) are present and active; `STAMP_CREDIT_NOTE` `0.600`; `STAMP_FISCAL_RECEIPT` `0.100` inactive |
| MTP-CFG-02 | HAPPY | P1 | as 01 | Profile tab → set default tax rate `19.00`, save, reload | Persists as `19.00`; used as the default on new document lines |
| MTP-CFG-03 | EDGE | P0 | as 01 | Create a config with `percentage_rate = 19.001` | Rejected — 2-dp ceiling. Percentages are NOT currency-scaled, so 3 dp must fail even on a TND tenant. |
| MTP-CFG-04 | EDGE | P1 | as 01 | `percentage_rate = 100.01` and `= -1` | Both rejected (`max:100`, `min:0`) |
| MTP-CFG-05 | EDGE | P1 | as 01 | `fixed_amount = 1.0001` | Rejected — 3-dp ceiling |
| MTP-CFG-06 | EDGE | P1 | as 01 | `effective_to` earlier than `effective_from` | Rejected (`after_or_equal`) |
| MTP-CFG-07 | EDGE | P1 | user without `taxation.tax_configurations.manage` | Attempt create/edit/delete | UI actions absent; API 403. Reads remain open to any authenticated tenant user. |
| MTP-CFG-08 | EDGE | P0 | two companies in the same country (`demo-garage`) | Edit a tax config as company A, then view as company B | Country-scoped: the change **is** visible to B. Confirm this is the intended shared-reference behavior and that no company-specific rate was silently overwritten. |
| MTP-CFG-09 | EDGE | P1 | as 01 | Deactivate the 19% config, then create a new document | The rate is no longer offered; existing posted documents keep their snapshotted rate (no retroactive re-computation) |
| MTP-CFG-10 | EDGE | P0 | PF-7 recorded | Verify `country_payment_settings.cash_rounding_denomination` for `{{COUNTRY_A}}` end to end (DB → policy resolver → API → device cache) | The string survives **unmutated** at every hop (`0.0500` in PG rendered against a scale-3 signed `0.050` — compare with `bccomp`, never string equality). Any float re-serialization (`0.05`) is a quarantine-class defect. |
| MTP-CFG-11 | EDGE | P0 | as 10 | Confirm the denomination is within the sanctioned cap for the currency scale (`CashRoundingCaps`: scale 3 → max `1.000`) | Within cap. A denomination above the cap, or an unlisted currency scale, must **disable** rounding (fail-closed, `cashRoundingEnabled: false`) rather than emit a broken value |
| MTP-CFG-12 | EDGE | P1 | — | Determine whether cash rounding is editable from the web UI at all | Record the answer. Configuration is performed by the `pos:configure-cash-rounding` ops command; if no web surface exists, note it so no case assumes one. |

### A.2 `PMT` — payment methods and routing

**Entry point.** `/treasury/payment-methods`. Methods carry a `has_maturity` flag that switches the
payment form into instrument mode (cheque / effet). GL routing is configured operationally via
`treasury:configure-method-routing --option='card-to=<REPO-CODE>'` (staging runbook §4).

| ID | Type | P | Preconditions | Steps | Expected result |
|---|---|---|---|---|---|
| MTP-PMT-01 | HAPPY | P0 | fresh company | `/treasury/payment-methods` | At least one **active CASH** method exists — the launch-contract minimum; without it POS deposit/payment flows error |
| MTP-PMT-02 | HAPPY | P1 | as 01 | Create a CARD method and a CHEQUE method (`has_maturity`) | Both persist; the cheque method drives the instrument block on the payment form |
| MTP-PMT-03 | EDGE | P0 | card routing configured to `<REPO-CODE>` | Take a card payment, then inspect the repository movement + GL | Card settles into the configured bank repository, **not** the cash repository or a fallback |
| MTP-PMT-04 | EDGE | P1 | as 01 | Deactivate the only cash method | Either refused, or POS/payment flows fail loudly with a clear message — never a silent zero-value payment |
| MTP-PMT-05 | EDGE | P1 | cashier role | `/treasury/payment-methods` | Blocked (`treasury.manage`); API 403 |

### A.3 `PRC` — price lists, price resolution, margin overrides

**Entry points.** `/pricing/price-lists`, `/new`, `/:id` (`PriceListDetailPage` — items + partner
assignments), `/:id/edit`. A price list holds `code`, `name`, `currency`, `is_active`, `is_default`,
`valid_from`, `valid_until`; items are **absolute prices** (not percent-off) with
`min_quantity`/`max_quantity` **quantity breaks**; partner assignments carry their own validity plus
a `priority`.

> **Known incompleteness:** the "add item" / "assign partner" modals on `PriceListDetailPage` are
> **placeholders**. If they are still stubs on `{{DEPLOYED_SHA}}`, seed price-list items through the
> API/DB and mark `MTP-PRC-02` `BLOCKED — UI stub`.

**Resolution order (locked, `PricingService::getPrice()`):**
1. Variant `price_override` → 2. Partner price list, **variant-specific** row → 3. Partner price
list, variant-agnostic row → 4. Default price list, variant-specific → 5. Default price list,
variant-agnostic → 6. Product `sale_price` fallback.
Partner lists are tried in **`priority DESC`** among the partner's active, currency-matching,
date-valid lists. Promotions/coupons are a **separate** engine applied on top of the resolved price.

**Margin hierarchy (`MarginResolver`), resolved independently per field:** product
`target_margin_override`/`minimum_margin_override` → nearest category ancestor → company
`default_target_margin`/`default_minimum_margin` → hard fallback **30% / 15%**. Minimum is **clamped
to ≤ target** (`minimum_clamped` flag). Enforcement: `MarginService::canSellAtPrice()` — **RED**
(below cost) needs `pricing.sell_below_cost` **and** `company.allow_below_cost_sales`; **ORANGE**
(below minimum margin) needs `pricing.sell_below_minimum_margin`; **YELLOW** (below target) needs
`pricing.sell_below_target_margin`. Document-save enforcement runs through
`DiscountPolicyDocumentValidator` → `DiscountPolicyService::resolveForSubject()`, gated by the
company `DiscountFloorMode` (`Block` / `WarnRequiresPermission` / `Advisory`). UI signalling:
`DocumentLineEditor` (`isBlocked` when `policy.allowed === false`, `isWarning` when level ≠ green)
and `PricingIntelligencePanel`.

| ID | Type | P | Preconditions | Steps | Expected result (exact) |
|---|---|---|---|---|---|
| MTP-PRC-01 | HAPPY | P1 | — | `/pricing/price-lists/new` → create list `PL-A`, currency TND, valid now, default off | Persists; appears in the list |
| MTP-PRC-02 | HAPPY | P0 | `PL-A`; `{{PRODUCT_A}}` with `sale_price = 12.500` | Add item `{{PRODUCT_A}} = 11.000`, assign `PL-A` to `{{CUSTOMER_A}}`; create a quote for `{{CUSTOMER_A}}` with that product | Resolved unit price `= 11.000` (step 2/3 beats step 6). A `12.500` here means the partner list was not consulted. |
| MTP-PRC-03 | EDGE | P0 | `{{CUSTOMER_A}}` assigned to `PL-A` (`priority 10`, price `11.000`) **and** `PL-B` (`priority 20`, price `10.000`) | Quote for `{{CUSTOMER_A}}` | `= 10.000` — highest `priority` wins (`priority DESC`) |
| MTP-PRC-04 | EDGE | P0 | default price list has `{{PRODUCT_A}} = 12.000`; customer has no partner list | Quote for `{{CUSTOMER_B}}` | `= 12.000` (step 4/5), **not** the product `sale_price` |
| MTP-PRC-05 | EDGE | P0 | product with a variant carrying `price_override = 9.000`, plus a partner list at `11.000` | Quote that variant | `= 9.000` — variant override outranks everything |
| MTP-PRC-06 | EDGE | P1 | `PL-A` item with `min_quantity 1 / max_quantity 9 = 11.000` and `min_quantity 10 = 10.000` | Quote qty `9`, then qty `10` | `11.000` then `10.000`; the break boundary is at exactly 10 |
| MTP-PRC-07 | EDGE | P1 | `PL-A` with `valid_until` in the past | Quote for its partner | Falls through to the default list / `sale_price` — an expired list must never be applied |
| MTP-PRC-08 | EDGE | P1 | `PL-A` in **EUR**, company in TND | Quote in TND | The EUR list is skipped (currency must match); no cross-currency price is applied without conversion |
| MTP-PRC-09 | EDGE | P0 | `{{PRODUCT_A}}` cost `10.571428`, no overrides, company defaults absent | `/inventory/products/:id` pricing panel | Target margin `30%`, minimum `15%` (hard fallback). Verify the derived floor prices are computed from the **6-dp** cost, not a display-rounded one. |
| MTP-PRC-10 | EDGE | P1 | category override `target 40 / minimum 25`; product overrides none | Same panel | Category values win over company defaults |
| MTP-PRC-11 | EDGE | P1 | product override `target 20 / minimum 35` (minimum > target) | Same panel | Minimum is **clamped to 20** and `minimum_clamped` is signalled |
| MTP-PRC-12 | EDGE | P0 | cashier (no `pricing.sell_below_*`), `DiscountFloorMode = Block` | Document line priced **below cost** | `DocumentLineEditor` shows the blocked (red) state; save is **refused** by `DiscountPolicyDocumentValidator` |
| MTP-PRC-13 | EDGE | P0 | manager (`pricing.sell_below_cost`) **and** `company.allow_below_cost_sales = false` | Same below-cost line | Still **refused** — the permission alone is insufficient; both conditions are required |
| MTP-PRC-14 | EDGE | P1 | manager with `sell_below_cost` and `allow_below_cost_sales = true` | Same line | Allowed; the override is recorded |
| MTP-PRC-15 | EDGE | P1 | user with `sell_below_target_margin` only | Line below **target** but above **minimum** (yellow) | Allowed with a warning |
| MTP-PRC-16 | EDGE | P1 | same user | Line below **minimum** margin (orange) | Refused — needs `sell_below_minimum_margin` |
| MTP-PRC-17 | EDGE | P1 | `DiscountFloorMode = Advisory` | Below-floor line, cashier | Warning only, save allowed — proves the mode actually changes behavior |
| MTP-PRC-18 | EDGE | P1 | user without `pricing.view_cost_prices` | Open `DocumentLineEditor` / `PricingIntelligencePanel` | Margin/floor/cost figures are absent from the UI **and** the API payload (SERVER-AUTHORITATIVE permission) |

**Subtotal §A: 12 + 5 + 18 = 35 cases** (6 HAPPY / 29 EDGE · P0 14 · P1 21 · P2 0)

<!-- SECTION-A-END -->

## §B — `DOC` documents · `TAX` VAT decomposition · `DSC` discounts (W2)

**Entry points.** `/sales/quotes[/new|/:id|/:id/edit]`, `/sales/orders[…]`, `/sales/invoices[…]`,
`/sales/credit-notes[/new|/create|/:id]`, `/sales/return-notes[…]`,
`/inventory/delivery-notes[…]`, `/inventory/return-notes[…]`.
Legacy `/documents*` and `/partners*` redirect to `/sales/invoices` and `/sales/customers`.

### B.0 The exact totals pipeline — assert against this, not against intuition

`DocumentTotalsCalculator` (via `Document::recalculateTotals()`) delegating to
`TaxCalculationService::calculateDocumentTaxes()`:

1. **Per-line net** — `DocumentLine::computeLineTotal()`:
   `subtotal = bcmul(qty, unit_price, scale)`; then **if `discount_percent` is non-zero**,
   `discount = subtotal × discount_percent/100` and subtract — **percent takes precedence**;
   **else** subtract `discount_amount` directly. (`unit_price` here is **NET / HT**.)
2. **Document subtotal** = Σ per-line nets, **then** the header `documents.discount_amount` is
   subtracted **once at the aggregate**.
3. **VAT is computed PER LINE, not on the aggregate.** Lines are grouped by `tax_rate`; each line's
   net is multiplied by the rate and accumulated at **`scale + 1`**, then truncated to the currency
   scale **exactly once** at the end via `CurrencyScale::bcformat()` (**truncates**, never half-up).
4. **Document-level taxes** (`applies_to = DOCUMENT_TOTAL`, e.g. Tunisia stamp duty) computed on the
   subtotal — a `FixedAmount` config returns `fixed_amount` verbatim, **no proration by quantity**.
5. `tax_amount = lineItemsTaxTotal + documentTaxTotal`; `total = subtotal + tax_amount`.
6. Persisted: `subtotal`, `line_tax_amount`, `stamp_duty_amount`, `tax_amount`, `total`.

**Validation:** `lines.*.discount_percent` — `nullable, numeric, min:0, max:100, regex:/^\d+(\.\d{1,2})?$/`;
`lines.*.discount_amount` — `nullable, numeric, min:0, regex:/^\d+(\.\d{1,3})?$/`.

> **Header discount is schema-only.** `documents.discount_amount` (`decimal:3`) exists and is
> honored by the calculator, but **no create/update endpoint currently ships it in its payload**
> (per `AppliesDiscountToleranceRule`'s own note). So "whole-document discount" is **not reachable
> from the web UI on documents** — the whole-receipt discount concept lives in the POS. `MTP-DSC-06`
> records this rather than asserting a UI that does not exist.

> **Eco-tax is schema-only.** `eco_tax_amount` (`decimal:5`), `eco_tax_rate` (`decimal:4`),
> `eco_tax_category` exist on `DocumentLine` but are **not referenced** by
> `DocumentTotalsCalculator` or `TaxCalculationService` (Phase 1: always null). `MTP-TAX-08` asserts
> the absence.

**Status model.** `Draft, Confirmed, Posted, Paid, Received, Cancelled`. `isEditable()` is true only
for **Draft** and **Confirmed**. `DocumentPostingService::post()` seals fiscal types (Invoice,
CreditNote) into the SHA-256 chain (`fiscal_status = Sealed`), after which `isFiscallyImmutable()`
also blocks edits. Enforcement is **per-controller at the top of `update()`**, not central.

| ID | Type | P | Preconditions | Steps | Expected result (exact) |
|---|---|---|---|---|---|
| MTP-DOC-01 | HAPPY | P0 | TN tenant, VAT 19%, stamp `1.000` active | `/sales/invoices/new` → L1 qty `10` @ net `12.500`, L2 qty `4` @ net `25.000` → save | `subtotal = 125.000 + 100.000 = 225.000` · `line_tax_amount = 42.750` · `stamp_duty_amount = 1.000` · `tax_amount = 43.750` · **`total = 268.750`** |
| MTP-DOC-02 | EDGE | P0 | as 01 | Verify the aggregate identity | `subtotal + tax_amount == total` → `225.000 + 43.750 == 268.750` exactly |
| MTP-DOC-03 | EDGE | P0 | TN tenant | Invoice with a single line qty `3` @ net `33.333` (VAT 19%) | net `99.999`; VAT accumulates at `scale+1`: `99.9990 × 0.19 = 18.99981 → 18.9998`, truncated once to **`18.999`** (half-up would give `19.000` — that is the failure signature). `total = 99.999 + 18.999 + 1.000 = 119.998` |
| MTP-DOC-04 | HAPPY | P0 | mixed rates configured (19% and 7%) | Invoice: L1 net `100.000` @19%, L2 net `100.000` @7% | `subtotal 200.000` · per-rate VAT `19.000` + `7.000` = `26.000` · stamp `1.000` · `tax_amount 27.000` · `total 227.000`. Per-rate grouping must be visible in the tax breakdown. |
| MTP-DOC-05 | EDGE | P0 | as 04 | Check the decomposition invariant | **At the aggregate level:** `Σ per-rate net == subtotal` and `Σ per-rate vat == line_tax_amount`. Do **not** assert per-line `unit_price × qty` arithmetic against a POS line — that is only valid for documents, where `unit_price` is net. |
| MTP-DOC-06 | HAPPY | P1 | quote exists | `/sales/quotes/:id` → convert to order → convert to invoice | Totals carry through **byte-identically** at each conversion; no re-rounding drift |
| MTP-DOC-07 | EDGE | P0 | invoice in `Draft` | Edit a line, save | Allowed (`isEditable`) and totals recompute |
| MTP-DOC-08 | EDGE | P0 | invoice `Posted` (sealed) | Attempt to edit a line via the UI and via a direct `PUT` | Refused by **both** `isFiscallyImmutable()` and `isEditable()` checks; the fiscal-immutability error is distinct from the not-editable error |
| MTP-DOC-09 | EDGE | P0 | invoice `Paid` / `Cancelled` | Attempt an edit | Refused |
| MTP-DOC-10 | EDGE | P0 | invoice `Confirmed` | Edit a line, then post | Allowed while Confirmed; on `post()` the fiscal chain seals it and the hash is recorded |
| MTP-DOC-11 | EDGE | P1 | any document | Enter `unit_price = 12.5001` | Rejected by the money regex ceiling |
| MTP-DOC-12 | EDGE | P1 | any document | Enter `quantity = 1.00001` | Rejected by the quantity regex ceiling (4 dp) |
| MTP-DOC-13 | EDGE | P1 | any document | Enter `quantity = 0` | Record actual: either refused, or a zero line contributing `0.000` to every total without breaking the identity |
| MTP-DOC-14 | EDGE | P1 | any document | Enter a negative `unit_price` | Rejected — document money fields are non-negative |
| MTP-DOC-15 | EDGE | P2 | product with a fractional unit (`decimal_places = 3`) | Line with qty `2.500` | Quantity displays at the **unit's** precision (`2.500`), not `2.5000`; line net `= 2.500 × unit_price` truncated at the currency scale |
| MTP-DOC-16 | HAPPY | P0 | invoice from MTP-DOC-01 (`total 268.750`), unpaid | `/sales/credit-notes/create` → source invoice, **amount-based** credit of `100.000` | Credit note created; its `subtotal`/`tax_amount`/`total` are stored **POSITIVE** (no negation); lines prorated by ratio |
| MTP-DOC-17 | EDGE | P0 | as 16 | Check the source invoice | `balance_due` reduced by `100.000` via the `CreditNoteAllocation` DB trigger — **not** by a negative document total |
| MTP-DOC-18 | EDGE | P0 | as 16 | Attempt a further credit note of `200.000` (remaining is `168.750`) | **Refused** — amount must be ≤ the invoice's remaining total |
| MTP-DOC-19 | HAPPY | P1 | fresh invoice `268.750` | Full credit note for the whole remaining amount | `balance_due = 0.000`; invoice reaches the credited/settled state |
| MTP-DOC-20 | HAPPY | P1 | invoice with 2 lines | **Line-based** credit note selecting `{line_id, quantity}` for one line only | Only that line's value is credited; the other line untouched |
| MTP-DOC-21 | EDGE | P1 | TN tenant | Any credit note | `stamp_duty_amount = 0.600` (`STAMP_CREDIT_NOTE`), not `1.000` |
| MTP-DOC-22 | HAPPY | P2 | — | `/sales/credit-notes/new` (generic `DocumentForm`) vs `/sales/credit-notes/create` (`CreateCreditNotePage`) | Both routes load. `/create` is the invoice-linked flow; `/new` is the standalone form. Record which one the operator should use so the runbook is unambiguous. |
| MTP-DOC-23 | EDGE | P1 | standalone credit note (no source invoice) | Create via `/new` | Allowed; no allocation created; totals positive |
| MTP-DOC-24 | EDGE | P1 | invoice for a **different** partner | Attempt to credit-note it against `{{CUSTOMER_B}}` | Refused — partner mismatch |
| MTP-TAX-01 | EDGE | P0 | 0% VAT rate configured | Invoice line at 0% | `line_tax_amount = 0.000`; stamp still applies; `total == subtotal + stamp` |
| MTP-TAX-02 | EDGE | P0 | tax status `NON_REGISTERED` | Create an invoice | Record the actual behavior: VAT should not be charged; the identity must still hold and the document must not show a phantom VAT line |
| MTP-TAX-03 | EDGE | P1 | stamp duty config deactivated | New invoice | `stamp_duty_amount = 0.000`; `tax_amount == line_tax_amount` |
| MTP-TAX-04 | EDGE | P1 | two `DOCUMENT_TOTAL` taxes with `stacks_on = TOTAL_INCLUDING_PREVIOUS` | Invoice | The second is computed on the running total including the first; record the exact stacked figures |
| MTP-TAX-05 | EDGE | P1 | invoice with 40 lines at 19% | Save | VAT is accumulated at `scale+1` across all lines and truncated **once** — the total must equal the single-pass computation, not the sum of 40 individually-truncated line VATs (which would drift by up to 40 millimes) |
| MTP-TAX-06 | EDGE | P0 | posted invoice | Change the tax configuration rate afterwards | The posted document's figures are **unchanged** (snapshotted); only new documents use the new rate |
| MTP-TAX-07 | HAPPY | P1 | `/finance/vat-periods` | Open a period covering MTP-DOC-01/04 | Output VAT per rate: `base_amount` / `vat_amount` / `document_count` / `is_recoverable`; `net_vat`; `credit_brought_forward` / `carried_forward`; `amount_payable`. **Σ per-rate `vat_amount` == output VAT total.** |
| MTP-TAX-08 | EDGE | P1 | any document line | Inspect `eco_tax_amount` / `eco_tax_rate` on the saved line and in the totals | Eco-tax is **schema-only** — always null and **never** part of `tax_amount`/`total`. A non-zero eco-tax contribution would be an unspecified behavior change. |
| MTP-TAX-09 | EDGE | P0 | `/finance/vat-periods` | **Close** a period (`reports.manage`), then re-read `/finance/vat-report/:id` | Closed periods return the **persisted snapshot** (`VatPeriodBreakdown`), not a live query. Post a new invoice into the closed period and confirm the snapshot does **not** move. |
| MTP-TAX-10 | EDGE | P1 | closed period | **Reopen**, then re-read | Live query resumes and now includes the newly posted invoice |
| MTP-TAX-11 | EDGE | P1 | closed period | **File** the period | Filing recorded; record whether a filed period can still be reopened |
| MTP-TAX-12 | EDGE | P1 | user with `reports.view` but not `reports.manage` | Attempt close/reopen/file | Read allowed; all three actions 403 |
| MTP-DSC-01 | HAPPY | P0 | invoice | L1 qty `10` @ `12.500`, `discount_percent = 10.00` | `subtotal_before = 125.000`, discount `= 12.500`, **line net `= 112.500`**; VAT `= 21.375`; `total = 112.500 + 21.375 + 1.000 = 134.875` |
| MTP-DSC-02 | EDGE | P0 | as 01 | Set `discount_percent = 10.00` **and** `discount_amount = 50.000` on the same line | **Percent wins** — line net `= 112.500`, NOT `75.000`. This precedence is easy to get backwards and silently over-discounts. |
| MTP-DSC-03 | HAPPY | P1 | invoice | L1 qty `10` @ `12.500`, `discount_amount = 25.000`, percent empty | line net `= 100.000`; VAT `= 19.000`; `total = 120.000` |
| MTP-DSC-04 | EDGE | P1 | as 03 | `discount_amount` greater than the line subtotal (`200.000` on a `125.000` line) | Refused, or the line net floors at `0.000` — never negative. Record actual. |
| MTP-DSC-05 | EDGE | P1 | as 01 | `discount_percent = 100.01`, then `= 10.001` | Both rejected (`max:100`; 2-dp ceiling) |
| MTP-DSC-06 | EDGE | P1 | any document | Look for a whole-document discount field in the UI | **Absent** — `documents.discount_amount` exists in the schema and the calculator honors it, but no endpoint ships it. Record; do not file. Whole-receipt discounts are a POS concept (see `MTP-RFD-06`). |
| MTP-DSC-07 | EDGE | P1 | `AppliesDiscountToleranceRule` active on invoices/orders | Apply a discount above the configured tolerance | The `DiscountAboveTolerance` rule fires; record whether it warns or blocks |
| MTP-DSC-08 | EDGE | P1 | user with `max_discount_percent = 10.00` | Apply an 11% line discount | Refused / requires override — see `MTP-PERM-13` |
| MTP-DSC-09 | EDGE | P1 | promotion, `applies_to = transaction`, `discount_type = percentage`, `discount_value = 10`, `max_discount_amount = 5.000` | Receipt/order of `100.000` | Discount capped at **`5.000`**, not `10.000` |
| MTP-DSC-10 | EDGE | P1 | promotion `applies_to = cheapest_item` | Basket with items `3.000` / `7.000` | Only the `3.000` line is discounted |
| MTP-DSC-11 | EDGE | P1 | coupon with `minimum_order_amount = 50.000` | Order of exactly `50.000`, then `49.999` | `50.000` accepted (inclusive), `49.999` refused |
| MTP-DSC-12 | EDGE | P1 | coupon with `max_uses_per_customer = 1` | Same customer redeems twice | Second redemption refused |
| MTP-DSC-13 | EDGE | P1 | two `is_exclusive` promotions both matching | Apply | Only one applies; the selection is deterministic by `priority` |
| MTP-DSC-14 | EDGE | P1 | promotion validation | `discount_value = 0` and `= -1` | Both rejected (`gt:0`); `discount_value` accepts ≤4 dp, `max_discount_amount` ≤3 dp |
| MTP-DSC-15 | EDGE | P1 | voucher (`/pos/vouchers/:id`) with `current_balance = 20.000` | Redeem against a `50.000` total | Voucher applies as a **whole-receipt tender** of `20.000` (not a per-line discount); remaining `30.000` due by another method; balance → `0.000` |
| MTP-DSC-16 | EDGE | P1 | goodwill voucher above `goodwill_four_eyes_threshold` (`250.00`) | Issue with a single admin | Refused — a `second_admin_user_id` is required (four-eyes) |
| MTP-DSC-17 | EDGE | P1 | voucher with `expires_at` in the past | Attempt redemption | Refused; balance untouched |
| MTP-DSC-18 | EDGE | P2 | voucher `redemption_mode = CustomerBound` | Attempt redemption by a different customer | Refused |

**Subtotal §B: 24 + 12 + 18 = 54 cases** (10 HAPPY / 44 EDGE · P0 18 · P1 33 · P2 3)

<!-- SECTION-B-END -->

## §C — `PUR` Purchasing: PO, goods receipt, supplier invoice, 3-way matching, bonuses, landed cost (W3)

**Entry points.** `/purchases/orders`, `/purchases/orders/new`, `/purchases/orders/:id` (landed-cost
and additional-costs panels live **inside** the PO detail page — `LandedCostBreakdown.tsx`,
`AdditionalCostsForm.tsx`, `PurchaseOrderLandedCostBreakdown.tsx`, `PurchaseOrderAdditionalCosts.tsx`),
`/purchases/receipts`, `/purchases/receipts/new` (perm `goods-receipt.create-standalone`; **no**
`receipts/:id` detail route exists — do not write a case that navigates to one),
`/purchases/supplier-invoices`, `/new`, `/:id`, `/purchases/quote-requests*`, `/purchases/scans*`.
Matching tolerances are configured at **`/settings/company` → Procurement**.

**Arithmetic.** `unit_price` here is **net/HT**. Matcher: `SupplierInvoiceMatcher.php`, two-tier —
**HARD** blocks (quantity over-clear, unlinked/exception lines) always block regardless of
enforcement; **ADVISORY** (price variance) is governed by `match_enforcement` (`warn` passes,
`block` throws). Dual threshold, **AND** semantics:
`percentageThreshold = poExtended × (variance_tolerance_percent / 100)`;
`withinTolerance = variance ≤ percentageThreshold AND variance ≤ variance_tolerance_max_amount`.
Defaults: `variance_tolerance_percent = 2.00`, `variance_tolerance_max_amount = 1.000`,
`match_mode` ∈ two-way/three-way, `match_enforcement` ∈ warn/block. Money scale 3, qty scale 4.

| ID | Type | P | Preconditions | Steps | Expected result (exact) |
|---|---|---|---|---|---|
| MTP-PUR-01 | HAPPY | P0 | `{{SUPPLIER_A}}`, `{{PRODUCT_A}}` | `/purchases/orders/new` → supplier, line: qty `10`, unit price `12.500`, VAT 19% → save | Line net `125.000`; VAT `23.750`; PO total `148.750`. Stored at scale 3. |
| MTP-PUR-02 | HAPPY | P0 | MTP-PUR-01 PO | `/purchases/receipts/new` → receive full qty 10 | Receipt posts; stock +10.0000; a `recordPurchase` WAC blend runs (assert in MTP-INV-01) |
| MTP-PUR-03 | HAPPY | P0 | MTP-PUR-02 receipt | `/purchases/supplier-invoices/new` → link PO+receipt, qty 10 @ `12.500` | `match_status = matched`; per-line `price_variance = 0.000` |
| MTP-PUR-04 | EDGE | P0 | as 03, tolerance defaults | Invoice qty 10 @ `12.550` | variance `= |12.550−12.500| × 10 = 0.500`; pctThreshold `= 125.000 × 0.02 = 2.500`; `0.500 ≤ 2.500` AND `0.500 ≤ 1.000` → **`matched`**, no block |
| MTP-PUR-05 | EDGE | P0 | as 03 | Invoice qty 10 @ `12.600` | variance `= 1.000` — **exactly** at `variance_tolerance_max_amount`. Rule is `≤` → **`matched`**. This is the inclusive-boundary case; a `price_variance` here is a defect. |
| MTP-PUR-06 | EDGE | P0 | as 03 | Invoice qty 10 @ `12.601` | variance `= 1.010 > 1.000` → **`price_variance`** even though `1.010 ≤ 2.500` (amount ceiling binds) |
| MTP-PUR-07 | EDGE | P0 | PO line qty `1` @ `10.000` (extended `10.000`) | Invoice qty 1 @ `10.250` | pctThreshold `= 0.200`; variance `= 0.250`. `0.250 > 0.200` **but** `0.250 ≤ 1.000` → **`price_variance`**. Proves the threshold is **AND**, not OR. |
| MTP-PUR-08 | EDGE | P1 | as 07 | Invoice qty 1 @ `10.150` | variance `= 0.150 ≤ 0.200` AND `≤ 1.000` → **`matched`** (percentage binds but passes) |
| MTP-PUR-09 | EDGE | P0 | `match_enforcement = block` at `/settings/company` | Repeat MTP-PUR-06 | Invoice save is **refused** (typed error), no supplier-invoice row persisted, no GL leg |
| MTP-PUR-10 | EDGE | P1 | `match_enforcement = warn` | Repeat MTP-PUR-06 | Invoice **saves** with `match_status = price_variance` and a visible warning; downstream payment allowed |
| MTP-PUR-11 | EDGE | P0 | Receipt of qty 10 exists, already invoiced 10 | Invoice a further qty `1` against the same receipt | **HARD block** — quantity over-clear (`matchable = received − already-invoiced = 0`). Refused under BOTH `warn` and `block` enforcement. |
| MTP-PUR-12 | EDGE | P0 | Supplier invoice line with no PO/receipt link | Save | `match_status = exception`; HARD block — refused under both enforcements |
| MTP-PUR-13 | EDGE | P0 | Supplier invoice referencing a receipt from **another company** | Save | `exception`, refused. No cross-company data rendered. |
| MTP-PUR-14 | HAPPY | P0 | `{{COUNTRY_A}} = TN` (bonus gate allowlist `procurement.bonus_quantity_countries` default `['TN']`), stock of `{{PRODUCT_A}}` = 0 | Receipt: paid qty `10` @ `10.000` + free/bonus qty `2` | Two WAC movements: paid `10 @ 10.000`, free `2 @ 0`. Resulting WAC `= 100 / 12 = 8.333333` at `COST_SCALE=6` (**truncated**, not `8.333334`). Line `effective_unit_cost = 8.333333`. |
| MTP-PUR-15 | EDGE | P1 | Tenant whose country is **not** in the bonus allowlist | Attempt a bonus-quantity receipt | Bonus-quantity field is unavailable / refused by `PurchaseBonusGate` |
| MTP-PUR-16 | EDGE | P1 | MTP-PUR-14 receipt invoiced | Invoice the bonus line (`is_bonus_line`) at a non-zero price | Bonus line is matched against `free_matchable_qty` only; **no price check** is applied to it |
| MTP-PUR-17 | HAPPY | P0 | PO with 2 lines: L1 net `100.000` (qty 10), L2 net `50.000` (qty 5) | Add additional cost (freight) `30.000` on `/purchases/orders/:id` → allocate | Proportional-by-line-value: L1 `= 30.000 × 100/150 = 20.000`, L2 `= 10.000`. **Σ allocated == 30.000 exactly.** L1 landed unit cost `= (100.000 + 20.000)/10 = 12.000000` at COST_SCALE 6. |
| MTP-PUR-18 | EDGE | P0 | PO with 3 lines of equal net value `100.000` each | Additional cost `10.000` → allocate | Largest-remainder reconciliation (`ProportionalMoneyAllocator`): shares at scale 3 are `3.333 / 3.333 / 3.334` (order per allocator) and **sum to exactly `10.000`**. No `9.999`, no `10.001`. |
| MTP-PUR-19 | EDGE | P0 | PO with allocated landed costs, goods **already received** (`goods_received_at` set) | Attempt to edit additional costs | Refused — `canModifyCosts()` blocks post-receipt reallocation |
| MTP-PUR-20 | EDGE | P1 | PO with allocated costs, costs modified **before** posting the receipt | Post the goods receipt | `reallocateCosts()` re-runs; landed unit costs reflect the modified pool; Σ allocated still equals the pool exactly |
| MTP-PUR-21 | EDGE | P1 | Receipt in progress, user **without** `goods-receipt.edit-price` | Attempt to change `received_unit_price` | Field is not editable / mutation 403 |
| MTP-PUR-22 | EDGE | P1 | Receipt in progress, user **with** `goods-receipt.edit-price` | Override `received_unit_price` from `12.500` to `13.000` with a reason | Override persists and the audit trio is recorded (`price_override_by`, `price_override_at`, `old_basis`, `reason`); WAC blends on `13.000`, not `12.500` |
| MTP-PUR-23 | EDGE | P1 | any PO | Enter unit price `12.5001` (4 dp) | Rejected by the money regex ceiling `/^\d+(\.\d{1,3})?$/` with the "at most 3 decimal places" message — **not** silently truncated |
| MTP-PUR-24 | EDGE | P1 | any PO | Enter qty `1.00001` (5 dp) | Rejected by the quantity regex ceiling `…{1,4}` |
| MTP-PUR-25 | EDGE | P2 | any PO | Enter unit price `0` and qty `1` | Zero-value line accepted (legitimate free line) or refused per validator — record actual; if accepted, PO total must not become `NaN`/blank |
| MTP-PUR-26 | EDGE | P1 | any PO | Enter negative unit price `-1.000` | Rejected — money fields here are non-negative (`^\d` form, no `-?`) |
| MTP-PUR-27 | EDGE | P2 | Fresh tenant, no purchases | `/purchases/supplier-invoices` | Empty state renders; no `0.000` totals presented as real data; no JS error |

**Subtotal §C: 27 cases** (5 HAPPY / 22 EDGE · P0 15 · P1 10 · P2 2)

<!-- SECTION-C-END -->

## §D — `INV` Inventory valuation: WAC, opening balances, counting variance, write-offs (W3)

**Entry points.** `/inventory/products/:id` (WAC / cost price — `ProductDetailPage.tsx`, field gated by
`pricing.view_cost_prices`; stock value in `ProductStockLevels.tsx`), `/inventory/stock`,
`/inventory/stock-by-location`, `/inventory/movements`, `/inventory/counting/:id/report`
(`DiscrepancyReportPage.tsx`), `/inventory/expiry-write-off` (ModuleGuard `BatchExpiry` + perm
`batches.write-off`), `/inventory/stock-transfers*`, `/settings/opening-balances[/:type]`
(types: ACCOUNTING / INVENTORY / AR_OPEN_ITEMS / AP_OPEN_ITEMS).

**Arithmetic — read before computing any expected value.**
`WeightedAverageCostService`: `COST_SCALE = 6` (cost_price is persisted at **6 dp**, not at the
currency scale); `workingScale = max(currencyScale + 4, COST_SCALE + 1)` = **7** for TND;
persistence uses `CurrencyScale::bcformat()` which **TRUNCATES toward zero** to 6 dp. Rounding to
the currency scale happens only at the GL/COGS/display boundary via `CurrencyScale::bcround()`
(explicit half-up away from zero). Counting variance value uses `bcformatStrict` — **also
truncating**. Quantity scale fixed at 4.

> **Two documented UI gaps — do NOT write assertions that expect money on these pages:**
> `/inventory/movements` does **not** render `unit_cost` / `avg_cost_before` / `avg_cost_after` /
> `total_cost` columns even though `StockMovement` persists them. `/inventory/expiry-write-off` is
> **quantity-only** — it displays no money total. Both are asserted as *absences* below so the
> campaign records them rather than tripping over them.

| ID | Type | P | Preconditions | Steps | Expected result (exact) |
|---|---|---|---|---|---|
| MTP-INV-01 | HAPPY | P0 | `{{PRODUCT_A}}` stock = 0; MTP-PUR-02 receipt of 10 @ `12.500` | `/inventory/products/:id` as a user **with** `pricing.view_cost_prices` | WAC / cost price `= 12.500000` (6 dp at rest; display truncates to currency scale). Stock value `= 10 × 12.500 = 125.000` |
| MTP-INV-02 | EDGE | P0 | INV-01 state (10 @ 12.500) | Receive 5 more @ `13.000`; reload product | New WAC `= (125.000 + 65.000) / 15 = 190/15 = 12.666666` — **truncated**, NOT `12.666667`. A half-up value here is a precision-contract violation. |
| MTP-INV-03 | EDGE | P0 | `{{PRODUCT_B}}` stock 3 @ `10.000` | Receive 4 @ `11.000` | WAC `= 74/7 = 10.571428571… → 10.571428` (truncated at 6 dp) |
| MTP-INV-04 | EDGE | P0 | Product driven to zero/negative company-owned qty | Post a receipt/return that leaves `newCompanyQty ≤ 0` | WAC is forced to `'0'` — **no** division-by-zero, no `NaN`, no 500 |
| MTP-INV-05 | EDGE | P1 | Product with total owned qty `≤ 0` | Trigger a cost adjustment (e.g. complete a transfer carrying `transfer_cost`) | `recordCostAdjustment` **no-ops** (nothing posted) — "nothing owned to capitalize against" |
| MTP-INV-06 | EDGE | P0 | Product with stock 2 | Attempt a document/flow that would consume 5 | `\DomainException` — WAC service refuses to drive stock negative; no partial state written |
| MTP-INV-07 | EDGE | P0 | User **without** `pricing.view_cost_prices` | `/inventory/products/:id` | Cost/WAC field and stock **value** are absent (not blanked-but-present, not `0.000`). API response must not carry the cost either. |
| MTP-INV-08 | EDGE | P2 | any product with movements | `/inventory/movements` | Documented gap: **no** cost columns rendered. Record as-is; do not file as new. |
| MTP-INV-09 | HAPPY | P1 | 2 locations, stock at `{{LOCATION_1}}` | `/inventory/stock-transfers/new` → transfer with a `transfer_cost`, complete it | On completion the freight is capitalized into WAC via `recordCostAdjustment`; new WAC = `(old value + transfer_cost) / qty` truncated at 6 dp |
| MTP-INV-10 | HAPPY | P0 | Fresh company, no opening posted | `/settings/opening-balances/INVENTORY` → wizard: setup → upload CSV (`10 × 5.000`, `4 × 12.250`, `2.5 × 8.000`) → validate → preview → post | Rows `VALID`; posted total `= 50.000 + 49.000 + 20.000 = 119.000`. One balanced journal entry: **Dr Inventory `119.000` / Cr Opening Balance Equity `119.000`**. One `Opening` StockMovement + StockLevel update per line; `cost_price` stamped from `unit_cost`. |
| MTP-INV-11 | EDGE | P0 | MTP-INV-10 posted | Attempt to post an opening batch for the same scope again | Refused — `OpeningAlreadyExistsException` (enter-once guard). No second JE, no duplicated stock. |
| MTP-INV-12 | EDGE | P1 | CSV with 1 malformed row among 3 | Upload → validate | Bad row `INVALID` and **excluded** from the posted total; the preview total equals the sum of `VALID` rows only |
| MTP-INV-13 | EDGE | P1 | CSV line with `unit_cost = 0` | Post | Row posts qty but `cost_price` is **not** stamped; its JE contribution is `0.000`; the batch JE stays balanced |
| MTP-INV-14 | EDGE | P1 | CSV with `unit_cost = 5.0001` and `qty = 1.00001` | Validate | Both rejected by the scale ceilings (money 3 dp, qty 4 dp) with per-row messages — never silently truncated |
| MTP-INV-15 | EDGE | P1 | Posted batch | Complete the wizard's **lock** step, then attempt a further post | Locked batch refuses further posting |
| MTP-INV-16 | EDGE | P0 | Count applied on a product whose basis cost is `10.571428` (from INV-03); variance qty `−1.5000` | `/inventory/counting/:id/report` | `varianceValue = −1.5000 × 10.571428 = −15.857142` → `bcformatStrict` at scale 3 **truncates toward zero** → **`−15.857`** (NOT `−15.858`) |
| MTP-INV-17 | EDGE | P0 | Count with both positive and negative variances | Same report | `total_variance_value.net == total_variance_value.positive + total_variance_value.negative` **exactly** at scale 3; `variance_breakdown` counts by resolution method sum to the line count |
| MTP-INV-18 | EDGE | P0 | Non-onboarding count whose correction would leave stock negative | Apply the count | Negative-at-apply guard **blocks** that line — no movement posted, line surfaced for manual review, rest of the count applies |
| MTP-INV-19 | HAPPY | P1 | First/onboarding count on a fresh product | Apply | Posts an `opening` movement and blends WAC via `openingUnitCost`; negative result is permitted for onboarding by design |
| MTP-INV-20 | EDGE | P2 | Count with zero discrepancies | `/inventory/counting/:id/report` | Empty/zero report renders; `total_variance_value.net = 0.000`; no blank or `NaN` cells |
| MTP-INV-21 | EDGE | P1 | Expired lot exists | `/inventory/expiry-write-off` → select lot, write off qty `3.0000` | Documented gap: **no money total on this page**. Verify server-side that the write-off value `= 3 × WAC` posted to the write-off movement/GL. |
| MTP-INV-22 | EDGE | P1 | User without `batches.write-off`, or `BatchExpiry` module off | `/inventory/expiry-write-off` | Route blocked by `ModuleGuard` / permission — no write-off mutation reachable |
| MTP-INV-23 | EDGE | P1 | Two locations with stock | `/inventory/stock-by-location` | Per-location quantities render at the **unit's** `decimal_places` (pieces → `7`, not `7.0000`); Σ per-location qty == the product's total on-hand |
| MTP-INV-24 | EDGE | P2 | Fresh tenant | `/inventory/stock` | Empty state; no fabricated `0.000` valuation rows |

**Subtotal §D: 24 cases** (4 HAPPY / 20 EDGE · P0 11 · P1 10 · P2 3)

<!-- SECTION-D-END -->

## §E — `TRE` treasury · `WHT` withholding (W4)

**Entry points.** `/treasury/payments[/new|/:id]`, `/treasury/instruments[/:id]`,
`/treasury/remittances[/new|/:id]`, `/treasury/payment-methods`, `/treasury/repositories[/:id]`,
`/treasury/statements[/:id]`, `/treasury/withholding-certificates[/:id]`,
`/treasury/sales-withholding-tracking`, `/expenses[/new|/:id|/:id/edit|/categories|/recurring|/analytics]`,
`/income[/new|/:id/edit]`, `/finance/cash-movements`, `/finance/overview`.

> **Two structural findings to test explicitly.**
> 1. **`PaymentDetailPage` does not gate its action buttons with `hasPermission`** — there is no
>    `usePermissions` import. Refund / Partial Refund / Reverse are rendered for anyone who can see
>    the page; enforcement is **backend-only**. `MTP-TRE-12` asserts exactly this shape (button
>    visible, API 403) so the gap is recorded rather than mistaken for a permission bypass.
> 2. **`payments.void` does not void a payment.** There is **no `DELETE /payments/{payment}` route**
>    in the API, yet `PaymentDetailPage` calls `apiDelete` in its `pending` branch — and `store()`
>    always creates payments as `Completed`, so that branch is **unreachable dead code**.
>    `payments.void` actually gates `POST /documents/{document}/refund-prepayment`.
>    `MTP-TRE-13`/`14` pin both facts.

### E.1 `TRE` — payments and allocations

Validation: `amount` — `numeric, min:0.01, regex:/^\d+(\.\d{1,3})?$/` (client also requires
`bccomp(v,'0') > 0`); `allocations[].amount` — same regex, `min:0.01`;
`withholding_rate` — `regex:/^\d+(\.\d{1,4})?$/`, range **0–1** (a **fraction**, not a percentage).

| ID | Type | P | Preconditions | Steps | Expected result (exact) |
|---|---|---|---|---|---|
| MTP-TRE-01 | HAPPY | P0 | `{{CUSTOMER_A}}` with invoice INV-A balance `600.000` and INV-B balance `400.000` | `/treasury/payments/new` → amount `1000.000`, cash method, allocate `600.000` + `400.000` | Payment `Completed`; both invoices `balance_due = 0.000`; `unallocated_amount = 0.000` |
| MTP-TRE-02 | EDGE | P0 | as 01 | Allocate `600.000` + `500.000` on a `1000.000` payment | **422 `ALLOCATION_EXCEEDS_PAYMENT`**; nothing persisted |
| MTP-TRE-03 | EDGE | P0 | INV-A balance `600.000` | Payment `1000.000`, allocate `700.000` to INV-A | Allocation capped at the document's `balance_due` (`600.000`); the surplus becomes **`unallocated_amount = 400.000`**, surfaced as "Credit balance" on the payment detail page. Never an over-allocated invoice, never a negative `balance_due`. |
| MTP-TRE-04 | HAPPY | P1 | INV-A balance `600.000` | Payment `250.000` fully allocated to INV-A | `balance_due = 350.000`; invoice stays partially paid |
| MTP-TRE-05 | EDGE | P0 | invoices belonging to `{{CUSTOMER_A}}` and `{{CUSTOMER_B}}` | One payment allocated across both | **`ALLOCATION_PARTNER_MISMATCH`**; nothing persisted |
| MTP-TRE-06 | EDGE | P0 | one AR invoice and one AP supplier invoice | One payment allocated to both | Refused — AP and AR allocations cannot be mixed in a single payment |
| MTP-TRE-07 | EDGE | P1 | — | Amount `0`, then `0.009`, then `12.5001` | `0` refused (`min:0.01`); `0.009` refused (`min:0.01`); `12.5001` refused (3-dp ceiling) |
| MTP-TRE-08 | EDGE | P1 | — | Negative amount `-100.000` | Refused |
| MTP-TRE-09 | HAPPY | P0 | completed payment `1000.000` | `/treasury/payments/:id` → **Refund** (full) | Reverses the money and the GL leg; the allocated invoices' `balance_due` is restored to `600.000` / `400.000` |
| MTP-TRE-10 | HAPPY | P1 | completed payment `1000.000` | **Partial Refund** of `250.000` | Only `250.000` reversed; allocations adjusted consistently; Σ(refunds) can never exceed the payment |
| MTP-TRE-11 | EDGE | P1 | completed payment | **Reverse** | Distinct from refund — record the resulting status, GL shape, and whether the allocations are released |
| MTP-TRE-12 | EDGE | P0 | **cashier** (`payments.view/create` only) | Open `/treasury/payments/:id`, click Refund / Partial Refund / Reverse | **Buttons are VISIBLE** (no FE permission gate) but every API call returns **403**. No money moves, no GL leg. Record the UI gap; the P0 assertion is that the backend holds. |
| MTP-TRE-13 | EDGE | P1 | any completed payment | Look for a "Cancel Payment" action | The `pending` branch is **unreachable** (`store()` always creates `Completed`) and the `apiDelete` it calls has **no matching route**. Record as dead code; do not file as a broken button unless it is reachable. |
| MTP-TRE-14 | EDGE | P1 | prepayment on a document | `POST /documents/{document}/refund-prepayment` as a `payments.void` holder, then as a non-holder | Holder allowed, non-holder 403 — confirming `payments.void` gates *this*, not payment voiding |
| MTP-TRE-15 | EDGE | P1 | — | Payment with `withholding_rate = 0.0150` on a gross of `1000.000` | Withholding `= 15.000`; net settled `= 985.000`. Rate is a **fraction** at 4 dp — entering `1.5` (as a percent) must be rejected or must mean 150%, never silently reinterpreted. |
| MTP-TRE-16 | EDGE | P1 | — | `withholding_rate = 1.00001` and `= 1.5` | 5-dp value rejected (4-dp ceiling); `1.5` rejected (range 0–1) |

### E.2 `TRE` — payment instruments (cheque / effet / LCR)

Statuses: `received, in_transit, deposited, clearing, cleared, bounced, expired, cancelled, collected`
— `in_transit`, `clearing`, `expired`, `collected` are **reserved-dormant** (no current transition
produces them). UI actions on `InstrumentDetailPage`: **Remit** (`instruments.remit`), **Transfer**
custody (`instruments.transfer`), **Cancel** (`instruments.cancel`), **Clear** (`instruments.clear`;
`fee_amount`, `fee_vat_amount`, `value_date`), **Bounce** (`instruments.bounce`; `routing` ∈
`re_present|receivable|doubtful`, `fee_amount`, `fee_vat_amount`, `reason`). Deposit exists as an
endpoint but is driven through the remittance flow. Outbound mirrors: `clear-outbound`,
`bounce-outbound`, `represent`, `cancel-outbound`. Fee fields are `numeric, regex:/^\d+(\.\d{1,3})?$/`.

| ID | Type | P | Preconditions | Steps | Expected result (exact) |
|---|---|---|---|---|---|
| MTP-TRE-17 | HAPPY | P0 | cheque method (`has_maturity`) | Create a payment with `instrument_number`, `maturity_date`, `drawer_name`, bank | Instrument created in **`received`**; the payment records the receivable, not cash in hand |
| MTP-TRE-18 | EDGE | P1 | effet/traite method | Omit `maturity_date` | Refused — `maturity_date` is required for `effet` |
| MTP-TRE-19 | HAPPY | P0 | instrument `received`, amount `1000.000` | Remit → remittance → **Clear** with `fee_amount 5.000`, `fee_vat_amount 0.950`, a `value_date` | Status `cleared`; bank repository credited; fee `5.000` + VAT `0.950` expensed; net effect `+994.050` on the bank leg (verify the actual netting convention and record it) |
| MTP-TRE-20 | EDGE | P0 | instrument `deposited`, amount `1000.000` | **Bounce** with `routing = re_present`, `fee_amount 10.000`, `fee_vat_amount 1.900`, a reason | Status `bounced`; the `1000.000` is reversed out of the bank; fees `11.900` posted; the receivable is restored |
| MTP-TRE-21 | EDGE | P1 | bounced instrument | **Bounce routing `receivable`** vs **`doubtful`** | Each routes to a different GL treatment — record both and confirm they differ |
| MTP-TRE-22 | EDGE | P1 | bounced instrument | **Transfer** custody back to a cash repository | Allowed from `bounced` and `received`; custody moves without changing the amount |
| MTP-TRE-23 | EDGE | P1 | instrument `received` | **Cancel** without a reason, then with one | Reason is required; on cancel the status is `cancelled` and no money remains committed |
| MTP-TRE-24 | EDGE | P1 | instrument `cleared` | Attempt Remit / Cancel | Refused — invalid transitions from a terminal state |
| MTP-TRE-25 | EDGE | P2 | any instrument | Try to reach `in_transit` / `clearing` / `expired` / `collected` | Unreachable (reserved-dormant). Confirm no UI action produces them. |
| MTP-TRE-26 | EDGE | P1 | fee entry | `fee_amount = 5.0001` | Refused (3-dp ceiling) |
| MTP-TRE-27 | EDGE | P1 | user lacking `instruments.clear` / `instruments.bounce` | Attempt each | 403 on both; UI actions absent |

### E.3 `TRE` — remittances (bordereaux)

`InstrumentRemittance`: status `draft → remitted → closed`, `bank_repository_id`, `instrument_kind`
(cheque/effet), `remittance_type` (collection/discount). Lines added/removed only while `draft`.
**"Remit" is the deposit action for the whole batch** — every line's instrument moves
`received|bounced → Deposited`, `deposited_at`/`deposited_to_id` are set, `InstrumentDeposited` fires.

> **Precision suspicion to test:** the displayed remittance total is a **client-side float reduce**
> (`slip.lines.reduce(...).toFixed(3)`), not a bcmath sum. `MTP-TRE-30` targets it.

| ID | Type | P | Preconditions | Steps | Expected result (exact) |
|---|---|---|---|---|---|
| MTP-TRE-28 | HAPPY | P0 | 3 cheques `333.333` / `333.333` / `333.334` in `received` | `/treasury/remittances/new` → add all 3 → total | Total displays **`1000.000`** exactly |
| MTP-TRE-29 | HAPPY | P0 | as 28 | **Remit** to a bank repository | All 3 instruments → `deposited`, `deposited_to_id` = that repository; remittance status `remitted`; lines can no longer be added |
| MTP-TRE-30 | EDGE | P1 | remittance with **≥ 20** lines of 3-dp amounts (e.g. twenty × `10.005`) | Compare the displayed total with the exact bcmath sum (`200.100`) | Must match **exactly**. A float artefact (`200.09999…` → wrong `toFixed`) is a precision-contract violation on a displayed money figure. |
| MTP-TRE-31 | EDGE | P1 | remittance in `draft` | Remove a line, re-check the total | Total recomputes exactly; the removed instrument returns to `received` and is remittable again |
| MTP-TRE-32 | EDGE | P1 | remittance `remitted` | Attempt to add/remove a line | Refused |
| MTP-TRE-33 | EDGE | P1 | remitted remittance | Clear one line and bounce another | Per-line clear/bounce works with the same fee fields as the single-instrument path; the remittance total is unaffected by the outcome |
| MTP-TRE-34 | EDGE | P1 | user without `instruments.remit` | Open a draft remittance | Remit action absent; API 403 |
| MTP-TRE-35 | EDGE | P1 | mixed cheques and effets | Attempt to add both kinds to one remittance | Refused / prevented — `instrument_kind` is per-remittance |

### E.4 `TRE` — bank statements and reconciliation

Import wizard: repository → file (`.csv`/`.xlsx`) → import profile (`parser`,
`direction_convention` signed|debit-credit, `decimal_format`, `date_format`, `header_rows`, column
mapping, `matching_window_days`) → preview (accepted / duplicate / dropped-zero / unparseable
counts) → confirm with `periodStart`, `periodEnd`, `openingBalance`, `closingBalance`.

> **There is NO amount tolerance in reconciliation matching.** Matching requires **exact** `bccomp`
> equality on the remaining amount (Tier-1 reference + exact amount; Tier-2 unique-amount-in-window).
> The only tolerance is a **date window** (`matching_window_days`, default **5**). Any case
> assuming a "close enough" amount match is invalid.

Completion requires **every** line resolved (`matched`, `resolved_by_creation`, or `ignored`).
Ignore reasons: `duplicate|informational|bank_error|out_of_scope|other`, permitted only when the
line has no allocations/executions. Permissions: `bank-statements.view` / `.import` / `.reconcile`,
plus a distinct `.reopen`.

| ID | Type | P | Preconditions | Steps | Expected result (exact) |
|---|---|---|---|---|---|
| MTP-TRE-36 | HAPPY | P0 | bank repository; CSV with 5 lines | `/treasury/statements` → upload wizard with a matching profile | Preview reports `accepted 5 / duplicate 0 / dropped-zero 0 / unparseable 0`; confirm with opening/closing balances |
| MTP-TRE-37 | EDGE | P0 | as 36 | Re-upload the **same** file | Rows classified **duplicate**, not re-imported. No double-counted bank lines. |
| MTP-TRE-38 | EDGE | P1 | CSV with a `0.000` line and a malformed line | Upload | Counted as **dropped-zero** and **unparseable** respectively; neither becomes a phantom movement |
| MTP-TRE-39 | EDGE | P0 | profile `direction_convention = signed` vs `debit-credit`, same underlying data | Import each way | Both produce **identical** signed amounts. A convention mismatch that flips a sign is launch-blocking. |
| MTP-TRE-40 | EDGE | P1 | profile with a European `decimal_format` (`1.234,56`) | Import | Parsed to `1234.560` at scale 3 — never `1.234` |
| MTP-TRE-41 | HAPPY | P0 | statement line `500.000` and a repository movement of exactly `500.000` within 5 days | Reconciliation workspace → accept the suggestion | Line `matched`; the movement is allocated once |
| MTP-TRE-42 | EDGE | P0 | statement line `500.000`, movement `500.001` | Open suggestions | **No match offered** — matching is exact `bccomp`, there is no amount tolerance. Manual match must also refuse to silently absorb the millime. |
| MTP-TRE-43 | EDGE | P1 | movement dated 6 days from the line, `matching_window_days = 5` | Suggestions | Not suggested (outside the date window); still reachable via manual search |
| MTP-TRE-44 | EDGE | P1 | statement line with no counterpart | Use **create-from-line** | A new payment/expense/income is created; line resolves as `resolved_by_creation` with the exact amount |
| MTP-TRE-45 | EDGE | P1 | statement line already allocated | Attempt **ignore** | Refused — ignoring is only allowed when the line has no allocations/executions |
| MTP-TRE-46 | EDGE | P0 | statement with one unresolved line | Attempt **complete** | Refused — completion requires every line resolved |
| MTP-TRE-47 | EDGE | P1 | fully resolved statement | Complete, then **reopen** (`bank-statements.reopen`) | Completion allowed; reopen restores editability and is gated by its own distinct permission |
| MTP-TRE-48 | EDGE | P1 | user with `bank-statements.view` only | Attempt import and reconcile actions | Both 403; the list remains visible |
| MTP-TRE-49 | EDGE | P1 | completed statement | Compare `closingBalance` with `openingBalance + Σ line amounts` | Equal exactly at scale 3 |

### E.5 `TRE` — repositories, adjustments, transfers

`RepositoryDetailPage` shows `balance` (sign-coloured), `totalTransactions`, `totalReceived`, plus a
**Movements** tab over the append-only `repository_movements` ledger
(`GET /payment-repositories/{id}/movements`, `treasury.view`).
**`treasury.adjust`** → `POST /payment-repositories/{repository}/adjustments`: one-sided correction
(`direction` in/out, `amount`, `reason_code` ∈ `count_variance|correction|theft_loss|other`,
`reason_text` **required**); posts a balanced GL entry (cash ↔ `PaymentToleranceExpense`/`Income`)
plus a `RepositoryMovement` (`sourceType: Adjustment`) in one transaction; **errors** if the
repository has no linked GL account.
**`treasury.transfer`** → `POST /payment-repositories/transfers`: same-currency only, `virtual`
repositories excluded; one balanced GL entry plus paired `out`/`in` movements sharing a
`transfer_group_id`.

| ID | Type | P | Preconditions | Steps | Expected result (exact) |
|---|---|---|---|---|---|
| MTP-TRE-50 | HAPPY | P0 | CASH-01 balance `1000.000`, BANK-01 balance `5000.000`, both TND | Transfer `500.000` CASH-01 → BANK-01 | CASH-01 `= 500.000`, BANK-01 `= 5500.000`; **exactly two** movements sharing one `transfer_group_id`; one balanced GL entry |
| MTP-TRE-51 | EDGE | P0 | CASH-01 (TND) and a EUR repository | Attempt a transfer between them | Refused — same-currency only |
| MTP-TRE-52 | EDGE | P1 | a `virtual` repository | Attempt a transfer to/from it | Excluded from the picker and refused by the API |
| MTP-TRE-53 | EDGE | P1 | CASH-01 balance `100.000` | Transfer `500.000` out | Record actual: refused, or balance goes negative. If negative is allowed it must render correctly and be visible in `/finance/cash-movements`. |
| MTP-TRE-54 | HAPPY | P0 | CASH-01 balance `1000.000`, linked GL account present | Adjust: direction `out`, amount `12.500`, `reason_code = count_variance`, reason text | Balance `= 987.500`; one `Adjustment` movement; balanced GL `Dr PaymentToleranceExpense 12.500 / Cr Cash 12.500` |
| MTP-TRE-55 | EDGE | P1 | as 54 | Adjust direction `in` `12.500` | Balance `= 1012.500`; GL uses the **Income** tolerance purpose, not the expense one |
| MTP-TRE-56 | EDGE | P0 | repository with **no linked GL account** | Attempt an adjustment | **Errors explicitly** — never a silent balance change with no GL counterpart |
| MTP-TRE-57 | EDGE | P1 | as 54 | Submit without `reason_text` | Refused — reason text is required |
| MTP-TRE-58 | EDGE | P1 | repository with movements | Movements tab | Ledger is **append-only** — no edit/delete affordance; running balance reconciles to the header `balance` exactly |
| MTP-TRE-59 | EDGE | P1 | user with `treasury.view` only | Attempt adjust and transfer | Both 403 |

### E.6 `TRE` — expenses and income

Flow: **create (Draft)** → **post** (`Gate::authorize('post')`) → **pay** (`expenses.pay`).
`post()` assigns the document number, sets `Posted`, and creates GL (`Dr Expense`, and if unpaid
`Cr AP/SupplierPayable`); if the expense was flagged `is_paid` at creation with a
`payment_repository_id` it **also** writes an `out` `RepositoryMovement` — **without creating a
Treasury `Payment` entity**. `pay()` (from `Posted` + unpaid) posts
`Dr AP-liability / Cr Cash-or-Bank`, writes an `out` `RepositoryMovement` (idempotency leg
`settlement`), and flips `is_paid`; `mode: 'instrument'` issues an **outbound** `PaymentInstrument`
instead of moving cash. `reverse()` exists only for `linked_cost` expenses.

Validation: `total` — `required, numeric, min:0.01, regex:/^\d+(\.\d{1,3})?$/`;
`vat_amount` — `numeric, min:0, regex:/^\d+(\.\d{1,3})?$/`;
`vat_rate` and `vat_deductible_percent` — `numeric, 0–100, regex:/^\d+(\.\d{1,2})?$/`.
Frontend additionally requires `vat_amount < total`.

| ID | Type | P | Preconditions | Steps | Expected result (exact) |
|---|---|---|---|---|---|
| MTP-TRE-60 | HAPPY | P0 | — | `/expenses/new` → `total 119.000`, `vat_amount 19.000`, `vat_rate 19.00`, `vat_deductible_percent 100.00` → save | Draft created with those exact values; net `= 100.000` |
| MTP-TRE-61 | HAPPY | P0 | Draft from 60 | **Post** | Status `Posted`; document number assigned; GL `Dr Expense 100.000` + VAT-deductible leg + `Cr AP 119.000`. **No** `Payment` entity created. |
| MTP-TRE-62 | HAPPY | P0 | Posted + unpaid | **Pay** from a cash repository | GL `Dr AP 119.000 / Cr Cash 119.000`; one `out` `RepositoryMovement` (`settlement` leg); `is_paid = true`; repository balance `−119.000`. Still **no** `Payment` entity — a report that expects one will under-count. |
| MTP-TRE-63 | EDGE | P0 | as 62 | Click **Pay** twice (or replay the request) | Idempotent on the `settlement` leg — exactly one movement, one GL entry |
| MTP-TRE-64 | EDGE | P1 | expense created with `is_paid = true` + `payment_repository_id` | Post | Post writes the `out` movement directly; the `pay` action is then unavailable |
| MTP-TRE-65 | EDGE | P1 | Posted + unpaid | Pay with `mode = 'instrument'` (cheque) | An **outbound** `PaymentInstrument` is issued; **no** immediate cash movement; the outbound clear/bounce actions become available |
| MTP-TRE-66 | EDGE | P1 | Draft | Attempt **Pay** before posting | Refused — pay requires `Posted` |
| MTP-TRE-67 | EDGE | P1 | Posted | Attempt to edit the amount | Refused / requires reversal — a posted expense's money must not be silently mutated |
| MTP-TRE-68 | EDGE | P1 | — | `total = 0`, `= 0.009`, `= 1.0001` | All refused (`min:0.01`; 3-dp ceiling) |
| MTP-TRE-69 | EDGE | P1 | — | `vat_amount` ≥ `total` (e.g. `119.000` on `119.000`) | Refused by the frontend rule `vat_amount < total`; verify the backend also refuses (client-only enforcement is a gap) |
| MTP-TRE-70 | EDGE | P1 | — | `vat_rate = 19.001`, `vat_deductible_percent = 100.01` | Both refused (2-dp ceiling; range 0–100) |
| MTP-TRE-71 | EDGE | P1 | `linked_cost` expense, posted | **Reverse** | Mirrored reversal Document + reversing GL/cost entries; net GL effect `0.000` |
| MTP-TRE-72 | EDGE | P1 | non-`linked_cost` expense | Attempt reverse | Unavailable / refused |
| MTP-TRE-73 | HAPPY | P1 | several posted expenses across 2 months | `/expenses/analytics` | Tiles `total`, `unpaid_total`, `mom_delta_percent`; category breakdown where **Σ `total` == the `total` tile** and **Σ `share_percent` == 100** (±the documented rounding rule); month×category matrix totals reconcile to the same figure |
| MTP-TRE-74 | EDGE | P1 | as 73 | A month with zero expenses in the comparison position | `mom_delta_percent` does not produce `Infinity`/`NaN`; it shows a defined value or an explicit "n/a" |
| MTP-TRE-75 | EDGE | P1 | recurring expense configured | `/expenses/recurring` | Generated instances carry the exact configured amount at scale 3; no drift across generations |
| MTP-TRE-76 | HAPPY | P1 | — | `/income/new` → post an income entry | GL mirror of the expense path; `/finance/cash-movements` shows it as an **in** |
| MTP-TRE-77 | EDGE | P1 | cashier (`expenses.view/create`, no post/pay) | Attempt post and pay | Both 403; create still works |

### E.7 `WHT` — withholding

| ID | Type | P | Preconditions | Steps | Expected result (exact) |
|---|---|---|---|---|---|
| MTP-WHT-01 | HAPPY | P1 | payment with `withholding_rate = 0.0150` on gross `1000.000` | `/treasury/withholding-certificates` | A certificate exists for `15.000` withheld; rate stored as the fraction `0.0150` (`decimal(5,4)`), never as `1.50` |
| MTP-WHT-02 | EDGE | P1 | as 01 | `/treasury/withholding-certificates/:id` | Gross, rate, withheld and net all render at their correct scales and satisfy `net == gross − withheld` |
| MTP-WHT-03 | EDGE | P1 | several withheld sales | `/treasury/sales-withholding-tracking` | Σ withheld matches the sum of the individual certificates exactly |
| MTP-WHT-04 | EDGE | P1 | — | `withholding_rate = 0` | No certificate created; payment settles at full gross |
| MTP-WHT-05 | EDGE | P2 | user without withholding permissions (`withholding.create/update`) | Attempt to create/edit a certificate | 403 |

**Subtotal §E: 16 + 11 + 8 + 14 + 10 + 18 + 5 = 82 cases**
(18 HAPPY / 64 EDGE · P0 26 · P1 54 · P2 2)

<!-- SECTION-E-END -->

## §F — Fiscal core: `ZRP` `SHF` `CASH` `RFP` `RFD` `AGG` `OWN` `GATE` `TRN` (W5)

This is the densest P0 section. **It depends entirely on device-authored data** (no seeder creates
`pos_shifts`/`pos_receipts`), so §Z must produce the fixtures below before W5 starts.

### F.0 Required device fixtures — author these in §Z, then assert them here

All figures TND, currency scale **3**, VAT **19%**, so that every expected value is exact.
**Every cash payment in SHIFT-1 is tendered EXACTLY (zero change)** — this removes the
tendered-vs-retained ambiguity from the clean-arithmetic shift, which is then tested on its own in
SHIFT-2.

**SHIFT-1 — clean arithmetic (opening float `100.000`)**

| Receipt | Content | Gross (TTC) | Net | VAT | Payment |
|---|---|---|---|---|---|
| R1 | item A ×1 @ `11.900` TTC | `11.900` | `10.000` | `1.900` | CASH `11.900` exact |
| R2 | item A ×2 @ `11.900` | `23.800` | `20.000` | `3.800` | CASH `23.800` exact |
| R3 | item B ×1 @ `59.500` | `59.500` | `50.000` | `9.500` | CASH `30.000` + CARD `29.500` |
| R4 | item A ×1 @ `11.900`, **per-line** discount 10% | `10.710` | `9.000` | `1.710` | CASH `10.710` exact |
| RF1 | **v4 full refund of R1** | `11.900` (POSITIVE magnitude, `receipt_type='return'`) | `10.000` | `1.900` | CASH payout `11.900` |

**Derived SHIFT-1 expectations (used by many cases below):**

```
sales_count            = 4
gross_sales            = 11.900 + 23.800 + 59.500 + 10.710 = 105.910
net_sales              = 10.000 + 20.000 + 50.000 +  9.000 =  89.000
tax_amount             =  1.900 +  3.800 +  9.500 +  1.710 =  16.910
identity check         : 89.000 + 16.910 = 105.910                     ✓
refunds_count          = 1
refunds_amount         = 11.900
VAT breakdown @19%     : net 79.000 / vat 15.010 / gross 94.010        (refund SUBTRACTED)
payment_methods CASH   : amount 64.510  (76.410 sales − 11.900 refund), count 5
payment_methods CARD   : amount 29.500, count 1
expected_cash          = opening 100.000 + net cash 64.510 = 164.510
```

> **Deliberate asymmetry to assert (spec §7.3):** `gross_sales` / `net_sales` / `tax_amount` are
> **sale-only** (refunds never fold into them), while the **VAT breakdown and payment-method
> breakdown ARE net of refunds**. So `Σ vat_breakdown.gross (94.010) ≠ gross_sales (105.910)` by
> exactly `refunds_amount`. That is correct behavior, not a bug — `MTP-ZRP-06` pins it.

**SHIFT-2 — change, rounding, refusal fixtures (opening float `100.000`)**

| Receipt | Content | Purpose |
|---|---|---|
| R5 | sale total `23.800`, CASH **tendered `50.000`**, change `26.200` | tendered-vs-retained trap |
| R6 | sale with a non-zero **whole-receipt (transaction) discount** | refund-refusal fixture (§3.5) |
| R7 | sale paid **CARD only** | refund-refusal fixture (§9.6) |
| R8 | sale whose **exact** total is `12.347`, cash-rounding denomination `0.050` | rounds **up** → total `12.350`, `cash_rounding_adjustment = +0.003` |
| R9 | sale whose **exact** total is `12.320`, same denomination | rounds **down** → total `12.300`, `cash_rounding_adjustment = −0.020` |
| R10 | sale whose **exact** total is `12.325` (exact half-denomination) | tie-break rule — **record** the actual direction; it must be deterministic and identical on device and server |
| R11 | **TRAINING** sale | training-isolation fixture (only if the `TreasuryReceiptBridge` training gate landed — see §0.3) |

### F.1 `ZRP` — Z-report web views

**Entry points.** `/pos/z-reports` (`ZReportListPage`) → `/pos/z-reports/:zNumber` (`ZReportDetailPage`).
API: list via `fetchZReports`; detail `GET /pos/reports/z/{zNumber}?terminal_id=…`; chain verify
`POST /pos/reports/z/verify-chain`; plus a PDF download.
List columns: `gross_sales`, `report_data.net_sales`, `report_data.tax_amount`, `sales_count`, `variance`.
Detail sections: **Sales Summary** (receiptCount, averageTicket *computed client-side* as
gross_sales/sales_count, grossSales, netSales, taxAmount, refundsCount,
`report_data.refunds_amount`, voidedCount) · **Cash Summary** (`opening_cash`, `expected_cash`,
`actual_cash`, `variance`) · **VAT Breakdown** (rate / net / vat / gross from
`report_data.vat_breakdown[]`) · **Payment Methods** (type / count / amount) · fiscal hash.

> **Gap to record, not to file:** the Z detail view renders **no `cash_rounding_adjustment` field
> and no grand-totals (perpetual) field**. Rounding is therefore invisible in the web Z view — its
> assertions must go through the API payload/DB (`MTP-ZRP-10`).

| ID | Type | P | Preconditions | Steps | Expected result (exact) |
|---|---|---|---|---|---|
| MTP-ZRP-01 | HAPPY | P0 | SHIFT-1 closed, Z synced | `/pos/z-reports` | Row present. `gross_sales = 105.910`, `net_sales = 89.000`, `tax_amount = 16.910`, `sales_count = 4` |
| MTP-ZRP-02 | HAPPY | P0 | as 01 | Open `/pos/z-reports/:zNumber` → Sales Summary | `grossSales 105.910` · `netSales 89.000` · `taxAmount 16.910` · `refundsCount 1` · `refundsAmount 11.900` · `voidedCount 0` |
| MTP-ZRP-03 | EDGE | P0 | as 02 | Check the aggregate identity on the detail page | `net_sales + tax_amount == gross_sales` → `89.000 + 16.910 == 105.910` **exactly**. Any drift is launch-blocking. |
| MTP-ZRP-04 | HAPPY | P0 | as 02 | VAT Breakdown table | One 19% row: net `79.000` / vat `15.010` / gross `94.010`. Per-row `net + vat == gross` exactly. |
| MTP-ZRP-05 | HAPPY | P0 | as 02 | Payment Methods table | CASH `64.510` (count 5) · CARD `29.500` (count 1). **Σ amounts `= 94.010`** — equals gross_sales − refunds_amount. |
| MTP-ZRP-06 | EDGE | P0 | as 02 | Compare `Σ vat_breakdown.gross` with `gross_sales` | They differ by **exactly `refunds_amount = 11.900`** (`94.010` vs `105.910`). This asymmetry is spec-correct (§7.3); a *matching* pair here would mean refunds are wrongly folded into gross. |
| MTP-ZRP-07 | EDGE | P0 | as 02 | Cash Summary | `openingCash 100.000` · `expectedCash 164.510` · `actualCash` = counted · `variance = actual − expected` |
| MTP-ZRP-08 | EDGE | P1 | as 02 | averageTicket tile | Client-computed `gross_sales / sales_count = 105.910 / 4 = 26.4775`. Record how it is rounded for display — it must not be computed with a JS float on the money value (precision contract §Frontend) |
| MTP-ZRP-09 | EDGE | P0 | SHIFT-1 Z + the **device Z sale-branch defect** (§0.3) | Compare Z `net_sales`/`tax_amount` against the server projection of the same receipts | **Known defect:** the device SALE branch treats gross `line_total` as net, so the Z's net is **overstated by the VAT amount per taxed line** while totals stay correct. Assert `gross_sales` strictly; record the exact decomposition residue. Expected known-fail. |
| MTP-ZRP-10 | EDGE | P0 | SHIFT-2 with R8/R9 | Fetch the Z detail **API payload** (not the page) | `cash_rounding_adjustment` totals are present in the payload and equal `+0.003 + (−0.020) = −0.017`; the value is a signed decimal **string** at scale 3; `-0` never appears |
| MTP-ZRP-11 | EDGE | P0 | SHIFT-2 Z | Verify the v3 aggregate identity per rounded receipt | `subtotal + vat_total == (total − cash_rounding_adjustment) + transaction_discount`. R8: `… == 12.350 − 0.003 = 12.347`. R9: `… == 12.300 − (−0.020) = 12.320`. |
| MTP-ZRP-12 | EDGE | P0 | SHIFT-2 Z | Check `cash_rounding_denomination` on the wire | Exactly `0.050` at currency scale — **never** `0.05`. A re-serialized denomination is a quarantine-class defect. |
| MTP-ZRP-13 | EDGE | P1 | SHIFT-2 with R10 (`12.325`, exact half) | Compare device Z and server projection | Tie-break direction is **identical** on both sides and deterministic across reruns. Record the direction. |
| MTP-ZRP-14 | EDGE | P0 | any Z | Click chain-verify (`POST /pos/reports/z/verify-chain`) | Reports the Z chain intact; a broken chain is surfaced explicitly, never silently green |
| MTP-ZRP-15 | EDGE | P1 | any Z | Download the Z PDF | The PDF's money figures match the page's, digit for digit, at the same scale |
| MTP-ZRP-16 | EDGE | P1 | user with `pos.view_reports` but not `pos.generate_z_report` | `/pos/z-reports` and `/pos/z-reports/:zNumber` | Read access allowed (these are read-only back-office views per the web-POS gate ticket); no generate action offered |
| MTP-ZRP-17 | EDGE | P1 | cashier role | `/pos/z-reports` | Cashier holds `pos.view_receipts` + `pos.generate_z_report` — record what is actually reachable and that no other terminal's Z is listed |
| MTP-ZRP-18 | EDGE | P2 | tenant with no Z closed | `/pos/z-reports` | Empty list, no phantom row, no error |

### F.2 `SHF` — shift history, shift dashboard, X report

**Entry points.** `/pos/shift-history` (`ShiftHistoryPage`; columns: shiftNumber, terminal, cashier,
openedAt, closedAt, duration, **`opening_cash`**, **`variance`**, status; API `GET /pos/shifts`).
`/pos/shifts` (`POSShiftsDashboard` → `ShiftDashboardPage`; fetches current shift + drawer balance,
maps `expected_cash: balance?.expected_cash ?? shift.opening_cash`).
`/pos/transactions`. `/pos/analytics` (`pos.view_reports`).

> **There is NO standalone X-report route in the web app.** `XReportResponse` / `generateXReport()`
> exist in `shiftApi.ts` and are consumed **inline** inside `POSShiftsDashboard` →
> `ShiftDashboardPage` as a modal/section. The `pos:xReport.*` i18n keys are reused as the Z detail
> page's VAT/payment-method column labels. Any "open the X report page" case is invalid.

| ID | Type | P | Preconditions | Steps | Expected result (exact) |
|---|---|---|---|---|---|
| MTP-SHF-01 | HAPPY | P0 | SHIFT-1 closed | `/pos/shift-history` | Row shows `opening_cash = 100.000` and the persisted `variance`. Closed-at and cashier match the device. |
| MTP-SHF-02 | EDGE | P1 | as 01 | Inspect the list columns | Documented gap: the list shows **no closing-cash and no expected-cash column** — only opening cash + variance. Record; do not file. |
| MTP-SHF-03 | HAPPY | P0 | an OPEN shift on the demo tenant with sales | `/pos/shifts` → generate the inline X report | X figures match the running shift: gross/net/VAT/payment methods consistent with the receipts so far, and the same identity `net + vat == gross` holds |
| MTP-SHF-04 | EDGE | P0 | as 03 | Generate X twice in a row with no intervening sale | Both X reports are identical; generating an X **does not** close the shift, reset counters, or write a Z |
| MTP-SHF-05 | EDGE | P0 | as 03, then author one more sale | Generate X again | Deltas match exactly the new sale's gross/net/VAT and its payment leg |
| MTP-SHF-06 | EDGE | P0 | SHIFT-2 (R5: tendered `50.000`, sale `23.800`, change `26.200`) | Compare `pos_receipt_payments.amount` with the Treasury `payments.amount` for R5 | **TENDERED `50.000`** vs **RETAINED `23.800`**. Any report that displays `50.000` as banked cash is overstating — flag it. A cash leg netting to zero must write **no** Treasury row. |
| MTP-SHF-07 | EDGE | P0 | SHIFT-2 closed | `/pos/shifts` drawer/expected-cash figure | Expected cash must subtract change given (`change_due`) — i.e. be built from RETAINED cash, not tendered |
| MTP-SHF-08 | EDGE | P1 | `/pos/transactions` | Open the page with SHIFT-1 data | Receipt list shows R1–R4 **and** RF1. RF1 renders as a refund/return, with its POSITIVE magnitude clearly signed or labelled — a refund shown as an extra +11.900 sale is a display defect |
| MTP-SHF-09 | EDGE | P1 | `/pos/analytics` with SHIFT-1 | Load the page | See `MTP-AGG-*` — the numbers here come from `PosAnalyticsService` and are the primary v4-refund consumer gate |
| MTP-SHF-10 | EDGE | P2 | no shift ever opened | `/pos/shift-history`, `/pos/shifts` | Empty states; `/pos/shifts` does not fabricate an `expected_cash` |

### F.3 `CASH` — expected cash, counted cash, variance

> **Known defect, expect these to fail (§0.3).** `CashDrawerService::calculateExpectedCash()`
> (`:387-413`) sums only `pos_cash_drawer_operations`, and **no v3 path writes those rows**;
> `ShiftManagementService.php:168-182` then **persists** that figure as `expected_cash`/`variance`
> on every close that did not use the cash-count path. On a v3 shift the server-side expected cash
> therefore collapses to roughly the **opening float alone**. The device-authored Z figure is the
> trustworthy one. Every case below must record **both** numbers and state which one it asserted.

| ID | Type | P | Preconditions | Steps | Expected result (exact) |
|---|---|---|---|---|---|
| MTP-CASH-01 | EDGE | P0 | SHIFT-1 closed | Compare the **device Z** `expected_cash` with the **server** persisted `expected_cash` | Device: `164.510`. Server: expected to be ≈ `100.000` (opening float only) — **known defect**, record both, mark `FAIL (known — cashdrawer-v3-expected-cash-blind)` |
| MTP-CASH-02 | EDGE | P0 | SHIFT-1, counted cash `164.510` | Close with an exact count | Variance `= 0.000` on the device Z. Server-side variance will be wrong per CASH-01 — record it. |
| MTP-CASH-03 | EDGE | P0 | SHIFT-1, counted cash `164.010` | Close | Device variance `= −0.500` (short). Sign convention: negative = short. Displayed at scale 3. |
| MTP-CASH-04 | EDGE | P0 | SHIFT-1, counted cash `165.010` | Close | Device variance `= +0.500` (over) |
| MTP-CASH-05 | EDGE | P0 | user **without** `pos.close_shift_with_variance` | Attempt to close with a non-zero variance | Refused; the shift stays open; no Z written |
| MTP-CASH-06 | EDGE | P1 | user **with** `pos.close_shift_with_variance` (manager) | Same close | Allowed; variance persisted with the approving actor recorded |
| MTP-CASH-07 | EDGE | P0 | `/reports` → `CashRegisterReconciliationTable` | Load with SHIFT-1 closed | Columns `expected_cash`, `counted_cash`, `variance` plus `variance_severity` styling. The figures must match whichever source the table reads — identify the source and confirm it is the device Z, not the blind server value |
| MTP-CASH-08 | EDGE | P1 | `/reports` → `CashAcrossStoresWidget`, multi-location | Load | Per-location `total` figures and a `grand_total`; **Σ per-location == grand_total** exactly at scale 3 |
| MTP-CASH-09 | EDGE | P1 | tolerance write-off configured; a shift closing inside tolerance | Close | The tolerance write-off entry posts once and is **training-gated**; a `pos.tolerance.apply` holder can apply it, others cannot |
| MTP-CASH-10 | EDGE | P0 | SHIFT-2 with R8/R9 cash rounding | Compare drawer/Z gross with revenue | Per the contract: the **drawer and Z gross consume the ROUNDED total**; **revenue and loyalty consume `total − adjustment`**. Assert both consumers use their correct basis. |
| MTP-CASH-11 | EDGE | P1 | `/finance/cash-movements` for the SHIFT-1 window | Load | Signed per-row amounts; totals bar shows per-currency `in` / `out` / `net`, with **`net == in − out`** exactly. The RF1 payout appears as an **out** of `11.900`. |
| MTP-CASH-12 | EDGE | P2 | `/finance/cash-movements`, period with no movement | Load | Zeroed totals at scale 3 |

### F.4 `RFP` — refund policy configuration

**Entry point.** `/settings/pos-refund-policies` (`PosRefundPoliciesPage.tsx`), persisted into the
company `reservation_settings` JSONB (`ReservationSettings`), validated in `CompanyController`.

> **Critical scoping caveat.** These settings feed the **LEGACY `/return` endpoint** via
> `RefundDestinationResolver`. The **v4 device-signed refund path does not consult them** — spec
> §3.6 narrows return-window / daily-cap / manager-threshold / disposition policy to
> **server-advisory only**, surfaced after the fact through the projector's `refund_policy_alerts`
> accept-and-flag table. Do **not** write a case asserting that a v4 device refund is blocked by a
> setting on this page; it will not be.

Defaults and validation to assert: `customer_history_window_days` `14` (int 0–365) ·
`out_of_window_policy` `voucher_only` (`refuse|voucher_only`) ·
`manager_override_threshold_amount` `'50.00'` (numeric ≥0) ·
`manager_override_threshold_percent` `'10.00'` (numeric 0–100) ·
`manager_override_required_for_no_receipt` `true` ·
`allowed_refund_destinations` `['original_payment','cash','store_voucher']` ·
`proration_strategy` `proportional` (`proportional|largest_first|cashier_choice`) ·
`voucher_default_expiry_days` `365` (1–3650) · `voucher_transferable_default` `true` ·
`voucher_cash_refund_allowed` `false` · `daily_refund_cap_per_cashier` `null` (nullable numeric ≥0) ·
`daily_refund_cap_override_allowed` `true` ·
`customer_history_search_max_per_cashier_per_day` `15` (0–1000) ·
`voucher_lookup_per_terminal_per_day` `200` · `voucher_lookup_per_cashier_per_day` `100` ·
`voucher_lookup_failed_per_tenant_per_hour_alert` `50` (all 0–10000) ·
`goodwill_named_customer_threshold` `'100.00'` · `goodwill_four_eyes_threshold` `'250.00'` ·
`goodwill_bearer_default_off` `true` · `default_restock_policy` `default_allow`.

| ID | Type | P | Preconditions | Steps | Expected result |
|---|---|---|---|---|---|
| MTP-RFP-01 | HAPPY | P1 | fresh company | `/settings/pos-refund-policies` | All defaults above render exactly as listed |
| MTP-RFP-02 | HAPPY | P1 | as 01 | Set `daily_refund_cap_per_cashier = 200.000`, save, reload | Persists; round-trips as a decimal **string** |
| MTP-RFP-03 | EDGE | P1 | as 01 | Set `manager_override_threshold_percent = 100.01` | Rejected (`max:100`) |
| MTP-RFP-04 | EDGE | P1 | as 01 | Set `manager_override_threshold_amount = -1` | Rejected (`min:0`) |
| MTP-RFP-05 | EDGE | P1 | as 01 | Set `customer_history_window_days = 366` | Rejected (`max:365`) |
| MTP-RFP-06 | EDGE | P1 | as 01 | Set `voucher_default_expiry_days = 0` | Rejected (`min:1`) |
| MTP-RFP-07 | EDGE | P1 | as 01 | Set `allowed_refund_destinations = ['bitcoin']` | Rejected (`in:original_payment,cash,store_voucher`) |
| MTP-RFP-08 | EDGE | P1 | as 01 | Clear `daily_refund_cap_per_cashier` to empty | Persists as `null` (nullable) = "no cap", not as `0` |
| MTP-RFP-09 | EDGE | P1 | as 01 | Set `out_of_window_policy = refuse`, then exercise a **legacy** `/return` outside the window | Refused by `RefundDestinationResolver` |
| MTP-RFP-10 | EDGE | P0 | as 09, but exercise a **v4 device** refund outside the window | Author on device | **Not** blocked (spec §3.6 — server-advisory only). Instead a `refund_policy_alerts` row appears. Asserting a block here would be asserting a behavior that does not exist. |
| MTP-RFP-11 | EDGE | P1 | cashier role | `/settings/pos-refund-policies` | Blocked; API 403 |
| MTP-RFP-12 | EDGE | P1 | Tenant B | Read Tenant A's settings via the API | 403/404; no policy values leak |

### F.5 `RFD` — refund observation and admin

> **Reachability facts (verified against the branch).** `git diff dev...feat/v3-refund-chain`
> touches **zero files under `apps/web`** — the entire v4 refund flow (payload builder, approval,
> intents, payout-reconciliation modal, offline aggregation) is **Tauri-device only** (§Z). The two
> new backend endpoints have **no SPA screen**: `GET /fiscal/dead-lettered-projections`,
> `GET /fiscal/dead-lettered-projections/{fiscalEventId}`, and
> `POST /fiscal/refund-compensations` (`RefundCompensationController`), gated by the new permission
> **`fiscal.refunds.manage_dead_letters`**. Those are tested here **at the API level** (Playwright
> can issue them) and are marked `[API]`.

Refund model to assert: `SALE_RECEIPT` event at **`event_version = 4`**, `invoice_type_code = 'REFUND'`,
non-null `original_receipt_reference`, `receipt_type = 'return'`, **all magnitudes positive**,
`refund_destination = 'cash'`, `settlement_allocation = null`, `payments[]` = exactly one cash leg,
every line `disposition = 'restock'`. Legacy returns are **negative**. Drawer leg is
`MovementDirection::Out` and the journal entry is the reversal.

| ID | Type | P | Preconditions | Steps | Expected result (exact) |
|---|---|---|---|---|---|
| MTP-RFD-01 | HAPPY | P0 | RF1 synced | `/pos/transactions` and the Z detail | RF1 appears with total **`+11.900`** and `receipt_type = 'return'`, `invoice_type_code = 'REFUND'`, `event_version = 4` |
| MTP-RFD-02 | EDGE | P0 | RF1 synced | Inspect stock for item A | Restocked by `1` (launch default `disposition='restock'`) |
| MTP-RFD-03 | EDGE | P0 | a **regulated / never-restock** product refunded | Inspect stock | **Not** restocked — `RestockPolicyResolver` `Never` overrides the payload disposition |
| MTP-RFD-04 | EDGE | P1 | a damaged-goods refund | Inspect stock | **Known limitation** (`refund-disposition-ui` ticket): it restocks anyway; manual adjustment required. Record; do not file. |
| MTP-RFD-05 | EDGE | P0 | RF1 synced | Treasury payments + GL for RF1 | Cash leg direction **Out** of `11.900`; journal entry is the **reversal** of the sale's; idempotency key distinct per leg |
| MTP-RFD-06 | EDGE | P0 | R6 (whole-receipt discount) | §Z: attempt refund on device | **REFUSED** — `WholeReceiptDiscountRefundRefusedError`, i18n `refundFlow.wholeDiscountReceiptRefused`, **before** any PIN spend. Partial and full both refused. |
| MTP-RFD-07 | EDGE | P0 | R7 (CARD-only original) | §Z: attempt refund on device | **REFUSED** — `NonCashOriginalRefundRefusedError`, i18n `refundFlow.nonCashOriginalRefused`. Refusal must also fire for a **mixed** cash+card original and for an empty/malformed payments array (fail closed). |
| MTP-RFD-08 | EDGE | P0 | R11 (TRAINING original) | §Z: attempt refund | **REFUSED** — `TrainingOriginalRefundRefusedError`. Must read the **original's own signed** `training_flag`, not the current session's training mode. Verify a non-training refund taken *while the session is in training mode* is **not** incorrectly refused. |
| MTP-RFD-09 | EDGE | P0 | R2 (qty 2) already refunded 1 | §Z: attempt to refund 2 more | **REFUSED** by the per-line cumulative backstop; server cap (`RefundQuantityExceededException`) is the sole authority and dead-letters immediately (non-retryable), not after 5 Horizon retries |
| MTP-RFD-10 | EDGE | P0 | an original with **both** a prior legacy-negative return line and a prior v4-positive return line | §Z: attempt a third refund that only breaches the cap if both are counted | **REFUSED** — the cap aggregates `SUM(ABS(quantity))`, so both sign conventions contribute their true magnitude |
| MTP-RFD-11 | EDGE | P0 | an original refunded up to its exact total | §Z: attempt one more refund | **REFUSED** by the receipt-level VALUE bound: `Σ|refunded| + attempt ≤ total − cash_rounding_adjustment` (the **exact**, not rounded, total). A rounded-DOWN original must still allow its **first full** refund. |
| MTP-RFD-12 | EDGE | P0 | corrupted/unreadable prior refund snapshot | §Z | **Fails CLOSED** (refuses), never skips the row — skipping undercounts and loses money |
| MTP-RFD-13 | EDGE | P0 | v4 refund whose projection was rejected | `[API]` `GET /fiscal/dead-lettered-projections` with `fiscal.refunds.manage_dead_letters` | The event is listed with `projection_status = DeadLettered`; detail endpoint returns its payload |
| MTP-RFD-14 | EDGE | P0 | as 13 | `[API]` `POST /fiscal/refund-compensations` class `invalid_refund` | Posts `Dr RefundWriteOff / Cr Cash`; **exactly one** `fiscal_refund_compensations` row (idempotency key `fiscal_event:{id}:refund_writeoff`); money movement and journal post in the **same** transaction |
| MTP-RFD-15 | EDGE | P0 | as 14 | `[API]` Repeat the identical POST | Idempotent — no second row, no second GL leg, no double money movement |
| MTP-RFD-16 | EDGE | P0 | as 13 | `[API]` POST class `valid_unbooked` | Posts `Dr Revenue / Cr Cash` via the `SalesReturn` purpose. **Precondition:** the chart of accounts must resolve `SalesReturn` — only the Generic chart seeded it historically, so on a FR/TN chart this previously 500'd. Assert a clean post, not a 500. |
| MTP-RFD-17 | EDGE | P0 | user **without** `fiscal.refunds.manage_dead_letters` | `[API]` Both endpoints | **403** on read and write |
| MTP-RFD-18 | EDGE | P1 | Tenant B credentials | `[API]` Request Tenant A's dead-lettered event id | 403/404; no payload leak |
| MTP-RFD-19 | EDGE | P0 | terminal count for the company is **2** | `php artisan fiscal:enable-v4-refund-authoring --tenant= --company= --dry-run` | **Refused** — preflight requires exactly one active physical terminal; **nothing mutates** on failure |
| MTP-RFD-20 | EDGE | P0 | terminal has legacy-sealed receipts (`fiscal_event_id IS NULL`, `fiscal_status='fiscalized'`) | Same command | **Refused** — preflight 2; nothing mutates |
| MTP-RFD-21 | EDGE | P0 | chart of accounts missing `RefundWriteOff` or `SalesReturn` | Same command | **Refused** — preflight 3; nothing mutates |
| MTP-RFD-22 | HAPPY | P0 | all three preflights satisfiable | Run with `--dry-run`, then for real | Dry run reports the plan and mutates nothing; the real run enables authoring (phase 1 "offer"); phase 2 acknowledgement is written by the **device's own sync round-trip**, not by this command |
| MTP-RFD-23 | EDGE | P0 | v4 authoring NOT yet acknowledged on the terminal | §Z: attempt a v4 refund | Typed refusal whose copy **must not** say "use the legacy path" (spec §9.4 corrected copy) |
| MTP-RFD-24 | EDGE | P1 | `{M2_COUNT} = 5` (confirm not overridden — §1.5); terminal holds unsynced fiscal events | §Z: author `5` v4 refunds on the same terminal inside the current shift, then attempt a `6`th | The `6`th is **refused** pre-PIN with `refundFlow.offlineRefundCountCeilingReached`; the drafted intent for the first 5 is unaffected. If the deployed default has been overridden on the target tenant, substitute the real value and record it in §1.5 — do not mark `BLOCKED`, the feature is live on `feat/v3-refund-chain` at merge. |
| MTP-RFD-25 | EDGE | P1 | as 24, fresh shift; `{M2_VALUE} = 300.000` TND | §Z: author v4 refunds summing to exactly `300.000` TND, then attempt one more refund of `0.001` TND | The ceiling is **inclusive**: cumulative payout `= 300.000` succeeds (the check is `already + this > ceiling`, strict); the next refund of any positive value is **refused** with `refundFlow.offlineRefundValueCeilingReached`. |
| MTP-RFD-26 | EDGE | P1 | `{M3_THRESHOLD} = 100.000` TND; terminal **offline** | §Z: attempt an offline refund of exactly `100.000` TND, then of `100.001` TND | `100.000` is allowed offline (threshold is inclusive — refusal fires only above it); `100.001` is **refused** at `begin()` with `refundFlow.largeRefundRequiresOnline`, before any PIN is spent. |
| MTP-RFD-27 | EDGE | P1 | as 26, terminal **online** | §Z: attempt the same `100.001` TND refund with connectivity restored | Allowed to start, but its manager PIN must be **server-verified** (`requireServerVerifiedPin`) — a genuinely offline server response at PIN-entry time throws `ServerVerifiedPinRequiredError` instead of falling back to the local PIN cache; the refusal happens before `authorPosOverride()`, so nothing is signed. See the link-drop-during-PIN-entry edge case in §Z (POSC-25a). |

### F.6 `AGG` — receipt_type-aware aggregates (the v4-positive-refund consumer gate)

> **This is the HARD PRE-ENABLE GATE.** Ticket
> `docs/superpowers/tickets/2026-08-01-positive-refund-total-consumers.md` lists five aggregates
> that blended `SUM(pos_receipts.total)` with no `receipt_type` filter, plus one on
> `pos_receipt_payments.amount`. The branch fixes them with a `netOfReturns()` helper
> (`CASE WHEN receipt_type = 'return' THEN -ABS({col}) ELSE {col} END`). **Every case here is
> asserted on a MIXED window containing at least one sale and at least one v4 POSITIVE refund** —
> a sale-only window cannot distinguish a fixed aggregate from a broken one.

Reference window = SHIFT-1 (`gross 105.910`, one refund of `11.900`) ⇒ **netted total `94.010`**.

| ID | Type | P | Consumer (post-fix site) | Steps | Expected result (exact) |
|---|---|---|---|---|---|
| MTP-AGG-01 | EDGE | P0 | `PosAnalyticsService` `net_sales` / `gross_sales` / `tax_total` (`:38-40`) | `/pos/analytics` summary over the SHIFT-1 window | Netted: **`94.010`**. A value of `117.810` (= 105.910 + 11.900) proves the refund was ADDED — launch-blocking. |
| MTP-AGG-02 | EDGE | P0 | `PosAnalyticsService` `refund_count` / `refund_total` (`:41-42`) | Same page | `refund_count = 1`, `refund_total = 11.900` reported **separately** from net sales |
| MTP-AGG-03 | EDGE | P0 | `PosAnalyticsService::getSalesByTimePeriod` | `/pos/analytics` → by-period view covering the refund's period | The refund's period is reduced by `11.900`, never increased |
| MTP-AGG-04 | EDGE | P0 | `PosAnalyticsService::getCashierPerformance` | `/pos/analytics` → cashiers | `total_sales = 94.010` for the cashier who took R1–R4 + RF1 |
| MTP-AGG-05 | EDGE | P0 | as 04, `average_ticket` | Same view | Computed through `CurrencyScale::bcround()` — **no PHP float**. Record the count basis, then assert `average_ticket == bcround(total_sales / count, 3)`. A value with float artefacts (e.g. a trailing `…9999`) is a defect. |
| MTP-AGG-06 | EDGE | P0 | `PosAnalyticsService::getCustomerAnalytics` | `/pos/analytics` → customers | A customer whose only activity is a refund shows a **negative or zero** contribution, never a positive one |
| MTP-AGG-07 | EDGE | P0 | `GrandtotalService::calculatePerpetualTotals` (`lifetime_sales`, `:230`) | Read the perpetual/grand totals (Z payload or API; **record where this surfaces in the web UI — it may be API-only**) | `lifetime_sales` is net of returns. The old docblock claimed "excluding voids/refunds" while filtering only `is_voided`/`is_training` — verify the claim now matches the behavior. |
| MTP-AGG-08 | EDGE | P0 | `GrandtotalService::calculatePeriodTotals` (`:174`) | Period totals over the mixed window | Branches on `receipt_type === Return`, not only on `is_voided` |
| MTP-AGG-09 | EDGE | P0 | `ReportGenerationService::calculateShiftTotals` payment breakdown (`:528`) | Shift-close reconciliation for SHIFT-1 | CASH `= 64.510` (refund **subtracted**), CARD `= 29.500`. Summing tendered refund cash as a positive is the exact defect this case exists to catch. |
| MTP-AGG-10 | EDGE | P0 | `OwnerSalesSummaryService` (`:105`) | `/reports` → `SalesSummaryCards` | `grossSales` netted to **`94.010`**; `returnsAmount = 11.900` shown separately; `averageBasket` consistent with the netted total |
| MTP-AGG-11 | EDGE | P0 | `SalesReportService::salesByLocation` | `/reports` → `SalesByLocationChart` / `BranchLeaderboard` | The refund's location total is **reduced** by `11.900`; Σ locations == the netted company total |
| MTP-AGG-12 | EDGE | P0 | `SalesReportService::topSkus` | `/reports` → `TopSkusWidget` | Item A's revenue is net of the refunded unit; its quantity is net units. Never inflated. |
| MTP-AGG-13 | EDGE | P0 | `SalesReportService::revenueByCategory` | `/reports` → `RevenueByCategoryDonut` | Category revenue netted; **Σ categories == netted total `94.010`** |
| MTP-AGG-14 | EDGE | P0 | `SalesReportService::paymentMethodBreakdown` | `/reports` → `PaymentMethodBreakdownPie` | CASH `64.510`, CARD `29.500`; **Σ == 94.010** |
| MTP-AGG-15 | EDGE | P0 | mixed **legacy-negative + v4-positive** refunds in ONE window | Every consumer above | All aggregates handle both sign conventions correctly and agree with each other. **This is the single most important case in the campaign** — it is the only one that exercises the mixed population the ticket warns about. |
| MTP-AGG-16 | EDGE | P1 | `LiveSalesReportService` | `/reports` → `LiveSalesFeed` during an active shift | A refund appears as a refund, not as a positive sale, and the running total decreases |
| MTP-AGG-17 | EDGE | P1 | v4 authoring still **inert** on staging (PF-8) | All of the above | With no v4 refund authorable, these read correct trivially. **Record `NOT EXERCISED` rather than `PASS`** — a green result here proves nothing about the gate. |

### F.7 `OWN` — owner reports dashboard

**Entry point.** `/reports` → `OwnerDashboardPage` (permission **`dashboard.owner`**). Widgets:
`SalesSummaryCards` (totalSales=grossSales, averageBasket, returnsAmount, salesCount),
`SalesByLocationChart`, `SalesTrendChart` (+comparison), `BranchLeaderboard` (per-branch total +
delta vs comparison period), `LiveSalesFeed`, `TopSkusWidget` (revenue + qty),
`RevenueByCategoryDonut`, `PaymentMethodBreakdownPie`, `CashRegisterReconciliationTable`,
`CashAcrossStoresWidget`, `LowStockAlertsList` (non-money), `DueThisWeekWidget`,
`RebalanceAlertsWidget`. Endpoints all under `ReportsController`
(`/reports/sales/{by-location,top-skus,revenue-by-category,payment-method-breakdown,summary,live}`,
`/reports/cash-register/reconciliation`, `/reports/stock/alerts`).

| ID | Type | P | Preconditions | Steps | Expected result |
|---|---|---|---|---|---|
| MTP-OWN-01 | HAPPY | P0 | SHIFT-1 data | `/reports` | Every money tile renders at scale 3 with no `NaN`/blank; see `MTP-AGG-10..14` for the exact values |
| MTP-OWN-02 | EDGE | P0 | as 01 | Cross-check tiles against each other | `Σ PaymentMethodBreakdownPie == Σ RevenueByCategoryDonut == SalesSummaryCards.grossSales == 94.010`. Any tile disagreeing with the others is launch-blocking. |
| MTP-OWN-03 | EDGE | P1 | as 01 | `SalesTrendChart` + `BranchLeaderboard` with a comparison period | Deltas are computed against the stated comparison window; a zero-activity comparison period does not produce a divide-by-zero or an `Infinity%` |
| MTP-OWN-04 | EDGE | P1 | period selector | Switch to a period boundary (first/last day of month) | Boundary-dated receipts are included consistently with the Z/X windowing; record the convention |
| MTP-OWN-05 | EDGE | P0 | user without `dashboard.owner` | `/reports` | Blocked by `RequirePermission`; API 403; no figures in the payload |
| MTP-OWN-06 | EDGE | P1 | `DueThisWeekWidget` | Load with the seeded AR (`CoffeeShopSeeder`: `300.000` + `1200.000` outstanding) | Amounts consistent with `/finance/aged-receivables`; no double counting |
| MTP-OWN-07 | EDGE | P2 | tenant with no POS data | `/reports` | See `MTP-EMPTY-01` |

### F.8 `GATE` — web-POS demo-only gate

| ID | Type | P | Preconditions | Steps | Expected result |
|---|---|---|---|---|---|
| MTP-GATE-01 | EDGE | P0 | **non-demo** tenant (`tenants.is_demo` false) | Attempt to open a shift from the browser (`shiftApi.openShift`) | **403 `WEB_POS_DEMO_ONLY`**. No `pos_shifts` row is created. |
| MTP-GATE-02 | EDGE | P0 | non-demo tenant | Attempt a browser web sale / close shift / table / kitchen mutation | All **403 `WEB_POS_DEMO_ONLY`**; no fiscal event, no receipt, no money moved |
| MTP-GATE-03 | EDGE | P0 | non-demo tenant | Load `/pos/z-reports`, `/pos/z-reports/:zNumber`, `/pos/shift-history` | **Allowed** — these read-only back-office views stay available to everyone by design |
| MTP-GATE-04 | HAPPY | P1 | **demo** tenant (`is_demo` true) | Open a shift and take a browser sale | Permitted — the demo path is the only sanctioned browser-selling path |
| MTP-GATE-05 | EDGE | P1 | non-demo tenant | Check the nav | POS hub visibility for non-demo tenants is a known open item (`is_demo` not yet exposed in the auth payload). Record what is shown; hiding is UX, the 403 is the enforcement. |
| MTP-GATE-06 | EDGE | P1 | any tenant | Back-office `DEPOSIT_RECEIPT` flow (server-authored via the virtual admin terminal) | **Unaffected** by the gate — this is a deliberate compliant server path and must keep working |

### F.9 `TRN` — training-mode money isolation

| ID | Type | P | Preconditions | Steps | Expected result |
|---|---|---|---|---|---|
| MTP-TRN-01 | EDGE | P0 | R11 TRAINING sale synced | Inspect Treasury `payments`, GL entries, repository movements for R11 | **Known defect** (`treasury-bridge-training-money-legs`): only the cash-rounding/tolerance entries are training-gated; the **tender-leg loop is not**, so a training sale creates real Payment/GL/movement rows. Expect `FAIL (known)` unless the fix landed. **If it has not landed, take NO further training receipts for the rest of the campaign.** |
| MTP-TRN-02 | EDGE | P0 | R11 | `/reports`, `/pos/analytics`, Z detail | Training receipts must be excluded from every revenue aggregate (`is_training` filters) |
| MTP-TRN-03 | EDGE | P0 | R11 | Z-report `is_training` handling | A v4 refund row is unconditionally `is_training = 0` (a refund can never be authored against a training original — see `MTP-RFD-08`) |
| MTP-TRN-04 | EDGE | P1 | training session active | Author a **non**-training-original refund while the session is in training mode | Permitted — session training mode and the original's `training_flag` must not be conflated |

**Subtotal §F: 18 + 10 + 12 + 12 + 27 + 17 + 7 + 6 + 4 = 113 cases**
(12 HAPPY / 101 EDGE · P0 71 · P1 38 · P2 4)

<!-- SECTION-F-END -->

## §G — `GL` Accounting: journal entries, trial balance, P&L, balance sheet, ledger, aged AR/AP (W6)

**Entry points & permissions.** `/finance/chart-of-accounts` (`accounts.view`),
`/finance/journal-entries` (`journal.view`), `/finance/journal-entries/create` (`journal.create`),
`/finance/journal-entries/:id` (`journal.view`), `/finance/ledger` (`journal.view`),
`/finance/trial-balance`, `/finance/profit-loss`, `/finance/balance-sheet`,
`/finance/aged-receivables`, `/finance/aged-payables` (all `accounts.view`),
`/finance/overview` and `/finance/cash-movements` (`reports.view`),
`/finance/vat-periods` + `/finance/vat-report/:id` (moduleKey `reports`).
Services: `ChartOfAccountsService`, `AccountingService` + `JournalEntryBuilder` +
`DoubleEntryValidator`, `GeneralLedgerReportService`, `TrialBalanceService`, `ProfitLossService`,
`BalanceSheetService`, `AgedReceivablesService`, `AgedPayablesService`, `CashMovementsReportService`.
Aging buckets: **Current 0–30 / 31–60 / 61–90 / Over 90**.

> **Known client-side trap (assert it, don't fix it here).** `JournalEntryForm.tsx` runs a
> **float** live balance indicator (`parseFloat`, `Math.abs(diff) < 0.01`). The authoritative check
> is server-side bcmath (`DoubleEntryValidator::isBalanced()` + `hasValidLines()` XOR debit/credit).
> An imbalance smaller than `0.01` therefore renders as "balanced" in the UI and is rejected on
> submit — that divergence is `MTP-GL-03`.

| ID | Type | P | Preconditions | Steps | Expected result (exact) |
|---|---|---|---|---|---|
| MTP-GL-01 | HAPPY | P0 | user with `journal.create` | `/finance/journal-entries/create` → Dr `4xx` `100.000` / Cr `7xx` `100.000` → post | Entry posts; appears at `/finance/journal-entries` and in `/finance/ledger` for both accounts |
| MTP-GL-02 | EDGE | P0 | as 01 | Dr `100.000` / Cr `99.000` → submit | Server **422**, no entry created, no partial lines persisted |
| MTP-GL-03 | EDGE | P1 | as 01 | Dr `100.000` / Cr `99.995` → observe the live indicator, then submit | Client indicator shows **balanced** (`|0.005| < 0.01` float tolerance) but server returns **422**. Record both observations — the UI is misleading by design today. |
| MTP-GL-04 | EDGE | P0 | as 01 | One line with **both** debit `50.000` and credit `50.000` → submit | Rejected — `hasValidLines()` requires XOR per line |
| MTP-GL-05 | EDGE | P1 | as 01 | One line with **neither** debit nor credit → submit | Rejected |
| MTP-GL-06 | EDGE | P1 | as 01 | Debit `-50.000` (negative debit — the contract permits `-?` on journal debit/credit) | Record actual behavior: if accepted, the entry must still balance and the ledger sign must be consistent; if rejected, the message must name the field |
| MTP-GL-07 | EDGE | P1 | as 01 | Debit `100.0001` (4 dp) | Rejected by the money regex ceiling with the "at most 3 decimal places" message |
| MTP-GL-08 | EDGE | P0 | any period with entries | `/finance/trial-balance` | **Σ debits == Σ credits exactly** at scale 3. Any non-zero difference is launch-blocking. |
| MTP-GL-09 | HAPPY | P0 | MTP-GL-01 posted | `/finance/trial-balance` for the containing period | The `100.000` appears on both sides; account balances move by exactly `100.000` |
| MTP-GL-10 | HAPPY | P0 | `CoffeeShopSeeder` tenant | `/finance/aged-receivables` | `CUST-001` outstanding **`300.000`**, `CUST-002` **`1200.000`**, `CUST-003` **absent or `0`**. Σ shown == `1500.000`. |
| MTP-GL-11 | HAPPY | P0 | `CoffeeShopSeeder` tenant | `/finance/aged-payables` | `SUPP-001` payable **`2500.000`** |
| MTP-GL-12 | HAPPY | P0 | `DemoPharmacySeeder` tenant | `/finance/aged-receivables` and `/finance/aged-payables` | Clinique Al Amal **`900`** outstanding; Medis Distribution **`3200`** payable |
| MTP-GL-13 | EDGE | P0 | aged report with several invoices | `/finance/aged-receivables` | Each invoice appears in **exactly one** bucket; **Σ(Current + 31-60 + 61-90 + Over 90) == total outstanding** exactly. Any double-counted invoice is launch-blocking. |
| MTP-GL-14 | EDGE | P1 | invoices dated so their ages are exactly 30, 31, 60, 61, 90, 91 days | `/finance/aged-receivables` | Record the actual bucket for each boundary day and confirm the boundary rule is **consistent** between AR and AP (same day-count must land in the same relative bucket on both reports) |
| MTP-GL-15 | EDGE | P0 | any period | `/finance/balance-sheet` | **Assets == Liabilities + Equity** exactly at scale 3 |
| MTP-GL-16 | EDGE | P0 | period containing MTP-INV-10's opening batch | `/finance/trial-balance` | The opening JE (Dr Inventory `119.000` / Cr Opening Balance Equity `119.000`) is present and balanced |
| MTP-GL-17 | HAPPY | P1 | any account with movements | `/finance/ledger` → pick account, set period | Running balance == opening balance + Σ(debits − credits) in the window, exactly |
| MTP-GL-18 | EDGE | P1 | P&L period `[D1, D2]` with entries dated exactly `D1` and exactly `D2` | `/finance/profit-loss` | Both boundary-dated entries are **included** (inclusive period) — or record the actual convention and confirm P&L and Balance Sheet use the same one |
| MTP-GL-19 | EDGE | P2 | period with no activity | `/finance/profit-loss`, `/finance/balance-sheet`, `/finance/trial-balance` | Zeros rendered at the currency scale (`0.000`), not blank, not `NaN`, not `-`. Page does not error. |
| MTP-GL-20 | HAPPY | P1 | user with `accounts.manage` | `/finance/chart-of-accounts` → add an account, then edit it | Account created/edited; appears in the tree; usable in a new journal entry |
| MTP-GL-21 | EDGE | P0 | `cashier` role (per the role matrix: no `journal.*`, no `accounts.*`) | Navigate to `/finance/trial-balance`, `/finance/journal-entries`, `/finance/journal-entries/create` | All three blocked by `RequirePermission`; direct API calls return **403**. No money figures in the response body. |
| MTP-GL-22 | EDGE | P1 | `viewer` role (`journal.view`, `accounts.view`, no create/post) | `/finance/journal-entries` then `/finance/journal-entries/create` | List **visible**; create route **blocked**; the create API returns 403 |
| MTP-GL-23 | EDGE | P1 | `accountant` role | `/finance/journal-entries/create` | Allowed (`journal.create`/`journal.post`) — confirms the role split is real and not accidentally admin-only |
| MTP-GL-24 | EDGE | P0 | Tenant A and Tenant B both have GL data | Log in as Tenant B user, visit every `/finance/*` page | **Zero** Tenant A figures anywhere, including in raw API responses. Bucket totals differ from Tenant A's. |
| MTP-GL-25 | EDGE | P1 | `demo-garage` tenant (EUR company + TND company) | Switch company, view `/finance/trial-balance` | Figures re-scale: EUR displays 2 dp, TND displays 3 dp. No value leaks between the two companies. |

**Subtotal §G: 25 cases** (7 HAPPY / 18 EDGE · P0 13 · P1 11 · P2 1)

<!-- SECTION-G-END -->

## §H — `LOY` Loyalty money legs (W6)

**Entry points.** These routes are nested under the `pos` parent route, so the real URLs are
**`/pos/loyalty/programs`**, `/pos/loyalty/programs/new`, `/pos/loyalty/programs/:id`,
`/pos/loyalty/programs/:id/edit`, `/pos/loyalty/members`, `/pos/loyalty/members/new`,
`/pos/loyalty/members/:id`, `/pos/loyalty/members/:id/edit`
(`loyalty.view` for reads, `loyalty.manage` for writes; API additionally gated by
`module:Loyalty`). Cashier-holdable narrow grants: `loyalty.enroll`, `pos.operate_terminal`.

**Arithmetic.** `PointsAmount` — numeric-string, bcmath only, **SCALE = 3**, matching
`loyalty_transactions.amount NUMERIC(15,3)`. `earning_rules.reward_value NUMERIC(15,4)`.
Earn math runs at `scale + 4` intermediate then canonicalizes to the currency scale.
Default bootstrapped rule ("Points per dinar"): `rule_type = Spend`, `reward_type = multiplier`,
`reward_value = '1'` → **1 point per currency unit spent**, multiplied by the tier's
`earning_multiplier`.

> **Reachability ruling — read before writing redemption cases.** **Pay-with-points is NOT wired to
> any live checkout UI.** `POST /loyalty/pos/redeem` exists server-side, but its only frontend
> caller lives in the unrouted/dead web-POS `POSPage.tsx`, and `apps/pos` (Tauri) has **zero**
> redeem callers. So the **points→money discount leg is unreachable from both the web SPA and the
> device** at launch (tracked as post-launch PL-1). Redemption below is therefore tested only via
> the **admin adjust/redeem admin surfaces** and the API, and is capped at **P1/P2** — it cannot be
> launch-blocking for a leg no customer can trigger.

> **Known earn-engine gaps (do not file as new):** **PL-9** — variant-keyed Item rules never match
> device sales (canonical line items carry the *parent* product id), and **fractional quantities
> truncate via an int cast** (`"2.500"` → `2`), producing real under-credit for fractional-unit
> retailers. **PL-8** — Category earning rules are uncreatable in the UI (`category_ids.*`
> validated as uuid while `categories` PK is bigint; no category selector in
> `EarningRuleFormModal.tsx`). **Double-earn:** two queued paths earn for the same receipt
> (`PosCoreReceiptProjection::earnLoyaltyPoints` on `fiscal-projections` and
> `EarnPointsOnReceiptCompleted` on `default`), deduped by the partial unique index
> `loyalty_txn_earn_source_unique`; the projection path sends `items: []` so Item/Category/Quantity
> rules compute **zero** there while the listener path computes real values. **Single active
> program is not enforced** — `activateProgram` never deactivates siblings and enroll paths take
> `.first()`.

| ID | Type | P | Preconditions | Steps | Expected result (exact) |
|---|---|---|---|---|---|
| MTP-LOY-01 | HAPPY | P1 | `DemoPharmacySeeder` tenant (program bootstrapped) | `/pos/loyalty/programs` | Exactly one ACTIVE program; its default Spend rule shows `reward_value = 1`, `reward_type = multiplier` |
| MTP-LOY-02 | HAPPY | P1 | program from 01, enrolled member with balance `0.000` | Author a device sale of exactly `100.000` TND for that member (§Z), wait for sync + queues | Member balance `= 100.000` points (1 pt per currency unit, tier multiplier 1). Displayed at scale 3. |
| MTP-LOY-03 | EDGE | P0 | as 02, after sync | Re-check the member ledger | **Exactly ONE** earn transaction for that receipt (`source_type`/`source_id` unique index holds). A duplicate earn is launch-blocking. |
| MTP-LOY-04 | EDGE | P1 | tier with `earning_multiplier = 2` | Sale of `100.000` for a member in that tier | Balance `= 200.000` points |
| MTP-LOY-05 | EDGE | P1 | Item-type earn rule keyed to a **variant** | Device sale of that variant | **Known gap PL-9:** rule does not match; zero item-points credited. Record as known-fail, do not file. |
| MTP-LOY-06 | EDGE | P1 | Quantity-type earn rule; product with a fractional unit | Sale of qty `2.500` | **Known gap PL-9:** quantity is int-cast to `2` — points credited for 2, not 2.5. Record exact under-credit. |
| MTP-LOY-07 | EDGE | P1 | Program with `points_expiry_months` set; member with points past expiry | `/pos/loyalty/members/:id` | Expired points are excluded from the usable balance; FIFO expiry consumed oldest-first; `expires_at` visible per transaction |
| MTP-LOY-08 | HAPPY | P1 | member with balance `500.000` | `/pos/loyalty/members/:id` → admin **adjust** `+50.000` with a reason | Balance `= 550.000`; an adjustment transaction is recorded with the actor and reason |
| MTP-LOY-09 | EDGE | P1 | member with balance `10.000` | Admin adjust `−50.000` | Either refused, or balance goes negative — record actual. If it goes negative, the member detail must display the negative at scale 3 and never render `NaN`. |
| MTP-LOY-10 | EDGE | P1 | reward with `points_cost = 200.000`; member balance `150.000` | Attempt redemption through the admin surface / API | Refused by `canRedeem` — insufficient balance; **no** negative transaction written |
| MTP-LOY-11 | EDGE | P2 | reward `points_cost = 100.000`; member balance `150.000` | Redeem via the admin surface / API | Negative transaction `amount = −100.000`; `current_balance = 50.000`; `lifetime_redeemed += 100.000` |
| MTP-LOY-12 | EDGE | P2 | as 11 | Confirm the money leg | **Unreachable at launch (PL-1)** — no checkout applies points as a discount. Record `N/A — feature not wired`, never `PASS`. |
| MTP-LOY-13 | EDGE | P1 | two ACTIVE programs created for one company | Enroll a new member | **Known gap:** no single-active-program invariant; enrolment silently takes `.first()`. Record which program won. |
| MTP-LOY-14 | EDGE | P1 | user with only `loyalty.enroll` (cashier) | Attempt `/pos/loyalty/programs` and a program edit | List/edit blocked (`loyalty.view`/`loyalty.manage` absent); enrolment endpoint still permitted |
| MTP-LOY-15 | EDGE | P1 | Tenant B member | As Tenant A user, query the member endpoints with Tenant B ids | 404/403 — no balance, no transaction history leaks |
| MTP-LOY-16 | EDGE | P2 | fresh tenant, no program | `/pos/loyalty/programs`, `/pos/loyalty/members` | Empty states; no phantom `0.000` balances |

**Subtotal §H: 16 cases** (3 HAPPY / 13 EDGE · P0 1 · P1 12 · P2 3)

<!-- SECTION-H-END -->

## §I — Cross-cutting sweeps: `MLC` `ISO` `I18N` `PERM` `CONC` `EMPTY` (W7)

### I.1 `MLC` — multi-location money views

**Mechanism.** There is **no parent/child location hierarchy field** — `Location` is a flat
per-company list (`type: shop|warehouse|office|mobile`, `isDefault`, `posEnabled`, per-location
tax identifiers). What behaves like a hierarchy is the seeded topology: `DemoPharmacySeeder`
creates one **non-POS central warehouse `WH-01`** plus **4 POS shops**, each with its own
establishment matricule, and pins cashiers to a shop via
`UserCompanyMembership.allowed_location_ids`.

Scope is selected **globally** in the TopBar via `ViewScopePicker` (re-exported as
`LocationSelector`), backed by `useViewScopeStore` + `useScopedLocations()`; `useViewScope()`
exposes `{ scope, effectiveLocationIds, isAll, setScope }` and **auto-clamps** a persisted subset
when a location grant is revoked. `locationScopedKey()` is threaded into the TanStack keys for:
owner reports (`salesByLocation`, `topSkus`, `revenueByCategory`, `paymentMethods`,
`cashReconciliation`, `salesSummary`, `liveSales`), POS analytics (`summary`, `salesByCategory`,
`salesByProduct`, `salesByPeriod`, `cashiers`, `discounts`, `customers`, `fnb`), Z-reports,
`/finance/cash-movements`, and `/inventory/stock-by-location`.

| ID | Type | P | Preconditions | Steps | Expected result (exact) |
|---|---|---|---|---|---|
| MTP-MLC-01 | HAPPY | P0 | `DemoPharmacySeeder`; sales authored at 2 different shops | TopBar scope = "All" → `/reports` | `salesByLocation` lists each shop; **Σ per-location revenue == the all-locations total** exactly at scale 3 |
| MTP-MLC-02 | HAPPY | P0 | as 01 | Set scope to Tunis Lac only | Every money tile re-queries and shows **only** Tunis Lac figures; the other shops' amounts are absent, not merely hidden |
| MTP-MLC-03 | EDGE | P0 | as 01 | Scope = Tunis Lac; then scope = Sousse Médina | The two result sets are disjoint and their sum equals the two-shop subtotal. A stale cached value from the previous scope is a **key-scoping defect** (`locationScopedKey`). |
| MTP-MLC-04 | EDGE | P0 | cashier pinned to one shop (`tunis1.cashier@pharmabio.tn`) | Log in as that cashier, open every money report reachable to them | Only their own shop's figures. Attempting another location id via the API returns 403/empty — never another shop's money. |
| MTP-MLC-05 | EDGE | P1 | as 04, then admin revokes that location grant | Reload with the stale persisted scope | Scope auto-clamps; **no 403 error page**, no stale figures |
| MTP-MLC-06 | EDGE | P1 | scope = warehouse `WH-01` (non-POS) | `/reports`, `/pos/z-reports` | Zero POS revenue for a non-POS location; empty states render cleanly rather than erroring |
| MTP-MLC-07 | EDGE | P1 | scope selection cleared (no locations) | `/inventory/stock-by-location` | Query is **disabled** (documented behavior) — page shows a prompt, not a spinner-forever and not an unscoped whole-company dump |
| MTP-MLC-08 | EDGE | P1 | multi-location tenant | `/finance/cash-movements` with scope changed | Cash figures re-scope; Σ across all locations == the "All" figure |

### I.2 `ISO` — cross-tenant / cross-company isolation

> Every case here is **P0**. A money figure crossing a tenant boundary is an unconditional no-go.
> Use `TwoTenantIsolationDemoSeeder` (`demo-tenant-a` / `demo-tenant-b`) plus the real Tenant A/B.
> Assert on the **API response body**, not only on the rendered page — a hidden-but-fetched figure
> is still a leak.

| ID | Type | P | Preconditions | Steps | Expected result |
|---|---|---|---|---|---|
| MTP-ISO-01 | EDGE | P0 | Tenant A + Tenant B both have documents | As Tenant B, walk `/sales/invoices`, `/sales/quotes`, `/sales/orders`, `/sales/credit-notes` | No Tenant A document, number, or total in any list or response |
| MTP-ISO-02 | EDGE | P0 | as 01 | As Tenant B, request a **known Tenant A** document id directly by URL and by API | 404 (preferred) or 403 — **never** a rendered total, never a partial payload |
| MTP-ISO-03 | EDGE | P0 | both tenants have treasury data | As Tenant B, walk every `/treasury/*` page and `/finance/*` page | Zero Tenant A figures; aged AR/AP totals differ from Tenant A's known seeded values |
| MTP-ISO-04 | EDGE | P0 | both tenants have POS data | As Tenant B, `/pos/z-reports`, `/pos/shift-history`, `/reports` | No Tenant A Z-number, receipt, shift, or cash figure |
| MTP-ISO-05 | EDGE | P0 | Tenant A token, Tenant B token | Replay a Tenant A money-mutation request while authenticated as Tenant B (same endpoint, Tenant A ids) | Refused; **no** row written in either tenant DB |
| MTP-ISO-06 | EDGE | P0 | `demo-garage` (two companies in ONE tenant) | Switch company; view `/finance/trial-balance`, `/treasury/payments`, `/sales/invoices` | Company-B figures never appear under Company A. Cross-**company** isolation is a separate boundary from cross-tenant and must be asserted separately. |
| MTP-ISO-07 | EDGE | P0 | permission cache freshly reset (PF-3) | As Tenant B user immediately after a Tenant A user's session | No permission bleed — the Spatie cache is tenant-blind, so a stale cache can grant Tenant A's permissions inside Tenant B |

### I.3 `I18N` — fr-locale number formatting

**Mechanism.** `formatCurrency` exists in **three** places (`lib/format.ts` canonical,
`lib/formatCurrency.ts` wrapper, `lib/decimal.ts`) — `currencyFormatting.test.ts` asserts all three
normalize identically for TND. Amounts are formatted with **big.js**, and `Intl.NumberFormat` is
used only for locale *metadata* (decimal separator + integer grouping), so no float rounding of the
amount itself. Currency→locale map: `TND→fr-TN`, `EUR→fr-FR`, `USD→en-US`, `GBP→en-GB`,
`MAD→fr-MA`, `DZD→fr-DZ`, `LYD→ar-LY`, `ITL→it-IT`, fallback `en-US`.
UI locales: `en`, `fr`, `ltr`; `ar` is RTL and **partially translated** (many namespaces fall back
to English). Language switch lives in the TopBar, persisted to `localStorage` key
`autoerp-language`, detection order querystring → localStorage → navigator.

> **Assertion pitfall — this will bite Playwright.** French grouping uses **U+202F NARROW NO-BREAK
> SPACE**, not an ASCII space. Reference vector: `formatCurrencyCompact('6607.6','TND','fr-TN')`
> → **`'6 607,600'`**. DOM text matchers normalize whitespace and will make U+202F *look*
> like a plain space, masking a real regression. **Every fr-locale assertion in this campaign must
> either compare against the literal U+202F or apply an explicit `.replace(/\s+/g, ' ')`
> normalizer — and the case must state which.**

| ID | Type | P | Preconditions | Steps | Expected result (exact) |
|---|---|---|---|---|---|
| MTP-I18N-01 | HAPPY | P1 | TND company, fr locale | Display any amount of `6607.600` | Renders `6 607,600` (narrow no-break space + comma decimal, **3** decimals for TND) |
| MTP-I18N-02 | EDGE | P1 | as 01 | Compare the rendered string with an ASCII-space expectation | Must **fail** on a raw compare and pass only under an explicit normalizer — proves the assertion harness is not silently normalizing away a real defect |
| MTP-I18N-03 | HAPPY | P1 | EUR company (`demo-garage` FR company), fr locale | Display `6607.60` | `6 607,60` — **2** decimals for EUR |
| MTP-I18N-04 | EDGE | P1 | TND company | Switch language en ⇄ fr in the TopBar on a money-dense page (`/finance/trial-balance`) | Separators switch; **the numeric values are identical** in both locales (formatting only, never re-rounding) |
| MTP-I18N-05 | EDGE | P1 | TND company | Render a negative amount `−1234.500` | Sign placement is correct for the locale and the minus is not lost or doubled |
| MTP-I18N-06 | EDGE | P1 | TND company | Render `0` | Renders `0,000` (scale-3 zero), not `0`, not blank |
| MTP-I18N-07 | EDGE | P2 | ar locale | Switch to Arabic on a money page | RTL layout applies; amounts remain readable and correctly scaled; untranslated namespaces fall back to English **without** breaking the number rendering |
| MTP-I18N-08 | EDGE | P1 | any money **input** (`MoneyInput`) | Type `1234,50` (comma) into a TND field in fr locale | Record actual: the payload must reach the API as a canonical dot-decimal **string**, never as a locale-formatted string or a float |

### I.4 `PERM` — permission-denied paths for money mutations

**Role→money-permission facts** (`RolesAndPermissionsSeeder.php`; `admin` gets `Permission::all()`;
`owner` is a `UserCompanyMembership.role`, not a Spatie role, and is additionally granted `admin`
in every seeder). FE gate: `RequirePermission` (`permission` / `permissions[]` / `requireAll` /
`moduleKey`) → `usePermissions()` → `canAccessModule` over `MODULE_PERMISSIONS`
(`finance: ['accounts.view','journal.view']`, `treasury: ['treasury.view']`,
`expenses: ['expenses.view']`, `pos: ['pos.operate_terminal','pos.manage_terminals','pos.view_receipts']`).
Permission truth is **server-provided `user.permissions`**, falling back to the generated
`permissionsMap.generated.ts` only when absent; `pricing.view_cost_prices` and `bank-statements.*`
are `SERVER_AUTHORITATIVE_PERMISSIONS` and never trust the client fallback.

- **cashier**: `payments.view/create`, `expenses.view/create`, `income.view/create`,
  `pos.tolerance.apply`, `pos.generate_z_report`, `pos.view_receipts`, `pos.manage_shifts`,
  `pos.redeem_voucher`. **No** `journal.*`, `accounts.*`, `pricing.sell_below_*`,
  `payments.refund`, `pos.refund_above_threshold` / `refund_no_receipt` / `refund_extend_daily_cap`.
- **manager**: everything money except `payments.void` / `payments.reverse` (admin-only); has
  `payments.refund`, `expenses.post/pay`, `income.post`, `journal.view` (not create/post),
  `accounts.view/manage`, all `pricing.sell_below_*`, all `pos.refund_*`, all `pos.approve_*`,
  `pos.close_shift_with_variance`.
- **accountant**: deepest finance role — `journal.view/create/post`, `accounts.view/manage`,
  `treasury.view/manage/adjust/transfer`, `bank-statements.*`, `instruments.*`,
  `repositories.view/manage`, `payments.view/create/allocate/refund` (not void/reverse) — but
  **no POS-floor permissions**.
- **viewer**: read-only. **operator/technician**: negligible money surface.

> **Every `PERM` case must assert BOTH layers:** the UI blocks it **and** a direct API call with the
> same credentials returns 403. Frontend hiding alone is not enforcement.

| ID | Type | P | Role | Attempted money mutation | Expected result |
|---|---|---|---|---|---|
| MTP-PERM-01 | EDGE | P0 | cashier | `payments.refund` — refund a payment at `/treasury/payments/:id` | UI action absent; API **403** |
| MTP-PERM-02 | EDGE | P0 | cashier | `payments.void` / `payments.reverse` | UI absent; API 403 (also 403 for **manager** — admin-only) |
| MTP-PERM-03 | EDGE | P0 | manager | `payments.void` | API **403** — confirms manager is genuinely excluded, not silently allowed |
| MTP-PERM-04 | EDGE | P0 | cashier | `journal.create` at `/finance/journal-entries/create` | Route blocked; API 403 |
| MTP-PERM-05 | EDGE | P0 | cashier | `expenses.post` / `expenses.pay` | UI actions absent; API 403 for both |
| MTP-PERM-06 | EDGE | P0 | cashier | View cost price / stock value on `/inventory/products/:id` (`pricing.view_cost_prices`, SERVER-AUTHORITATIVE) | Field absent **and** absent from the API payload — a client-side-only hide is a P0 defect here |
| MTP-PERM-07 | EDGE | P0 | cashier | `pricing.sell_below_cost` — enter a below-cost price on a document | Blocked/warned per the margin policy; the override is not silently accepted |
| MTP-PERM-08 | EDGE | P0 | cashier | `bank-statements.reconcile` at `/treasury/statements/:id` (SERVER-AUTHORITATIVE) | UI absent; API 403 |
| MTP-PERM-09 | EDGE | P1 | accountant | `pos.operate_terminal` — any POS-floor action | 403 — accountant has no POS-floor grants |
| MTP-PERM-10 | EDGE | P1 | viewer | Any create/update/post on expenses, income, payments, journal | All 403; all list views still render |
| MTP-PERM-11 | EDGE | P1 | technician / operator | `/treasury/*`, `/finance/*` | Blocked at the module gate (`MODULE_PERMISSIONS`) |
| MTP-PERM-12 | EDGE | P0 | cashier, **immediately after** a permission reseed without `permission:cache-reset` inside the tenant | Re-attempt MTP-PERM-01 | Documents the tenant-blind-cache hazard: if the refusal flips to allowed, PF-3 was not executed correctly. This case is the guard for `docs/superpowers/.../spatie_permission_cache` risk. |
| MTP-PERM-13 | EDGE | P1 | user whose `max_discount_percent` is `10.00` (`cashier@pharmabio.tn`) | Apply a 25% discount on a document/receipt | Refused / requires a manager-PIN override (`pos.approve_discount_limit_override`) |
| MTP-PERM-14 | EDGE | P1 | user with `max_discount_percent = 25.00` (`barista@cafe-tunis.tn`) | Apply exactly `25.00%` then `25.01%` | `25.00` allowed (inclusive boundary), `25.01` refused |

### I.5 `CONC` — concurrency and stale edits

| ID | Type | P | Preconditions | Steps | Expected result |
|---|---|---|---|---|---|
| MTP-CONC-01 | EDGE | P0 | one draft invoice, two browser sessions | Both open `/sales/invoices/:id/edit`; A saves a line change; B then saves a different change | Second save must **not** silently overwrite with stale totals — expect a conflict/refetch. Record the actual behavior; a silent last-write-wins on a money document is launch-blocking. |
| MTP-CONC-02 | EDGE | P0 | one payment, two sessions | Both attempt to allocate the **same** payment to the same invoice simultaneously | Exactly one allocation persists; no double-allocation, no over-allocation past the invoice balance |
| MTP-CONC-03 | EDGE | P0 | one expense pending payment, two sessions | Both click "Pay" | Exactly one Payment row; no duplicated GL leg |
| MTP-CONC-04 | EDGE | P1 | one goods receipt, two sessions | Both post the same receipt | Exactly one posting; stock moves once; WAC blends once |
| MTP-CONC-05 | EDGE | P1 | one opening-balance batch | Two sessions post it simultaneously | `OpeningAlreadyExistsException` on the loser; exactly one JE |
| MTP-CONC-06 | EDGE | P1 | invoice open in a tab, then voided/credited elsewhere | Return to the stale tab and attempt a money action | Refused with a clear state error; no action against a stale status |

### I.6 `EMPTY` — empty-state reports

| ID | Type | P | Surface | Expected result |
|---|---|---|---|---|
| MTP-EMPTY-01 | EDGE | P1 | `/reports` (owner dashboard) on a tenant with **no** POS data | Empty state; every money tile shows a scale-correct zero or an explicit "no data", never a spinner-forever, never `NaN`, never a stale other-tenant figure |
| MTP-EMPTY-02 | EDGE | P1 | `/pos/z-reports` with no Z closed | Empty list; no phantom Z row |
| MTP-EMPTY-03 | EDGE | P1 | `/pos/shift-history` with no shift | Empty list |
| MTP-EMPTY-04 | EDGE | P1 | `/finance/cash-movements` for a period with no movement | Zeroed report at scale 3 |
| MTP-EMPTY-05 | EDGE | P1 | `/treasury/statements` with none imported | Empty state; import CTA present |
| MTP-EMPTY-06 | EDGE | P2 | `/finance/aged-receivables` with nothing outstanding | All buckets `0.000`; Σ `0.000`; no divide-by-zero in any percentage column |
| MTP-EMPTY-07 | EDGE | P2 | `/expenses/analytics` with no expenses | Charts render empty rather than erroring |
| MTP-EMPTY-08 | EDGE | P1 | Any report with a period selected **in the future** | Empty, not an error; the period label matches what was requested |

**Subtotal §I: 8 + 7 + 8 + 14 + 6 + 8 = 51 cases** (4 HAPPY / 47 EDGE · P0 23 · P1 25 · P2 3)

<!-- SECTION-I-END -->

## §Z — POS DESKTOP CAMPAIGN: required coverage (computer-use, NOT Playwright)

**Why this section exists.** `apps/pos` (Tauri 2) cannot be driven by Playwright: it is a native
shell with a local SQLite database and it is the **fiscal authority** — the device authors and signs
receipts, owns the hash chain, and closes the Z. It gets a **separate computer-use campaign**
(recipe: `reference_pos_tauri_dev_computer_use_recipe`; tester-facing protocol format:
`docs/qa/desktop-protocols/TEMPLATE.md`).

**Two hard dependencies run in this direction:**
1. **The web campaign depends on §Z.** No seeder creates `pos_shifts`/`pos_receipts`, and the
   browser POS is 403 for non-demo tenants. Every figure §F asserts must be authored here first —
   specifically the **SHIFT-1** and **SHIFT-2** fixtures in §F.0.
2. **§Z is where the entire v4 refund flow lives.** `git diff dev...feat/v3-refund-chain` touches
   **zero files under `apps/web`** — the payload builder, approval protocol, refund intents,
   payout-reconciliation modal, and the offline Z/EOD/X aggregation fixes are all device-side.
   A web-only campaign therefore tests **none** of the refund logic, only its downstream projections.

**Sequencing:** §Z group Z.1–Z.3 must complete before web wave **W5**. Groups Z.4–Z.9 may run in
parallel with W5–W7. Z.10 runs immediately before §Y.

### Z.1 — Author the web campaign's fixtures (blocking dependency for W5)

| # | Coverage item |
|---|---|
| POSC-01 | Open SHIFT-1 with opening float `100.000`; author R1–R4 exactly as specified in §F.0 (all cash tendered **exactly**, zero change), then the v4 full refund RF1 of R1. |
| POSC-02 | Open SHIFT-2 with opening float `100.000`; author R5 (tendered `50.000` on a `23.800` sale → change `26.200`), R6 (non-zero **whole-receipt/transaction** discount), R7 (**CARD-only** tender), R8 (exact total `12.347`), R9 (exact total `12.320`), R10 (exact total `12.325`), and R11 (**TRAINING** sale — only if the `TreasuryReceiptBridge` training gate has landed; see §0.3). |
| POSC-03 | Confirm each authored receipt syncs to the server and appears in `/pos/transactions` before W5 begins. |
| POSC-04 | If any **legacy-negative** refunds already exist on the tenant, record their ids — they are the other half of the mixed-population window that `MTP-AGG-15` needs. If none exist, author one through the **legacy `/return`** path on a non-acknowledged terminal (still live by design, spec §9.2). |

### Z.2 — Offline sale → sync

| # | Coverage item |
|---|---|
| POSC-05 | Take the terminal fully offline (≥10 minutes, ≥5 receipts) and author sales covering: single-line, multi-line, mixed VAT rates, per-line discount, fractional quantity on a unit with `decimal_places = 3`. |
| POSC-06 | Verify each offline receipt prints and is retrievable locally while still offline, with correct totals at the currency scale. |
| POSC-07 | Reconnect; verify **every** offline receipt syncs exactly once — no duplicates, no drops. Compare the device's local count and Σ totals against the server projection. |
| POSC-08 | Verify the fiscal chain is continuous across the offline segment (no sequence gap, each `previous_hash` links) — this is what §Y re-verifies at the end. |
| POSC-09 | Verify SQLite TEXT timestamp handling: same-day reports on the device must include receipts authored around the offline boundary. (Regression guard for the `' ' < 'T'` lexicographic bug — every JS-supplied boundary must go through `toSqliteUtc()`.) |
| POSC-10 | Kill the app mid-sync and restart; verify no partial/duplicated receipt and no orphaned money leg. |

### Z.3 — Offline refund with approval + payout reconciliation

| # | Coverage item |
|---|---|
| POSC-11 | **Happy v4 refund**: full refund of a cash-tendered original, manager PIN approval (`verifyScopedManagerPin`, scope `void_or_return_override`) — PIN is **unconditional** on the v4 path, not threshold-gated. Verify the paired approval + override fiscal events are authored. |
| POSC-12 | **Partial refund** by line/quantity; verify the refund's positive magnitudes and that the residual quantity remains refundable. |
| POSC-13 | **Refusal — whole-receipt discount** (R6): `WholeReceiptDiscountRefundRefusedError`, i18n `refundFlow.wholeDiscountReceiptRefused`, fired at `resolveOriginalFiscalEventLocally()` **before** any PIN is spent. Verify for **both** partial and full line selections. |
| POSC-13a | **Refusal — PARTIAL refund of a per-line-discounted line** (R4, wave-2 finding 10, fiscal I-1): edit the return quantity down on a per-line-discounted line and attempt to refund it → refused pre-PIN with `refundFlow.discountedPartialRefundRefused` (`refundCheckoutStore.ts`, before `createOrReuseActiveRefundIntent`), nothing signed. Then verify a **FULL**-quantity refund of the SAME line (R4 unedited) **is** refundable — confirms the refusal is proration-scoped, not a blanket per-line-discount ban. |
| POSC-14 | **Refusal — non-cash original** (R7): `NonCashOriginalRefundRefusedError`, i18n `refundFlow.nonCashOriginalRefused`. Verify it also fires for a **mixed** cash+card original, an empty payments array, and a malformed payments array (**fail closed**). Payment-method match is case-insensitive `'CASH'` and must be exactly **one** leg. |
| POSC-15 | **Refusal — training original** (R11): `TrainingOriginalRefundRefusedError`, read from the **original's own signed** `training_flag`. Then verify a **non**-training original **is** refundable while the session is in training mode (non-conflation). |
| POSC-16 | **Per-line cumulative backstop**: refund 1 of 2 units, then attempt 2 more → refused. Verify it sums across every `refund_intents` row in a state proving a fiscal event was appended (`refund_event_appended` / `synced`). |
| POSC-17 | **Backstop fails CLOSED**: corrupt/blank a prior appended snapshot, or supply a **number-typed** (non-canonical) quantity → `CumulativeRefundSnapshotUnreadableError`, refusal — never a skipped row, never a coerced number. |
| POSC-18 | **Receipt-level VALUE bound** (finding 19): `Σ|local_refund_records| + this attempt ≤ original's EXACT total (`total − cash_rounding_adjustment`)`. Critically: a **rounded-DOWN** original (R9) must still allow its **first full** refund. i18n `refundFlow.legacyRefundValueExceeded`. |
| POSC-19 | **Server cap is the sole authority**: attempt a refund that only the server can know is over-cap (e.g. prior refund settled on another terminal) → `RefundQuantityExceededException`, and confirm it **dead-letters immediately** (`NonRetryableProjectionException`), not after 5 Horizon retries. |
| POSC-20 | **Mixed legacy/v4 cap population**: one prior legacy-negative return line + one prior v4-positive return line against the same original; a third refund that breaches the cap only when both are counted must be **refused** (`SUM(ABS(quantity))`). |
| POSC-21 | **Payout reconciliation**: interrupt between payout confirmation and printing; restart the app → `RefundPayoutReconciliationModal` offers the reprint, reading from the `offline_receipts` row via `getOfflineReceiptForPrint()`. Verify `payout_confirmed_at` / `payout_disputed_at` / `printed_at` transitions and that a disputed payout does not double-pay. |
| POSC-22 | **Refund while offline**, then sync: the refund's fiscal event chains correctly and the server projection produces a POSITIVE-total `receipt_type='return'` row. |
| POSC-23 | **Restock disposition**: every launch refund line defaults to `restock`; a **regulated / never-restock** product is **not** restocked (`RestockPolicyResolver` `Never` overrides the payload). Damaged goods **will** restock — known limitation. |
| POSC-24 | **Capability rollout**: with authoring **not yet acknowledged**, a v4 refund attempt shows the typed refusal whose copy must **not** say "use the legacy path". Then run the two-phase enable → device sync acknowledgement → refund succeeds. |
| POSC-25 | **M2 velocity ceiling** (`{M2_COUNT}=5` refunds / `{M2_VALUE}=300.000` TND per shift while unsynced) and **M3 offline threshold** (`{M3_THRESHOLD}=100.000` TND) refusals — LANDED, `company_fraud_settings`. Cover: at-limit accepted (5th refund / cumulative `300.000` succeed), over-limit refused (`refundFlow.offlineRefundCountCeilingReached` / `refundFlow.offlineRefundValueCeilingReached`), M3 at-threshold (`100.000`) allowed offline, above-threshold (`100.001`) refused offline (`refundFlow.largeRefundRequiresOnline`) and the same refund permitted once **online**. Confirm the deployed defaults on the target tenant match before asserting exact numbers — substitute if overridden, do not mark `BLOCKED`. |
| POSC-25a | **M3 TOCTOU refusal — link drop DURING PIN entry** (I-1 fix, `f6a418720`): start an above-`{M3_THRESHOLD}` refund while online (so `requireServerVerifiedPin=true` is set at `begin()`), then drop connectivity **after** `begin()` but **during** manager-PIN entry, before the server round trip resolves. Expected: `verifyScopedManagerPin()` throws `ServerVerifiedPinRequiredError` (`refundFlow.largeRefundRequiresOnline` copy) rather than silently falling back to the device-local PIN cache; the refusal fires **before** `authorPosOverride()`, so **no approval or override fiscal event is ever signed** — verify nothing is appended to the local chain and the intent stays resumable (retry once back online costs no second PIN entry beyond the one already refused). |

### Z.4 — Z close offline (and the X/EOD path)

| # | Coverage item |
|---|---|
| POSC-26 | Close a Z **while offline**; verify the Z is authored and signed locally, prints, and syncs later intact. |
| POSC-27 | Verify the offline Z's aggregation matches §F.0's SHIFT-1 expectations: gross/net/tax are **sale-only**; `refunds_amount` is its own field; VAT breakdown and payment-method breakdown are **net of refunds**; `expected_cash` no longer subtracts a separate `cashRefundImpact` term. |
| POSC-28 | Verify `endOfDayPreview.ts` (a **structurally separate** query and loop from `zReportService.ts`) produces figures identical to the Z for the same window — including its `cashTenderedSum` / `perMethod` per-payment loop being refund-branched (the errata-T3 fix). |
| POSC-29 | Verify the local **X report** (`generateLocalXReport`) agrees with the Z for the same receipt set, and that generating an X neither closes the shift nor mutates counters. |
| POSC-30 | **Known defect check** — device Z/EOD/X **SALE** branch treats gross `line_total` as net: totals correct, net/VAT decomposition wrong (net overstated by the VAT amount per taxed line). Record the exact residue; a fully-refunded taxed sale leaves a `+VAT / −0` residue in the Z buckets. |
| POSC-31 | Attempt a **remote/server Z close** on a v3 terminal → expect **409** (B3 behavior); the device remains the sole Z authority. |
| POSC-32 | Z with **zero** sales; Z with **only** a refund; Z with sales but no cash tender. All must produce a valid signed Z with coherent zeros, not a crash or a NULL money field. |

### Z.5 — Cash rounding at the drawer

| # | Coverage item |
|---|---|
| POSC-33 | With denomination `0.050`: exact total `12.347` → collected `12.350`, `cash_rounding_adjustment = +0.003`; exact total `12.320` → collected `12.300`, adjustment `= −0.020`. Verify the printed receipt shows the rounding line. |
| POSC-34 | **Tie case** `12.325` (exact half-denomination): record the direction; it must be deterministic and **identical to the server's**. |
| POSC-35 | Verify `-0` never appears — canonical zero is the unsigned `'0.000'` — and that a non-rounded receipt carries the canonical zero, not a null. |
| POSC-36 | Verify rounding applies to the **cash** tender path only and is **VAT-neutral** (never allocated across VAT buckets), so `total ≠ Σ vat gross` by exactly one adjustment is legal. |
| POSC-37 | Verify the device's cached `cash_rounding_denomination` is stored as **TEXT** and matches the server byte-for-byte (`'0.050'`, never `0.05`). |
| POSC-38 | Verify the consumer split: **drawer, payable and Z gross** consume the **rounded** total; **revenue and loyalty** consume `total − adjustment`. |
| POSC-39 | Latent divergence probe: the device's `Big.RM` is **half-up** while the server **truncates**. Author receipts whose arithmetic lands exactly on a rounding boundary and compare device-signed bytes against the server's canonicalization. Any mismatch is a fiscal-integrity finding. |

### Z.6 — Training mode

| # | Coverage item |
|---|---|
| POSC-40 | Enter training mode; author a training sale. Verify `training_flag` is set on the signed payload and the receipt is visually marked. |
| POSC-41 | **Money isolation**: verify a training sale creates **no** Treasury Payment, **no** GL entry, and **no** repository movement. **Expect this to FAIL** until the `TreasuryReceiptBridge` tender-leg training gate lands. **If it fails, take no further training receipts for the remainder of the campaign.** |
| POSC-42 | Verify training receipts are excluded from Z/X/EOD aggregates and from every server revenue report. |
| POSC-43 | Verify a refund can never be authored against a training original, and that a v4 refund row is unconditionally `is_training = 0`. |
| POSC-44 | Exit training mode; verify normal receipts resume on the correct chain with no sequence anomaly. |

### Z.7 — Multi-shift

| # | Coverage item |
|---|---|
| POSC-45 | Open → close → open a second shift on the same terminal the same day; verify shift numbering, that the second shift's opening float is independent, and that Z figures do not bleed across shifts. |
| POSC-46 | Two different cashiers across two shifts; verify per-cashier attribution in the server-side cashier performance report (feeds `MTP-AGG-04/05`). |
| POSC-47 | Close a shift with a **variance** (short and over); verify the `pos.close_shift_with_variance` gate and that the counted/expected/variance figures reach the server. |
| POSC-48 | Attempt to author a sale with **no open shift** → refused with a clear message; no orphan receipt. |
| POSC-49 | Leave a shift open across midnight; verify the day-boundary handling in local reports and that the Z covers the intended window. |
| POSC-50 | Verify device-authored `fiscal_shift_id` / `fiscal_session_id` are **merged**, never replaced, when a shift is re-hydrated from a server response (`fetchCurrentShift`). |

### Z.8 — Device-only money flows not reachable from the web

| # | Coverage item |
|---|---|
| POSC-51 | **Customer-account deposit** (money-in) — follow `docs/qa/desktop-protocols/01-customer-account-deposit.md`; verify balance movement, receipt, offline behavior and sync. |
| POSC-52 | **Charge-to-account** (money-out) and credit-limit / account-status approval overrides. |
| POSC-53 | **Cash drawer deposit / payout** (till float in/out) — distinct from the customer-account deposit; verify the drawer operations and their effect on expected cash. |
| POSC-54 | **Multi-payment splits** at the tender screen: cash+card, cash+voucher, three-way; verify tendered vs retained, change computation, and that a leg netting to zero writes no Treasury row. |
| POSC-55 | **Voucher redemption** as a whole-receipt tender, including partial balance and expired-voucher refusal. |
| POSC-56 | **Manager-PIN override paths**: discount above the cashier's `max_discount_percent`, tender tolerance, void/return. Verify each authors its approval evidence. |
| POSC-57 | **Loyalty earn** on a device sale, including the fractional-quantity under-credit gap (PL-9) and the variant-keyed Item-rule miss. Confirm exactly one earn transaction per receipt. |

### Z.9 — Resilience

| # | Coverage item |
|---|---|
| POSC-58 | Printer offline / paper out during a sale and during a refund payout — the money state must not depend on the print succeeding; reprint recovery must work. |
| POSC-59 | Power loss mid-transaction; restart and verify no half-written receipt, no double drawer movement. |
| POSC-60 | Clock skew on the device (forward and backward) — verify fiscal timestamps and same-day report windows behave predictably and that the chain is not corrupted. |

### Z.10 — Handover to §Y

| # | Coverage item |
|---|---|
| POSC-61 | Force a full sync; confirm zero pending items in the device outbox and zero dead-lettered projections attributable to the campaign (other than those deliberately created by POSC-19). |
| POSC-62 | Record the terminal's final sequence number, last hash, and Z-number so §Y can verify the exact expected chain length. |

**§Z totals: 64 coverage items across 10 groups** (62 original + POSC-13a discount-partial-refusal +
POSC-25a M3 TOCTOU link-drop, both added 2026-08-01 once M2/M3 and the discount-refund correction
landed).

<!-- SECTION-Z-END -->

## §Y — LAST-GATE FISCAL RE-RUN (final pre-production gate)

> **This runs LAST, after every other case in this document and every item in §Z.** Its purpose is
> not to find new features' bugs — it is to prove that **the campaign itself did not damage the
> fiscal record**, and that the chains, the Z decomposition and the aggregates are all mutually
> consistent on the exact build that will go to production.
>
> **Nothing may be authored between §Y and the production cutover.** If any money is written after
> §Y completes, §Y must be re-run in full.

### Y.0 Preconditions

| # | Check |
|---|---|
| Y-PRE-1 | `{{DEPLOYED_SHA}}` re-recorded and **identical** to the value captured at PF-1. If staging redeployed mid-campaign, the campaign's results are attributable to two builds — re-run at minimum all P0 cases on the final build. |
| Y-PRE-2 | POSC-61/62 complete: device outbox empty, final sequence number / last hash / Z-number recorded. |
| Y-PRE-3 | Horizon drained: zero pending `fiscal-projections` jobs, and the dead-letter list contains **only** the events deliberately created by POSC-19 / `MTP-RFD-13`. |
| Y-PRE-4 | The §PF-6 baseline verifier output (captured before the campaign) is on hand for diff. |

### Y.1 Chain verification — the three real verifiers

These are the **only** three chain verifiers that exist. A `fiscal:verify-chain` command does **not**
exist — do not invent one.

| # | Command | Expected |
|---|---|---|
| Y-1 | `php artisan fiscal:verify-event-chain --tenant={{TENANT_A_UUID}} --terminal={{TERMINAL_UUID}} --actor-id={{SYSTEM_USER_UUID}}` (optional `--chain-context=operational`, `--from-sequence=`) | Chain intact from sequence 1 to the sequence recorded in POSC-62. **Zero** breaks, **zero** gaps. Every `previous_hash` links. The v4 refund events (`event_version = 4`) are in the chain and the **next sale chains off the refund**. |
| Y-2 | `php artisan pos:verify-chains --company={{COMPANY_A_UUID}} --terminal={{TERMINAL_UUID}} --type=all` | Both the **receipt** chain and the **z-report** chain verify clean. Re-run with `--type=receipts` and `--type=z-reports` separately so a failure is attributable. |
| Y-3 | `php artisan fiscal:verify-chains --company={{COMPANY_A_UUID}} --type=invoice` then `--type=credit_note` | Document-side fiscal hash chains intact for the invoices and credit notes authored in §B. **Never pass `--fix`** — it is destructive and would mask exactly what this gate is looking for. |
| Y-4 | Repeat Y-1 for **every** terminal and **every** tenant that was touched during the campaign | All clean. A single unverified terminal invalidates the gate. |

**Any chain failure is an unconditional NO-GO.** There is no risk-acceptance path. Capture the full
command output verbatim as evidence; do not summarize it.

### Y.2 X/Z decomposition re-verification against known figures

| # | Check | Expected (exact) |
|---|---|---|
| Y-5 | Re-open `/pos/z-reports/:zNumber` for **SHIFT-1** | `gross_sales 105.910` · `net_sales 89.000` · `tax_amount 16.910` · `sales_count 4` · `refunds_count 1` · `refunds_amount 11.900` — **unchanged** from `MTP-ZRP-01/02`. Any drift means something re-projected. |
| Y-6 | Aggregate identity | `net_sales + tax_amount == gross_sales` → `89.000 + 16.910 == 105.910` |
| Y-7 | VAT breakdown | 19% row: net `79.000` / vat `15.010` / gross `94.010`; per-row `net + vat == gross` |
| Y-8 | Payment methods | CASH `64.510` (count 5) · CARD `29.500` (count 1) · **Σ `94.010`** |
| Y-9 | The documented asymmetry still holds | `gross_sales − Σ vat_breakdown.gross == refunds_amount` → `105.910 − 94.010 == 11.900` |
| Y-10 | SHIFT-2 rounding identity per receipt | R8: `subtotal + vat_total == 12.350 − 0.003 == 12.347`; R9: `== 12.300 − (−0.020) == 12.320` |
| Y-11 | `pos_receipts_totals` CHECK holds for **every** receipt authored during the campaign | `total = subtotal + tax_amount − discount_amount + COALESCE(cash_rounding_adjustment, 0)` — query it directly; a NULL-satisfied CHECK on a legacy row is expected, a violated one on a campaign row is not |
| Y-12 | Device Z vs server projection for the same shift | Totals match exactly. The **net/VAT decomposition** may still diverge per the known device sale-branch defect — record the residue and confirm it is **unchanged** from `MTP-ZRP-09` (a *changed* residue means something else moved) |
| Y-13 | Re-generate an X on an open shift, if one exists, and compare to its Z once closed | Consistent; no double counting |

### Y.3 Aggregate re-verification (the v4-positive-refund gate, re-run)

| # | Check | Expected |
|---|---|---|
| Y-14 | Re-run `MTP-AGG-01` … `MTP-AGG-15` end-to-end on the final build | Every aggregate still nets refunds to **`94.010`** on the SHIFT-1 window; the **mixed legacy/v4** window (`MTP-AGG-15`) still agrees across every consumer |
| Y-15 | Cross-consumer reconciliation | `/reports` `SalesSummaryCards.grossSales` == `Σ PaymentMethodBreakdownPie` == `Σ RevenueByCategoryDonut` == `PosAnalyticsService` net sales == `OwnerSalesSummaryService` — **one number, five sources** |
| Y-16 | GL reconciliation | Σ Treasury `payments` (RETAINED) for the campaign window reconciles to the cash movements report and to the GL cash account; the RF1 payout appears once as an **out** of `11.900` |
| Y-17 | `/finance/trial-balance` | **Σ debits == Σ credits** exactly, after everything the campaign posted |
| Y-18 | `/finance/balance-sheet` | **Assets == Liabilities + Equity** exactly |
| Y-19 | Aged AR/AP vs seeded baselines | Seeded balances still reconcile: `CoffeeShopSeeder` `300.000` / `1200.000` / payable `2500.000`; `DemoPharmacySeeder` `900` / `3200` — adjusted only by what the campaign deliberately posted, with each delta attributable to a specific case ID |

### Y.4 Sign-off

| Item | Value |
|---|---|
| Campaign executed by | |
| Final `{{DEPLOYED_SHA}}` | |
| Date/time completed (with timezone) | |
| Total cases executed / passed / failed / blocked | |
| **P0 failures** (must be zero to proceed) | |
| Known-defect reproductions (from §0.3, expected) | |
| New defects filed (ticket ids) | |
| Chain verification outputs archived at | |
| **GO / NO-GO** | |

**Gate rule (aligned with `docs/qa/2026-05-12-first-tenant-smoke.md`):** any failed **P0** case is an
**unconditional NO-GO** — there is no risk-acceptance path. A failed **P1** may close only via a
recorded owner risk-acceptance entry naming the reason and a revisit milestone, logged against the
relevant gate in `docs/handoff/OWNER-manual-launch-gates-2026-07-31.md`. A **P1/P2** failure must
never be silently marked PASS. Reproductions of the §0.3 known defects are recorded as
`FAIL (known — <ticket>)` and are assessed by the owner against the existing tickets, not re-filed.

<!-- SECTION-Y-END -->

