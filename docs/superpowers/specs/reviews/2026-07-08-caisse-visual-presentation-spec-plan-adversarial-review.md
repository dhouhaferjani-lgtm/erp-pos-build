# Adversarial Review — Caisse Visual Presentation Spec + Plan

Severity counts: **BLOCKER 2 / MAJOR 13 / MINOR 4**

Scope reviewed:
- Spec: `docs/superpowers/specs/2026-07-08-caisse-visual-presentation-redesign-design.md`
- Plan: `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md`
- POS code under `apps/pos/src`

## 1. False Code Claims

### [MAJOR] Wrong TransactionCart edit target will send implementers to a non-existent file
Why it matters: Task 6 cannot be executed as written, and the actual cart implementation has the refund footer branch that Task 6 also needs to preserve.

Evidence:
- Plan cites `src/components/pos/TransactionCart.tsx` as the Task 6 file: `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:192`
- That file does not exist in this repository. The live component is exported from `apps/pos/src/components/organisms/TransactionCart/index.ts:1` and implemented at `apps/pos/src/components/organisms/TransactionCart/TransactionCart.tsx:60`

### [MINOR] Customer-chip "all children shrink-0" claim is stale/overbroad
Why it matters: The plan may remove the wrong classes; the current root already has `min-w-0`, and the outer cart wrapper, not the chip root itself, is the confirmed `shrink-0` constraint.

Evidence:
- Spec frames the bug as "loyalty badge + wallet + detach must not all be `shrink-0` on a non-wrapping row": `docs/superpowers/specs/2026-07-08-caisse-visual-presentation-redesign-design.md:145`
- Plan says "remove the outer `shrink-0` that caused collision (`TransactionCart.tsx:137`)": `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:227`
- Actual chip root is `flex min-h-11 min-w-0 ...`, not `shrink-0`: `apps/pos/src/components/customers/CartCustomerControl.tsx:33`
- The actual wrapper around `customerControl` is `flex min-w-0 shrink-0 justify-end`: `apps/pos/src/components/organisms/TransactionCart/TransactionCart.tsx:136`
- Wallet is `shrink-0`, but the loyalty cluster itself is `flex flex-wrap`: `apps/pos/src/components/customers/CartCustomerControl.tsx:48`, `apps/pos/src/components/customers/CustomerLoyaltyBadge.tsx:28`

### [MINOR] The "dual source of truth" claim points at dead/legacy code, not the live ProductGrid
Why it matters: Treating `pos-display-mode` as an active source can import stale values from an unused component into the real settings store.

Evidence:
- Spec says reconcile `pos-display-mode` localStorage vs `settingsStore.displayMode`: `docs/superpowers/specs/2026-07-08-caisse-visual-presentation-redesign-design.md:108`
- Plan Task 14 says read legacy `localStorage['pos-display-mode']` once into the store: `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:403`, `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:416`
- Live HomePage imports the organism grid: `apps/pos/src/pages/HomePage.tsx:55`, `apps/pos/src/pages/HomePage.tsx:1503`
- The live organism reads `settingsStore.displayMode`: `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:89`, `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:92`
- `pos-display-mode` only exists in the older `apps/pos/src/components/pos/ProductGrid.tsx:10`, which no searched production import uses.

## 2. Parity Risks

### [MAJOR] Out-of-stock policy is not consistently modeled across the planned surfaces
Why it matters: The plan claims `hardBlockOutOfStock` parity, but the existing drawer already disables add-to-cart on visual stock state rather than terminal policy; new rows/tables can easily repeat this mismatch.

Evidence:
- Spec says out-of-stock exact match disables `+` only while respecting existing `hardBlockOutOfStock` policy: `docs/superpowers/specs/2026-07-08-caisse-visual-presentation-redesign-design.md:133`
- Plan gives `ProductListRow` a `hardBlockOutOfStock` prop: `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:340`
- HomePage derives that prop from terminal policy: `apps/pos/src/pages/HomePage.tsx:1511`, `apps/pos/src/pages/HomePage.tsx:1512`
- ProductCard respects policy by blocking activation only when `isOutOfStock && hardBlockOutOfStock`: `apps/pos/src/components/molecules/ProductCard/ProductCard.tsx:169`, `apps/pos/src/components/molecules/ProductCard/ProductCard.tsx:172`
- ProductDetailDrawer currently disables its add button whenever `isOut && !exempt`, with no `hardBlockOutOfStock` prop: `apps/pos/src/components/pos/ProductDetailDrawer.tsx:162`, `apps/pos/src/components/pos/ProductDetailDrawer.tsx:165`

### [MAJOR] Payment footer restyle can miss the refund/exchange footer and its payment-config gate
Why it matters: Task 6 only names `PaymentSummary`, but TransactionCart has a separate refund-mode footer with its own cash button and readiness gate; a partial navy/footer refactor will produce inconsistent UI or bypass a prior checkout guard.

Evidence:
- Plan Task 6 applies navy to `PaymentSummary` and says "Confirm no change to cart logic/totals/precision": `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:196`, `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:197`
- Normal sale footer delegates to `PaymentSummary`: `apps/pos/src/components/organisms/TransactionCart/TransactionCart.tsx:323`, `apps/pos/src/components/organisms/TransactionCart/TransactionCart.tsx:329`
- Refund/exchange footer is a separate branch with its own `paymentConfigReady` and button: `apps/pos/src/components/organisms/TransactionCart/TransactionCart.tsx:277`, `apps/pos/src/components/organisms/TransactionCart/TransactionCart.tsx:288`, `apps/pos/src/components/organisms/TransactionCart/TransactionCart.tsx:289`, `apps/pos/src/components/organisms/TransactionCart/TransactionCart.tsx:305`
- The normal `PaymentSummary` readiness gate is separate: `apps/pos/src/components/pos/PaymentSummary.tsx:52`, `apps/pos/src/components/pos/PaymentSummary.tsx:54`, `apps/pos/src/components/pos/PaymentSummary.tsx:119`

### [MINOR] TransactionCart already violates the plan's money/qty precision rule in a touched file
Why it matters: Task 6 touches TransactionCart while the global plan says never use `parseFloat`/`Number()` for money or quantity; leaving this unmentioned risks preserving or copying the wrong pattern.

Evidence:
- Plan global constraint: never `parseFloat`/`Number()` money or quantity: `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:20`
- Actual refund line display parses money with `parseFloat(item.line_total)`: `apps/pos/src/components/organisms/TransactionCart/TransactionCart.tsx:372`

## 3. Accent → Action Reversal

### [MAJOR] Repointing only `[data-accent='green']` will not affect the default caisse theme
Why it matters: The owner-approved "stray accent degrades to blue" will silently not apply unless the user/device already has `accent='green'`; the shipped default is orange.

Evidence:
- Spec resolves Q1 as "point `--accent` → `--action` (blue) for this tenant": `docs/superpowers/specs/2026-07-08-caisse-visual-presentation-redesign-design.md:153`
- Plan Task 1 changes only `:root[data-accent='green']`: `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:87`
- Default theme accent is `orange`: `apps/pos/src/lib/theme.ts:33`, `apps/pos/src/lib/theme.ts:35`
- CSS default also maps bare `:root` to orange accent: `apps/pos/src/index.css:209`, `apps/pos/src/index.css:210`, `apps/pos/src/index.css:211`

### [MAJOR] The accent reversal is global, not caisse-scoped, and the plan misses non-caisse accent users
Why it matters: The same `data-accent` drives Settings, Reports, ShiftClosure, ThemePreview, and the app brand mark; repointing green changes more than caisse selection chrome.

Evidence:
- Spec says keep `data-accent` machinery for other tenants/verticals: `docs/superpowers/specs/2026-07-08-caisse-visual-presentation-redesign-design.md:58`
- Plan says leave orange/blue/teal untouched as "other tenants": `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:87`
- Theme application writes a single global `root.dataset.accent`: `apps/pos/src/lib/theme.ts:45`, `apps/pos/src/lib/theme.ts:47`
- Settings uses accent for active sidebar items: `apps/pos/src/pages/SettingsPage.tsx:216`, `apps/pos/src/pages/SettingsPage.tsx:218`
- Reports uses accent fills/focus rings: `apps/pos/src/pages/ReportsPage.tsx:15`, `apps/pos/src/pages/ReportsPage.tsx:94`
- ShiftClosure uses accent for icon panel, focus, and primary action: `apps/pos/src/pages/ShiftClosurePage.tsx:32`, `apps/pos/src/pages/ShiftClosurePage.tsx:102`, `apps/pos/src/pages/ShiftClosurePage.tsx:124`

### [MAJOR] Brand maps to accent, so "brand-green only where intended" is not implemented by Task 1
Why it matters: If green accent becomes blue, the `text-accent` wordmark also becomes blue unless a separate brand token is introduced.

Evidence:
- Spec Q1 says keep explicit brand-green where deliberately intended: `docs/superpowers/specs/2026-07-08-caisse-visual-presentation-redesign-design.md:153`
- Current token map sets brand to accent: `apps/pos/src/index.css:300`, `apps/pos/src/index.css:301`
- AppShell brand mark uses `text-accent`: `apps/pos/src/components/AppShell.tsx:99`
- Plan Task 1 only repoints accent values and updates a comment; it does not introduce a separate brand token: `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:87`, `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:90`

### [MAJOR] The plan misses real accent usages in ProductDetailDrawer
Why it matters: ProductDetailDrawer is part of the spec, and it still uses accent for a positive add-to-cart button and selected tabs; Task 11 only covers ProductCard.

Evidence:
- Spec says selection/active/in-cart move from accent to action: `docs/superpowers/specs/2026-07-08-caisse-visual-presentation-redesign-design.md:55`, `docs/superpowers/specs/2026-07-08-caisse-visual-presentation-redesign-design.md:56`
- Plan self-review maps color-system work to Tasks 1, 5, and 11 only: `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:530`, `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:531`
- Drawer brand label uses `text-accent`: `apps/pos/src/components/pos/ProductDetailDrawer.tsx:133`
- Drawer add button uses `bg-accent` / `active:bg-accent-strong`: `apps/pos/src/components/pos/ProductDetailDrawer.tsx:169`, `apps/pos/src/components/pos/ProductDetailDrawer.tsx:171`
- Drawer active tab uses `border-accent`: `apps/pos/src/components/pos/ProductDetailDrawer.tsx:193`, `apps/pos/src/components/pos/ProductDetailDrawer.tsx:195`

## 4. DisplayMode Migration

### [BLOCKER] Task 14 cannot pass typecheck before Task 15/16/19 update consumers
Why it matters: Task 14 explicitly requires `pnpm typecheck` clean after widening `settingsStore.displayMode`; current consumers still type and call only `'grid'|'visual'`.

Evidence:
- Plan Task 14 changes store displayMode to `'vitrine'|'liste'|'tableau'` and then runs typecheck: `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:403`, `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:418`
- ProductGrid local `DisplayMode` is still `'grid' | 'visual'`: `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:25`
- ProductGrid setter callback accepts only that local type: `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:167`, `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:168`
- ProductCard prop type is still `'grid' | 'visual'`: `apps/pos/src/components/molecules/ProductCard/ProductCard.tsx:36`
- SettingsPage still calls `setDisplayMode('grid')` and `setDisplayMode('visual')`: `apps/pos/src/pages/SettingsPage.tsx:245`, `apps/pos/src/pages/SettingsPage.tsx:258`
- ThemePreview casts to `setDisplayMode(m as 'grid' | 'visual')`: `apps/pos/src/pages/ThemePreviewPage.tsx:210`, `apps/pos/src/pages/ThemePreviewPage.tsx:213`

### [MAJOR] "Adopt legacy localStorage if store is default" cannot distinguish default from a real saved choice
Why it matters: A cashier who intentionally uses the default `grid` can be overwritten by stale `pos-display-mode` from a legacy/dead component.

Evidence:
- Plan Task 14 says adopt and clear `pos-display-mode` "if store is default": `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:416`
- Store default is `displayMode: 'grid'`: `apps/pos/src/stores/settingsStore.ts:59`, `apps/pos/src/stores/settingsStore.ts:60`
- Persist config has no `version` or `migrate` today to tell old from current persisted state: `apps/pos/src/stores/settingsStore.ts:127`, `apps/pos/src/stores/settingsStore.ts:128`
- The old localStorage key is only in the legacy component: `apps/pos/src/components/pos/ProductGrid.tsx:10`, `apps/pos/src/components/pos/ProductGrid.tsx:36`, `apps/pos/src/components/pos/ProductGrid.tsx:43`

## 5. Virtualizer Risk

### [MAJOR] ProductTable's planned interface conflicts with ProductGrid's row virtualizer shape
Why it matters: `ProductTable({ products })` wants a whole list/table, while current ProductGrid virtualizes rows by slicing `filteredProducts` into `rowProducts`; nesting a full table into that model either duplicates data or bypasses measurement.

Evidence:
- Plan Task 13 defines `ProductTable({ products, ... })`: `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:370`, `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:371`
- Plan Task 15 says ProductGrid consumes `ProductTable` and keeps `measureElement`: `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:427`, `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:428`
- Current ProductGrid computes `rowCount` from `filteredProducts.length / columns`: `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:325`, `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:326`
- Current virtual rows slice products per row: `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:655`, `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:656`, `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:657`
- Current dynamic measurement is attached to each absolute-positioned row: `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:660`, `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:662`

## 6. Equivalents Surfacing

### [BLOCKER] Scan results never reach ProductGrid, so Task 17 cannot implement "search/scan result groups" without new state flow
Why it matters: The plan says "restyle in place" and "no new business logic", but surfacing scan equivalents requires changing HomePage scan routing, which currently immediately adds, opens a picker, shows chooser, or errors.

Evidence:
- Spec says on a resolved search/scan render matched product then equivalents/complements: `docs/superpowers/specs/2026-07-08-caisse-visual-presentation-redesign-design.md:130`, `docs/superpowers/specs/2026-07-08-caisse-visual-presentation-redesign-design.md:132`
- Plan Task 17 says ProductGrid renders groups when a search/scan resolves a single primary product: `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:456`, `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:478`
- HomePage routes scan result immediately through `routeScanResult`: `apps/pos/src/pages/HomePage.tsx:473`, `apps/pos/src/pages/HomePage.tsx:476`
- `routeScanResult` hit path adds or opens variant picker; choose path opens chooser; miss shows error: `apps/pos/src/lib/scan/routeScanResult.ts:29`, `apps/pos/src/lib/scan/routeScanResult.ts:33`, `apps/pos/src/lib/scan/routeScanResult.ts:38`, `apps/pos/src/lib/scan/routeScanResult.ts:40`
- ProductGrid props have no scan-result input/state today: `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:35`, `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:69`

### [MAJOR] "Resolved search" is not a current concept in ProductGrid
Why it matters: Current search is substring filtering; deciding a single primary match is new matching/ranking behavior and can change search results or scan parity.

Evidence:
- Spec cites `ProductGrid.tsx:276` as matches on name/sku/barcode: `docs/superpowers/specs/2026-07-08-caisse-visual-presentation-redesign-design.md:132`
- Actual code is a simple lower-case substring filter over all products: `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:276`, `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:277`, `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:278`, `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:280`
- Scan resolution has four result kinds and multiple tiers, not a ProductGrid-local search result: `apps/pos/src/lib/scan/resolveScannedCode.ts:66`, `apps/pos/src/lib/scan/resolveScannedCode.ts:69`, `apps/pos/src/lib/scan/resolveScannedCode.ts:85`

### [MINOR] The claimed merchandising symbols do exist
Why it matters: No finding on existence; the implementation risk is integration/gating, not missing symbols.

Evidence:
- `ParapharmacyMeta.equivalent_product_ids` / `complement_product_ids`: `apps/pos/src/types/product.ts:9`, `apps/pos/src/types/product.ts:11`, `apps/pos/src/types/product.ts:12`
- `productStore.getByIds`: `apps/pos/src/stores/productStore.ts:47`, `apps/pos/src/stores/productStore.ts:53`, `apps/pos/src/stores/productStore.ts:498`
- `hasModule`: `apps/pos/src/stores/productStore.ts:116`
- Drawer gate is `hasMerchandising && meta != null`: `apps/pos/src/components/pos/ProductDetailDrawer.tsx:54`, `apps/pos/src/components/pos/ProductDetailDrawer.tsx:57`, `apps/pos/src/components/pos/ProductDetailDrawer.tsx:79`

## 7. Missing Tasks / Spec Gaps

### [MAJOR] Near-expiry chip slot is specified but explicitly not implemented by the plan
Why it matters: The spec says this track reserves the visual slot; the plan's self-review says the matrix is enough, which does not create a slot in any component.

Evidence:
- Spec non-goal still requires reserving the chip visual slot: `docs/superpowers/specs/2026-07-08-caisse-visual-presentation-redesign-design.md:38`
- Field matrix requires near-expiry chip slot in all modes: `docs/superpowers/specs/2026-07-08-caisse-visual-presentation-redesign-design.md:126`
- Plan self-review says "chip slot reserved (matrix, not implemented)": `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:542`
- ProductCard current footer only renders price/stock and incoming badge; no reserved expiry slot: `apps/pos/src/components/molecules/ProductCard/ProductCard.tsx:394`, `apps/pos/src/components/molecules/ProductCard/ProductCard.tsx:423`, `apps/pos/src/components/molecules/ProductCard/ProductCard.tsx:435`

### [MAJOR] The plan invents `tokens.section` but does not account for current token consumers
Why it matters: Header/Nav/Cart tasks depend on `tokens.section.*`, but the current token module has no such group; any task consuming it before Task 2 lands will fail.

Evidence:
- Plan Task 2 produces `tokens.section.{header,rail,canvas,cartPanel,footer}`: `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:100`, `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:103`
- Header task consumes `tokens.section.header`: `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:161`
- Nav task consumes `tokens.section.rail`: `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:185`
- Current `tokens` exports `button`, `badge`, `segmented`, `statusPill`, `disabledReason`, `surface`, and `money`, but no `section`: `apps/pos/src/lib/designTokens.ts:54`, `apps/pos/src/lib/designTokens.ts:100`, `apps/pos/src/lib/designTokens.ts:108`

## 8. Test Quality

### [MAJOR] ProductGrid tests mock out the dynamic measurement that Task 15 must preserve
Why it matters: A mode-routing test can pass while the real virtualizer reintroduces fixed-row clipping; the existing unit setup never exercises `measureElement`.

Evidence:
- Plan Task 15 says virtualizer keeps `measureElement` dynamic rows: `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:428`
- Existing ProductGrid test mock replaces TanStack virtualizer and never exposes or asserts `measureElement`: `apps/pos/src/components/organisms/ProductGrid/__tests__/ProductGrid.test.tsx:128`, `apps/pos/src/components/organisms/ProductGrid/__tests__/ProductGrid.test.tsx:141`, `apps/pos/src/components/organisms/ProductGrid/__tests__/ProductGrid.test.tsx:142`
- The real component uses `ref={virtualizer.measureElement}`: `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:660`, `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:662`

### [MINOR] Several planned tests are class-string snapshots that can go green without proving layout
Why it matters: The plan is trying to fix clipping and hit targets, but class assertions alone do not prove text fits or buttons are actually >= 40/48/64px in the rendered layout.

Evidence:
- Button task asserts className contains `whitespace-nowrap`, `truncate`, and min-height strings: `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:121`, `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:132`, `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:138`
- QuickActions task asserts button className contains `min-w-0`: `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:240`, `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:242`
- Existing ProductCard tests already lock exact old sizes that the redesign wants to change, including 29px eye buttons and 10px brand labels: `apps/pos/src/components/molecules/ProductCard/__tests__/ProductCard.test.tsx:186`, `apps/pos/src/components/molecules/ProductCard/__tests__/ProductCard.test.tsx:187`, `apps/pos/src/components/molecules/ProductCard/__tests__/ProductCard.test.tsx:211`

## 9. Ordering / Dependency Hazards

### [BLOCKER] Task 14 consumes future display-mode values before the rendering tasks exist
Why it matters: After Task 14, `settingsStore.displayMode` can be `'liste'` or `'tableau'`, but ProductGrid, ProductCard, cardSizing, SettingsPage, and ThemePreview still only handle `'grid'|'visual'` until later tasks.

Evidence:
- Task 14 produces tri-mode values and runs typecheck: `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:403`, `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:418`
- Task 15, which updates ProductGrid routing, comes later: `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:420`, `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:432`
- Current card sizing helpers accept only `'grid'|'visual'`: `apps/pos/src/components/molecules/ProductCard/cardSizing.ts:53`, `apps/pos/src/components/molecules/ProductCard/cardSizing.ts:54`, `apps/pos/src/components/molecules/ProductCard/cardSizing.ts:74`, `apps/pos/src/components/molecules/ProductCard/cardSizing.ts:75`
- Current ProductGrid options are only grid/visual: `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:174`, `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:177`, `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:182`

### [MAJOR] Task 17 depends on a "resolved primary product" signal that no previous task creates
Why it matters: The plan orders `SearchResultGroups` after display modes, but no task before it defines how ProductGrid will know whether a free-text search or scan has a primary matched product.

Evidence:
- Task 17 says "when the search query resolves to a primary match (or a scan `hit`)": `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:478`
- Tasks 12-16 create rows/table/mode settings, not a scan/search-resolution state contract: `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:333`, `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:364`, `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:396`, `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:420`, `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md:436`
- Current search query is local component state with no exported primary-match signal: `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:97`, `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:276`
- Current scan hit is consumed inside HomePage and not stored for ProductGrid: `apps/pos/src/pages/HomePage.tsx:463`, `apps/pos/src/pages/HomePage.tsx:476`, `apps/pos/src/pages/HomePage.tsx:508`
