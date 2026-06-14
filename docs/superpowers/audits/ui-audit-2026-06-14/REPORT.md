<!-- Hallmark · audit · target: apps/web (AutoERP / IziPOS web admin)
     pre-emit critique: P5 H4 E5 S5 R4 V4 -->

# AutoERP Web UI — Consistency & Atomic-Design Audit

**Date:** 2026-06-14 · **Target:** `apps/erp/apps/web` · **Vertical audited:** IziPOS (live cafe-tunis demo, `http://localhost:8089`)
**Method:** `hallmark audit` — 7 parallel read-only code audits across ~50 feature dirs + the design system, plus 33 screenshots of the running app (representative page per cluster). **No files were modified.**

> Screenshots: `docs/superpowers/audits/ui-audit-2026-06-14/screens/` · Capture harness: `apps/web/tools/ui-audit-shots.mjs` (re-runnable; delete if unwanted).

---

## 1. Verdict

**The design system exists and is well-conceived, but it is decorative rather than load-bearing.** Atoms, molecules, organisms, and a token file are all present and documented (`components/README.md`), and a handful of features use them faithfully. But **adoption is ~20%** — the other 80% of the app hand-rolls the same buttons, inputs, modals, tables, badges, and empty states with inline Tailwind classes. The result is exactly the drift you suspected: every screen is *close* to consistent, but no two list pages, forms, or modals are built the same way, and a large fraction render slightly **off-brand** because of a theming gap.

This is not "the UI is ugly." Individual screens are clean (the data tables especially — see `screens/14-inventory-stock.png`, `screens/02-sales-customers.png`). The problem is **systemic inconsistency**: the same concept is implemented 3–5 different ways, so the app *feels* assembled by many hands rather than designed as one product. For a CRM/ERP — where users live in forms and tables all day — that inconsistency is the usability tax.

**Severity tally:** 9 critical (systemic) · 11 major · 8 minor.

---

## 2. Root causes (fix these and most findings collapse)

### RC-1 — Two token systems that only half-agree → an "off-theme palette" trap
There are **two** sources of truth:
- `src/index.css` defines the **"Deep Ocean"** theme (blue primary + copper secondary) and bridges it into Tailwind by remapping **only `blue-*` → primary and `gray-*` → neutral**.
- `src/lib/designTokens.ts` defines `tokens.*` as **hardcoded Tailwind class strings** (`bg-blue-600`, `text-gray-700`, …).

Consequence: `bg-blue-*` / `text-gray-*` are theme-bridged and safe. **Every other palette — `slate`, `sky`, `amber`, `emerald`, `rose`, `violet`, `teal`, `cyan`, `orange`, `purple`, `pink`, `indigo` — renders as raw Tailwind and ignores the theme entirely.** So when a developer reaches for `emerald-600` to mean "success" or `amber-500` to mean "warning", they get a color that has no relationship to the brand. ~13,000 hardcoded color literals exist across 397 files; the dangerous subset (non-remapped palettes) lives in **~130 files**.

### RC-2 — The lint guard has a hole the size of the problem
The ESLint rule (`eslint.config.js:171,211`) only flags `(bg|text|border|ring)-(red|blue|green|yellow|gray|purple|pink|indigo)-NNN`. It **does not catch** `slate/sky/amber/emerald/rose/violet/teal/cyan/orange` — which are precisely the off-theme palettes people actually use. Only `scheduling/` (line 229) bans the full set. **This is why the four "strict reference dirs" (`workshop-*`) pass CI while being saturated with off-theme color** — they exploit the gap (e.g. `workshop-work-orders/components/StatusPill.tsx:14-24` uses 9 off-theme families).

### RC-3 — `designTokens.ts` itself bakes in off-theme drift
The token file — the thing meant to *prevent* drift — hardcodes off-theme values:
- `chartColors.primary = '#2563eb'` ≠ theme primary `#1A6FB5`. **Every ECharts dashboard renders in a blue that doesn't match the app** (`owner-dashboard/components/SalesByLocationChart.tsx`, etc.). `chartColors.violet/cyan/neutral` are also off-theme. **One-file fix, app-wide impact.**
- `tokens.statusBadge.*` (slate/sky/amber/violet/emerald/stone/rose/zinc), `tokens.utilizationBar.*`, `tokens.toggleButton.active` (`bg-sky-600`), `tokens.designationOverride.dot` (`bg-amber-400`) — sanctioned drift, off-theme by design.

### RC-4 — The most-repeated layouts have no shared component
There is **no `PageHeader`** (every page rolls its own title/subtitle/actions), **no `DataTable`** (131 files hand-roll `<table>`), and **no shared list-page scaffold** (filters + pagination + empty + loading reinvented per page). The primitives that *do* exist (`EmptyState`, `Spinner`, `FormField`, `Modal`) are barely adopted. So the highest-frequency patterns in the app are also the least standardized.

### RC-5 — Parallel/duplicate systems left from an unfinished migration
`components/ui/` (flat, legacy) and `components/{atoms,molecules,organisms}/` (atomic) coexist, reconciled by **9 re-export shims**. There are **two** `LocationSelector` components (different jobs, same name), **two** paginators (`Pagination` + `OffsetPagination`), **two** feature dirs for one entity (`location/` vs `locations/`), and a feature-local mini-design-system (`channels/pages/channelPageStyles.ts`). Two import paths for the same thing keeps the drift alive.

---

## 3. Findings by severity

### 🔴 Critical (systemic — ship-blocking for "consistent product" goal)

- **C1 · Off-theme palette trap (RC-1).** ~130 files render non-brand colors because index.css bridges only blue/gray. → Bridge the full semantic palette in `@theme`, or ban non-token palettes everywhere (extend the scheduling lint to all dirs).
- **C2 · Atoms are not load-bearing.** **~922 raw `<button>` across 346 files** vs 46 using the `Button` atom; **~630 raw `<input>/<select>/<textarea>`** vs a handful using atoms; `FormField` used in **~3 files total**. Three+ ways to make every control. → Make atoms mandatory; lint-ban raw `<button>`/`<input>` outside the atom layer.
- **C3 · ~45 bespoke modals** hand-roll `fixed inset-0 … bg-black/50` instead of the `Modal` organism (29 files use it). Even first-party organisms bypass it (`AddCompanyModal`, `AddLocationModal`, `TaxConfigFormModal`). Inconsistent focus-trap / escape / sizing / a11y. → Migrate all onto `Modal`.
- **C4 · Chart colors off-theme (RC-3).** `chartColors.primary #2563eb` ≠ theme `#1A6FB5`; every dashboard chart is off-brand. → Derive `chartColors` from the theme hex. *(1-file, highest ROI fix in the report.)*
- **C5 · No shared list-page scaffold / `DataTable` / `PageHeader` (RC-4).** 131 raw `<table>`, 32 ad-hoc page titles, filters/pagination/empty/loading reinvented per page. → Extract `PageHeader`, `DataTable`, `ListPageLayout`.
- **C6 · Rainbow icon-tile hub pages.** Finance (`screens/24-finance.png`), Inventory (`InventoryHubPage.tsx:30-118`), POS (`screens/33-pos.png`), and Marketing (`MarketingHubPage.tsx`) hubs each render a grid of cards with a different pastel icon chip per card — a recognizable AI-template tell **and** the chips are off-theme. → One tokenized hub-card; drop the per-card color.
- **C7 · Money/number presentation breaks in financial tables.** `tabular-nums` is absent almost everywhere (1 file in the finance cluster, 1/23 inventory table files); finance report tables (Aged AR/AP, Trial Balance, P&L, Balance Sheet) **left-align** money. → Right-align + `tabular-nums` on all numeric cells. (Plus 109 `parseFloat`-on-money in finance — a precision-contract issue, cross-ref CLAUDE.md rule 19.)
- **C8 · Empty/loading states are bespoke per page.** `EmptyState` used in ~7 of ~60 list pages; `Spinner` rarely; **no skeletons anywhere**; loading is often plain "Loading…" text. → Drive all list states through `EmptyState` + `Spinner`/skeleton.
- **C9 · Status badges reinvented per feature.** Most features hand-roll a `Record<status,string>` color map (`CountingStatusBadge`, `BatchStatusBadge`, `StatusPill`, `EmploymentStatusBadge`, fraud/document status fns, …), almost always with off-theme palettes. Only a few use the `Badge` atom. → One `StatusBadge` driven by a semantic status→token map.

### 🟠 Major (looks inconsistent / drifts)

- **M1 · Two parallel component systems (`ui/` vs atomic) + 9 re-export shims (RC-5).** Remove shims after updating importers.
- **M2 · Two `LocationSelector` (same name, different jobs); two paginators; `location/` vs `locations/` duplicate feature dirs** modelling one entity against `/locations`. → Rename (`LocationField` vs `LocationSwitcher`); collapse to one `location` dir + one `Location` type; pick one paginator.
- **M3 · Buttons built from raw classes** even where the rest of a file is tokenized (copy-pasted `inline-flex … rounded-lg bg-blue-600 px-4 py-2` export buttons across all 5 finance report pages). → `<Button variant="primary">`.
- **M4 · Inline `<label>`+error triads instead of `FormField`** — 107 in the sales/CRM cluster alone; repeated in finance/admin/inventory/workshop. Inconsistent error rendering. → `FormField` wrapper.
- **M5 · No shared filter bar / tab strip.** `Tabs`/`FilterTabs` used in ~5 files; pages hand-roll `border-b-2 border-blue-500` tab nav (`purchases/GoodsReceiptListPage.tsx:215`). → Standardize on `Tabs`/`FilterTabs`.
- **M6 · `channelPageStyles.ts` is a feature-local fork of the atom layer.** Cohesive within channels, but a parallel system. → Reconcile with atoms/tokens.
- **M7 · `EmailVerificationBanner` (global shell) uses raw amber palette** (`organisms/EmailVerificationBanner.tsx:48-86`) while `tokens.alert.warning` exists — an off-theme element on *every* page (visible top of every screenshot). → `tokens.alert`.
- **M8 · Reports/admin dashboards use local `colorClasses` color-map props** (`reports/ReportsPage.tsx:314-322`, `color="purple"/"orange"`) — the exact anti-pattern tokens exist to kill. → token badge/chip.
- **M9 · POS token drift.** POS's *separate touch-first layout* (`POSButton`, 48px targets) is intentional and fine; its **color drift is not** — ~26 files scatter indigo/purple/emerald/amber/orange/teal, an undefined `bg-card` class, plus glassmorphism + dark surfaces in a light-only app (`smart-prompts/*`). → Keep POSButton, adopt the shared tokens.
- **M10 · Admin super-panel unlocalized** (`admin/pages/MonitoringPage.tsx` 0 `t()`; `AdminLayout` nav hardcoded English). Its dark shell is an intentional separate design language (fine), but English-only violates CLAUDE.md rule 11. → Confirm intent or localize.
- **M11 · Dashboard stacks two card systems on one page.** `screens/01-dashboard.png` shows rainbow-chip KPI cards (one style) above an "Owner Dashboard" section (a second, token-correct style), plus a misaligned Amount/Percentage toggle. The token-correct owner-dashboard/progression cards are the standard the top KPIs should adopt.

### 🟡 Minor (taste / polish)

- **m1 · Dead `dark:` classes** in ~7 component files in a light-only app (`MarginIndicator`, etc.). → strip.
- **m2 · `orange` used ad-hoc for "low margin/warning"** instead of the warning token (`MarginIndicator`, `PriceInputWithMargin`, coupons `revoked`). → warning token.
- **m3 · List footer label gap:** `screens/02` shows "showing [blank] rowsPerPage: 25" — missing count + a camelCase prop leaking as a label. → fix i18n/label.
- **m4 · Placeholder copy:** "Select... customer" (ellipsis misplaced; should be "Select a customer…"). Curly ellipsis `…` over `...`.
- **m5 · Cookie consent bar overlaps page content** at the bottom of every screen (fixed bar, no body padding). → reserve space.
- **m6 · Glassmorphism + text-glyph icons** (`✦/✕/ℹ`) in POS smart-prompts vs lucide everywhere else. → lucide.
- **m7 · Mixed `@/` vs relative imports** across the sales/CRM cluster — a copy-paste drift signal. → enforce `@/` via lint.
- **m8 · `bg-opacity-75` legacy overlay** in one modal vs `bg-black/50` elsewhere. → fold into `Modal`.

---

## 4. What's already good (use these as the templates)

The system *works* where it's used — these are your reference implementations, not rewrites:

| Reference | Why it's the gold standard |
|---|---|
| `features/scheduling/` | Only dir that fully honors the token system (`statusBadge`/`utilizationBar`/`toggleButton`) **and** is lint-guarded against the full palette. The model for RC-2's fix. |
| `features/vat-reporting/` | Best finance dir: `Button` + `Badge` atoms, dedicated `VatPeriodStatusBadge`, shared breakdown table. |
| `features/owner-dashboard/` + `features/progression/` | Token-correct KPI/chart cards (`tokens.card.base`). The standard for C6/M11. |
| `features/uom/`, `features/loyalty/`, `features/vouchers/` | Faithful atoms + `Modal` + `Spinner` + `EmptyState`. |
| `features/vehicles/` + `workshop-*` structure | Cleanest *folder* architecture (`pages/ + components/{atoms,molecules,organisms}/ + hooks/ + api/`). `CompleteWorkOrderDialog.tsx` is the lone fully-compliant modal — the canonical modal reference. |
| Data tables (`StockLevelsPage`, `PartnerListPage`) | Dense, right-aligned, scannable — prove the visual language is sound. |
| Global shell (`Sidebar`/`TopBar`/`CommandPalette`/`Breadcrumb`) | 0 off-theme hits, single icon set (lucide), centralized breadcrumb. |

---

## 5. Remediation plan

Sequenced so that **early phases stop the bleeding cheaply and unlock the rest**. Each phase is independently shippable. No phase requires a visual redesign — this is consolidation onto the system that already exists.

### Phase 0 — Stop new drift (½ day, do first)
1. **Close the lint gap (RC-2):** extend the scheduling color rule (`eslint.config.js:229`, full palette list) to **all** `src/features/**` and `src/components/**`, as `warn` globally + `error` in the strict dirs. This freezes the problem at today's size.
2. **Add lint rules:** ban raw `<button>` and raw `<input|select|textarea>` JSX outside `components/atoms/**` (custom rule, mirror the existing `eslint-rules/` pattern).
3. Add a TanStack-style **architecture test** asserting `chartColors` are sourced from theme hex (prevents C4 regression).

### Phase 1 — One-file / high-ROI fixes (1 day)
4. **C4:** rewrite `chartColors` in `designTokens.ts` to the theme hex (`primary → #1A6FB5`, neutral → theme neutral, etc.). Instantly re-brands every chart.
5. **RC-1:** in `index.css` `@theme`, bridge the semantic palettes to theme scales (map `emerald→success`, `amber/orange→warning`, `rose/red→error`, `sky→primary-ish` or a defined info scale) **or** add real `success-*/warning-*/error-*/info-*` token scales. This makes the ~130 off-theme files snap back toward brand without touching them.
6. **M7, m2:** point `EmailVerificationBanner`, `MarginIndicator`, `PriceInputWithMargin` at `tokens.alert.*`/warning.

### Phase 2 — Build the missing primitives (3–4 days)
7. Extract **`PageHeader`** (title/subtitle/breadcrumb-slot/actions), **`DataTable`** (header/stripe/hover/`tabular-nums`/right-align-numeric/empty/loading), **`ListPageLayout`** (header + filter bar + table + pagination), and a single **`StatusBadge`** (status→token map). Model them on the gold-standard dirs (§4).
8. Standardize empty/loading on `EmptyState` + `Spinner`/skeleton inside `DataTable`.
9. Add a **`HubCard`** + `HubGrid` (one tokenized card, no per-card color) and convert the 4 hub pages (C6).

### Phase 3 — Migrate, cluster by cluster (subagent-driven, ~1–2 days/cluster)
Order by impact (worst first): **documents** → **finance/treasury** → **admin/settings** → **inventory/catalog** → **pos color** → **workshop-***. Per cluster: bespoke modals → `Modal`; raw controls → atoms + `FormField`; raw buttons → `Button`; status pills → `StatusBadge`; list pages → `ListPageLayout`/`DataTable`; off-theme → tokens. Each cluster is a separate PR with a screenshot diff.

### Phase 4 — Kill the duplicates (1 day)
10. Remove the 9 `ui/` re-export shims (update importers). Rename the two `LocationSelector`s. Pick one paginator. Collapse `location/` + `locations/`. Reconcile `channelPageStyles` with atoms.

### Phase 5 — Polish (½ day)
11. Minors m1, m3–m8; cookie-bar spacing; placeholder/curly-punctuation copy; admin localization decision (M10).

**Guardrails throughout:** every migrated cluster keeps the Phase-0 lint at `error`; the architecture tests prevent chart/token regressions; PRs carry before/after screenshots so consistency is verifiable, not asserted.

---

## 6. Appendix — quantified drift (per cluster)

| Cluster | raw input/select/textarea | raw `<button>` | bespoke modals | off-theme files | notes |
|---|---|---|---|---|---|
| Sales/CRM | ~94 | hundreds | 5 | 6 | `documents/` is the epicenter; loyalty/vouchers clean |
| Inventory/Catalog | 115 | 133 | 8 | 23 | rainbow Inventory hub; `location` vs `locations` dup |
| Purchases/Finance | 135 | ~135 | 11 | 19 | 0/9 dirs use tabular-nums; 109 parseFloat-on-money |
| Admin/Settings | 169 | 150 | 10 | 45 | admin dark shell intentional; settings 3 layouts |
| POS/Dashboards | 45 | 105 | 3 | ~28 | POS layout separate (OK), color drift (not OK) |
| Workshop/Vehicles | 74 | ~60 | 8 | 8 | "strict" dirs drift via lint gap; vehicles clean |
| **Design system / shell** | — | — | — | — | 4 button systems, no PageHeader/DataTable, chart+badge tokens off-theme |

App-wide: ~13,000 hardcoded color literals / 397 files; **~922 raw `<button>` / 346 files**; ~60 `fixed inset-0` modal sites / 29 `Modal` users; `FormField` ~3 files; `EmptyState` ~7 files; lucide is the sole icon set (no mixed icon libraries — a genuine positive).

*Typography note:* the app is Inter-only. For a landing page that's a slop tell; for a data-dense ERP a single neutral sans is a defensible deliberate choice. Optional upgrade: add a `tabular`/mono numeric face for financial columns (pairs with C7) and consider a slightly more characterful display face for page titles — low priority.
