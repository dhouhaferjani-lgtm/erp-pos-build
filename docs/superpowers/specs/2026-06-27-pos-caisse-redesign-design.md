# Design Spec — IziPOS "Caisse Parapharmacie" Redesign

> Status: **DRAFT for review** · Branch `feat/pos-caisse-redesign` (worktree `../erp.pos-caisse`, off `origin/dev`)
> Source of truth for intent: `docs/design_handoff_pos_caisse/` (KICKOFF.md, README.md, `IZI POS - Caisse Parapharmacie.dc.html`).
> This spec records the **code-grounded** plan: it overrides the handoff wherever the handoff's assumptions don't match the real `apps/pos` codebase (those deltas are called out explicitly).

---

## 1. Goal & guardrails

Visual + UX redesign of the POS sell flow (`apps/pos`, React 19 / Vite / TS strict / Tauri 2) for the parapharmacy vertical (Tunisia, French, cash-first). Restyle the existing two-pane sell screen and all its modals; add a nav rail, a full **Light + Dark** theme, parapharmacy merchandising, and tactile touch micro-interactions.

**Non-negotiable guardrails:**
- **100% functional parity** (README §7 matrix). Restyle organisms *in place*; keep their data flow, Zustand stores, hooks, Tauri IPC, offline-first/sync/shift/receipt behavior intact. Do not rewrite business logic to match the mock's demo logic.
- **Token discipline (no mid-render improvisation).** Every colour and font references a named token. No hardcoded hex / raw Tailwind palette classes — already enforced by the repo's ESLint colour guard and `design-language.md`. The redesign *extends* that system; it does not bypass it.
- **i18n.** All copy through the existing i18next layer (`fr` locale files exist). No hardcoded strings.
- **TND money** via the existing `formatCurrency` (`lib/currency.ts`, already 3-decimal `fr-TN` → `1 234,500 DT`). Never `parseFloat` money/qty; reuse `MoneyInput`/formatters.
- **Ergonomics first** (see §6): touch floor and type sizes grounded in POS accessibility standards, not the mock's small values.
- **Atomic design**, both themes, stories/tests per repo convention, typecheck + lint + tests green.

---

## 2. Code-grounded architecture (ground truth)

Established by reading the live code (not the handoff's assumptions):

- **Live sell screen** is `apps/pos/src/pages/HomePage.tsx` (~1672 lines), two-pane (TransactionCart left, ProductGrid right), driven entirely by **Zustand** stores. Routing: `App.tsx` → `AppShell.tsx` with routes `/` (HomePage), `/settings`, `/sales`, `/reports/z`.
- **`components/organisms/` is a facade**, not a duplicate of `components/pos/`. Each organism either holds the real implementation (e.g. `TransactionCart/`, `ProductGrid/`, `AdvancedPaymentsModal/`, `CashPaymentScreen/`, `DiscountModal/`, `LineDiscountModal/`, `ModifierSelectionModal/`) or re-exports from `components/pos/` (e.g. `ProductDetailDrawer`, `PaymentSummary`, `CardPaymentModal`, `HeldTransactionsModal`, `CheckoutSuccessModal`, `QuantityNumpad`, `ReportsMenu`, `XReportModal`, `ZReportModal`, `CloseShiftModal`, `CashDrawerModal`). **Always import via `@/components/organisms`**; restyle the real implementation file behind the facade.
- **Canonical atoms** live in `components/ui/`: `Button` (variants primary/confirm/secondary/ghost/destructive; sizes sm 36 / md 44 / lg 56), `IconButton`, `Badge`, `SegmentedControl`, `StatusPill`. These are the enforced single source for interactive chrome — the redesign extends these, it does not hand-roll parallel buttons.
- **App-specific atoms** in `components/atoms/`; **molecules** in `components/molecules/` (`ProductCard`, `CartLineItem`, `NumPad`, `QuickActions`, `BarcodeChooserModal`).
- **Styling**: Tailwind v4 via `@tailwindcss/vite`, `@theme` block in `src/index.css`. Semantic role tokens already exist: `surface-canvas/raised/overlay/sunken`, `ink/ink-muted/ink-faint/ink-inverse`, `border-subtle/strong`, `action(/-hover/-subtle/-strong)`, `brand`, `success/warning/danger` (each with `-surface/-strong/-subtle`). **Light-only today** (no dark mode, no theme provider).
- **Settings**: `stores/settingsStore.ts` (Zustand + `persist`, key `izipos-settings`) already has `displayMode` ('grid'|'visual'), `cartPosition` ('start'|'end'), `language`, `touchMode`, etc. → the home for new theme settings. `SettingsPage.tsx` is fully tokenized (~695 lines).
- **Fonts**: currently **Inter** only (one-font = the "Inter-everywhere" AI tell). **Icons**: `lucide-react`. **i18n**: i18next, namespaces `common/pos/smart-prompts/fiscal`, `fr` locale files present.
- **Returns**: the live return/exchange flow is **`RefundCheckoutFlow` + `ReceiptLocatorScreen` + `ReceiptScanConfirmationSheet` + `RefundDestinationPicker`/`RefundConfirmModal`** (refund draft persistence + resume banner). The handoff's `VoidReturnModal` is **not mounted** in HomePage. **Delta: restyle the real refund flow, not the dead `VoidReturnModal`.**

### 2.1 Parapharmacy data: what exists vs. missing (backend = `apps/api`, types in `packages/shared/types/generated.d.ts`)

| Field/surface | Status | Source |
|---|---|---|
| category | EXISTS | `POSProduct.category`, `ProductData.category` |
| ingredients, health_claims, warnings, contraindications, dosage_form, usage_instructions | EXISTS | `ParapharmacyProductMetadataData` |
| brand | EXISTS but **not surfaced** in `POSProduct` | only in `EnrichedProductData` |
| skin_type (on product) | **MISSING** | (session-only in `smartPromptsStore`) |
| routine membership | **MISSING** | no model |
| equivalents / complementary relations | **MISSING** | no relations |
| Customer full mgmt page (browse/detail/history/edit/store-credit/loyalty) | **MISSING** | only selection (`components/customers/`) |
| Customer balances (receivable/credit) | EXISTS | `CustomerMirrorRow` |
| Loyalty points/enrollment/tier | EXISTS backend, **not surfaced** in POS | `EnrollmentData`/`LoyaltyMemberData` |
| skin_type (on customer) | **MISSING** | session-only |

**Decision (§8 of README — "implement or ticket"):** the redesign builds the *UI* for the new parapharmacy surfaces with data plumbed through **optional** fields + graceful **empty states**; it does **not** fabricate data (honest-content discipline — no mock data in production). Genuinely-missing backend data (product skin_type, routine, equivalents/complements relations; customer skin_type persistence; loyalty surfacing) is delivered as: (a) frontend types + UI + empty state now, (b) a **backend contract ticket** per item. Fields that already exist (brand via enriched, ingredients/health_claims) get surfaced for real.

---

## 3. Theming strategy — extend, don't replace

**Approach: keep the existing `@theme`/CSS-variable architecture and the `components/ui/` atoms; layer the new dimensions on top.** This maximizes reuse and keeps the ESLint guard valid. Concretely:

1. **Override the `--color-*` tokens directly per theme (no `--sem-*` indirection).** Keep `@theme` declaring the **light defaults as literal values**, then **redeclare the `--color-*` tokens (and the raw `--theme-*`, `--pos-*`, focus-ring vars) directly under `[data-theme="dark"]` and `[data-accent="…"]` selectors.** Utilities read `var(--color-x)` at use-site, so a direct redeclaration in a selector cascades per element — the canonical Tailwind v4 manual-dark pattern. This deliberately avoids the `@theme inline` vs plain-`@theme` subtlety: a non-inline `@theme { --color-x: var(--other) }` computes `--color-x` at `:root` to the light value and would **not** cascade (the documented reason `@theme inline` exists). By overriding the concrete `--color-*` token in each theme selector we sidestep that entirely and opacity modifiers (`bg-x/50` → `color-mix(... var(--color-x) ...)`) still cascade. Add the dark variant for any `dark:` utilities we author: `@custom-variant dark (&:where([data-theme=dark], [data-theme=dark] *))`. **Verify empirically** (render + toggle `data-theme`/`data-accent`, screenshot) before building the rest of P1 on it. Values come verbatim from README §6 surface/semantic tables (light + dark). **Must also override** (they're outside the current `@theme` plan): the global `*:focus-visible` outline (today hardcoded `--theme-primary-500` → make accent/theme-aware), `--pos-header-bg/-text/-surface/-grid-bg`, and any `--theme-primary-*` ramp steps used as chrome.

2. **Accent as a swappable dimension.** New tokens `--accent`, `--accent-strong`, `--accent-tint`, `--accent-ring`, `--accent-text`, swapped by **`data-accent`** = `orange`(default)|`green`|`blue`|`teal` (README §6 accent table; tint has light+dark variants). The POS-caisse primary CTA (Encaisser) uses accent. The caisse theme re-points `--color-action*` to the accent vars, so any component using the **`action` token** (incl. canonical `Button variant="primary"`) adopts the accent. **Caveat (from review): this does NOT cover the ~63 rogue direct `primary-*`/`blue-*` palette refs** in `.tsx` (e.g. `AdvancedPaymentsModal`, `ModifierSelectionModal`, `CloseShiftModal`, `HomePage` status pills) — those bind `--color-primary-*` / Tailwind `blue-*`, not `action`, and would stay ocean-blue → a two-tone split-brain. Resolution: keep `--color-primary-*` as the blue ramp (it's still chrome, e.g. header = primary-900); **sweep the rogue `primary-*`/`blue-*` refs → tokens (`action`/`accent`/`surface`) as each surface is restyled** in its phase, and add that file to the ESLint `tokenMigratedGlobs` so CI guards it thereafter. Until a file is restyled it keeps blue — acceptable interim, and every surface is restyled by DoD. `brand` (copper wordmark) is retired in favour of the IziPOS mark + accent chrome.

3. **Corner-radius scale.** Tokens `--r-panel/-card/-tile/-ctl/-sm/-pill`, swapped by **`data-corner`** = `rounded`(default)|`sharp` (README §6 radius table). Components reference `rounded-[var(--r-card)]` etc. via small token utilities.

4. **Density.** `density` = `comfortable`(default)|`dense`. **Not pure CSS** (from review): `ProductGrid` virtualizes and computes its column count in **JS** (`getColumns()` from `window.innerWidth` + Tailwind `grid-cols-N` classes + `rowHeight`/`CARD_MIN_H_*` constants). Thread `density` into a single `getColumns(displayMode, density, width)` and the card-height constants — a CSS `--grid-cols` var alone won't drive the virtualizer. **Density × displayMode are orthogonal and reconciled** (matches README "6 dense / 5 comfortable; compact list 5/4"): `displayMode` = card *style* (visual tiles vs compact list); `density` = column step. visual+comfortable=5, visual+dense=6, compact+comfortable=4, compact+dense=5. **Prerequisite cleanup:** `ProductGrid` currently keeps its OWN `useState` seeded from a separate `localStorage` key `pos-display-mode` and ignores `settingsStore.displayMode` (dual source of truth). Reconcile first — make the grid read `displayMode`/`density` from `settingsStore` (migrate the legacy key) so Settings and grid stay in sync.

5. **Cart side.** Reuse the existing `cartPosition` setting ('start'|'end'); the nav rail sits opposite the cart (README rule). Drive via flex direction + a `data-cart-side`/existing class.

6. **Fonts.** Self-host **Montserrat** (display/headings), **Public Sans** (UI/body), **IBM Plex Mono** (numbers/prices/IDs) — offline-first ⇒ bundled `@font-face` (no Google CDN at runtime). Tokens `--font-display`, `--font-sans`, `--font-mono`. Prices/totals/IDs use `--font-mono` + `tabular-nums`. (Fixes the Inter-everywhere tell.)

7. **ThemeProvider.** A small provider reads `theme`/`accent`/`corner`/`density`/`cartPosition` from `settingsStore` and applies `data-theme`/`data-accent`/`data-corner` attributes (+ density/cart vars) to `<html>` (or `#root`). Persisted via the existing store. Default on first run: `theme:'light'`, `accent:'orange'`, `corner:'rounded'`, `density:'comfortable'` (ergonomic default — fewer, bigger tiles), `cartPosition:'start'`. An **Appearance** section in `SettingsPage` exposes all five with `SegmentedControl`s + a sun/moon toggle in the nav rail.

This is the "theming system to tweak later" the brief asks for: five orthogonal knobs, all token-driven, all persisted, all live-switchable.

---

## 4. Atomic component plan (new shared atoms/molecules)

Factor these out while restyling (README §4), all token-driven, all with stories/tests + 8-state coverage where interactive:

- **Atoms (`components/ui/` or `components/atoms/`):** `ThemeToggle`, `Pill`/`Chip` (incl. removable filter chip), `StockBadge` (stock-specific tokens — see §5), `Avatar` (initials), `ProductThumb` (tinted-initials tile), `Stepper` (−/＋ qty), `Toggle` (consent), `Tab`, `KpiCard`, `BreakdownBar`, `Divider` (vertical group divider for header). Reuse existing `Button`, `IconButton`, `Badge`, `SegmentedControl`, `StatusPill`, `MoneyInput`.
- **Shells (`components/ui/`):** `ModalShell`, `FullScreenSheet`, `Drawer`, `Toast` — extract the mock's overlay/sheet/drawer transitions (fade 160ms; sheet rise+scale 200–220ms `cubic-bezier(.2,.8,.3,1)`; drawer 240ms) as reusable wrappers. Existing `components/pos/Modal.tsx` is the consolidation point.
- **Molecules:** `NavRailItem`, `CategoryPill`, `FilterChipRow`, `SkinAdviceBar`, `CartLine` (compact, expandable), `PaymentLineRow`, `QuickAmountRow`.

**Reconciliations with the existing colour grammar:**
- **StockBadge.** Mock uses green "En stock" / amber "Stock faible" / red "Rupture". The existing grammar reserves `success/warning/danger` for money/errors and forbids success-for-in-stock. → Introduce **stock-specific tokens** `--stock-ok / --stock-low / --stock-out` (+ surfaces), visually green/amber/red but semantically distinct, and document the exception in `design-language.md`. Avoids overloading the money/error semantics.
- **Checkout CTA.** "Encaisser" uses `primary` (now accent), not the green `confirm` variant; green stays for confirmed-money contexts (change-due covered panel, success).

---

## 5. Screen-by-screen plan

Tracked in `docs/superpowers/pos-caisse-redesign-tracker.yaml` (the per-screen SoT with parity items, real component paths, new-surface flags, status). Phases:

1. **Theme foundation** (§3) — tokens, fonts, ThemeProvider, settings Appearance section. *Everything consumes this.*
2. **App shell + nav rail** (`AppShell`, `Header`) — 88px rail opposite the cart (Caisse/Clients/Rapports/Caisse-Shift), theme toggle; header with grouped, divider-separated clusters + ghost icon buttons.
3. **Sell screen** — restyle `TransactionCart` (compact expandable cart lines, quick actions, summary), `ProductGrid` + `ProductCard` (visual + compact, in-cart treatment, tap pulse), toolbar (search, Filtres, Top ventes, view toggle), category pills, filter chips, skin-advice bar.
4. **Payment flows** — `CashPaymentScreen` (full-screen), `AdvancedPaymentsModal` (3-col method/amount/lines, real `getCompatibleRepositoryTypes` rules), `CardPaymentModal` sub-flow, `CheckoutSuccessModal`.
5. **Cart-adjacent modals** — `LineDiscountModal`, `DiscountModal`, `HeldTransactionsModal` (hold/recall), customer picker + add-customer (`CustomerSearchModal`/`CustomerAttachPanel`).
6. **Returns** — restyle the **real** `RefundCheckoutFlow`/`ReceiptLocatorScreen` path (not `VoidReturnModal`).
7. **Reports / Shift** — `ReportsMenu`, `TodaySalesPanel`/sales history, `XReportModal`, `ZReportModal`/`CloseShiftModal` reconciliation, `CashDrawerModal`.
8. **Customers page** (new) — browse/search/detail (history, loyalty, store credit, skin type, advice note)/edit. New route under `AppShell`.
9. **New parapharmacy surfaces** — Filtres drawer (brand/category/routine/skin), skin-advice bar, ProductDetail tabs (Équivalents/Compléments/Routine) with optional data + empty states; backend tickets for missing data.

Each phase: restyle in place → both themes → parity check against the matrix → typecheck/lint/test → **Codex adversarial review** → commit.

---

## 6. Ergonomics & accessibility (POS-grounded)

The mock's `body ≥ 12.5px` is **rejected** as too small for a 15"+ terminal at ~50–70cm. Numbers below are grounded in WCAG 2.2, Apple HIG / Material, DIN 1450 visual-angle guidance, and touchscreen-kiosk/numpad research (sources in the research report; key anchor: the W3C reference pixel is defined at 71cm arm's-length, so standard 16px web sizes are "distance-correct" at reference density).

**Touch & spacing**
- **Touch floor (any control): 48×48px.** Never below. Clears WCAG AAA (44) and Material (48dp). Applies to close/X and edit controls too — those are the classic POS mis-tap traps; do not shrink them.
- **Primary actions (Encaisser/Valider/Finaliser, numpad keys): 64–80px** (~17–21mm physical — kiosk research sweet spot).
- **Gap between adjacent targets: 12–16px** (8px min). Dense numpad grid: keep keys big (~20mm/72–80px), tight 4–8px gutters (packed-keypad research beats small-keys-wide-gaps).

**Type scale** (becomes `--text-*` tokens so sizing is itself tweakable/enforced):

| Role | Recommended | Floor |
|---|---|---|
| Min body / dense lists | **16px** | 14px (never below) |
| Primary body / cart line text | **18px** | 16px |
| Secondary / meta (SKU, unit, time) | **14px** | 13px |
| Button labels (medium/bold) | **16–18px** | 16px |
| Product names (cart, tiles) | **16–18px** | 16px |
| Line prices / qty / subtotals (mono) | **20–24px** | 18px |
| Grand total / amount due (mono bold) | **28–40px** | 28px |

**Contrast** — target **AAA 7:1 for body** (parapharmacy floors are bright/glare-prone, so treat AAA as the practical target, not the 4.5:1 AA minimum); 4.5:1 large text; **3:1 non-text/UI** (icons, focus rings, input borders, stock badges). Verify BOTH themes (navy payment panels, accent-on-surface, stock badges) with an automated contrast pass. No light-gray-on-white meta text.

**Fixed-viewport clipping risk (from review — must audit, not assume).** The app is `html,body,#root { overflow:hidden }` with fixed two-pane flex. Bumping body to 16–18px, touch floor to 48px (cart-line buttons are `h-7`=28px today), and the total to 28–40px in a **non-scrolling** viewport will **clip**, not scroll. **Per-region overflow audit is required**: the cart line list and product grid must own internal `overflow-y` scroll; the cart summary + checkout buttons stay pinned; modals/full-screen sheets size to viewport. Do this audit as each region is restyled.

**Density-rendering caveat (must verify, not assume):** px figures assume reference density (1 CSS px ≈ 0.265mm). A dense 15" panel (e.g. 1920×1080 ≈ 147ppi) at 100% OS scale renders 1 CSS px ≈ 0.17mm, so 48 CSS px is only ~8mm — below the kiosk floor. **Action:** verify rendered physical mm on the target terminal and/or run the Tauri webview at a global zoom so CSS px ≈ reference px — a **deployment/calibration note**, NOT a `--ui-scale` root multiplier (a global multiplier amplifies the clipping above on a fixed viewport; rejected per review).

**Color-for-speed & consistency** (cashier ergonomics): primary/confirm action in one high-salience color, destructive clearly differentiated and not adjacent to confirm; primary action in a consistent fixed position (build muscle memory — never move Encaisser between screens); minimize taps to complete a sale.

---

## 7. Anti-AI-slop disciplines (Hallmark lens)

Applied as a QC lens over every emitted surface (this is a `redesign` within existing boundaries, so Hallmark's page-macrostructure machinery doesn't apply; its disciplines do):
- **Paired fonts** (Montserrat/Public Sans/IBM Plex Mono) — kills the Inter-everywhere tell.
- **Tabular mono numerals** for all prices/totals/IDs.
- **Tinted surfaces**, never pure `#000`/`#fff` flat (app-bg `#EBEEF1`/`#080B11`, panels elevated).
- **One icon set** (lucide-react); no emoji icons.
- **Token lock** — no inline hex/font mid-render (= ESLint guard).
- **Touch-first** — no hover-only affordances; every hover has tap/focus equivalent (terminal is touchscreen).
- **Motion restraint** — `transform`/`opacity` only, named ease-out, `prefers-reduced-motion` collapse; the `ezTap` pulse is a genuine physical-tap feedback (allowed overshoot), not gratuitous.
- **Honest content** — no fabricated metrics/customers/stock; empty states for missing data.
- **No card-in-card** nesting without reason; one containment layer per region.
- **Pre-emit self-critique** (P/H/E/S/R/V ≥3) stamped on substantial new UI.

---

## 8. Risks & open decisions (made autonomously; flag on review)

1. **Accent re-points `action`.** Existing ocean-blue primary buttons become accent-orange app-wide in the POS. Intended (cash-first Encaisser = accent). If the team wants `action` to stay blue and only the checkout CTA to be accent, that's a one-line change. **Decision: re-point, for visual cohesion with the mock.**
2. **Stock badge exception** to the colour grammar — documented in `design-language.md`. **Decision: stock-specific tokens.**
3. **Returns** restyle targets `RefundCheckoutFlow`, not `VoidReturnModal` (which is dead). **Decision: real flow.**
4. **Missing parapharmacy/loyalty data** → UI + empty state now, backend tickets. **Decision: no fabricated data.**
5. **Fonts self-hosted** (offline-first) — adds ~Montserrat+Public Sans+IBM Plex Mono webfont weight to the bundle; subset to French + needed weights.
6. **Scope is large** (9 phases). Delivered incrementally behind the parity matrix; theme foundation first.

---

## 8b. Adversarial review resolutions (2026-06-27)

Folded in from the spec adversarial review. Each finding → decision:

- **Theming mechanism [confirmed viable].** Switched to override-`--color-*`-directly (Approach B, §3.1); empirical verify before building on it. Forbid relying on non-inline `@theme { --color: var(--other) }` for runtime theming.
- **Non-`@theme` vars [HIGH].** Global focus ring (`--theme-primary-500`), `--pos-*`, palette chrome must get explicit dark/accent overrides (§3.1).
- **`action` re-point split-brain [HIGH].** ~63 rogue `primary-*`/`blue-*` refs stay blue until their file is restyled; sweep per phase + add to ESLint `tokenMigratedGlobs` (§3.2).
- **AdvancedPaymentsModal [HIGH].** 916 lines, ~40 hardcoded colors, NOT in ESLint guard → it's a from-scratch detox + dark pass, the single highest-effort restyle. Tracker re-flags P4 advanced-payments as `effort: high`; phase carefully, tokenize + add to guard.
- **Cart-line "tap-to-expand" [HIGH — DEVIATE FROM MOCK].** Reality: today steppers/discount/delete are **always visible** (nothing increments on row tap; qty tap opens numpad via `onQuantityTap`). The mock's collapse-then-tap-to-expand **adds a tap to the most frequent cashier action**, contradicting §6 "minimize taps". The mock's own stated rationale ("visible stepper is the researched pattern; tap-to-increment surprises cashiers") is **already satisfied** by the current always-visible steppers. **Decision: keep steppers visible by default** (ergonomics-led, using the user's granted leeway); restyle the line visually but do NOT hide primary qty controls. Reserve any chevron/expand strictly for *secondary* detail (fiche shortcut, skin advice) where space allows. Preserve `onQuantityTap` numpad. No tests assert on `CartLineItem` markup (no safety net — add a behavior test).
- **Ergonomics vs `overflow:hidden` [MED].** Per-region overflow audit; dropped the global `--ui-scale` multiplier (amplifies clipping); calibration via webview zoom is a deployment note (§6).
- **Density is JS-driven, not CSS [MED].** Thread into `getColumns()` + card-height constants; reconcile `displayMode` dual-source-of-truth first (§3.4).
- **Storybook fabricated [HIGH].** Dropped from DoD; Vitest only (§9).
- **RTL/Arabic [MED — SCOPED OUT].** French launch only; `en`/`fr` supported, layout uses physical-direction classes. RTL is an explicit out-of-scope retrofit, noted here so it's a conscious decision, not an oversight.
- **`fr` default [LOW].** Leave `settingsStore` global default `en` (multi-vertical app; other deployments rely on it); the Tunisia/parapharmacy deployment selects French via tenant/onboarding config. Flagged, not changed.
- **Duplicate `components/pos/ProductGrid.tsx` [LOW].** Confirm live file (`organisms/ProductGrid/` is live) before restyling; avoid editing a dead dup (same class of bug we caught with `VoidReturnModal`).

## 9. Definition of done (per README §6 DoD)

- Every screen in README §5 re-implemented in both themes via atomic components.
- Parity matrix §7 fully green.
- New surfaces (§8) implemented or explicitly ticketed with notes.
- No hardcoded theme colours; all via tokens (ESLint green).
- Ergonomic type/touch scale applied and contrast-verified in both themes.
- Typecheck, lint, existing tests pass; new components have **Vitest tests** per repo convention. **No Storybook** — `apps/pos` has none (the README's "stories" wording doesn't match the repo); do not invent it. An optional throwaway 8-state demo page is fine for manual QA but is not committed.
