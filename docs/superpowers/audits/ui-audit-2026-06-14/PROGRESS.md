# UI Consistency Remediation — Progress & Handover

**Branch:** `feat/ui-consistency-remediation` (off `origin/dev`)
**Worktree:** `apps/erp/.worktrees/ui-consistency`
**Audit:** see [`REPORT.md`](./REPORT.md) · **Started:** 2026-06-14
**Live app for verification:** `http://localhost:8089` · login `owner@cafe-tunis.tn` / `password` (cafe-tunis demo). The Docker stack serves a *built* image, so it reflects `dev`, **not** this worktree — use `apps/web` vite dev (or rebuild) to see local changes; use the live stack for unchanged-page regression baselines.

## Rules of engagement
- TDD: test first (Vitest), red → green → refactor. Strict types (no `any`). Tokens only — no new off-theme literals.
- **Never run the full PHPUnit/preflight suite** (crashes laptop). Frontend gates only here: `tsc --noEmit`, `eslint`, `vitest run --filter`/path-scoped.
- One PR per phase (or per cluster in Phase 3), each with before/after screenshots.
- Parallel session active → stay in this worktree; rebase on `origin/dev` before each push.

## 🖥️ Visual testing pipeline (set up 2026-06-14 — USE THIS)
The Docker stack's web container (`erp-dev-web-1`, nginx) serves a **bind-mounted static `dist/`** from `/Users/houssamr/Projects/syneriva/apps/erp.dev-consolidation/apps/web/dist`. To see THIS branch at `http://localhost:8089`, build this worktree into that `dist/` (its *source* is untouched — `dist/` is a gitignored artifact; API stays reachable because the app's axios `baseURL` is the relative `/api/v1`, proxied by the same nginx):
```
DIST=/Users/houssamr/Projects/syneriva/apps/erp.dev-consolidation/apps/web/dist
cd apps/web && node_modules/.bin/vite build --outDir "$DIST" --emptyOutDir   # ~8s
# iterate: node_modules/.bin/vite build --watch --outDir "$DIST"
```
Then drive `http://localhost:8089` with the Playwright MCP (login `owner@cafe-tunis.tn` / `password`). NOTE: this overwrites what the live stack serves — fine for this work, but coordinate with the parallel session. WebSocket/Pusher console errors at :8089 are pre-existing env noise (Reverb not proxied), not regressions.

## Verification commands (run from `apps/web/`)
```
node_modules/.bin/tsc --noEmit
node_modules/.bin/eslint <changed paths>
node_modules/.bin/vitest run <changed test paths>
```

---

## Phase 0 — Stop new drift  ☐ (RESEQUENCED — see notes; ratchet-entangled)
> **Sequencing correction discovered during impl:** the lint *bans* can't come "first." (a) A broad full-palette WARN rule floods the per-app ratchet (fails `lint:ratchet` until the baseline is regenerated — coordinate with parallel session). (b) Raw-element **bans** (no raw `<button>/<input>`) must come AFTER the migration that removes those elements, else they instantly fail CI for both this work and the parallel session. So Phase 0 bans are effectively the *last* gate, enabled per-dir as each cluster is migrated.
- [x] 0.4 chartColors arch-guard — covered by `src/lib/chartColors.theme.test.ts` (asserts theme hex, forbids `#2563eb` et al.). ✅
- [ ] 0.1 Extend full-palette color lint to all dirs — **WARN globally requires `--update-baseline` (+~? warnings) → coordinate**. Safer interim: enable as ERROR only in dirs *after* they're migrated clean.
- [ ] 0.2 Custom rule: ban raw `<button>` outside `components/atoms/**` — enable per-dir post-migration (Phase 3).
- [ ] 0.3 Custom rule: ban raw `<input|select|textarea>` outside `components/atoms/**` — enable per-dir post-migration.
- [ ] 0.5 Verify new rules fire on a known offender and don't break gold-standard dirs.

## Phase 1 — High-ROI token fixes  ◧ (1.1 done; rest folded into RC-1)
- [x] 1.1 Rewrite `chartColors` in `designTokens.ts` → theme hex (primary `#1A6FB5`, success/warning/error/neutral + copper `secondary`). TDD: `src/lib/chartColors.theme.test.ts` (4 tests). ✅ committed.
- [ ] 1.2/1.3/1.4 **RESEQUENCED → see RC-1 below.** Why: piecemeal palette swaps (amber→yellow in the banner, orange→warning in MarginIndicator) are NOT ratchet-safe — `amber` is *invisible* to the color lint but `yellow` is warn-flagged, so swapping *grows* the warning count and fails `lint:ratchet`. The correct fix is the `@theme` remap (RC-1): keep the class strings, change what they resolve to → 0 new warnings, fixes ~130 files at once. The banner/MarginIndicator swaps were reverted.
- [ ] 1.5 Visual check via Playwright MCP — pending RC-1 / a worktree vite dev server (live :8089 runs the built dev image, not this branch).

### ⚠ Implementation findings (must read before continuing)
- **chartColors is per-vertical-blind.** A static JS object can't follow the CSS `[data-product="otospex"]` theme switch. My fix is correct for **IziPOS** (Deep Ocean, the live vertical) but Otospex (deep-blue + pink) charts still won't match. PROPER fix (follow-up): expose `--chart-*` CSS vars in *both* theme blocks in `index.css` and read them at runtime via `getComputedStyle`, with the static object as SSR/jsdom fallback. Touches the 3 owner-dashboard chart components + adjusts the test to check var names + fallback.
- **`@theme` only remaps blue→primary and gray→neutral.** green/yellow/red/amber/emerald/etc. are NOT remapped, so even "semantic" classes drift from `--theme-success/warning/error`. This is the heart of RC-1.
- **The lint baseline is stale.** `origin/dev` already lints at **11747** warnings vs baseline **11739** (+8) BEFORE this branch — pre-existing dev drift, not us. This branch adds **0**. Whoever regenerates `scripts/lint-warning-baseline.json` (`node scripts/lint-ratchet.mjs --update-baseline`) should coordinate with the parallel session.

## RC-1 — Wire the full palette to the theme (the lever)  ☐ NOT STARTED
The single highest-impact fix; deferred to its own visually-verified step because it changes color resolution app-wide and must be checked across both verticals + many screens.
- [ ] Define `--theme-success-50..900`, `--theme-warning-50..900`, `--theme-error-50..900`, `--theme-info-*` scales in BOTH `:root` and `[data-product="otospex"]`.
- [ ] In `@theme`, remap `--color-green-* → success`, `--color-yellow-*`+`--color-amber-* → warning`, `--color-red-*+--color-rose-* → error`, `--color-emerald-* → success`, `--color-sky-* → primary` (and decide on slate/violet/teal/cyan). Class strings stay identical → **0 ratchet impact**, ~130 off-theme files snap to brand.
- [ ] Visually verify each major screen in BOTH verticals (run `apps/web` vite dev from this worktree, or rebuild the container) via Playwright MCP. Watch for status palettes that were *intentionally* multi-hued (scheduling).
- [ ] Add an architecture/CSS test asserting the remaps exist.

## Phase 2 — Build missing primitives (TDD)  ☑ DONE (48 tests, tsc clean, 0 lint warnings)
- [x] 2.1 `PageHeader` molecule — 5 tests
- [x] 2.2 `DataTable<T>` molecule (header/stripe/hover, `tabular-nums`, numeric right-align, skeleton loading, empty slot) — 10 tests
- [x] 2.3 `StatusBadge` atom + `statusTone()` helper (own module for fast-refresh) — 14 tests
- [x] 2.4 `ListPageLayout` (PageHeader + filter slot + body + pagination slot) — 7 tests
- [x] 2.5 `HubCard` + `HubGrid` (one tokenized icon-chip, no per-card color) — 8 tests
- [x] 2.6 Wired into atoms/molecules barrels. ALL built only from existing baselined tokens → 0 new lint warnings.
- [ ] 2.7 (follow-up) update `components/README.md` to document the 5 new primitives.

## Phase 3 — Module canonicalization (structural drift — the owner's main concern)  ☐ NOT STARTED
> **Full spec: [`CANONICALIZATION-SPEC.md`](./CANONICALIZATION-SPEC.md)** — file:line deltas + effort + sequence.
> Key finding: the same input renders **4 ways** (`rounded-lg` original vs `rounded-md` atom = most visible drift); headers vary `font-bold`/`font-semibold`; 5 different table treatments; forms differ in sectioning/footer. Visual evidence: `screens/drift-A-original-product-form.png` vs `screens/drift-B-composite-item-form.png`.
> Canonical target: lists → `ListPageLayout`+`DataTable`+`StatusBadge`; forms → `PageHeader`+`tokens.card.base` sections+`FormField`/`Input`+`StickyFormFooter`. **Rule: never raw `<input>` — always the `<Input>` atom.**
> Suggested order: `documents/DocumentForm` + `DocumentListPage` (the reference originals) → `ProductForm`/`ProductListPage` → catalog/menu drift modules. Fold rule-19 precision fixes in per form.

### Module order (per CANONICALIZATION-SPEC §C)
- [x] **`documents/DocumentForm.tsx`** → `PageHeader` (back link in breadcrumb slot) + `tokens.card.base` + `<h2>` "Details" section (new `tokens.heading.section` token) + `FormField`+`Input`/`Select`/`Textarea` (settles `rounded-lg`→`rounded-md` drift) + `Button` footer (Cancel→`navigate`, Save submit) + auto-save SVGs→lucide `Loader2`/`Check` with `textColors`. 24 color warnings → 0; 4 Vitest tests; before/after `screens/phase3/documentform-{before,after}.png`. ✅
- [x] **`documents/DocumentListPage.tsx`** → `ListPageLayout` (header/subtitle/Add `Button`/filters/pagination slots) + `DataTable` (numeric right-align+`tabular-nums` totals; built-in skeleton + `EmptyState`) + `StatusBadge`/`statusTone` (retired the bespoke `typeColors`/`statusColors`/`paymentStatus.color` maps) + **server pagination** via `OffsetPagination` (page/per_page added to query + key). Fixed a latent i18n bug exposed by the always-rendered table header (`sales:documents.status` is an *object* of values → header now uses `common:fields.status`). Updated `DocumentTenantScope` expected key for the new page/per_page positions. 0 color warnings; web lint 11724→11683 (−41). 4 Vitest tests; tsc clean; before/after `screens/phase3/documentlist-{before,after}.png`. ✅
  - ⚠ Demo (`cafe-tunis`) has 0 B2B documents, so the live screenshot shows the canonical **empty** path; the populated-table path (rows + status badges + pagination) is covered by the Vitest test. The shared `dist` is contended by the parallel session — verified the served bundle via DOM assertion (`thead` headers + 0 legacy dashed boxes) before capturing.
  - ⚠ Pre-existing (also red on clean `dev`, NOT touched here): `DocumentForm.tenantScope` cascade test + 2 `DocumentComponents.tenantScope` tests.
- [x] **`inventory/ProductForm.tsx`** → PageHeader + tokens.card.base sections (heading token) + FormField/Input/Textarea + `<MoneyInput>` sale price (rule-19) + Button footer; tokenized checkboxes. 0 color warnings.
- [x] **`inventory/ProductListPage.tsx`** → PageHeader (StatCards/filters/grid-toggle/OffsetPagination kept) + `DataTable` (sort preserved via header nodes; `Sale Price` numeric→tabular-nums) + `StatusBadge`/`statusTone`. 0 color warnings.
- [x] **`catalog/pages/CompositeItemListPage.tsx`** → ListPageLayout + DataTable (base-price numeric fix) + OffsetPagination (replaced hand-rolled buttons) + SearchInput + StatusBadge + Button. 0 color warnings.
- [x] **`catalog/pages/CompositeItemFormPage.tsx`** → PageHeader + heading token; **precision fix** — `parseFloat(base_price)` margin calc → string-safe `computeMarginPercent` via `@/lib/decimal` (parseFloat-on-money 8→0). color 23→12 (untouched lines).
- [x] **`catalog/components/ProductVariantMatrixEditor.tsx`** → heading token + `<MoneyInput>` price/cost (rule-19) + `<Input>`/`<Button>` atoms (editable matrix table kept). 0 color warnings. (Child component — covered by tests, no standalone route.)
- [x] **`catalog/pages/CategoryManagementPage.tsx`** → PageHeader + tokens.card.base + `<Textarea>` atom + tokenized modal/dialog (danger Button). color 23→0.
- [x] **`menu/pages/MenuListPage.tsx`** → PageHeader + StatusBadge (card grid kept) + EmptyState + Button. color 17→0. Follow-up: child `MenuCategoryItemManager` (overridePrice precision/color) untouched — next pass.
- [x] **`menu/pages/MenuFormPage.tsx`** → PageHeader + heading tokens + tokenized day-pills (no raw `bg-blue-600`) + tokenized checkboxes. color 12→0.
  - Verified: full `tsc` clean across all 8; `vitest` inventory+catalog+menu = 142 pass / 4 fail (the 4 are pre-existing on clean `dev`: 2× `CompositeItemSearchSelect` clear-button, `ProductDocumentsTab`/`ProductMovementsTab` error-state). web lint 11683→**11512** (−171, ratchet shrinks, 0 errors). Runtime smoke (Playwright headless, bundle-guarded against the shared-`dist` race) on all 7 routes: 0 error boundaries, 0 i18n-object errors, correct PageHeaders. Before/after screenshots `screens/phase3/{products-list,product-form,composite-list,composite-form,menu-list,menu-form,categories}-{before,after}.png`.
  - Migrated by 4 parallel subagents (disjoint feature dirs → 0 conflicts); orchestrator ran the authoritative tsc/tests/lint, the single `dist` build, screenshots, and all commits serially.

### (original cluster framing — superseded by CANONICALIZATION-SPEC for module order)
> **Continuation note (branch `feat/ui-consistency-clusters`, off LOCAL dev `04873f158`):**
> ✅ Phase 3.7, ✅ 3.2 (finance+treasury ~43 files), ✅ 3.3 light settings, ✅ 3.4 (inventory/catalog/menu),
> ◧ 3.5 POS (~31/56 files). Lint ratchet 11512 → **8615** (−2897), 0 errors, tsc clean, all suites green
> (the 4 documented pre-existing fails persist: CompositeItemSearchSelect clear-button ×2,
> ProductDocuments/MovementsTab error-state ×2). Pattern proven: parallel subagents on DISJOINT files
> (presentation-only; preserve every useQuery/mutation key; no shared-file/locale/barrel/test edits),
> orchestrator runs the authoritative tsc/eslint/vitest + reconciles existing suites + all commits serially.
> Shared a11y fix landed: the `Modal` organism sets `role="dialog"`/`aria-modal`/`aria-label`.
>
> **Shared-atom follow-ups flagged by agents (NOT yet done — would touch shared files):**
> (1) `atoms/Textarea` declares `error?: boolean` but doesn't destructure it → leaks `error` attr to DOM
> (Input/Select handle it). (2) No `ring`/selection-emphasis token in designTokens — selection UIs use
> shadow/border instead. (3) POS `OrderStatusBadge`/`TableStatusBadge` still carry their own off-theme
> status maps + `dark:` variants (left as-is; candidates for a StatusBadge migration). (4)
> AdvancedPaymentsModal has a pre-existing `step="0.001"` precision warning (line ~520).
> **Recurring gotcha:** existing FEATURE-ROOT suites (treasury.test.tsx, finance/JournalEntryForm.test.tsx)
> render the migrated pages and assert pre-canonical markup — after each list/detail batch, run them and
> reconcile (link→Button role, loading-text→`.animate-pulse` DataTable skeleton). Agents must NOT edit
> these shared suites (conflict risk); the orchestrator reconciles them.
- [ ] 3.1 `documents/` (epicenter: 65 raw controls, 5 bespoke modals, ~50 off-theme) — NOTE: the two
  `documents/*` files (DocumentForm, DocumentListPage) were done in the prior session; the rest of the
  `documents/` cluster (DocumentLineEditor, detail/print views, etc.) is still open if it exists.
- [x] **3.2 finance + treasury — ✅ COMPLETE (~43 files).** report pages (Aged AR/AP, Trial Balance,
  P&L, Balance Sheet); list pages (Payment/Repository/Instrument/JournalEntry/Expense, WithholdingCertificates,
  WithholdingRules, SalesWithholdingTracking, GeneralLedger, ChartOfAccounts); forms (Payment, SplitPayment,
  JournalEntry, Expense page+fields); detail pages (Payment/Instrument/Repository/Expense/JournalEntry/
  WithholdingCertificate); pages BankReconciliation, PaymentMethods; ALL bespoke modals → Modal organism
  (refund/reverse, deposit/transfer/bounce, AddPaymentMethod, Withholding Preview/RuleForm, Add/EditAccount);
  components (AllocationPreview, OpenInvoicesList, PaymentAllocationForm, ToleranceSettingsDisplay,
  Ledger Table/Filters, FinanceWidget, AccountTreeView, ExpenseCard/ExpenseList). lint 11512 → 10123.
- ◧ 3.3 admin/settings — **PARTIAL.** ✅ DONE (light settings, lint 10123→9809): UsersPage + UserEditModal
  (+Add/PIN modals), RolesPage, LocationsPage, CompanyPage, TaxSettingsPage — all bespoke modals → Modal
  organism; tenantScope suites green. ⏳ REMAINING light settings (~5 files): settings/components/
  InventorySettings (67), CompanyOnboardingPage (64), settings/components/SetupChecklist (24),
  settings/SettingsPage (22), users/components/UserSelector (18).
  🚫 DEFERRED BY DESIGN: the entire `admin/*` super-admin panel (MonitoringPage 208, PaymentsPage 175,
  InvoicesPage 129, SubscriptionsPage 90, BillingDashboard 54, TenantsPage 44, CompanyOwnersPage 43,
  AuditLogs 35, AdminDashboard 24, AdminLogin 18, AdminLayout 17, TenantDetailModal 67) is an INTENTIONAL
  separate DARK design language (REPORT M10). Do NOT force it into light tokens. Its English-only
  localization is the M10 owner decision (Phase 5). If the owner wants the dark shell tokenized, that
  needs a dark-token scale first — separate workstream.
- [x] **3.4 inventory/catalog/menu — ✅ COMPLETE.** ProductDetailPage, StockLevels/StockMovements,
  Product Documents/Movements/StockLevels tabs, ModifierGroup list+form, RecipeLineEditor,
  CompositeItemSearchSelect, VariantEditor, ModifierGroupAssigner, MenuCategoryItemManager (rule-19
  override-price → MoneyInput), pricing ProductPricingCard/MarginIndicator/PriceInputWithMargin
  (m1/m2), + CompositeItemFormPage residuals. inventory/catalog/menu at 0 color warnings.
- [x] **3.5 POS color drift — ✅ COMPLETE.** All POS pages/organisms/molecules/atoms/components/layouts +
  smart-prompts (glassmorphism killed, glyph→lucide) + Analytics (shadcn surfaces → opaque token cards).
  `src/features/pos` now at **0 color warnings + 0 shadcn-surface/backdrop-blur**. POSButton/touch layout +
  deliberate amber/emerald accents preserved.
- [x] **3.6 workshop-* — ✅ COMPLETE.** Swept the FULL palette (the dirs exploited the lint GAP) → 0
  off-theme literals; StatusPill/badges → StatusBadge+statusTone; 6 bespoke modals → Modal organism;
  work-order + technician pages → primitives. **Lint gap CLOSED:** added a workshop full-palette ERROR
  override in `eslint.config.js` (mirrors scheduling), verified it fires.
- [x] **3.7 Convert 4 hub pages (Finance/Inventory/POS/Marketing) → HubCard/HubGrid** ✅
  (icons as `LucideIcon` refs, per-card rainbow chips removed, gating + Finance sections + POS
  Open-POS CTA preserved; 17 tests; color 0/file)

## Phase 4 — Kill duplicates  ◧ (4/5 done; 4.4 deferred w/ rationale)
- [x] **4.1 Remove the `ui/` re-export shims** (`5aecf7f26`) — deleted 5 thin shims
  (SearchInput/FilterTabs/Tabs/Breadcrumb/LoadingSpinner), repointed ~18 importers to
  molecules/atoms. (REPORT said "9"; only 5 existed at this branch point.)
- [x] **4.2 Rename two `LocationSelector`** (`<commit>`) → `LocationField` (ui form field) /
  `LocationSwitcher` (organism switcher in TopBar). `features/location/LocationSelector.tsx`
  kept as a feature-local alias wrapper so inventory consumers/mocks are untouched.
- [x] **4.3 One paginator** (`c6d7c4d0d`) — migrated 3 `Pagination` importers → `OffsetPagination`
  (from Laravel meta); deleted `ui/Pagination.tsx`.
- [ ] 4.4 Collapse `location/` + `locations/` — **DEFERRED (deliberate).** They are NOT really one
  entity: `location/` is the global active-location CONTEXT/provider/switcher (app-shell infra);
  `locations/` is the locations DATA/CRUD layer (types, useLocations hook, multi-select). Merging +
  unifying the `Location` type is a large, opinionated, high-risk refactor across providers/contexts
  for low visual value. Left as-is; revisit only if the owner wants a single locations module.
- [x] **4.5 Reconcile `channelPageStyles.ts`** (`c15c487a6`) — deleted the channels-local
  mini-design-system; mapped every constant to PageHeader/Button/atoms/tokens across 6 channel pages.

## Phase 5 — Polish  ☐
- [ ] 5.1 list footer "showing __ rowsPerPage" label/i18n gap
- [ ] 5.2 placeholder copy ("Select a customer…"), curly punctuation
- [ ] 5.3 cookie-consent bar overlap (reserve body space)
- [ ] 5.4 POS smart-prompts: text-glyph icons → lucide
- [ ] 5.5 enforce `@/` imports via lint
- [ ] 5.6 admin localization decision (M10) — confirm with owner

---

## Change log (append per commit)
| Date | Phase | Commit | Notes |
|---|---|---|---|
| 2026-06-14 | setup | — | worktree off origin/dev @ 9177950d8; baseline tsc green |
| 2026-06-14 | audit docs | (this commit) | REPORT.md + PROGRESS.md + screenshot harness onto branch |
| 2026-06-14 | 1.1 | (this commit) | chartColors → Deep Ocean theme (IziPOS); 4 tests; per-vertical caveat logged |
| 2026-06-14 | 2 | (this commit) | 5 primitives (PageHeader/DataTable/StatusBadge/ListPageLayout/HubCard+HubGrid); 48 tests; tsc clean; 0 new lint warnings; barrels wired |
| 2026-06-14 | 3 | (this commit) | rebased onto origin/dev; DocumentForm canonicalized → PageHeader/card/section-heading/FormField+atoms/Button; added `tokens.heading.section`; 4 tests; tsc clean; web lint 11747→11724 (−23); before/after screenshots |
| 2026-06-14 | 3 | (this commit) | DocumentListPage canonicalized → ListPageLayout/DataTable/StatusBadge + server pagination (OffsetPagination); retired type/status color maps; fixed `sales:documents.status`-is-an-object header bug (→`common:fields.status`); 4 tests + tenant-scope key updated; tsc clean; web lint 11724→11683 (−41); before/after screenshots |
| 2026-06-14 | 3 | (8 commits) | inventory (ProductForm, ProductListPage), catalog (CompositeItemListPage, CompositeItemFormPage, ProductVariantMatrixEditor, CategoryManagementPage), menu (MenuListPage, MenuFormPage) — migrated by 4 parallel subagents, serially integrated. tsc clean; +25 TDD tests; 142 pass/4 pre-existing-fail; web lint 11683→11512 (−171); runtime-smoke + before/after screenshots for 7 routes |
| 2026-06-14 | 3.7 | `8a40dea3b` | **branch `feat/ui-consistency-clusters` off LOCAL dev `04873f158`.** 4 hub pages (Finance/Inventory/POS/Marketing) → PageHeader+HubGrid/HubCard; rainbow chips removed; 17 TDD tests; tsc clean; web lint 11512→11456 (−56) |
| 2026-06-14 | 3.2 | `02b79390e` | 5 finance report pages (Aged AR/AP, Trial Balance, P&L, Balance Sheet) → PageHeader/Button/FormField + tokenized tables + tabular-nums; 3 parallel subagents; 5 TDD suites, 34 tests; web lint 11456→11320 (−136) |
| 2026-06-14 | 3.2 | `706e988b8` | 5 list pages (treasury Payment/Repository/Instrument, finance JournalEntry, Expense) → ListPageLayout/DataTable/StatusBadge/OffsetPagination (presentation-only, keys preserved); 5 TDD suites; 44 tests incl. tenantScope; web lint 11320→11174 (−146) |
| 2026-06-14 | 3.2 | `a99495fd4` | 5 form files (treasury Payment/SplitPayment, finance JournalEntry, Expense page+fields) → PageHeader/card/FormField+atoms/MoneyInput/StickyFormFooter; reconciled treasury.test.tsx + finance/JournalEntryForm.test.tsx (link→Button, loading-text→DataTable skeleton); 113 tests; web lint 11174→11028 (−146) |
| 2026-06-14 | 3.2 | `8d32dfff0` | 3 treasury detail pages (Payment/Instrument/Repository) → PageHeader/StatusBadge/tokenized tables; bespoke modals → Modal organism; 3 TDD suites; 67 tests incl. tenantScope; web lint 11028→10807 (−221) |
| 2026-06-14 | 3.2 | `75372f309` | 3 detail pages (Expense, JournalEntry, WithholdingCertificate) → PageHeader/StatusBadge/tokenized tables; JournalEntryDetailPage dropped bespoke local StatusBadge; 3 TDD suites; 55 tests incl. finance JournalEntryForm suite + tenantScope; web lint 10807→10641 (−166) |
| 2026-06-14 | 3.2 | `e31e50edf` | whole withholding feature (CertificatesList, RulesPage, SalesWithholdingTrackingPage lists → ListPageLayout/DataTable; PreviewModal + RuleFormModal → Modal organism); 5 TDD suites; 20 tests incl. tenantScope; web lint 10641→10439 (−202) |
| 2026-06-14 | 3.2 | `9575e1f9b` | treasury PaymentMethodsPage + AddPaymentMethodModal + BankReconciliationPage + allocation components (OpenInvoicesList/AllocationPreview/PaymentAllocationForm) → primitives; bespoke modals → Modal organism; 168 treasury tests green; web lint 10439→10210 (−229) |
| 2026-06-14 | 3.2 | `9a8e6e5f9` | **Phase 3.2 FINISH** — finance GeneralLedger (page+table+filters), ChartOfAccounts (page+tree+Add/EditAccountModal), FinanceWidget, ToleranceSettingsDisplay, ExpenseCard/List. Shared a11y fix: Modal organism `role="dialog"`/`aria-modal`/`aria-label`. Reconciled finance suites (skeleton/eager-filters/dialog-role). 213 tests green; web lint 10210→10123 (−87). **Phase 3.2 done: 11512→10123 (−1389).** |
| 2026-06-14 | 3.3 | `96b72e559` | core light settings (UsersPage+UserEditModal+Add/PIN modals, RolesPage, LocationsPage, CompanyPage, TaxSettingsPage) → primitives; bespoke modals → Modal organism; 6 TDD suites; 77 settings tests green incl. tenantScope; web lint 10123→9809 (−314). admin/* dark shell deferred by design (M10). |
| 2026-06-15 | 3.3 | `3387f65da` | finish light settings (InventorySettings, ReceiptSettingsTab, CompanyOnboarding, UserSelector, SettingsPage→HubGrid, SetupChecklist); 92 tests; lint 9809→9620 |
| 2026-06-15 | 3.4 | `bf7c90639` `368fde8a6` `b7f931e09` | inventory detail/stock + tabs; catalog/menu/pricing components (MenuCategoryItemManager rule-19); CompositeItemFormPage residuals. inventory/catalog/menu → 0 color. lint 9620→9185 |
| 2026-06-15 | 3.5 | `dc1828837` `00d7d0a6c` `fb6970186` | POS color drift ~31/56 files (reporting, terminals, order/cart, smart-prompts glassmorphism+glyph→lucide, checkout core, kitchen/orders). lint 9185→8615 |
| 2026-06-15 | 3.5 | `cc7aeeb13` `69c9d0757` | finish POS — 24 atoms/badges/molecules/components/layouts + Analytics de-glassmorphism (shadcn surfaces→tokens). pos at 0 color + 0 shadcn-surface. lint 8615→8478 |
| 2026-06-15 | 3.6 | `afa2fe5f9` `90b8cb515` | workshop-* full-palette sweep (StatusPill→StatusBadge, 6 modals→Modal, pages→primitives); 92 tests; 0 off-theme literals; CLOSED the lint gap (workshop full-palette ERROR override). lint steady 8478 (gap palettes weren't counted) |
| 2026-06-14/15 | docs | `a3f38d44f` `4b48d0f08` `1122fcfc9` (+ this) | PROGRESS handover updates |
