# Adversarial Review — Caisse Visual & Presentation Redesign (Spec + Plan)

> Reviewer: Claude (Opus 4.8, 1M) · 2026-07-08 · gates execution, does not merge
> Targets:
> - `docs/superpowers/specs/2026-07-08-caisse-visual-presentation-redesign-design.md`
> - `docs/superpowers/plans/2026-07-08-caisse-visual-presentation-redesign.md`
> Code verified against the LIVE app at `apps/pos/` (worktree `apps/erp/apps/pos`). Every citation below was read; where I could not confirm a claim I say so.

## Severity counts
- **BLOCKER: 2**
- **MAJOR: 9**
- **MINOR: 6**

Verdict: **CHANGES REQUIRED before execution.** The color/chrome/button/badge restyle tasks (1–11) are sound and mostly well-cited. The tri-density engine (Tasks 14/15/13) and equivalents-on-scan (Task 17) — the spec's headline features — are architecturally under-specified against the real ProductGrid/virtualizer/scan pipeline, and several audit premises are stale (already fixed in code) or mis-located. Three unit tests are false-greens.

---

## BLOCKERS

### B1 — Tri-density is grafted onto a `'grid'|'visual'` virtualizer with no migration design (Tasks 14→15→13→11)
**Why it matters:** the core deliverable will not typecheck and the virtualizer will regress the exact P3 clip bug the spec swears to avoid.

Evidence:
- `settingsStore.displayMode` is typed `'grid' | 'visual'` (`settingsStore.ts:13,37,60,74`). Task 14 widens it to `'vitrine'|'liste'|'tableau'`.
- The live `ProductGrid` declares its OWN local `type DisplayMode = 'grid' | 'visual'` (`organisms/ProductGrid/ProductGrid.tsx:25`) and feeds `displayMode` straight into:
  - `getColumns(displayMode, density, …)` / `getCardMinH(displayMode, density)` whose signatures are `'grid' | 'visual'` only (`ProductCard/cardSizing.ts:54,75`) and which drive the virtualizer row math (`ProductGrid.tsx:145-165,325-333`);
  - `<ProductCard displayMode={displayMode}>` where the prop is `'grid' | 'visual'` (`ProductCard.tsx:36,686`);
  - the `SegmentedControl` options `value:'grid'|'visual'` (`ProductGrid.tsx:174-188`).
- Widening the store type (Task 14, Phase 8) while Tasks 11 (ProductCard) and cardSizing keep the two-value type → **type errors across ProductGrid/ProductCard/cardSizing**. No task reconciles `getColumns`/`getCardMinH`/`ProductCard.displayMode` to the tri-mode.
- The virtualizer renders `rowCount = ceil(len/columns)` rows, each a CSS grid slicing `filteredProducts.slice(startIdx, startIdx+columns)` measured with `measureElement` (`ProductGrid.tsx:655-691`). **Liste** (1 column) and especially **Tableau** (a `<table>` with a header row + ↑↓/⏎ keyboard nav, Task 13) do not fit this "N-columns-per-virtual-row" model. Task 15 Step 3 only says "ensure virtualization works for list/table (content-sized)" with no design for how a table header + row virtualization plugs into this loop. The spec itself flags this (§14) but the plan does not resolve it.

Fix: define the DisplayMode type migration end-to-end (store + cardSizing + ProductCard + ProductGrid), and specify how each mode virtualizes (columns=1 for Liste; a real table/row-virtualizer strategy for Tableau, or an explicit non-virtualized fallback with a row cap).

### B2 — Equivalents-on-scan (Task 17 / spec §8.3) is not wireable in ProductGrid as written
**Why it matters:** the "parapharmacy value moment" cannot be delivered by the code path the plan names, and the interface can't honor the stock policy.

Evidence:
- All scan resolution lives in **HomePage**, not ProductGrid: `useBarcodeScanner` → `handleBarcodeScan` → `handleProductBarcode` → `resolveScannedCode` → `routeScanResult` (`HomePage.tsx:432-583`). A `hit` is added straight to the cart via `addProductToCartWithToast` (gated by `autoAddToCart`), a `choose` opens `BarcodeChooserModal`, a `miss` toasts. **ProductGrid never receives a scan result.** Task 17 says render groups "when a search/scan resolves a single primary product" inside ProductGrid — the scan branch has no hook point there.
- With `autoAddToCart` on (the default flow), a scanned hit is auto-added and no result surface renders at all; if it's out-of-stock+block, `addItemGated` returns false and toasts — nothing shows equivalents. The plan does not address this interaction.
- The search box is internal to ProductGrid (`ProductGrid.tsx:97,442-448`) and is a **multi-match substring filter** over name·sku·barcode (`ProductGrid.tsx:276-284`). There is no definition of "resolves to a single primary product," so the trigger for switching from the flat grid to `SearchResultGroups` is undefined.
- `SearchResultGroups`'s own test signature (`plan Task 17 Step 1`) is `{matched, equivalents, complements, onAddToCart}` — **no `hardBlockOutOfStock` prop**, yet §8.3 requires the matched-out-of-stock `+` to "respect the existing `hardBlockOutOfStock` policy." It cannot.

Fix: move the surfacing decision to HomePage (which owns scan + search intent + policy), pass the resolved primary product + policy down, and define the exact trigger (exact sku/barcode match, or an explicit "1 result" search). Thread `hardBlockOutOfStock`.

---

## MAJOR

### M1 — Plan names a non-existent file path for TransactionCart (Tasks 6, 7; File Structure)
`plan` File Structure and Tasks 6/7 reference `src/components/pos/TransactionCart.tsx`. **That file does not exist.** The live component is `src/components/organisms/TransactionCart/TransactionCart.tsx` (imported by `HomePage.tsx:55`). Line 137 there IS the offending wrapper (`<div className="flex min-w-0 shrink-0 justify-end">{customerControl}</div>`), so the line number is right but the path is wrong — an executor will edit/create the wrong file. Fix the path.

### M2 — "Display-mode dual source of truth" is already resolved; the reconcile is dead work (Task 14; spec §8.1, §14)
The live `ProductGrid` already reads a single source: `settingsStore.displayMode`, with the explicit comment *"Task 25: replaced localStorage dual-source with store subscription"* (`organisms/ProductGrid/ProductGrid.tsx:89-96`). The `pos-display-mode` localStorage key exists **only in dead code** — `src/components/pos/ProductGrid.tsx:10` (`DISPLAY_MODE_STORAGE_KEY = 'pos-display-mode'`), which is imported nowhere (grep for imports = 0 hits). Task 14 Step 3's "if legacy `localStorage['pos-display-mode']` exists … adopt+clear it" and the §8.1/§14 dual-source risk are moot. Drop it (or, if truly wanted, delete the dead `components/pos/ProductGrid.tsx`).

### M3 — Task 1 repoint turns the brand wordmark blue and unaddressed on navy (spec §11-Q1, Tasks 1 & 4)
`--color-brand: var(--accent)` (`index.css:301`) and the Header wordmark uses `text-brand` (`Header.tsx:548`). Task 1 repoints `--accent`→blue for `[data-accent='green']` but never touches `--color-brand`. Consequences: (a) the wordmark turns **blue**, directly contradicting spec Q1 which says keep "an explicit brand-green … e.g. wordmark"; (b) after Task 4 paints the header navy `#14283f`, blue `#1a6fb5` on navy is a low-contrast AAA regression on the wordmark. Neither Task 1 nor Task 4 mentions `--color-brand`. Add an explicit brand token decision.

### M4 — Persist migration can wipe all other settings (Task 14)
`settingsStore` persist config today has **no `version` and no `migrate`** (`settingsStore.ts:127-135`). Zustand's `migrate(persistedState, version)` must return the FULL merged state or it replaces it. Task 14 Step 3 and its test (`migrateDisplayMode` only) specify nothing about preserving `touchMode/theme/accent/corner/density/cartPosition/…`. A naive migrate returning `{displayMode}` wipes every other persisted setting on upgrade. Also note: migrating `'grid'→'liste'` **flips every existing user's default view** on first launch (behavior change, acceptable but call it out). Specify a spread-preserving migrate + a test asserting unrelated keys survive.

### M5 — Task 9 ProductThumb test is a false green
The hardcoded `FULL_WIDTH_NEUTRAL_TINT_CLASS = 'bg-[#eef3f8] text-[#5e6670]'` (`ProductThumb.tsx:60`) is used **only when `fullWidth && tint === 'neutral'`** (`ProductThumb.tsx:93`). The plan's test renders `<ProductThumb name="…" category="corps" />` — `category="corps"` maps to tint `'corps'` and `fullWidth` defaults false, so the hardcoded branch is never taken and `expect(...).not.toContain('#eef3f8')` **passes with zero code change**. To actually gate the fix, render with `fullWidth` and no/neutral category. (Citation of line 60 itself is correct.)

### M6 — Task 5 NavRail test is a false green and the duplicate-label fix is mis-located
- NavRail renders `<button>` elements (`NavRail.tsx:57`), so `getAllByRole('link')` returns `[]` → `new Set([]).size === 0 === labels.length` passes **vacuously**.
- NavRail is **presentational** — it renders `item.label` (`NavRail.tsx:73`); it does not author labels. The real duplicate is data: `nav.caisse = "Caisse"` AND `nav.shift = "Caisse"` (`locales/fr/pos.json:1036,1039`), wired by `AppShell.tsx:82-87`. The fix is the i18n value (+ AppShell), which the plan does do in `fr/pos.json` — but the "rename in `NavRail.tsx`" framing and the test are wrong. Test AppShell's rendered items, or assert on the i18n values.

### M7 — Task 7 CartCustomerControl audit premise is stale; test is a false green (spec §1, §10.1)
The overflow fix the spec describes is **already in place**: the name button is `min-w-0 truncate` (`CartCustomerControl.tsx:38`) and the chip wrapper is `min-w-0` (line 33), not `shrink-0` (this file was reworked by the deposit-discoverability PR). So spec §1/§10.1's claim that CartCustomerControl:33 is the `shrink-0` offender is **false**. The real remaining `shrink-0` is on the customer-control wrapper in `organisms/TransactionCart/TransactionCart.tsx:137`. The plan's test asserts `root.className` not contain `shrink-0` on CartCustomerControl's root — which already passes — and requires new testids (`cart-customer-control`, `customer-name`) that don't exist. It never touches the component that actually holds `shrink-0`. Retarget the fix + test at TransactionCart:137.

### M8 — `useStockDisplay` extraction is a cross-task edit + parity-sensitive (Tasks 11→12)
Task 12 (Phase 6) says extract the shared stock derivation from ProductCard into `useStockDisplay.ts` and "reuse in both." But Task 11 (Phase 5, earlier) restyles ProductCard without creating the hook, so Task 12 must re-open an already-"done" file. More importantly, ProductCard's stock logic has **three parity-sensitive branches** — `undefined` legacy (`stock_quantity`), `null` exempt (no chrome, never gated), and object location-aware (`bccomp` on decimal strings) (`ProductCard.tsx:114-164`). Extracting and rewiring risks regressing the exempt/legacy paths. The plan gives no test for the three-way behavior of the extracted hook. Sequence the extraction before/inside Task 11 and add a hook parity test covering all three slices.

### M9 — Out-of-stock policy parity for the new surfaces (Tasks 12, 17)
ProductCard only refuses activation under `'block'`: `isActivationBlocked = isOutOfStock && hardBlockOutOfStock`, and under `'warn'/'off'` the tap must reach the stock gate (`ProductCard.tsx:169-193`). The `posStockPolicy` comes from `terminal.pos_stock_policy` (`HomePage.tsx:116`). The plan's `ProductListRow` test only asserts the **block** case disables `+`; it never asserts that under warn/off the `+` stays tappable, so the obvious implementation ("disable when out-of-stock") silently breaks warn/off parity. `SearchResultGroups` has no policy prop at all (see B2). Add the warn/off case to the row test and thread the policy into both.

---

## MINOR

### m1 — Button §6 citation is stale (spec §1, §6)
`md` is **already 48px** (`Button.tsx:49`, comment "md = 48px … (was 44px)"), not the "md44" the spec claims (the doc header comment lines 22-23 is the stale text, not the code). Base `Button` padding is **already size-driven** (`sm px-3 / md px-4 / lg px-5`, `Button.tsx:47-50`), so "fixed px-4" is wrong for Button — the fixed `px-4` actually lives in the `designTokens.ts` button recipes (`designTokens.ts:25,31,37,50`). The only true base-Button gap is the missing `whitespace-nowrap` (`Button.tsx:90`). Net target values (sm40/md48/lg64) are fine; just fix the premise.

### m2 — StockBadge is already a unified single pill (spec §7)
`StockBadge` already renders one pill shape with `whitespace-nowrap` and caller-supplied text (`StockBadge.tsx:34-48`). The only inconsistency is the **caller wording**: low uses `t('products.lowStock')` ("Stock faible", no number) on both branches (`ProductCard.tsx:124,133`). Task 10 Step 2 correctly targets the caller, but must change **both** the legacy (`:122-126`) and location-aware (`:130-136`) branches, and the Step-1 badge test (passing literal `'Stock 12'` children) asserts nothing about the fix. Attribute the bug to the caller, not StockBadge.

### m3 — `getByIds` line citation off (spec §8.3)
`getByIds` is declared at `productStore.ts:53` (interface) / `498` (impl), not `:49` as cited. Data-layer facts are otherwise correct: `equivalent_product_ids`/`complement_product_ids` on `ParapharmacyMeta` (`types/product.ts:11-12`), the drawer gates on `showMerchandising = hasMerchandising && meta != null` and resolves via `getByIds` (`components/pos/ProductDetailDrawer.tsx:79-82`), `hasModule(config, moduleName)` (`productStore.ts:116`). Note the live drawer is `components/pos/ProductDetailDrawer.tsx` re-exported by `organisms/ProductDetailDrawer/index.ts` — plan Task 18's path is correct.

### m4 — Duplicate display i18n keys already exist; new keys undefined (Tasks 15/16)
`fr/pos.json` already has `gridMode`/`visualMode` in two blocks (`:549-550` "Grille"/"Visuel" and `:711-712` "Vue grille"/"Vue images"). The plan adds tri-mode labels but never enumerates the new keys (e.g. `display.vitrine/liste/tableau`). Enumerate them and reconcile the existing duplicated keys so ESLint/i18n guards stay clean.

### m5 — `resolveDefaultMode()` may mis-detect a desktop touchscreen (Task 14)
Auto-pick via `window.matchMedia('(pointer: coarse)')` can report `fine` on a 15" desktop touchscreen with a mouse attached, landing on **Tableau** where the spec wants **Liste** on touch. Treat the auto-pick as a soft default and make the persisted user choice authoritative (the plan does persist, but validate the first-run heuristic on the target hardware).

### m6 — "Cart icon clipped at screen edge" fix is mis-located (spec §4/§10.6, Task 6)
The ShoppingCart icon sits at the LEFT of the cart header, already inside `px-3` padding (`TransactionCart.tsx:126-128`). Task 6/spec point the icon-clip fix at "wrapper ~137", but line 137 is the RIGHT-side customer-control wrapper. Re-derive where the icon actually clips (if at all) before adding padding at the wrong node.

---

## Spot-check of the reviewer's explicit citation list
- ProductCard price in `text-accent-strong` ~line 409 — **TRUE** (`ProductCard.tsx:409`).
- `designTokens.ts:14` says "prices use `text-ink`, never accent" — **TRUE** (`designTokens.ts:14`).
- `--success` and `--stock-ok` same hex — **TRUE** (`index.css:77` and `:94` both `#1f8a5b`; accent green `:220` also `#1f8a5b`).
- CartCustomerControl badges/wallet/detach + wrapper all `shrink-0` @33 / TransactionCart @137 — **MIXED**: wallet/user-icon are `shrink-0` (`:34,48`), but the CartCustomerControl **wrapper @33 is `min-w-0`, not `shrink-0`**, and the name is already `truncate`; the real `shrink-0` wrapper is `TransactionCart.tsx:137`. See M7.
- Button lacks `whitespace-nowrap` — **TRUE** (`:90`); "fixed px-4" — **FALSE** (size-driven; fixed px-4 is in designTokens recipes). See m1.
- NavRail renders two "Caisse" — **misattributed**: NavRail is presentational; duplicate is `fr/pos.json:1036/1039` + `AppShell.tsx:82-87`. See M6.
- ProductThumb hardcodes `bg-[#eef3f8] text-[#5e6670]` ~line 60 — **TRUE** (`:60`), but only reachable via `fullWidth && neutral`. See M5.
- accent `@theme inline` per-`[data-accent]` override works at runtime — **TRUE**: utilities inline `var(--accent)` via `--color-accent: var(--accent)` (`:294`) overridden under `:root[data-accent='green']` (`:219-227`); `ez-tap`/`--accent-ring` (`:439`) and focus ring (`:389`) also read `--accent` and will follow the repoint (consistent with Strategy A). The only missed accent-derived token is `--color-brand` (M3).
</content>
</invoke>
