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

1. **Split the token layer into "raw" → "semantic" → `@theme`.** Today `@theme` maps `--color-*` directly to `--theme-*` palette ramps. Introduce an intermediate **semantic variable layer** (`--sem-*`) defined in `:root` (light) and overridden under **`:root[data-theme="dark"]`** (dark). `@theme` maps `--color-surface-canvas: var(--sem-surface-canvas)` etc. Flipping `data-theme` re-points every semantic token — no palette-ramp inversion, clean and predictable. Values come verbatim from README §6 surface/semantic tables (light + dark).

2. **Accent as a swappable dimension.** New tokens `--accent`, `--accent-strong`, `--accent-tint`, `--accent-ring`, `--accent-text`, swapped by **`data-accent`** = `orange`(default)|`green`|`blue`|`teal` (README §6 accent table; tint has light+dark variants). The POS-caisse primary CTA (Encaisser) uses accent. To keep existing `Button variant="primary"` (today ocean-blue `action`) consistent with the new look, the caisse theme re-points `--color-action*` to resolve from the accent vars — so existing primary buttons adopt the accent automatically without per-component edits. `brand` (copper wordmark) is retired in favour of the IziPOS mark + accent chrome.

3. **Corner-radius scale.** Tokens `--r-panel/-card/-tile/-ctl/-sm/-pill`, swapped by **`data-corner`** = `rounded`(default)|`sharp` (README §6 radius table). Components reference `rounded-[var(--r-card)]` etc. via small token utilities.

4. **Density.** `--grid-cols`/`--list-cols` (6/5 dense, 5/4 comfortable) driven by a `density` setting; consumed by ProductGrid's grid template.

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

**Density-rendering caveat (must verify, not assume):** px figures assume reference density (1 CSS px ≈ 0.265mm). A dense 15" panel (e.g. 1920×1080 ≈ 147ppi) at 100% OS scale renders 1 CSS px ≈ 0.17mm, so 48 CSS px is only ~8mm — below the kiosk floor. **Action:** verify rendered physical mm on the target terminal, and/or run the Tauri webview at a global zoom so CSS px ≈ reference px. Capture this as a deployment note + a `--ui-scale` root multiplier hook so the terminal can be calibrated without per-component edits.

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

## 9. Definition of done (per README §6 DoD)

- Every screen in README §5 re-implemented in both themes via atomic components.
- Parity matrix §7 fully green.
- New surfaces (§8) implemented or explicitly ticketed with notes.
- No hardcoded theme colours; all via tokens (ESLint green).
- Ergonomic type/touch scale applied and contrast-verified in both themes.
- Typecheck, lint, existing tests pass; new components have stories/tests.
