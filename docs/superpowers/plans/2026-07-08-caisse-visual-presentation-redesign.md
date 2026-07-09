# Caisse Visual & Presentation Redesign — Implementation Plan (Track B, Spec 1)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> ⛔ **EXECUTION GATE — DO NOT START until the concurrent POS visual-revamp session has landed on `origin/dev`.** First action of the executing session: create a worktree off the **latest** `origin/dev` (which by then includes Track A enrichment images + the other session's revamp), then reconcile this plan against any files that session restyled — treat its output as the new baseline. Never resume the stale `feat/pos-caisse-redesign` branch.

**Goal:** Rework the IziPOS caisse (`apps/pos`) into a calm, high-contrast, section-separated 15" touchscreen checkout where one color = one job, spacing is purposeful, and product presentation switches between three densities (Vitrine / Liste / Tableau) — with equivalents surfaced inline — while preserving 100% of existing behavior.

**Architecture:** Frontend-only. Extend the existing Tailwind v4 `@theme inline` token system (`index.css`) and `components/ui` atoms; restyle organisms **in place** behind the `components/organisms` facade; preserve all Zustand stores, hooks, Tauri IPC, offline/sync/shift/receipt flows. No backend, sync, or SQLite change.

**Tech Stack:** React 19 · Vite 7 · TypeScript strict · Tailwind CSS 4 · Zustand 5 · TanStack Query 5 · lucide-react · react-i18next · Vitest (jsdom) · Tauri 2. Visual verification via `/theme-preview` + `playwright-core` (system Chrome).

**Spec:** `docs/superpowers/specs/2026-07-08-caisse-visual-presentation-redesign-design.md` (read it first, incl. its **Rev 2 reconciliation** block, which is authoritative).

## Rev 2 reconciliation (2026-07-09) — READ FIRST, overrides the task bodies below

Folded from two adversarial reviews (`specs/reviews/2026-07-08-caisse-visual-presentation-spec-plan-adversarial-review*.md`). Where this block and a task body disagree, this block wins.

- **Task 17 (SearchResultGroups) is DELETED** — equivalents-on-scan is deferred (owner 2026-07-09); the detail drawer's existing equivalents tab suffices. Also remove the `SearchResultGroups` fixture from Task 19 and any "render groups" wording from Task 15. `useStockDisplay` (extracted in Task 12) is still used by Liste/Tableau.
- **Task 1 does NOT repoint `--accent`.** Repointing hits brand/Settings/Reports/ShiftClosure globally and the default accent is orange, not green. Task 1 = (a) focus ring `*:focus-visible` → `var(--action)` (`index.css:389`), (b) color-grammar doc comment, (c) `tokens.section` helpers (was Task 2 — merge). Leave `--accent`, `--color-brand`, and every `[data-accent]` block untouched. Selection→blue is delivered by switching *caisse surfaces* to `--action` tokens directly: ProductCard (Task 11), NavRail active (Task 5), TransactionCart in-cart, ProductDetailDrawer (Task 18).
- **Task 5 targets the RIGHT duplicate-label source.** The two "Caisse" labels are in `src/locales/fr/pos.json:1036/1039` + `AppShell.tsx:82-87`, **not** `NavRail`. Rename one there. Replace the vacuous `getAllByRole('link')` test with one asserting the two nav destinations render distinct text (query by the actual rendered role/testid). Nav active state → `--action`.
- **Task 6/7 path fix:** `TransactionCart` = `src/components/organisms/TransactionCart/TransactionCart.tsx` (NOT `components/pos/`). Task 6 must also restyle the **refund-mode footer** branch (`:277-305`), not only `PaymentSummary`, and fix the existing `parseFloat(item.line_total)` at `:372` (use `formatCurrency`/decimal string). Task 7: the chip root is already `min-w-0`; fix the outer wrapper `TransactionCart.tsx:136` (`shrink-0`) + the `shrink-0` wallet so points+balance don't force overflow (the loyalty cluster already `flex-wrap`s). Replace the vacuous chip test.
- **Task 9 test must hit the placeholder branch** — render the no-image path that actually emits the hardcoded `bg-[#eef3f8]`/`text-[#5e6670]` (check whether `fullWidth` / a specific branch is required in `ProductThumb.tsx:60`); the prior test (`category="corps"`, no `fullWidth`) never reached it.
- **Task 14 is ATOMIC + no legacy adopt.** Widen `displayMode` to `'vitrine'|'liste'|'tableau'` **and** update every consumer in the same task — `ProductGrid` local `DisplayMode` (`:25`), setter (`:167-168`), `ProductCard.displayMode` (`:36`), `cardSizing.getColumns`/`getCardMinH` (`:53-75`), `SettingsPage.setDisplayMode` calls (`:245/258`), `ThemePreviewPage` cast (`:210/213`) — so `pnpm typecheck` stays green. Add persist `version` bump + `migrate` (`grid→liste`, `visual→vitrine`). **Delete** the `pos-display-mode` legacy-adopt step (dead code; ProductGrid already single-sources the store).
- **Task 15 needs an explicit per-mode virtualization design.** Liste = 1 product per virtual row (existing measured-row model). **Tableau** = a real `<table>`; virtualize `<tbody>` rows (or a windowed table) with a sticky header + ↑↓/⏎ keyboard nav — never fixed row heights (P3 clip). The existing ProductGrid unit test **mocks the virtualizer**, so it cannot catch clipping — Task 20 playwright with ≥200-item fixtures per mode is the real gate.
- **Task 18 (drawer) also removes accent + aligns policy.** Repoint drawer add button (`:169/171`), active tab (`:193/195`), brand label (`:133`) → `--action`. Align out-of-stock: today it disables add on `isOut && !exempt` with no `hardBlockOutOfStock` (`:162/165`) — make it respect the terminal policy like ProductCard (note this small behavior change in the task).
- **Add a real near-expiry reserved slot** — a conditional render point in ProductCard/ProductListRow/ProductTable that renders `null` until Spec 2 (so §8.2's "reserved" is real, not just a matrix row).
- **Task 2 is merged into Task 1** (both are token-foundation); renumber or keep Task 2 as the `tokens.section` sub-step of Task 1. Ensure `tokens.section.*` lands before its consumers (Tasks 4/5/6).

## Global Constraints

- **Parity is law** — restyle in place; never rewrite business logic. Import organisms via `@/components/organisms`; restyle the real impl behind the facade.
- **Tokens only** — no hardcoded hex / raw Tailwind palette classes. ESLint color guard must stay clean. Extend `index.css` + `designTokens.ts` + `design-language.md`.
- **i18n** — all user-facing copy via `t()` (react-i18next), keys in `src/locales/fr/pos.json`. No hardcoded strings.
- **Money/qty precision** — never `parseFloat`/`Number()` money or quantity; use `formatCurrency`/`formatQuantity` and decimal-string helpers (`bccomp`, `bcsum`). Currency via `useCurrency()`.
- **Color grammar (Strategy A)** — blue (`--action`/`--color-action*`) = all interaction + selection; green (`--success*`/`--stock-ok*`) = stock/money only; ink (`--color-ink`) = prices. Amber = warning/low; red = danger/rupture.
- **Ergonomics** — AAA 7:1 body contrast; tappable ≥ 48px; primary (Encaisser/numpad) = 64px; icon hit area ≥ 40px.
- **Visual verification is mandatory** for every restyle task — jsdom does not lay out. Verify both themes at a 15" viewport (e.g. 1366×768 / 1280×1024) via `/theme-preview` + playwright before marking a visual task done.
- **TanStack query keys** — any new tenant-data query uses `tenantScopedKey([...])`.
- **After a hung vitest run**, kill leftover `node (vitest` worker pools (they OOM the laptop).
- **Commit frequently**, one deliverable per task. Commit trailer: `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`.

---

## File Structure (decomposition)

**Token layer**
- `src/index.css` — repoint accent→action for this tenant, focus ring, verify navy tokens; no palette hex change.
- `src/lib/designTokens.ts` — button size scale, badge recipe note, section-surface helpers.
- `docs/architecture/.../design-language.md` (or the POS design-language doc) — spacing scale, color grammar, stock-badge rule.

**Atoms**
- `src/components/ui/Button.tsx` — label non-clip + size scale.
- `src/components/ui/StockBadge.tsx` — unified format.
- `src/components/ui/ProductThumb.tsx` — token-ize placeholder, contrast.

**Chrome**
- `src/components/Header.tsx` — navy bar + inverse children.
- `src/components/NavRail.tsx` — seam, de-duplicate labels.
- `src/components/pos/PaymentSummary.tsx` — navy footer.
- `src/components/pos/TransactionCart.tsx` — panel elevation, icon clip, wrapper.

**Product surfaces**
- `src/components/molecules/ProductCard/ProductCard.tsx` (+ `cardSizing.ts`) — Vitrine tile restyle, price→ink, in-cart→blue.
- `src/components/organisms/ProductGrid/ProductGrid.tsx` — host the 3 modes + search filter.
- Create `src/components/organisms/ProductGrid/ProductListRow.tsx` — Liste touch row.
- Create `src/components/organisms/ProductGrid/ProductTable.tsx` — Tableau desktop dense.
- Create `src/components/organisms/ProductGrid/useDisplayMode.ts` — mode resolution/persistence (or fold into settingsStore selector).

**Search/equivalents**
- `src/components/organisms/ProductGrid/SearchResultGroups.tsx` (new) — matched → equivalents → complements grouping.
- Consumes `productStore.getByIds`, `ParapharmacyMeta`, `hasModule`.

**Detail drawer**
- `src/components/pos/ProductDetailDrawer.tsx` — restyle + Stock&lots / Autres officines tab shells.

**Customer chip / quick actions**
- `src/components/customers/CartCustomerControl.tsx`, `CustomerLoyaltyBadge.tsx`
- `src/components/molecules/QuickActions/QuickActions.tsx`

**State**
- `src/stores/settingsStore.ts` — `displayMode` → `'vitrine'|'liste'|'tableau'` + migration; reconcile `pos-display-mode` localStorage.
- `src/pages/SettingsPage.tsx` — appearance/mode + optional-field toggles.

**Harness**
- `src/pages/ThemePreviewPage.tsx` — seeded Vitrine/Liste/Tableau + search-result fixture.

---

## Phase 0 — Token foundation (Strategy A)

### Task 1: Repoint accent→action, focus ring, verify navy

**Files:**
- Modify: `src/index.css` (accent block ~206-249; focus-visible ~389)
- Modify: `src/lib/designTokens.ts` (doc comment for color grammar)
- Test: `src/index.css` changes are verified visually (no unit test); add/adjust a `/theme-preview` swatch section.

**Interfaces:**
- Produces: color grammar where selection/active/in-cart read from `--color-action*`; `--accent` resolves to the action blue for this tenant.

- [ ] **Step 1:** In `index.css`, under `:root[data-accent='green']`, set `--accent`, `--accent-strong`, `--accent-ring`, `--accent-tint-light/dark` to the **action-blue** values (`--accent: #1a6fb5; --accent-strong: #155c9a; --accent-ring: rgba(26,111,181,.16); --accent-tint-light: #e8f4fc;`). (Owner decision Q1 — stray accent degrades to blue.) Leave orange/blue/teal blocks untouched (other tenants).
- [ ] **Step 2:** Change `*:focus-visible` outline from `var(--accent)` to `var(--action)` (`index.css:389`).
- [ ] **Step 3:** Confirm `--pay-navy #14283f` / `--pay-navy-fg` exist and are theme-constant (they are, `index.css:72-75`); note in the design-language doc that navy is the chrome anchor.
- [ ] **Step 4:** Update the `designTokens.ts` header color-grammar comment to state Strategy A explicitly (blue = interaction+selection; green = stock/money; ink = prices).
- [ ] **Step 5:** Run `pnpm dev`, open `/theme-preview`, screenshot swatches in light+dark; confirm accent renders blue. Run `pnpm lint` (color guard) — expect clean.
- [ ] **Step 6:** Commit: `style(pos): repoint caisse accent→action (Strategy A color grammar)`.

### Task 2: Spacing scale + section-surface helpers (doc + tokens)

**Files:**
- Modify: `src/lib/designTokens.ts` (add `section` surface helpers), the POS design-language doc.

**Interfaces:**
- Produces: `tokens.section.{header,rail,canvas,cartPanel,footer}` class strings (navy header/footer, raised+bordered rail/cart, recessed canvas) reused by chrome tasks.

- [ ] **Step 1:** Document a 4px spacing scale (4·8·12·16·20·24) in the design-language doc with the rule "tighten intra-group spacing; keep inter-section spacing."
- [ ] **Step 2:** Add to `designTokens.ts` a `section` group: `header: 'bg-pay-navy text-pay-navy-fg'`, `rail: 'bg-surface-raised border-r border-border-strong'`, `canvas: 'bg-surface-canvas'`, `cartPanel: 'bg-surface-raised border-l border-border-strong shadow-sm'`, `footer: 'bg-pay-navy text-pay-navy-fg'`.
- [ ] **Step 3:** `pnpm typecheck` + `pnpm lint` clean.
- [ ] **Step 4:** Commit: `style(pos): add spacing scale + section-surface token helpers`.

---

## Phase 1 — Button system

### Task 3: Base Button — label non-clip + ergonomic size scale

**Files:**
- Modify: `src/components/ui/Button.tsx`
- Modify: `src/lib/designTokens.ts` (button recipes: add `whitespace-nowrap`; size padding)
- Test: `src/components/ui/__tests__/Button.test.tsx`

**Interfaces:**
- Produces: `Button` props `size?: 'sm'|'md'|'lg'` mapping to min-heights **40/48/64**; base class includes `whitespace-nowrap`; opt-in `truncate` via a `truncate?: boolean` prop; supports `min-w-0` from parent (no fixed `px-4` that forces overflow).

- [ ] **Step 1: Write the failing test** — render a `Button` with a long label inside a narrow `min-w-0` flex parent; assert the rendered element carries `whitespace-nowrap` and (when `truncate`) `truncate`, and that `size="md"` yields `min-h-[48px]`, `size="lg"` yields `min-h-[64px]`.

```tsx
import { render } from '@testing-library/react';
import { Button } from '../Button';

test('button never wraps its label and respects min-w-0 parent', () => {
  const { getByRole } = render(
    <div className="flex min-w-0"><Button truncate>Rappeler la transaction</Button></div>,
  );
  const btn = getByRole('button');
  expect(btn.className).toContain('whitespace-nowrap');
  expect(btn.className).toContain('truncate');
});

test('size scale maps to ergonomic min-heights', () => {
  const { getByRole, rerender } = render(<Button size="md">A</Button>);
  expect(getByRole('button').className).toContain('min-h-[48px]');
  rerender(<Button size="lg">A</Button>);
  expect(getByRole('button').className).toContain('min-h-[64px]');
});
```

- [ ] **Step 2: Run test, verify it fails** — `pnpm test src/components/ui/__tests__/Button.test.tsx` → FAIL.
- [ ] **Step 3: Implement** — in `Button.tsx`, add `truncate` prop; add `whitespace-nowrap` to the base class; define `sizeClasses = { sm: 'min-h-[40px] px-3', md: 'min-h-[48px] px-4', lg: 'min-h-[64px] px-6 text-lg' }`; apply `truncate` conditionally; ensure no hardcoded width blocks `min-w-0`.
- [ ] **Step 4: Run test, verify pass.**
- [ ] **Step 5:** Update `designTokens.ts` button recipes to include `whitespace-nowrap` and remove the fixed `px-4` where a size prop now governs padding.
- [ ] **Step 6:** `pnpm test` (Button only) + `pnpm typecheck` + `pnpm lint` clean.
- [ ] **Step 7:** Commit: `fix(pos): Button never clips labels + 40/48/64 size scale`.

---

## Phase 2 — Chrome & section separation

### Task 4: Navy top bar (Header) + inverse children

**Files:**
- Modify: `src/components/Header.tsx`
- Test: visual (`/theme-preview` if Header is previewable; else drive the authed shell) — see Task 20 harness note.

- [ ] **Step 1:** Apply `tokens.section.header` to the Header root; remove any light-surface background.
- [ ] **Step 2:** Audit every Header child on navy: StatusPill variants, online/sync dot, StockFreshness, operator Avatar, Divider atoms, manager-PIN entry. For each that fails AAA on navy, switch to an inverse token (`text-pay-navy-fg`, or a navy-surface pill variant). **Do not hardcode**; add inverse variants to `designTokens.ts`/`StatusPill` where needed.
- [ ] **Step 3:** Ensure no logic change — StatusPill(B3), sync, StockFreshness, shift→EOD, manager-PIN, every modal trigger preserved.
- [ ] **Step 4: Visual verify** — light+dark, 15" viewport; contrast spot-check each child ≥ AAA (use a contrast check in `page.evaluate`).
- [ ] **Step 5:** `pnpm lint`/`typecheck` clean. Commit: `style(pos): navy top bar + inverse-on-navy header children`.

### Task 5: Nav rail seam + de-duplicate "Caisse" labels

**Files:**
- Modify: `src/components/NavRail.tsx`
- Modify: `src/locales/fr/pos.json` (new label key)
- Test: `src/components/__tests__/NavRail.test.tsx` (label uniqueness)

- [ ] **Step 1: Write failing test** — render `NavRail`; assert the visible item labels are unique (no two items render the same text).

```tsx
test('nav rail labels are unique', () => {
  const { getAllByRole } = render(<NavRail /* minimal props/store */ />);
  const labels = getAllByRole('link').map((n) => n.textContent?.trim());
  expect(new Set(labels).size).toBe(labels.length);
});
```

- [ ] **Step 2: Run, verify fail** (two "Caisse").
- [ ] **Step 3: Implement** — rename the bottom shift/wallet item to a distinct key (e.g. `nav.session` → "Session" or `nav.cashFund` → "Fond de caisse"); add the key to `fr/pos.json`. Apply `tokens.section.rail` for the seam; active item uses `--color-action` (blue) not accent-green.
- [ ] **Step 4: Run, verify pass.**
- [ ] **Step 5: Visual verify** rail seam + active state (blue). Commit: `fix(pos): unique nav labels + rail section seam (active=blue)`.

### Task 6: Cart panel elevation, icon clip, navy payment footer

**Files:**
- Modify: `src/components/pos/TransactionCart.tsx` (wrapper ~137)
- Modify: `src/components/pos/PaymentSummary.tsx`

- [ ] **Step 1:** Apply `tokens.section.cartPanel` to the cart column; add inner padding so the cart icon never sits under the screen edge (fix the clip).
- [ ] **Step 2:** Apply `tokens.section.footer` (navy) to PaymentSummary; verify totals/amount-due (mono, `--pay-navy-fg`) clear AAA on navy; Encaisser stays green (`buttonConfirm`) at `size="lg"` (64px).
- [ ] **Step 3:** Confirm no change to cart logic/totals/precision.
- [ ] **Step 4: Visual verify** — sections read as distinct blocks (header navy · rail · canvas · cart raised · footer navy); icon not clipped; light+dark. Commit: `style(pos): cart panel elevation + navy payment footer + fix cart icon clip`.

---

## Phase 3 — Audit bug fixes (chip / quick actions / thumb)

### Task 7: Customer chip overflow

**Files:**
- Modify: `src/components/customers/CartCustomerControl.tsx` (~33), `CustomerLoyaltyBadge.tsx`
- Test: `src/components/customers/__tests__/CartCustomerControl.test.tsx`

**Interfaces:**
- Produces: a chip that, given a customer with both loyalty points and a wallet balance, does not force horizontal overflow — name truncates first; badges/wallet get a priority order; detach stays reachable.

- [ ] **Step 1: Write failing test** — render with a customer having a long name + points + balance; assert the root is not a single non-wrapping row where every non-name child is `shrink-0` (assert the name container has `min-w-0` and at least the secondary cluster is allowed to wrap or truncate). Prefer testing rendered structure/classes that encode the fix over pixel layout.

```tsx
test('customer chip keeps name truncatable and does not lock all children shrink-0', () => {
  const { getByTestId } = render(<CartCustomerControl customer={longNameWithPointsAndBalance} /* ... */ />);
  const root = getByTestId('cart-customer-control');
  // name zone must be able to give up space
  expect(root.querySelector('[data-testid="customer-name"]')?.className).toContain('min-w-0');
  // the control wrapper must not be shrink-0 (it was, causing collision)
  expect(root.className).not.toContain('shrink-0');
});
```

- [ ] **Step 2: Run, verify fail.**
- [ ] **Step 3: Implement** — give the control a coherent min-width strategy: name zone `min-w-0 truncate`; loyalty/wallet cluster allowed to wrap to a second line OR truncate with a tooltip; detach button always visible ≥40px hit area; remove the outer `shrink-0` that caused collision (`TransactionCart.tsx:137`). Loyalty badge uses blue/neutral per Strategy A (not green unless it denotes money).
- [ ] **Step 4: Run, verify pass.**
- [ ] **Step 5: Visual verify** with points+balance present, narrow cart — no collision, name truncates. Commit: `fix(pos): customer chip no longer collides with points+balance`.

### Task 8: QuickActions "Rappeler" clip

**Files:**
- Modify: `src/components/molecules/QuickActions/QuickActions.tsx`
- Test: `src/components/molecules/QuickActions/__tests__/QuickActions.test.tsx`

- [ ] **Step 1: Write failing test** — render QuickActions; assert each button parent allows `min-w-0` (buttons carry `min-w-0` and use the fixed `Button` `truncate`), and the container is not relying on `overflow-x-auto` to hide text.

```tsx
test('quick action buttons can shrink and truncate instead of clipping', () => {
  const { getAllByRole } = render(<QuickActions /* handlers */ />);
  getAllByRole('button').forEach((b) => expect(b.className).toContain('min-w-0'));
});
```

- [ ] **Step 2: Run, verify fail.**
- [ ] **Step 3: Implement** — add `min-w-0` to each `flex-1` button; pass `truncate` to `Button`; remove reliance on `overflow-x-auto` clipping. Labels via `t()`.
- [ ] **Step 4: Run, verify pass.**
- [ ] **Step 5: Visual verify** at narrow width — "Rappeler" shows fully or truncates with ellipsis, never "Rapp". Commit: `fix(pos): QuickActions labels no longer clip`.

### Task 9: ProductThumb placeholder → tokens + contrast

**Files:**
- Modify: `src/components/ui/ProductThumb.tsx` (~60)
- Test: `src/components/ui/__tests__/ProductThumb.test.tsx`

- [ ] **Step 1: Write failing test** — render ProductThumb with no image; assert the placeholder does NOT contain the hardcoded `bg-[#eef3f8]` / `text-[#5e6670]` classes (uses tokens instead).

```tsx
test('placeholder uses tokens not hardcoded hex', () => {
  const { getByTestId } = render(<ProductThumb name="Avène Cicalfate" category="corps" />);
  const ph = getByTestId('product-thumb-placeholder');
  expect(ph.className).not.toContain('#eef3f8');
  expect(ph.className).not.toContain('#5e6670');
});
```

- [ ] **Step 2: Run, verify fail.**
- [ ] **Step 3: Implement** — replace hardcoded classes with `bg-surface-sunken text-ink-muted` (or the category-tint tokens already in `index.css:104-111`); raise initials size/contrast. Add `data-testid="product-thumb-placeholder"`.
- [ ] **Step 4: Run, verify pass.** `pnpm lint` (color guard) clean.
- [ ] **Step 5:** Commit: `fix(pos): ProductThumb placeholder uses tokens + higher contrast`.

---

## Phase 4 — Unified stock badge

### Task 10: StockBadge unified format

**Files:**
- Modify: `src/components/ui/StockBadge.tsx`
- Modify: `src/locales/fr/pos.json` (`products.stock`, `products.outOfStock` wording)
- Test: `src/components/ui/__tests__/StockBadge.test.tsx`

**Interfaces:**
- Produces: `StockBadge` renders `Stock {n}` for `ok`/`low` (number always shown) and `Rupture` for `out`; color by status (`stock-ok`/`stock-low`/`stock-out`); one pill shape.

- [ ] **Step 1: Write failing test:**

```tsx
test('badge always shows the number for ok and low, word only for out', () => {
  expect(render(<StockBadge status="ok">{'Stock 12'}</StockBadge>).getByText('Stock 12')).toBeTruthy();
  const low = render(<StockBadge status="low">{'Stock 3'}</StockBadge>);
  expect(low.getByText('Stock 3')).toBeTruthy();
  const out = render(<StockBadge status="out">{'Rupture'}</StockBadge>);
  expect(out.getByText('Rupture')).toBeTruthy();
});
```

- [ ] **Step 2:** Update the **caller** (`ProductCard.tsx:118-137`) so `stockLabel` for low is `t('products.stock',{count})` (with number) not the wordless `products.lowStock`; keep `out` = `t('products.outOfStock')` → "Rupture". Adjust `fr/pos.json` so `products.stock` = `"Stock {{count}}"` and `products.outOfStock` = `"Rupture"`. Write a test on the ProductCard label logic (in ProductCard test) asserting low renders a number.
- [ ] **Step 3: Run, verify pass** for badge + card label.
- [ ] **Step 4: Visual verify** all three states share one pill shape across tile/row/table. Commit: `feat(pos): unified stock badge (Stock N / Rupture)`.

---

## Phase 5 — Vitrine tile restyle

### Task 11: ProductCard — price→ink, in-cart→blue, brand contrast

**Files:**
- Modify: `src/components/molecules/ProductCard/ProductCard.tsx` (price ~405-413; in-cart ~215,244,253,346; brand label ~311-320,367-376)
- Test: `src/components/molecules/ProductCard/__tests__/ProductCard.test.tsx`

- [ ] **Step 1: Write failing test** — assert the price element uses an ink token, not accent: render a card, query `[data-testid="price-row"]`, assert its className contains `text-ink` and NOT `text-accent-strong`.

```tsx
test('price is ink, never accent', () => {
  const { getByTestId } = render(<ProductCard product={p} onAddToCart={()=>{}} />);
  const price = getByTestId('price-row');
  expect(price.className).toContain('text-ink');
  expect(price.className).not.toContain('accent');
});
```

- [ ] **Step 2: Run, verify fail** (currently `text-accent-strong`).
- [ ] **Step 3: Implement** — price → `text-ink` (out-of-stock → `text-ink-faint`); in-cart border/tint/badge → `border-action`/`bg-action-subtle`/action tokens (was accent); the 3px top bar → `bg-action`; brand label raise from `text-[10px]` washed to a tokenized readable size/contrast; keep the two-line name clamp + `mt-auto` price/stock row (P3 layout fix) intact.
- [ ] **Step 4: Run, verify pass.**
- [ ] **Step 5: Visual verify** on `/theme-preview` seeded grid with **real Track-A images**: price reads dark/high-contrast; selected card is clearly blue; brand legible; no clipping; light+dark. Commit: `style(pos): ProductCard price→ink, selection→blue, brand contrast`.

---

## Phase 6 — Liste (touch table)

### Task 12: ProductListRow component

**Files:**
- Create: `src/components/organisms/ProductGrid/ProductListRow.tsx`
- Test: `src/components/organisms/ProductGrid/__tests__/ProductListRow.test.tsx`

**Interfaces:**
- Produces: `ProductListRow({ product, onAddToCart, onViewDetails, locationStock, hardBlockOutOfStock })` — a ≥48px row: thumb · brand+name (min-w-0 truncate) · unified StockBadge · price (ink) · `+` button (64px hit area, disabled when out-of-stock+block) · eye (≥40px). Reuses `useCurrency`, `StockBadge`, `ProductThumb`, the same stock-path logic as ProductCard.

- [ ] **Step 1: Write failing test** — render a row for an in-stock product; assert min-height ≥48 class, price is ink, `+` enabled; render an out-of-stock+block product; assert `+` is `aria-disabled`/disabled and the row still shows price + "Rupture".

```tsx
test('list row: in-stock has enabled +, price ink; out-of-stock+block disables +', () => {
  const inStock = render(<ProductListRow product={p} locationStock={{available:'12',incoming_transfer:'0',incoming_po:'0'}} onAddToCart={()=>{}} />);
  expect(inStock.getByTestId('add-button').getAttribute('aria-disabled')).not.toBe('true');
  expect(inStock.getByTestId('price-row').className).toContain('text-ink');
  const out = render(<ProductListRow product={p} locationStock={{available:'0',incoming_transfer:'0',incoming_po:'0'}} hardBlockOutOfStock onAddToCart={()=>{}} />);
  expect(out.getByTestId('add-button').getAttribute('aria-disabled')).toBe('true');
  expect(out.getByText('Rupture')).toBeTruthy();
});
```

- [ ] **Step 2: Run, verify fail.**
- [ ] **Step 3: Implement** the row per interface; extract the shared stock-derivation (label/isOut/isLow) from ProductCard into a small helper (`src/components/organisms/ProductGrid/useStockDisplay.ts`) and reuse in both to stay DRY.
- [ ] **Step 4: Run, verify pass.**
- [ ] **Step 5: Visual verify** a list of rows at 15" — 48px targets, aligned prices, truncating names. Commit: `feat(pos): ProductListRow (Liste touch density)`.

---

## Phase 7 — Tableau (desktop dense)

### Task 13: ProductTable component

**Files:**
- Create: `src/components/organisms/ProductGrid/ProductTable.tsx`
- Test: `src/components/organisms/ProductGrid/__tests__/ProductTable.test.tsx`

**Interfaces:**
- Produces: `ProductTable({ products, onAddToCart, onViewDetails, locationStock })` — a dense table: columns Code(sku) · Produit(brand+name) · Catégorie · Stock(+incoming) · Prix(ink) · action; keyboard nav (↑↓ move focus, ⏎ add); rows hover-highlight; prices ink; unified StockBadge.

- [ ] **Step 1: Write failing test** — render with 3 products; assert headers exist (Code/Produit/Stock/Prix), price cells are ink, and ↑↓/⏎ keyboard handlers add the focused product.

```tsx
test('table renders columns, ink prices, and ⏎ adds focused row', () => {
  const add = vi.fn();
  const { getByText, getAllByRole } = render(<ProductTable products={[p1,p2,p3]} onAddToCart={add} />);
  expect(getByText('Prix')).toBeTruthy();
  const firstRow = getAllByRole('row')[1];
  firstRow.focus();
  fireEvent.keyDown(firstRow, { key: 'Enter' });
  expect(add).toHaveBeenCalledWith(p1);
});
```

- [ ] **Step 2: Run, verify fail.**
- [ ] **Step 3: Implement** per interface; reuse `useStockDisplay`, `useCurrency`; ink prices; incoming via `bcsum` decimal strings.
- [ ] **Step 4: Run, verify pass.**
- [ ] **Step 5: Visual verify** dense table at desktop viewport, keyboard nav works, both themes. Commit: `feat(pos): ProductTable (Tableau desktop density)`.

---

## Phase 8 — Mode switcher, persistence, migration

### Task 14: settingsStore displayMode migration + dual-source reconcile

**Files:**
- Modify: `src/stores/settingsStore.ts`
- Test: `src/stores/__tests__/settingsStore.displayMode.test.ts`

**Interfaces:**
- Produces: `settingsStore.displayMode: 'vitrine'|'liste'|'tableau'`, default resolved by input type; a persisted `migrate` mapping legacy `'grid'→'liste'`, `'visual'→'vitrine'`; the store is the single source of truth (legacy `pos-display-mode` localStorage read once then written into the store and ignored thereafter).

- [ ] **Step 1: Write failing test** — persisted state `{displayMode:'grid'}` migrates to `'liste'`; `{displayMode:'visual'}` → `'vitrine'`; unknown → default.

```ts
test('legacy displayMode migrates', () => {
  expect(migrateDisplayMode('grid')).toBe('liste');
  expect(migrateDisplayMode('visual')).toBe('vitrine');
  expect(migrateDisplayMode('tableau')).toBe('tableau');
});
```

- [ ] **Step 2: Run, verify fail.**
- [ ] **Step 3: Implement** — widen the type; add a persist `version` bump + `migrate` fn exporting `migrateDisplayMode`; on init, if legacy `localStorage['pos-display-mode']` exists and store is default, adopt+clear it. Add `resolveDefaultMode()` using `window.matchMedia('(pointer: coarse)')`.
- [ ] **Step 4: Run, verify pass.**
- [ ] **Step 5:** `pnpm typecheck` clean. Commit: `feat(pos): displayMode tri-mode + migration + single source`.

### Task 15: ProductGrid hosts the three modes + segmented switcher

**Files:**
- Modify: `src/components/organisms/ProductGrid/ProductGrid.tsx` (search filter ~276-306; render branch)
- Test: extend `ProductGrid` test for mode routing.

**Interfaces:**
- Consumes: `settingsStore.displayMode`, `ProductListRow`, `ProductTable`, `ProductCard`.
- Produces: a toolbar `SegmentedControl` (Vitrine/Liste/Tableau via `t()`); renders the matching view; search filter (name·sku·barcode) unchanged and shared across modes; virtualizer keeps `measureElement` dynamic rows (never reintroduce fixed row heights — the P3 bug).

- [ ] **Step 1: Write failing test** — set `displayMode='liste'` → grid renders `ProductListRow`s; `'tableau'` → `ProductTable`; `'vitrine'` → `ProductCard`s; switching via the segmented control updates the store.
- [ ] **Step 2: Run, verify fail.**
- [ ] **Step 3: Implement** the render branch + `SegmentedControl`; keep the existing search/facet filters; ensure virtualization works for list/table (content-sized).
- [ ] **Step 4: Run, verify pass.**
- [ ] **Step 5: Visual verify** switching all three at 15" and desktop; no clipping/overlap; both themes. Commit: `feat(pos): tri-density switcher in ProductGrid`.

### Task 16: SettingsPage — mode + optional-field toggles

**Files:**
- Modify: `src/pages/SettingsPage.tsx`, `src/stores/settingsStore.ts` (optional-field flags), `src/locales/fr/pos.json`
- Test: settingsStore flags default off (except as specified).

- [ ] **Step 1: Write failing test** — new flags `showSkuOnRows`, `showSkinTypeOnTiles`, `showEquivalentsBadge` default `false`.
- [ ] **Step 2: Run, verify fail.**
- [ ] **Step 3: Implement** the flags + a Settings "Affichage caisse" section (mode + toggles) via `t()`.
- [ ] **Step 4: Run, verify pass.** Wire the flags into ProductCard/Row/Table conditionals.
- [ ] **Step 5: Visual verify** toggles take effect. Commit: `feat(pos): caisse display settings (mode + optional fields)`.

---

## Phase 9 — Search/scan result with equivalents

### Task 17: SearchResultGroups (matched → equivalents → complements) — ⛔ STRUCK (Rev 2, deferred — do not implement; see reconciliation)

**Files:**
- Create: `src/components/organisms/ProductGrid/SearchResultGroups.tsx`
- Modify: `src/components/organisms/ProductGrid/ProductGrid.tsx` (render groups when a search/scan resolves a single primary product + Merchandising module on)
- Test: `src/components/organisms/ProductGrid/__tests__/SearchResultGroups.test.tsx`

**Interfaces:**
- Consumes: `productStore.getByIds`, `ParapharmacyMeta.{equivalent_product_ids,complement_product_ids}`, `hasModule(companyConfig,'Merchandising')`.
- Produces: `SearchResultGroups({ matched, equivalents, complements, ... })` rendering the matched product first (out-of-stock shows with disabled `+`), then a labelled Équivalents group (in-stock first), then Compléments; each item is a `ProductListRow`.

- [ ] **Step 1: Write failing test** — given a matched product with 2 equivalent ids + 1 complement id resolvable via a stubbed `getByIds`, assert render order: matched, then group label `Équivalents`, then equivalents, then `Compléments`; matched out-of-stock → its `+` disabled.

```tsx
test('renders matched → equivalents → complements in order', () => {
  const { getAllByTestId, getByText } = render(
    <SearchResultGroups matched={outOfStockMatch} equivalents={[eqA,eqB]} complements={[cmp]} onAddToCart={()=>{}} />,
  );
  const rows = getAllByTestId('result-row');
  expect(rows[0]).toHaveAttribute('data-role','matched');
  expect(getByText('Équivalents')).toBeTruthy();
  expect(getByText('Compléments')).toBeTruthy();
});
```

- [ ] **Step 2: Run, verify fail.**
- [ ] **Step 3: Implement** the component; in ProductGrid, when the search query resolves to a primary match (or a scan `hit`) and Merchandising is on and metadata non-null, resolve equivalents/complements via `getByIds` and render `SearchResultGroups` instead of the flat grid. Gate exactly as `ProductDetailDrawer` does. Labels via `t()`.
- [ ] **Step 4: Run, verify pass.**
- [ ] **Step 5: Visual verify** with a seeded fixture (matched out-of-stock + in-stock equivalents). Commit: `feat(pos): surface equivalents/complements in search-scan results`.

---

## Phase 10 — Detail drawer

### Task 18: ProductDetailDrawer restyle + new tab shells

**Files:**
- Modify: `src/components/pos/ProductDetailDrawer.tsx`
- Modify: `src/locales/fr/pos.json` (tab labels, empty/offline states)
- Test: `src/components/pos/__tests__/ProductDetailDrawer.test.tsx`

**Interfaces:**
- Produces: tabs `Routine · Équivalents · Compléments` (existing, restyled to Strategy A) + `Stock & lots` and `Autres officines` **shells** with empty/"bientôt disponible"/offline states — no batch or branch data rendered (Spec 2).

- [ ] **Step 1: Write failing test** — assert the five tab labels exist and that the Stock&lots / Autres officines panels render their placeholder empty-state (not batch data).
- [ ] **Step 2: Run, verify fail.**
- [ ] **Step 3: Implement** — add the two tab shells; restyle to blue-interaction/ink; keep the existing equivalents/complements/routine resolution untouched.
- [ ] **Step 4: Run, verify pass.**
- [ ] **Step 5: Visual verify** drawer both themes; eye hit area ≥40px. Commit: `feat(pos): detail drawer restyle + Stock&lots / Autres officines tab shells`.

---

## Phase 11 — Harness & full visual verification

### Task 19: /theme-preview fixtures for all three modes + search result

**Files:**
- Modify: `src/pages/ThemePreviewPage.tsx`

- [ ] **Step 1:** Add seeded fixtures: a Vitrine grid, a Liste, a Tableau, and a `SearchResultGroups` (matched out-of-stock + in-stock equivalents + complement), using realistic parapharmacy fixtures and (where available) real image URLs. Add `data-testid`s (`sell-preview-vitrine|liste|tableau`, `search-result-preview`).
- [ ] **Step 2:** `pnpm dev`, open `/theme-preview`, eyeball all four in light+dark.
- [ ] **Step 3:** Commit: `test(pos): theme-preview fixtures for tri-density + equivalents`.

### Task 20: Playwright visual verification pass (whole redesign)

**Files:**
- Scratch playwright script (not committed) per the repo recipe.

- [ ] **Step 1:** In a scratch dir, `npm i playwright-core`; script `chromium.launch({channel:'chrome'})` → `localhost:<vite>/theme-preview`.
- [ ] **Step 2:** For each fixture and each theme (`data-theme` toggle) at 1280×1024 and 1366×768: screenshot; assert no horizontal overflow; measure a sample of touch targets ≥48px via `getBoundingClientRect`; contrast-check price text and header children (≥ AAA 7:1 body) in `page.evaluate`.
- [ ] **Step 3:** Record findings; fix any layout regressions in the relevant task's file; re-verify.
- [ ] **Step 4:** Run full quality gates: `pnpm typecheck`, `pnpm lint` (color + tanstack-key guards), `pnpm test` (POS package, by path if the full suite is unstable). Kill any lingering vitest workers afterward.
- [ ] **Step 5:** Commit: `test(pos): playwright visual verification pass for caisse redesign`.

---

## Self-Review (against the spec)

- **§3 color system** → Tasks 1, 5, 11 (accent→action, focus ring, price→ink, selection→blue). ✅
- **§3.1 accent reversal** → Task 1 (repoint) + Task 5/11 (selection uses action). ✅
- **§4 composition/navy/sections** → Tasks 2, 4, 5, 6. ✅
- **§5 spacing** → Task 2 (scale) applied throughout restyle tasks. ✅
- **§6 button system** → Task 3. ✅
- **§7 unified stock badge** → Task 10. ✅
- **§8 tri-density + switcher + matrix + search flow** → Tasks 11–17. ✅
- **§9 detail drawer** → Task 18. ✅
- **§10 audit bug fixes** → chip (7), Rappeler (3+8), touch targets (3,6,7,12,18), duplicate nav (5), lavender/brand (9,11), cart icon (6). ✅
- **§11 resolved decisions** → Q1 (Task 1), Q3 (Task 10), Q4 (Task 6); Q2 deferred (no task, backend ticket noted). ✅
- **§13 testing/visual** → Tasks 19, 20 + per-task visual verify. ✅
- **§14 risks** → execution gate (header), dual-source reconcile (Task 14), navy contrast (Task 4), virtualizer (Task 15). ✅
- **§15 Spec-2 deferrals** → drawer shells only (Task 18); chip slot reserved (matrix, not implemented). ✅

**Placeholder scan:** logic tasks carry real test code; restyle tasks carry exact files/lines + change spec + mandatory visual verification (honest for a visual redesign — no fake unit tests). No TBD/TODO.

**Type consistency:** `displayMode` values (`vitrine`/`liste`/`tableau`) consistent across Tasks 14/15/16; `migrateDisplayMode`, `useStockDisplay`, `SearchResultGroups`, `ProductListRow`, `ProductTable` names consistent across producing/consuming tasks.
