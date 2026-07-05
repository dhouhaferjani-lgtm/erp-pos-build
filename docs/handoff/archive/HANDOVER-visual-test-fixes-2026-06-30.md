# HANDOVER — Visual-Test Bug Fixes (2026-06-30)

Source of findings: `docs/sessions/2026-06-30-visual-test-results.md` (web Playwright discovery pass on PharmaBio Tunisie / TN-TND parapharmacy demo). Companion: `docs/sessions/2026-06-30-root-causes-and-fixes.md` + memory `project_az_test_findings_and_fixes.md`.

This handover groups the findings into **fix themes** so they can be worked TDD, theme by theme, with verification. Do them in order — Theme 1 (GL integrity) and Theme 3 (gating) are launch-blockers.

---

## KICKOFF PROMPT (paste into the new session)

> We are fixing bugs found in the 2026-06-30 visual test pass on the parapharmacy demo (PharmaBio Tunisie, TN/TND). Read `apps/erp/docs/sessions/2026-06-30-visual-test-results.md` and `apps/erp/docs/handoff/HANDOVER-visual-test-fixes-2026-06-30.md` first. Work TDD (test first, red→green), one theme at a time, smallest scope per fix. Use a git worktree off `dev` and the subagent-driven-development + verification-before-completion skills. After each theme: run the relevant backend tests by path (NEVER the full PHPUnit suite) + web typecheck/lint, then re-verify the exact screen in the browser with the same login (owner@pharmabio.tn / password at http://localhost:5173, stack on api:8010 / web:5173, reseed if needed). Confirm GL postings balance and screenshots match before claiming done. Start with **Theme 1 (GL/fiscal integrity)** — it's the launch blocker. Owner decision already locked: **sales stamp duty posts to account 4375 (liability — create it)**. Do not expand scope beyond the listed findings without asking.

---

## Theme 1 — GL / fiscal integrity (LAUNCH BLOCKER) — ✅ FIXED & VERIFIED 2026-07-01
Root cause family: postings don't balance; stamp + credit-note + AR payment GL paths broken.

> **✅ STATUS 2026-07-01 (reuse-branch session):** All Theme-1 items were already implemented + TDD'd on branch `fix/az-test-findings` (7 commits, was unmerged). This session **rebased that branch cleanly onto current origin/dev** (zero conflicts — the newer "Unify GL hash chain" work touched `GeneralLedgerService`; the stamp fix lives in `AccountingService`, no overlap), re-ran the GL/payment suites BY PATH (29 + 87 + 62 green, incl. dev's own `InvoiceAndCreditNoteGLIntegrationTest`), web typecheck/lint + `partners.test.tsx` (46) green, and **re-verified live** on the PharmaBio Tunisie tenant (`tenant019f1772…`, Docker PG :5433).
>
> **Live GL proof (DB journal_lines):**
> - **Post-fix invoice INV-2026-0003** posts `411 DR 19.560` (full gross) + `707 CR 15.600` + `4457 CR 2.964` + **`4375 CR 0.996` (stamp → 4375)** + COGS 12=12 → **BALANCED.** ✅
> - **Pre-fix invoice INV-2026-0001** (created 09:09, *before* the fix existed) posts `411 DR 15.600` (net only), no 4375 leg → unbalanced by 2.964. **This stale entry is the ENTIRE source of the Trial Balance's residual 2.96 imbalance** — the fix correctly does NOT rewrite already-posted, hash-chained JEs (GL is append-only). ⚠️ **DEMO ACTION REQUIRED:** repair the one legacy JE (reverse + repost) OR reseed the tenant so the demo Trial Balance reads `is_balanced=true`. Code is correct; this is a one-time historical-data cleanup (same family as the already-open "backfill historical JEs" follow-up).
> - **Customer payment** posts a balanced `customer_payment` JE (DR=CR 19.560) → AR moves. ✅
> - **#6A** customer detail shows real **"Total Receivable: 15,600 TND"** (was 0). **#6C** Vehicles tab GONE on parapharmacy customer. ✅ (screenshot `apps/erp/theme1-verify-customer-detail-6A-6C.png`)
> - **#2 credit note** verified via passing integration/unit tests (`CreditNoteGLIntegrationTest`, `CreditNoteIntegrationTest`, `CreditNoteServiceTest`) — no CN in the live tenant to re-drive.
> - **Extra fix this session:** `PartnerDetailPage` no-balance check used `parseFloat` on the canonical decimal balance strings (Rule-19 drift the #6A fix introduced) → swapped to `bccomp` (commit `8497cc489`). Pre-existing `accountBalance.unallocated_balance` parseFloats left untouched (Rule 18).
>
> Branch `fix/az-test-findings` (rebased HEAD `8497cc489`, 8 commits, 0 behind / clean ff candidate onto origin/dev). NOT yet promoted — awaiting owner review + the demo-data cleanup decision.

> **ROOT CAUSE PINNED (code-grounded):** stamp duty IS computed correctly (`TaxCalculationService` → `CalculatedTax.isStampDuty=true`, ~`:146-177`) and stored on the document (`Document.php:56-57` `stamp_duty_amount`), but **`AccountingService::createInvoiceGLEntries()` (~`:147-186`) and `createCreditNoteGLEntries()` (~`:227-336`) never emit a GL line for it.** So invoice GL posts DR(AR)=CR(Revenue)+CR(VAT) and silently drops the stamp → DR≠CR by the stamp amount (1.000 invoice / 0.600 Avoir). **Patch:** after the VAT line, post `stamp_duty_amount` as DR to the stamp account on invoices and CR on credit notes; add `SystemAccountPurpose::SalesStampDuty` → account **4375** (owner-locked) and `findAccountByPurpose(...)`. Verify `TrialBalanceService::generate()` returns `is_balanced=true` (`:428-437`, ZERO_THRESHOLD 0.0001). Add/extend `InvoiceGLIntegrationTest` + `CreditNoteGLIntegrationTest` to assert the stamp line exists.

1. ✅ **FIXED (code) — Trial Balance** balances for all post-fix postings. ⚠️ Demo tenant still shows residual 2.96 from the ONE stale pre-fix invoice INV-2026-0001 (see status note) → needs JE repair or reseed before demo.
2. ✅ **FIXED — Sales stamp duty → 4375** (`SalesStampDutyPayable`, liability). Verified live: INV-2026-0003 posts `4375 CR 0.996`, balanced. (commit `c0421a727`)
3. ✅ **FIXED — AR payment posts GL + reduces receivable** (dead `account_id`→`gl_account_id`). Balanced `customer_payment` JE verified. (commit `53ec7e1c8`)
4. ✅ **FIXED — Credit note** materializes prorated lines so the GL reversal balances (verified via tests; no live CN in tenant). (commit `24601fc09`)
5. ✅ **FIXED — Invoice VAT split + stamp** both legs written (4457 VAT + 4375 stamp), verified live on INV-2026-0003. (EUR→company-currency also fixed, commit `a18a685ba`.)

**Assert:** every journal entry DR=CR; trial balance balances to 0; stamp on 4375; AR payment debits cash/credits 411 Clients and reduces receivable; credit note reverses correctly.

## Theme 2 — Vertical-module gating (LAUNCH BLOCKER for parapharmacy) — route leaks ✅ FIXED 2026-07-01; automotive leaks DEFERRED
The route-permission guard and nav-permission gate disagree, and gating is **inverted**.

> **✅ STATUS 2026-07-01:** Both launch-blocker route problems FIXED (TDD + live-verified on PharmaBio Tunisie). The three automotive leaks (#8) + Price Lists 403 (#9) are DEFERRED — each needs a design/backend decision (see below), not a narrow one-liner.
>
> **⚠️ ROOT-CAUSE CORRECTION (the pinned cause below was WRONG for the pharmacy-blocked half):** Live-verified that `GET /api/v1/company/config` **already returns** `all_enabled_modules` incl. `BatchExpiry`, `Parapharmacy`, `Merchandising`, `Loyalty`, `Ecommerce`. The config is NOT stale/missing. The real cause is a **ModuleGuard cold-load race**: `isAuthenticated` is deliberately not persisted (`authStore` partialize), so on a direct URL load it is briefly false while `/auth/me` is in flight → the config query (gated on `isAuthenticated`) stays DISABLED → TanStack v5 disabled query = `isLoading:false, data:undefined` → `ModuleGuard`'s `if (error || !config) redirect` fired, conflating "config unresolved" with "module absent". Proven behaviorally: SOFT nav to `/inventory/batches` rendered fine; HARD/direct load redirected.
> - **Fix #7 (commit `9c1779889`):** `ModuleGuard` now HOLDS (renders null) while config is unresolved (`isLoading || (!config && !error)`); redirects only on genuine error or loaded-but-absent module. New `ModuleGuard.coldLoad.test.tsx` (5 tests) + existing 14 green. **Verified live:** direct loads of `/inventory/batches` (Product Batches), `/parapharmacy/ingredients` (Ingredients), `/pos/loyalty/programs` (Loyalty Programs) now render. Covers Expiry Write-off too (same guard).
> - **Fix #6 (commit `a8a0c017e`):** `/pos/tables` wrapped in `<ModuleGuard module="Tables">`, `/pos/kitchen` in `<ModuleGuard module="Menu">` — the exact keys the Sidebar nav uses (`Sidebar.tsx:218-219`; Menu, not Tables, for the KDS because Menu is in both restaurant + coffee_shop while Tables is only a coffee_shop extra). `routes.test.tsx` source-scan tests added. **Verified live:** both now redirect to /dashboard on parapharmacy.
> - **#8 automotive leaks — DEFERRED (need decisions):** (a) Roles matrix `RolesPage.tsx:355-387` renders unfiltered groups from API `/permissions` → best fixed **backend** (filter `/permissions` + `/roles` by the company's enabled modules — single source of truth, needs a permission-group→module map); (b) Technician/Operator roles are **seeded into the tenant** (`UsersPage.tsx`/`UserEditModal.tsx` show real roles) → needs seeder change (don't seed automotive roles for parapharmacy) or role→vertical tagging, not just a dropdown filter; (c) product type **"Part"** (`ProductForm.tsx:793-795`, hardcoded enum) → needs a taxonomy decision (what type do parapharmacy goods use? backend enum support) before hiding it. `ProductForm` already has `hasModule`/`isParapharmacy` (lines 144-146) available.
> - **#9 Price Lists 403 — DEFERRED:** Admin role missing the price-list view permission (seeding gap) — needs the permission name + seeder change.

> **ROOT CAUSES PINNED (code-grounded) — see correction above; the pharmacy-blocked cause here is SUPERSEDED:**
> - **F&B leak:** `apps/web/src/routes/index.tsx` — `/pos/tables` (~line 2562) and `/pos/kitchen` (~line 2814) wrap only `RequirePermission`, **missing `<ModuleGuard module="Tables">`**. Add the guard (both routes).
> - **Pharmacy blocked:** `BatchExpiry`/`Parapharmacy` ARE in parapharmacy `default_modules` (`config/verticals.php` ~344-360), yet `<ModuleGuard>` redirects → the bug is in the `GET /api/v1/company/config → all_enabled_modules → hasModule()` path (stale/missing config), NOT the vertical definition. Verify config returns the 14 modules incl. BatchExpiry/Parapharmacy; check `RequireModule.php:62` and CompanyConfig cache invalidation.
> - **Loyalty** is a compatible_extra (`verticals.php` ~340) but redirects unless the extra is enabled — verify the enable-extra → config merge path.
> - **Automotive leaks** are all "render unconditionally, no `hasModule()` filter": customer-detail Vehicles tab (wrap in `hasModule('Vehicle')`), Roles matrix (filter permission groups by `all_enabled_modules`), Technician role + "Part" product type (vertical-aware enums).

6. ✅ **FIXED — F&B routes gated** (`/pos/tables`→`ModuleGuard module="Tables"`, `/pos/kitchen`→`module="Menu"`). Verified live: both redirect to /dashboard on parapharmacy. (commit `a8a0c017e`)
7. ✅ **FIXED — Pharmacy/loyalty routes reachable** on direct load (ModuleGuard cold-load race, not a config problem — see correction). Batches / Expiry / Parapharmacy/* / Loyalty all render. (commit `9c1779889`) Customer-detail Vehicles tab already gated by #6C.
8. ✅ **FIXED — Automotive leaks** (owner-directed 2026-07-01):
   - **Roles matrix + roles list**: `RoleController` now vertical-filters `GET /permissions` (groups) and `GET /roles` (roles) at READ-TIME by `all_enabled_modules` (map incl. hyphenated prefixes `workshop-bundles`/`work-orders`→Workshop, `modifier-groups`→Menu, `composite-items`→CompositeItems, plus vehicles/menus/scheduling/batches/loyalty). technician/operator roles hidden unless Workshop enabled. Seeder untouched (roles still created — 111 tests depend on them; a tenant that enables the module sees them instantly). New `PermissionCatalogVerticalFilterTest` (4). **Verified live:** parapharmacy shows 0 leaked groups + only admin/manager/cashier/viewer/accountant. (commit `14a159fc0`)
   - **Product Type "Part"**: selector removed entirely from ProductForm — owner retired the part/service/consumable taxonomy (services are first-class now; catalog data describes products). Column already nullable; `is_physical` defaults true. **Verified live.** (commit `b997336b3`)
   - "Workshop Cash Register" seed name: cosmetic, in seed data only — not addressed (demo-cosmetic).
9. 🟡 **DEFERRED — Price Lists 403 for owner/Admin** — permission-seeding gap; needs permission name + seeder change. (Not in the owner-approved 2026-07-01 batch.)

**Trial balance (Theme-1 data):** ✅ the demo tenant was reseeded fresh (2026-07-01 06:46, new tenant `019f1c6d…`); Trial Balance now balances **6,310.00 = 6,310.00** on data authored by the fixed code — the stale pre-fix INV-2026-0001 is gone. No manual JE repair needed.

**Assert:** `config/verticals.php` is the SoT; for IziPOS-parapharmacy each F&B/automotive route is hidden in nav AND redirected AND 403 on API; each pharmacy route is visible AND reachable for the roles that should have it.

## Theme 3 — Bulk import data-row parsing (BLOCKER for onboarding)
10. **Import never extracts cell values** — wizard upload/map/validate UI is correct, but `GET /api/v1/imports/{id}/preview` returns `rows:[{data:[],…}]` for every row (headers + total_rows correct). Reproduced on Products and Partners. Find where row cells are read/mapped server-side; data array is empty so all rows fail "field required". Then verify commit + dedupe/re-import.

**Assert:** a valid CSV imports → records created; duplicates deduped on re-import; money/qty respect precision.

## Theme 4 — Frontend data bugs
11. **#6A customer balance = 0 on detail** while list shows real balance — field-name mismatch (`total_receivable` vs `receivable_balance`). (`/sales/customers/:id`.)
12. **Bank Reconciliation page errors** — "Query data cannot be undefined" for `payment-repositories` + `reconciliations` query fns; FE double-unwraps an empty/paginated `{data:[]}` envelope and returns undefined. (`/treasury/reconciliation`.)
13. **Dashboard `/api/v1/documents?limit=5&sort=-created_at` → 500** (Recent Documents silently masked). Backend triage. (Note: list pages use a different working query.)
14. **#3 No discount field in the sales/PO line editor** — add per-line (and/or document-level) discount input + wire it into totals (and the GL). Columns currently Article/Description/Qty/Unit Price/Tax %/Total only.
15. **Per-line Tax dropdown empty** — only "Select tax…/+ Add new tax…"; populate with seeded TVA 19/13/7/Exonéré so a line's tax is selectable.
16. **Dashboard "Payments Received" KPI = 0** despite a completed 15,600 TND payment (date-window/aggregation).
17. **Payment-status inconsistency** — invoice list shows "Partial"/Balance Due 2,964 while detail shows "Paid". Reconcile.
18. **Goods Receipt "Receive Goods" is confirm-all only** — add partial-qty receiving + batch/lot/expiry capture dialog for batch-tracked products.
19. **No smart-payments allocation UI** — `/treasury/payments/new` is a flat payment; add open-invoice allocation (partial/full), split-tender (multiple methods), advance/overpayment.
20. **Goods Receipts list shows "Invalid Date"** for PO rows — date parse/format.

## Theme 5 — i18n / currency-number formatting (pervasive; demo-credibility)
21. **Unify currency + number formatting app-wide** — pick TND xor DT (recommend "TND" suffix, French comma-decimal, 3-dp millimes) and route ALL money/qty through `formatCurrency`/`formatQuantity`. Offenders found: "DT" (payment form, expenses, procurement, product preview); dot vs comma decimal; **EUR (€) leak** in invoice Related-Documents chain; **Anglo format** on Trial Balance; "TND 0.000" on chart of accounts; same invoice mixing "15,600 DT" line and "15.600 TND" totals.
    > **ROOT CAUSE PINNED:** there are **two `formatCurrency` implementations** — `apps/web/src/lib/format.ts` (Intl.NumberFormat, locale-aware) and `apps/web/src/lib/decimal.ts` (Big.js, no locale). Screens mix them, which produces the TND/DT, comma/dot, and EUR inconsistencies. Consolidate on `format.ts` (locale-aware) and remove/redirect `decimal.ts`'s formatter; grep call sites of both.
22. **Localize TN labels** — "VAT Number"/"Tax ID / VAT Number" → **Matricule Fiscal**; placeholders RCS Paris→RNE, +33→+216, 75001→TN; timezone default Europe/Paris→Africa/Tunis; add gouvernorat to TN address.
23. **Raw i18n keys** — Opening Balances (whole `openingBalances.*` namespace), `actions.back`, `common.actions`/COMMON.ACTIONS, `fields.total:`, `TABLE.ACTIONS`, `PURCHASEORDERS.RECEIVED`, `documents.supplier`, `viewAll`, `parapharmacy_metadata.requires_consultation`; key-returns-object errors `invoices.paymentHistory` + `ACTIONS (EN)` (pos/tables).
24. **Arabic coverage gaps** — English leaks in content ("customer"/"Customer"/"Has outstanding balance"/"BALANCE" header), AR `<title>` falls back to English, header aria-labels stay English. (RTL layout itself works.)

## Theme 6 — Cleanup / lower priority
25. App Information "API URL: http://localhost:8002/api/v1" stale (real :8010).
26. RolesPage React "unique key prop" warning.
27. Inventory Settings `GET /api/v1/company` → 404 (wrong endpoint; page still renders).
28. Bank-account repository fields (bank/RIB/IBAN/BIC) have no required validation.
29. Persistent "Connecting to real-time updates…" (Reverb/websocket not connected locally); email-verification banner for seeded owner.
30. Product **batch-tracking defaults OFF** — for parapharmacy consider default-ON with opt-out.
31. **#4 return-qty cap** — could not reproduce (needs a received PO/invoice to return against); re-test after a GR is posted.

---

## Test-data note
The visual pass created (in the test tenant): UoM "Box of 12", repo "Test Bank Repo VT" (gl 512), customer "VT Test Client Pharmacie", product "VT Test Crème Solaire SPF50". Import test files in `apps/erp/.playwright-mcp/`. Reseed before fixing if you want a clean baseline.
