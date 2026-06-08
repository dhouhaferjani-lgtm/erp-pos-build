# Branch / Per-Establishment — Doc 09b: Hybrid Sub-Account Model — Chart-of-Accounts Mechanics & Design Decision

**Date:** 2026-06-04
**Branch:** `feat/branch-tax-id-spec`
**Type:** Codebase deep-dive + design decision. RESEARCH/PLANNING ONLY — no code change.
**Companion to:** Doc 09a (sub-account *practice* — Tunisia/FR/MA/DZ). Doc 08 (program scope). Doc 06 (accounting current state).

**Owner directive (2026-06-04):** Keep the per-branch ledger **dimension** AND *also* offer per-branch **sub-accounts** (e.g. `6420` main → `64201`/`64202`/`64203` per branch) as a **company-settings toggle**, for analytical accounting + end-of-period presentation (used in Tunisia). Fiscally only the main entity tax-ID matters. Branches = **sellable locations only** (warehouses/offices excluded).

---

## 1. Verdict: the hybrid is viable and Tunisia-sanctioned — but "selective opt-in", not "expand everything"

- **Tunisia confirms the owner (Doc 09a, high confidence):** the Système Comptable des Entreprises (Loi 96-112, NC 01) explicitly permits "les subdivisions de comptes nécessaires", codes subdivide open-endedly, and the framework itself ships a per-establishment subdivision (compte 17 = comptes de liaison "subdivisé en autant de comptes … que d'établissements"). So `6420 → 64201/64202` per branch is a recognised TN pattern — **permitted, not legally mandatory** for ordinary P&L accounts.
- **France (Doc 09a):** PCG permits subdivisions, but the French *norm* for slicing by establishment is the **analytic dimension** (sections analytiques), not GL sub-accounts. They're framed as alternatives.
- **Design conclusion:** a *full* hybrid (every account both dimensioned and sub-accounted) is redundant double-tracking + the classic chart-of-accounts-explosion anti-pattern. The right model is **dimension as the single source of truth, with selective opt-in branch sub-accounts** on a short list of accounts (TN-driven). The dimension always carries branch; sub-accounts are an optional *presentation* layer on top, defaulting OFF.

### Which accounts get branch sub-accounts (when the toggle is on)
| Account family | Branch sub-accounts? | Why |
|---|---|---|
| **Class 7 — revenue** | ✅ opt-in | Branch P&L presentation; primary owner ask. |
| **Class 6 — expenses** | ✅ opt-in | Branch cost breakdown in the trial balance directly. |
| **TN compte 17 / FR compte 18 — liaison/inter-establishment** | ✅ (framework precedent) | The one place the standard itself subdivides per établissement. |
| **VAT / tax (445x)** | ❌ stay single | One entity, one declaration, one matricule — fiscally must consolidate. |
| **Balance-sheet (assets/liabilities/equity, AR/AP 411/401)** | ❌ stay single (dimension-only) | Per-branch balance sheet is a stretch goal; keep dimension-only to avoid CoA bloat and the roll-up trap on subledger accounts. |

---

## 2. How the chart of accounts works today (the constraints that shape the design)

All paths in `apps/api/`. Grounded by a fresh CoA deep-dive (2026-06-04) on top of Doc 06.

| Fact | Detail | Cite |
|---|---|---|
| **`accounts.code`** | `string(20)`, **free-form, no format/length validation** at DB or model. Branch-suffixed codes (`64201`) are allowed with zero code change. | `migrations/tenant/2025_11_30_090000_create_accounts_table.php:17`; `Accounting/Domain/Account.php:68-94` |
| **Hierarchy** | `parent_id` self-FK (`nullOnDelete`), true tree; reports walk it. No schema depth limit (`AccountHierarchyService::MAX_HIERARCHY_DEPTH=100`). | `create_accounts_table.php:16,37-42`; `Account.php:124-135`; `AccountHierarchyService.php:53` |
| **Uniqueness #1** | `unique(company_id, code)`. | `2025_12_30_195200_fix_accounts_unique_constraint.php:18-22` |
| **Uniqueness #2 (the blocker)** | `unique(company_id, system_purpose)` — **exactly one account per purpose per company.** | `2025_12_06_002513_add_company_id_and_system_purpose_to_accounts.php:55-58` |
| **No `accounts.location_id`** | Does not exist today (only the dead `journal_entries.location_id`). | (grep) |
| **Toggle home** | Per-feature boolean directly on `companies` (precedent: `allow_below_cost_sales`). | `2025_12_02_064506_add_inventory_costing_settings_to_companies_table.php:14-19` |
| **Sellable location** | `LocationType::Shop` (vs Warehouse/Office/Mobile); `Location::isShop()`. | `Company/Domain/Enums/LocationType.php:9-12`; `Location.php:135-138` |

### 2.1 Account resolution — the blast radius
Posting resolves accounts via three near-duplicate helpers, all `WHERE company_id=? AND system_purpose=? ->first()`:
- `Account::findByPurposeOrFail` (`Account.php:252-266`), `GeneralLedgerService::getAccountByPurpose` (`:1525-1528`), `AccountingService::findAccountByPurpose` (`:331-352`, a duplicate to unify).
- **~40 call sites** across `GeneralLedgerService` (the full posting surface — AR, revenue, VAT, COGS, inventory, cash/bank, discounts, write-offs), `AccountingService` (invoice/credit-note), `PartnerBalanceService`, `ChartOfAccountsService`, opening-balance + uninvoiced-delivery services. **None take `location_id` today.**

### 2.2 THE ROLL-UP TRAP (the single most important design constraint)
Trial Balance / P&L / Balance Sheet all roll children up to parents via `AccountHierarchyService::calculateSubtotals`, which **overwrites** a parent node's balance with the **sum of its children** — it does **not** add the parent's own direct postings (`AccountHierarchyService.php:160-177`).

> **Consequence:** once an account is branch-expanded (has children), **all postings must go to the branch child accounts, never to the parent.** A parent that has both children and its own direct lines would have those direct lines silently clobbered in every statutory report.

This makes branch sub-accounts a **behavioral change to every posting site**, not just to resolution: when the toggle is on and the account is expanded, the posting service must resolve and post to the branch child. The flip side is good news — **branch children auto-roll-up to the statutory parent for free** (TB/P&L/BS need no change for the consolidated view), provided children carry `parent_id` and postings only hit leaves. (Note: the General Ledger *detail* report is flat, not hierarchical.)

### 2.3 The `system_purpose` conflict → the required shape
Because of `unique(company_id, system_purpose)`, branch children **cannot share a purpose** (the second insert throws; `ChartOfAccountsService::assignPurpose` also rejects duplicates `:99-109`). Therefore:

- **`system_purpose` stays on the single parent only** (`6420`/`411`); branch children carry **no purpose**.
- Add a new nullable **`accounts.location_id`** column: branch children point to their sellable location; the purpose-bearing parent stays `location_id = null`.
- Add a new resolver **`findByPurposeForLocation(companyId, locationId, purpose)`**: resolve the parent by purpose, then — if the toggle is on, the account is branch-expanded, and `locationId` is a sellable branch — return the child where `location_id = locationId`; else return the parent (current behavior).
- **Do NOT** relax the unique constraint to `(company_id, system_purpose, location_id)` unless purpose moves onto children — keeping purpose on the parent is lower-risk and preserves the auto roll-up.

---

## 3. Proposed design (gated, opt-in, additive)

1. **Toggle:** `companies.subdivide_accounts_by_branch` boolean, default **false**. (The branch *dimension* is independent and always available; this toggle only adds the presentation sub-accounts.)
2. **Per-account opt-in:** a flag marking which parent accounts are branch-expandable (default set = class 6/7 + liaison; VAT/balance-sheet excluded). Primary control is per-account; optional per-class default for 6/7.
3. **Schema:** add nullable `accounts.location_id` (FK locations, nullOnDelete); keep `system_purpose` on parents only.
4. **Resolver:** new `findByPurposeForLocation(...)`; unify the two duplicate purpose-resolvers behind it; thread `location_id` (already needed for the journal dimension — Doc 08 §1) through the ~40 posting call sites.
5. **Generation:** `ChartOfAccountsService::seedBranchSubAccounts(Company, Location)` clones each expandable parent into a branch child (`code` = parent code + branch suffix, same `type`, `parent_id` = parent, `location_id` = branch, **no** `system_purpose`). Fire it from `LocationService` when a `LocationType::Shop` is created **and** the toggle is on. Country seeders (`TunisiaChartOfAccountsSeeder`, `FranceChartOfAccountsSeeder`) are the templates; the generator reuses their two-pass code→parent pattern.
6. **Posting rule:** when an account is expanded, posting MUST target the branch child (roll-up trap §2.2). Add a guard/assert that no journal line posts to a parent that has children.
7. **Lifecycle:** branch close → **deactivate** sub-accounts (`is_active=false`), never delete (fiscal-chain + report immutability). Inactive accounts already excluded from the balance query (`TrialBalanceService.php:192`).
8. **Reporting:** consolidated TB/P&L/BS work unchanged (auto roll-up). Per-branch statements still need the new `location_id` filter on the report services (Doc 08 §1/§3, P3).

---

## 4. Effort / risk read
- **Schema + toggle + generator:** low–medium (additive; codes are free-form).
- **Resolver + threading `location_id` through ~40 call sites:** medium-wide — but this is the **same plumbing** the ledger dimension needs (Doc 08 §1), so it should be done **once**, together, not twice.
- **The roll-up trap behavioral change:** the real risk — every posting site must post to the branch child when expanded; needs a structural guard test. Recommend the dimension (Doc 08 P2) lands first and is proven, then layer sub-accounts (presentation) on top as a strictly additive, toggle-gated step.

---

## 5. Open questions / gates
1. **Confirm the expandable-account default set** (class 6/7 + TN compte 17 / FR compte 18) with a Tunisian accountant — Doc 09a found per-establishment subdivision is *permitted, not mandatory* for ordinary P&L accounts (no source makes it obligatory). Treat as presentation preference, not statutory.
2. **Branch suffix scheme** (`64201` vs `6420-01` vs `6420.BR1`) — codes are free-form; pick a convention (and how it interacts with the country plan's own digit depth). Verify TN/FR seeder codes don't already collide at the chosen depth.
3. **Per-account opt-in UX** — global toggle + per-account checkbox vs per-class default; where in company settings.
4. **Retroactivity** — enabling the toggle for an existing company: generate children going forward only (historical postings stay on the parent and would be clobbered by roll-up if children appear). Likely require enabling **before** first posting, or a one-time migration of historical parent balances to a `…-00`/HQ child. **Flag — needs a deliberate decision.**

**End of Doc 09b.**
