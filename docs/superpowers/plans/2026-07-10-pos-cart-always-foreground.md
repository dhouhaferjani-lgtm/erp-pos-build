# POS Cart Always Foreground Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the IziPOS cart structurally impossible to occlude by any add-to-cart-adjacent surface: the product detail drawer and the modifier composer become in-pane views that replace the product grid (never the cart), with an added-line pulse confirming each add.

**Architecture:** Pane replacement (spec §1–2, Approach A): the product area (`HomePage.tsx:1502`, `flex flex-[7]`) becomes a switched pane — `grid | detail | customize` — derived from the existing `detailProduct` / `modifierProduct` state, hosted by a small new `ProductPaneHost` component. The grid stays mounted-but-hidden so scroll/virtualizer state survives; the cart column (`HomePage.tsx:1463`, fixed 460px) is untouched and remains the sibling flex child. The overlay host `ProductDetailDrawer` is deleted; `ModifierSelectionModal`'s content is extracted into `ModifierComposerSheet` and the `<Modal>` shell leaves the HomePage flow.

**Tech Stack:** React 19 · Vite 7 · TypeScript strict · Tailwind CSS 4 · Zustand 5 · lucide-react · react-i18next · Vitest (jsdom) · Tauri 2. Visual verification via `/theme-preview` + `playwright-core` (system Chrome).

**Spec (authoritative):** `docs/superpowers/specs/2026-07-10-pos-cart-always-foreground-design.md`. All work in worktree `apps/erp.cart-foreground`, branch `feat/pos-cart-foreground`. All paths below are relative to the worktree root unless absolute. Do NOT push.

## Rev 2 (2026-07-10) — adversarial-review reconciliation

Folds in the accepted findings of `docs/superpowers/specs/reviews/2026-07-10-pos-cart-always-foreground-spec-plan-adversarial-review.md` (2 reviewers, 0 BLOCKER / 5 MAJOR / 12 MINOR). **Rev 2 items override any conflicting text below**; all task bodies below have also been amended in place so the plan reads consistently.

- **OWNER DECISION (U4): close pane on settle/new-sale.** `handleNewSale` clears `detailProduct` / `modifierProduct` / `editingLineId` (all three are separate `useState`s — clearing `modifierProduct` does NOT clear `editingLineId` by itself); `handleRecall`'s `replaceCart` path clears `modifierProduct` / `editingLineId` but NOT `detailProduct` (detail pane unaffected, spec §4). Task 3 carries the detail-side tests; the customize-side tests land in Task 4, where the composer stub exists. Within-sale "post-add stays open" is untouched.
- **Esc stacking guard (U1)** — Task 2: the pane's Esc handler bails while `document.querySelector('[aria-modal="true"]')` is non-null (every Modal binds its own window Esc listener, `Modal.tsx:31-39`), with a stub-dialog test.
- **Scroll-added-line-into-view (U2)** and **customize-EDIT confirm stamps the pulse (U9)** — Task 6 (store: `updateLineModifiers` also stamps `lastAddedLineId`/`lastAddedNonce`; UI: `TransactionCart` scrolls the matching line into view on nonce change).
- **Stale customize-EDIT guard (U3)** — Task 4: `handleModifierConfirm` validates the edited line still exists; if not, toast with NEW i18n key `modifiers.lineGone` and close.
- **Citation/count fixes:** `seedStores` copy range 216–354 (C2); composer fixture citation `:50-84` (C3); `CartLineItem` call sites `:261`/`:283`, subscription anchors `confirmLineDelete :96` / `cartPosition :97` (C4); the re-authored pane container line migrates `bg-gray-50` → `bg-surface-canvas` per repo rule 18 (C5); explicit-scale instruction scoped to `bcadd`/`bcsum` only — `bccomp(a, b)` takes NO scale arg, `decimal.ts:42` (C6); 22 drawer-test call sites, and `ThemePreviewPage.tsx:855` is the 4th `onViewDetails={setDetailProduct}` site (C1/C7).
- **Task 7** policy-doc inventory completed (U10); **Task 8** device checklist extended (U1/U2/U4/U5); **Task 3** notes the U6 hidden-grid fallback (scrollTop capture / `visibility` hiding); **Task 2** pane wrappers carry `overflow-x-auto` (U8) so a squeezed sheet scrolls instead of clipping the tab strip and close X.

## Global Constraints

- **Design tokens only** — no hex values or raw Tailwind palette classes in `.tsx` (ESLint color-guard must stay clean); new CSS uses `var(--…)` semantic tokens from `apps/pos/src/index.css`.
- **All user-facing text via `t()`** (react-i18next); every NEW key added to BOTH `apps/pos/src/locales/fr/pos.json` AND `apps/pos/src/locales/en/pos.json`. (`/theme-preview` is a dev-only route with an existing hardcoded-French precedent — its captions are exempt.)
- **Never `parseFloat`/`Number()` on money or quantity** — decimal strings + `@/lib/decimal` helpers (`bcadd`, `bcsum`, `bccomp`) and `useCurrency().format`; the `no-parsefloat-on-money` ESLint rule fails CI on new drift.
- **Touch targets ≥ 48px** for every new interactive control.
- **NEVER run the full vitest suite** — targeted `pnpm exec vitest run <path>` only (from `apps/pos`); after EVERY vitest run (especially a hung one) kill orphaned workers: `ps aux | grep 'node (vitest' | grep -v grep | awk '{print $2}' | xargs kill 2>/dev/null || true`.
- **`pnpm exec tsc --noEmit` clean** (from `apps/pos`) at the end of every task before its commit.
- **TDD** — write the failing test first, run it to observe RED, implement minimally, run to GREEN, then commit.
- **Strategy A colors** — blue `--action` = interaction/selection ONLY; green = stock/money confirmation ONLY; prices = ink. The pulse animation is an interaction cue → `--action`.
- **Do NOT touch payment/checkout takeover surfaces or cart-action modals** (spec §3 classes (b)/(c)): `CashPaymentScreen`, `AdvancedPaymentsModal`, `CheckoutSuccessModal`, `DiscountModal`, `LineDiscountModal`, `QuantityNumpad`, `HeldTransactionsModal`, `RefundCheckoutFlow`, `ReceiptScanConfirmationSheet`, `ReceiptLocatorScreen`, `CustomerSearchModal`, shift/PIN/fiscal/reports surfaces, `VariantPickerModal`, `BarcodeChooserModal`.

## Decisions taken while planning (for reviewers)

1. **Pulse triggers on `cartStore.addItem`** (both its merge-increment and new-line paths) **and — Rev 2, U9 — on a successful `updateLineModifiers`** (the customize-EDIT confirm is a pane-originated cart mutation the operator must confirm). Every `addItemGated` ingress lands on `addItem` (`addItemWithDefaults` delegates, `apps/pos/src/stores/cartStore.ts:333-336`). Cart-local `updateQuantity` (stepper/numpad) does NOT pulse — the operator is already looking at the cart, and quantity edits are not "adds landing from the pane". `replaceCart`/hydration resets pulse state (a recalled cart must not pulse).
2. **Detail-tab state moves to HomePage** (`detailTab`), reset to `'details'` on every `handleViewDetails`. The old overlay host owned it with a product-id reset that in practice always reopened on Details (see comment `apps/pos/src/components/pos/ProductDetailDrawer.tsx:52-55`) — reset-on-open preserves those semantics exactly, including "opening detail for a different product swaps content in place".
3. **`ModifierSelectionModal` is deleted** after extraction (HomePage is its only consumer — verified by grep; spec §2.3). Its test suite is ported to `ModifierComposerSheet`. The two "renders nothing when closed / product null" tests are dropped: mount-gating now belongs to `ProductPaneHost` and is tested there.
4. **The extracted composer fixes the two `parseFloat`-on-money instances it inherits** (`ModifierSelectionModal.tsx:88,94,167`) using `bcadd`/`bcsum`/`bccomp` — moving code into a new file makes it "new code" under the precision guard; displayed values are unchanged (behavior parity holds).
5. **jsdom cannot prove hit-testing.** The Task 3 invariant test asserts the structural proxy (no `fixed`+`inset-0` element anywhere in the document with a pane open; `TransactionCart` present in the tree; grid hidden-not-unmounted). Real clickability is verified in Task 8 (playwright + on-device). Stated explicitly per spec §5.
6. **Pane min-width floor = `min-w-[680px]`** (sheet aside is 344px + divider + ≥320px usable tab column). At 1366px the pane gets ≈840px (spec §4) so the floor never engages on target hardware; it only guards degenerate widths.
7. **`/theme-preview`'s existing inline sheet section becomes the pane-variant panel**; its testid is RENAMED `product-drawer-preview` → `product-pane-preview` (grep confirmed no references outside `ThemePreviewPage.tsx`). The separate overlay demo (`open-product-detail-preview` button + `<ProductDetailDrawer>` mount) is removed with the overlay host.
8. **File `components/pos/ProductDetailDrawer.tsx` keeps its name** after the overlay host is deleted (it still exports `ProductDetailSheet`); renaming would churn imports in 5 files for zero behavior. Noted for a future janitorial pass.

---

### Task 1: `ProductDetailSheet` pane variant

**Files:**
- Modify: `apps/pos/src/components/pos/ProductDetailDrawer.tsx` (sheet props `:79-86`, sheet root element `:151-159`)
- Test: `apps/pos/src/components/pos/__tests__/ProductDetailDrawer.test.tsx` (append a describe block; extend the import at `:5`)

**Interfaces:**
- Consumes: existing `ProductDetailSheetProps` (`ProductDetailDrawer.tsx:79-86`), `POSProduct` (`@/types/product`), `cn` (`@/lib/utils`).
- Produces: `ProductDetailSheetProps` gains `variant?: 'overlay' | 'pane'` (default `'overlay'`). Pane mode renders `role="region"` + `aria-label={product.name}`, classes `h-full w-full min-w-[680px]` (no `ez-sheet-rise`, no fixed geometry, no `aria-modal`). Overlay mode is byte-identical to today. Exported unchanged as `ProductDetailSheet` via barrel `apps/pos/src/components/organisms/ProductDetailDrawer/index.ts`.

**Steps:**

- [ ] **Step 1 — failing test.** In `apps/pos/src/components/pos/__tests__/ProductDetailDrawer.test.tsx`, extend the component import (line 5) to:

```tsx
import { ProductDetailDrawer, ProductDetailSheet } from '@/components/pos/ProductDetailDrawer';
```

and append at the end of the file:

```tsx
// Cart-always-foreground v1 (spec §2.1) — the sheet gains a 'pane' variant that
// fills the product pane fluidly with region (not dialog) semantics. The
// overlay variant stays the default and byte-identical during the transition.
describe('ProductDetailSheet — pane variant (cart-always-foreground v1)', () => {
  function renderPane() {
    return render(
      <ProductDetailSheet
        variant="pane"
        product={product}
        onClose={() => {}}
        activeTab="details"
        onTabChange={() => {}}
      />,
    );
  }

  it('renders as a non-modal region labelled by the product name', () => {
    renderPane();
    const pane = screen.getByTestId('product-detail-modal');
    expect(pane).toHaveAttribute('role', 'region');
    expect(pane).toHaveAttribute('aria-label', 'Widget');
    expect(pane).not.toHaveAttribute('aria-modal');
    expect(screen.queryByRole('dialog')).toBeNull();
  });

  it('fills its host fluidly with a min-w floor and no overlay geometry/animation', () => {
    renderPane();
    const pane = screen.getByTestId('product-detail-modal');
    expect(pane).toHaveClass('h-full');
    expect(pane).toHaveClass('w-full');
    expect(pane).toHaveClass('min-w-[680px]');
    expect(pane.className).not.toContain('w-[1080px]');
    expect(pane.className).not.toContain('h-[680px]');
    expect(pane.className).not.toContain('max-w-[96vw]');
    expect(pane.className).not.toContain('max-h-[92vh]');
    expect(pane.className).not.toContain('ez-sheet-rise');
    expect(pane.className).not.toContain('fixed');
  });

  it('keeps the overlay variant as the default (dialog semantics + fixed geometry)', () => {
    render(
      <ProductDetailSheet
        product={product}
        onClose={() => {}}
        activeTab="details"
        onTabChange={() => {}}
      />,
    );
    const sheet = screen.getByTestId('product-detail-modal');
    expect(sheet).toHaveAttribute('role', 'dialog');
    expect(sheet).toHaveAttribute('aria-modal', 'true');
    expect(sheet).toHaveClass('w-[1080px]');
    expect(sheet).toHaveClass('ez-sheet-rise');
  });
});
```

- [ ] **Step 2 — RED.** `cd apps/pos && pnpm exec vitest run src/components/pos/__tests__/ProductDetailDrawer.test.tsx` — expect the new describe to fail with a TS/prop error on `variant` (unknown prop) or role assertion failures; the pre-existing suites must still pass. Kill orphaned workers afterward.

- [ ] **Step 3 — implement.** In `apps/pos/src/components/pos/ProductDetailDrawer.tsx`:

Add to `ProductDetailSheetProps` (after `onTabChange` at line 85):

```tsx
  /**
   * Cart-always-foreground v1 (spec §2.1): 'overlay' (default) = the fixed-size
   * centered sheet inside an overlay host; 'pane' = fluid fill of the product
   * pane with region (not dialog) semantics — it is genuinely not a modal.
   */
  variant?: 'overlay' | 'pane';
```

Destructure it in the sheet signature (line 94-101): add `variant = 'overlay',` after `onTabChange,`.

Replace the sheet root `<section …>` opening tag (lines 151-159):

```tsx
  const isPane = variant === 'pane';

  return (
    <section
      role={isPane ? 'region' : 'dialog'}
      aria-modal={isPane ? undefined : 'true'}
      aria-label={isPane ? product.name : t('productDetail.title')}
      data-testid="product-detail-modal"
      className={cn(
        'relative flex overflow-hidden rounded-panel bg-surface-overlay',
        isPane
          ? // Pane: fill the host; min-w floor per spec §4 (aside 344px + usable
            // tab column). shadow-sm reads as a canvas panel, not overlay chrome.
            'h-full w-full min-w-[680px] shadow-sm'
          : 'ez-sheet-rise h-[680px] max-h-[92vh] w-[1080px] max-w-[96vw] shadow-2xl',
      )}
      onClick={(event) => event.stopPropagation()}
    >
```

(The `const isPane` line goes immediately before the `return`, after the existing `tabs` array at line 149. Everything inside the section is untouched.)

- [ ] **Step 4 — GREEN.** `cd apps/pos && pnpm exec vitest run src/components/pos/__tests__/ProductDetailDrawer.test.tsx src/components/pos/__tests__/ProductDetailDrawerMerchandising.test.tsx` — all pass. Then `pnpm exec tsc --noEmit` — clean. Kill orphaned workers.

- [ ] **Step 5 — commit.**

```bash
git add apps/pos/src/components/pos/ProductDetailDrawer.tsx apps/pos/src/components/pos/__tests__/ProductDetailDrawer.test.tsx
git commit -m "feat(pos): ProductDetailSheet pane variant (region semantics, fluid fill)

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: `ProductPaneHost`

**Files:**
- Create: `apps/pos/src/components/pos/ProductPaneHost.tsx`
- Test: `apps/pos/src/components/pos/__tests__/ProductPaneHost.test.tsx` (new)

**Interfaces:**
- Consumes: `POSProduct` (`@/types/product`), `cn` (`@/lib/utils`), React `ReactNode`/`useEffect`.
- Produces (later tasks import exactly these):

```tsx
export type ProductPaneView = 'grid' | 'detail' | 'customize';

export function deriveProductPaneView(
  detailProduct: POSProduct | null,
  modifierProduct: POSProduct | null,
): ProductPaneView;

export interface ProductPaneHostProps {
  detailProduct: POSProduct | null;
  modifierProduct: POSProduct | null;
  onCloseDetail: () => void;
  renderDetail: (product: POSProduct) => ReactNode;
  renderCustomize: (product: POSProduct) => ReactNode;
  children: ReactNode; // the grid view — stays MOUNTED (hidden) while a pane is active
}

export function ProductPaneHost(props: ProductPaneHostProps): JSX.Element;
```

DOM contract: root `data-testid="product-pane-host"`; grid wrapper `data-testid="product-pane-grid"` carries `flex` in grid view and `hidden` otherwise; pane wrappers `data-testid="product-pane-detail"` / `"product-pane-customize"`. Esc closes DETAIL only — and ONLY when no modal dialog is above the pane: the handler bails while `document.querySelector('[aria-modal="true"]')` is non-null (Rev 2, U1 — every Modal binds its own window Esc listener, `Modal.tsx:31-39`; without the guard one Esc press would close both surfaces). No `fixed`/`inset-0`/`aria-modal` anywhere on the host path.

**Steps:**

- [ ] **Step 1 — failing test.** Create `apps/pos/src/components/pos/__tests__/ProductPaneHost.test.tsx`:

```tsx
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ProductPaneHost, deriveProductPaneView } from '@/components/pos/ProductPaneHost';
import type { POSProduct } from '@/types/product';

const productA = { id: 'p1', name: 'Widget', sku: 'W1', sale_price: '9.990', stock_quantity: 5 } as POSProduct;
const productB = { id: 'p2', name: 'Gadget', sku: 'G1', sale_price: '5.000', stock_quantity: 3 } as POSProduct;

function renderHost(
  overrides: {
    detailProduct?: POSProduct | null;
    modifierProduct?: POSProduct | null;
    onCloseDetail?: () => void;
  } = {},
) {
  return render(
    <ProductPaneHost
      detailProduct={overrides.detailProduct ?? null}
      modifierProduct={overrides.modifierProduct ?? null}
      onCloseDetail={overrides.onCloseDetail ?? vi.fn()}
      renderDetail={(p) => <div data-testid="detail-pane-content">{p.name}</div>}
      renderCustomize={(p) => <div data-testid="customize-pane-content">{p.name}</div>}
    >
      <div data-testid="grid-content">grid</div>
    </ProductPaneHost>,
  );
}

describe('deriveProductPaneView', () => {
  it('is grid when both products are null', () => {
    expect(deriveProductPaneView(null, null)).toBe('grid');
  });
  it('is detail when only detailProduct is set', () => {
    expect(deriveProductPaneView(productA, null)).toBe('detail');
  });
  it('is customize when modifierProduct is set — and customize wins if both are set (defensive)', () => {
    expect(deriveProductPaneView(null, productB)).toBe('customize');
    expect(deriveProductPaneView(productA, productB)).toBe('customize');
  });
});

describe('ProductPaneHost', () => {
  it('shows the grid (flex, not hidden) when no pane product is set', () => {
    renderHost();
    expect(screen.getByTestId('grid-content')).toBeInTheDocument();
    expect(screen.getByTestId('product-pane-grid')).toHaveClass('flex');
    expect(screen.getByTestId('product-pane-grid')).not.toHaveClass('hidden');
    expect(screen.queryByTestId('product-pane-detail')).toBeNull();
    expect(screen.queryByTestId('product-pane-customize')).toBeNull();
  });

  it('keeps the grid MOUNTED but hidden while the detail pane is active (spec §1 grid preservation)', () => {
    renderHost({ detailProduct: productA });
    expect(screen.getByTestId('detail-pane-content')).toHaveTextContent('Widget');
    expect(screen.getByTestId('grid-content')).toBeInTheDocument(); // NOT unmounted
    expect(screen.getByTestId('product-pane-grid')).toHaveClass('hidden');
  });

  it('renders the customize pane from modifierProduct', () => {
    renderHost({ modifierProduct: productB });
    expect(screen.getByTestId('customize-pane-content')).toHaveTextContent('Gadget');
    expect(screen.queryByTestId('product-pane-detail')).toBeNull();
    expect(screen.getByTestId('product-pane-grid')).toHaveClass('hidden');
  });

  it('mounts NO fixed/inset overlay or modal semantics from the pane path (structural invariant, spec §5)', () => {
    const { container } = renderHost({ detailProduct: productA });
    expect(container.querySelector('.fixed')).toBeNull();
    expect(container.querySelector('[class*="inset-0"]')).toBeNull();
    expect(container.querySelector('[aria-modal]')).toBeNull();
  });

  it('Escape closes the detail pane', () => {
    const onCloseDetail = vi.fn();
    renderHost({ detailProduct: productA, onCloseDetail });
    fireEvent.keyDown(window, { key: 'Escape' });
    expect(onCloseDetail).toHaveBeenCalledTimes(1);
  });

  it('Escape does NOT close the detail pane while a modal dialog is above it (Rev 2, U1)', () => {
    const onCloseDetail = vi.fn();
    renderHost({ detailProduct: productA, onCloseDetail });

    // Stub a stacked dialog (variant picker, held, customer search…) — every
    // Modal binds its own window Esc listener (Modal.tsx:31-39); that press
    // belongs to the dialog, not the pane.
    const dialogStub = document.createElement('div');
    dialogStub.setAttribute('role', 'dialog');
    dialogStub.setAttribute('aria-modal', 'true');
    document.body.appendChild(dialogStub);

    fireEvent.keyDown(window, { key: 'Escape' });
    expect(onCloseDetail).not.toHaveBeenCalled();

    dialogStub.remove();
    fireEvent.keyDown(window, { key: 'Escape' });
    expect(onCloseDetail).toHaveBeenCalledTimes(1);
  });

  it('Escape does NOT close the customize pane (explicit confirm/cancel only, spec §4)', () => {
    const onCloseDetail = vi.fn();
    renderHost({ modifierProduct: productB, onCloseDetail });
    fireEvent.keyDown(window, { key: 'Escape' });
    expect(onCloseDetail).not.toHaveBeenCalled();
  });

  it('Escape is inert in grid view', () => {
    const onCloseDetail = vi.fn();
    renderHost({ onCloseDetail });
    fireEvent.keyDown(window, { key: 'Escape' });
    expect(onCloseDetail).not.toHaveBeenCalled();
  });
});
```

- [ ] **Step 2 — RED.** `cd apps/pos && pnpm exec vitest run src/components/pos/__tests__/ProductPaneHost.test.tsx` — fails: cannot resolve `@/components/pos/ProductPaneHost`. Kill orphaned workers.

- [ ] **Step 3 — implement.** Create `apps/pos/src/components/pos/ProductPaneHost.tsx`:

```tsx
import { useEffect, type ReactNode } from 'react';
import { cn } from '@/lib/utils';
import type { POSProduct } from '@/types/product';

/**
 * Cart-always-foreground v1 (spec §1-2, Approach A: pane replacement).
 *
 * The product area is a switched pane — grid | detail | customize — derived
 * from HomePage's existing `detailProduct` / `modifierProduct` state (no new
 * duplicated state; mutual exclusion is enforced in HomePage's setters, this
 * host only derives). The grid stays MOUNTED but hidden while a pane view is
 * active so scroll position and TanStack Virtual state survive; the cart
 * column is a sibling flex child and can never be occluded — there is no
 * overlay, no z-index, no `fixed inset-0` on this path, by construction.
 */
export type ProductPaneView = 'grid' | 'detail' | 'customize';

export function deriveProductPaneView(
  detailProduct: POSProduct | null,
  modifierProduct: POSProduct | null,
): ProductPaneView {
  // Customize wins defensively; HomePage's setters keep the two mutually
  // exclusive so both-set only happens if a future caller regresses.
  if (modifierProduct !== null) return 'customize';
  if (detailProduct !== null) return 'detail';
  return 'grid';
}

export interface ProductPaneHostProps {
  detailProduct: POSProduct | null;
  modifierProduct: POSProduct | null;
  /** Esc / X close for the DETAIL pane. Customize closes ONLY via its explicit confirm/cancel (spec §4). */
  onCloseDetail: () => void;
  renderDetail: (product: POSProduct) => ReactNode;
  renderCustomize: (product: POSProduct) => ReactNode;
  /** The grid view (TableSelector + ProductGrid). Stays mounted (hidden) while a pane is active. */
  children: ReactNode;
}

export function ProductPaneHost({
  detailProduct,
  modifierProduct,
  onCloseDetail,
  renderDetail,
  renderCustomize,
  children,
}: ProductPaneHostProps) {
  const paneView = deriveProductPaneView(detailProduct, modifierProduct);

  // Esc closes the detail pane only (spec §4): inspecting is a context you can
  // dismiss; composing is a task with an explicit confirm/cancel.
  useEffect(() => {
    if (paneView !== 'detail') return;
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key !== 'Escape') return;
      // Esc-stacking guard (Rev 2, U1): every Modal binds its own window Esc
      // listener (Modal.tsx:31-39). While a dialog is stacked above the pane
      // (variant picker, held, customer search…), that Esc belongs to the
      // dialog — bail so one press doesn't close both surfaces.
      if (document.querySelector('[aria-modal="true"]') !== null) return;
      onCloseDetail();
    };
    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  }, [paneView, onCloseDetail]);

  return (
    <div data-testid="product-pane-host" className="flex min-h-0 flex-1 flex-col">
      <div
        data-testid="product-pane-grid"
        className={cn(
          'min-h-0 flex-1 flex-col',
          // Explicit flex/hidden branch (never both classes at once) so the
          // display outcome cannot depend on Tailwind's utility output order.
          paneView === 'grid' ? 'flex' : 'hidden',
        )}
      >
        {children}
      </div>
      {/* overflow-x-auto (Rev 2, U8): the sheets carry min-w-[680px]; at
          degenerate pane widths the wrapper scrolls horizontally instead of
          clipping the tab strip and close X inside overflow-hidden parents. */}
      {paneView === 'detail' && detailProduct !== null && (
        <div data-testid="product-pane-detail" className="flex min-h-0 flex-1 flex-col overflow-x-auto">
          {renderDetail(detailProduct)}
        </div>
      )}
      {paneView === 'customize' && modifierProduct !== null && (
        <div data-testid="product-pane-customize" className="flex min-h-0 flex-1 flex-col overflow-x-auto">
          {renderCustomize(modifierProduct)}
        </div>
      )}
    </div>
  );
}
```

- [ ] **Step 4 — GREEN.** `cd apps/pos && pnpm exec vitest run src/components/pos/__tests__/ProductPaneHost.test.tsx` — all pass. `pnpm exec tsc --noEmit` — clean. Kill orphaned workers.

- [ ] **Step 5 — commit.**

```bash
git add apps/pos/src/components/pos/ProductPaneHost.tsx apps/pos/src/components/pos/__tests__/ProductPaneHost.test.tsx
git commit -m "feat(pos): ProductPaneHost — grid|detail|customize pane switch, grid hidden-not-unmounted

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: HomePage wiring — detail pane + structural invariant test

**Files:**
- Modify: `apps/pos/src/pages/HomePage.tsx` — import block (`:55`), state (`:241`), `handleCustomize` (`:984-990`), `handleViewDetails` (`:992-994`), `handleEditModifiers` (`:996-1006`), `handleRecall` (`:1303-1313`), `handleNewSale` (`:1385-1396`), product-pane layout (`:1502-1535`), overlay mount removal (`:1637-1644`).
- Test: `apps/pos/src/pages/__tests__/HomePage.paneInvariant.test.tsx` (new; harness copied from `apps/pos/src/pages/__tests__/HomePage.customerModal.test.tsx`).

**Interfaces:**
- Consumes: `ProductPaneHost` (Task 2 signature), `ProductDetailSheet` with `variant="pane"` + `activeTab`/`onTabChange` (Task 1), `DetailTab` type (barrel `@/components/organisms/ProductDetailDrawer`, re-exported from `components/pos/ProductDetailDrawer.tsx:34`), existing `detailProduct`/`modifierProduct`/`editingLineId` state, `locationStock` record and `posStockPolicy` already threaded to the grid (`HomePage.tsx:1520-1521`).
- Produces: HomePage state contract for later tasks — `handleViewDetails` clears `modifierProduct`+`editingLineId` and resets `detailTab`; `handleCustomize`/`handleEditModifiers` clear `detailProduct`. Close-on-settle (Rev 2, owner decision U4): `handleNewSale` clears `detailProduct`+`modifierProduct`+`editingLineId`; `handleRecall`'s `replaceCart` path clears `modifierProduct`+`editingLineId` but NOT `detailProduct` (detail pane unaffected, spec §4). `ProductPaneHost` mounted inside the `flex flex-[7]` container wrapping `TableSelector`+`ProductGrid` as children, with `ToastSmartPrompts` OUTSIDE the host (stays visible during panes, spec §4). Interim: `modifierProduct={null}` and `renderCustomize={() => null}` are passed to the host (Task 4 flips them); the `ModifierSelectionModal` overlay mount (`:1620-1625`) stays for now.

**Steps:**

- [ ] **Step 1 — failing test.** Create `apps/pos/src/pages/__tests__/HomePage.paneInvariant.test.tsx`. Build it from the existing HomePage render harness: copy VERBATIM from `apps/pos/src/pages/__tests__/HomePage.customerModal.test.tsx` — the lib/hook mocks (its lines 10–113), the heavy component mocks (lines 114–215), the store imports + `seedStores()` helper (lines 216–354), and the `import { HomePage } from '../HomePage';` — with exactly these FIVE mock changes, then append the new describe block below.

Change (1): replace the `@/components/organisms/ProductGrid` mock with a stub that exposes trigger buttons for the pane callbacks:

```tsx
vi.mock('@/components/organisms/ProductGrid', () => ({
  ProductGrid: ({
    onViewDetails,
    onCustomize,
  }: {
    onViewDetails?: (p: unknown) => void;
    onCustomize?: (p: unknown) => void;
  }) => (
    <div data-testid="product-grid">
      <button
        data-testid="open-detail-trigger"
        onClick={() =>
          onViewDetails?.({ id: 'p1', name: 'Widget', sku: 'W1', sale_price: '9.990', stock_quantity: 5 })
        }
      >
        open detail
      </button>
      <button
        data-testid="open-customize-trigger"
        onClick={() =>
          onCustomize?.({ id: 'p2', name: 'Gadget', sku: 'G1', sale_price: '5.000', stock_quantity: 3 })
        }
      >
        open customize
      </button>
    </div>
  ),
}));
```

Change (2): add a mock for the drawer barrel so the real sheet (which pulls product/operator/terminal stores + cross-location hooks) stays out of this structural test — the sheet's own semantics are pinned in Task 1's suite:

```tsx
vi.mock('@/components/organisms/ProductDetailDrawer', () => ({
  ProductDetailSheet: ({ product }: { product: { name: string } }) => (
    <div data-testid="product-detail-sheet-stub">{product.name}</div>
  ),
}));
```

Change (3): keep the copied `@/components/organisms/ModifierSelectionModal` mock as-is (`ModifierSelectionModal: () => null`) — Task 4 updates it.

Change (4) (Rev 2, U4): replace the `@/components/organisms/CheckoutSuccessModal` mock (`() => null`) with a trigger stub so tests can drive `handleNewSale` (HomePage passes it as `onClose`, `HomePage.tsx:1552`; the mount is gated on `lastReceipt`, which the settle test seeds):

```tsx
vi.mock('@/components/organisms/CheckoutSuccessModal', () => ({
  CheckoutSuccessModal: ({ onClose }: { onClose: () => void }) => (
    <button data-testid="new-sale-trigger" onClick={onClose}>
      new sale
    </button>
  ),
}));
```

Change (5) (Rev 2, U3/U4): replace the `@/components/organisms/HeldTransactionsModal` mock (`() => null`) with a recall trigger (HomePage passes `onRecall={(id) => void handleRecall(id)}`, `HomePage.tsx:1580`):

```tsx
vi.mock('@/components/organisms/HeldTransactionsModal', () => ({
  HeldTransactionsModal: ({ onRecall }: { onRecall: (id: string) => void }) => (
    <button data-testid="recall-trigger" onClick={() => onRecall('held-1')}>
      recall
    </button>
  ),
}));
```

Append the tests:

```tsx
// ---------------------------------------------------------------------------
// Cart-always-foreground v1 — THE story's regression guard (spec §5).
// jsdom cannot hit-test, so "cart clickable" is asserted via its structural
// proxy: with a pane open there is NO fixed+inset-0 element anywhere in the
// document, the TransactionCart is still in the tree, and the grid is hidden
// but NOT unmounted. Real pointer reachability is verified in the final
// playwright/on-device pass (plan Task 8).
// ---------------------------------------------------------------------------
describe('HomePage — pane invariant (cart always foreground)', () => {
  beforeEach(() => {
    seedStores();
  });

  it('opens the detail pane with the cart present and no fixed-inset overlay mounted', async () => {
    render(<HomePage />);
    await act(async () => {
      fireEvent.click(screen.getByTestId('open-detail-trigger'));
    });

    // Detail renders IN the pane host, not an overlay.
    expect(screen.getByTestId('product-detail-sheet-stub')).toHaveTextContent('Widget');
    expect(screen.getByTestId('product-pane-detail')).toBeInTheDocument();

    // THE invariant: no fixed inset-0 surface exists while the pane is open.
    const fixedInsetOverlays = Array.from(document.querySelectorAll('[class]')).filter(
      (el) => el.classList.contains('fixed') && el.classList.contains('inset-0'),
    );
    expect(fixedInsetOverlays).toHaveLength(0);

    // Cart untouched beside the pane.
    expect(screen.getByTestId('transaction-cart')).toBeInTheDocument();

    // Grid hidden, NOT unmounted (state preservation, spec §1).
    expect(screen.getByTestId('product-grid')).toBeInTheDocument();
    expect(screen.getByTestId('product-pane-grid')).toHaveClass('hidden');
  });

  it('Escape closes the detail pane and restores the grid', async () => {
    render(<HomePage />);
    await act(async () => {
      fireEvent.click(screen.getByTestId('open-detail-trigger'));
    });
    expect(screen.getByTestId('product-detail-sheet-stub')).toBeInTheDocument();

    await act(async () => {
      fireEvent.keyDown(window, { key: 'Escape' });
    });
    expect(screen.queryByTestId('product-detail-sheet-stub')).toBeNull();
    expect(screen.getByTestId('product-pane-grid')).not.toHaveClass('hidden');
  });

  it('opening customize closes the detail pane (setter mutual exclusion, spec §1)', async () => {
    render(<HomePage />);
    await act(async () => {
      fireEvent.click(screen.getByTestId('open-detail-trigger'));
    });
    expect(screen.getByTestId('product-detail-sheet-stub')).toBeInTheDocument();

    await act(async () => {
      fireEvent.click(screen.getByTestId('open-customize-trigger'));
    });
    // detailProduct must have been cleared by handleCustomize.
    expect(screen.queryByTestId('product-detail-sheet-stub')).toBeNull();
  });

  it('settle/new-sale closes the detail pane — the next sale starts on the grid (Rev 2, U4)', async () => {
    // CheckoutSuccessModal only mounts when lastReceipt is set (HomePage.tsx:1549-1558).
    usePaymentStore.setState({
      lastReceipt: { receipt_number: 'R-1', total: '10.000' } as never,
    } as never);
    render(<HomePage />);
    await act(async () => {
      fireEvent.click(screen.getByTestId('open-detail-trigger'));
    });
    expect(screen.getByTestId('product-detail-sheet-stub')).toBeInTheDocument();

    await act(async () => {
      fireEvent.click(screen.getByTestId('new-sale-trigger'));
    });
    expect(screen.queryByTestId('product-detail-sheet-stub')).toBeNull();
    expect(screen.getByTestId('product-pane-grid')).not.toHaveClass('hidden');
  });

  it('recall leaves the DETAIL pane open (spec §4: detail is product context, not cart state)', async () => {
    // seedStores' recallTransaction resolves undefined (early return before
    // replaceCart) — override it so handleRecall reaches the replaceCart path.
    useHoldStore.setState({
      recallTransaction: vi
        .fn()
        .mockResolvedValue({ items: [], transactionDiscount: undefined }) as never,
    } as never);
    render(<HomePage />);
    await act(async () => {
      fireEvent.click(screen.getByTestId('open-detail-trigger'));
    });
    await act(async () => {
      fireEvent.click(screen.getByTestId('recall-trigger'));
    });
    expect(screen.getByTestId('product-detail-sheet-stub')).toBeInTheDocument();
  });
});
```

(The customize-side of U3/U4 — recall clearing the composer, and the vanished-line confirm guard — is tested in Task 4 Step 5, where the composer stub exists.)

- [ ] **Step 2 — RED.** `cd apps/pos && pnpm exec vitest run src/pages/__tests__/HomePage.paneInvariant.test.tsx` — fails (the detail still mounts via the old overlay `ProductDetailDrawer`, which the barrel mock doesn't export → HomePage import of the barrel breaks, and/or no `product-pane-detail` testid exists). Kill orphaned workers.

- [ ] **Step 3 — implement HomePage wiring.** In `apps/pos/src/pages/HomePage.tsx`:

(a) Replace the import at line 55:

```tsx
// BEFORE
import { ProductDetailDrawer } from '@/components/organisms/ProductDetailDrawer';
// AFTER
import { ProductDetailSheet, type DetailTab } from '@/components/organisms/ProductDetailDrawer';
import { ProductPaneHost } from '@/components/pos/ProductPaneHost';
```

(b) After the `detailProduct` state (line 241), add:

```tsx
  // Detail pane tab state (cart-always-foreground v1) — owned here since the
  // overlay host that owned it is being retired. Reset to 'details' on every
  // open: same effective semantics as the old product-id reset (reopening
  // always started on Details — ProductDetailDrawer.tsx:52-61 pre-deletion).
  const [detailTab, setDetailTab] = useState<DetailTab>('details');
```

(c) Replace `handleCustomize` (lines 984-990) and `handleViewDetails` (lines 992-994):

```tsx
  const handleCustomize = useCallback(
    (product: POSProduct) => {
      setModifierProduct(product);
      setEditingLineId(null);
      // Pane mutual exclusion (spec §1): opening customize closes detail.
      setDetailProduct(null);
    },
    [],
  );

  const handleViewDetails = useCallback((product: POSProduct) => {
    setDetailTab('details');
    setDetailProduct(product);
    // Pane mutual exclusion (spec §1): opening detail closes customize.
    setModifierProduct(null);
    setEditingLineId(null);
  }, []);
```

(d) In `handleEditModifiers` (lines 996-1006), add `setDetailProduct(null);` immediately after `setEditingLineId(itemId);`.

(d2) **Close-on-settle + recall invalidation (Rev 2, U3/U4).** Replace `handleNewSale` (lines 1385-1396) with:

```tsx
  const handleNewSale = useCallback(() => {
    setShowSuccessModal(false);
    // Checkout-success teardown — NOT a discard (no fraud signal).
    clearCart('checkout');
    clearLastReceipt();
    setSelectedTableId(null);
    // Close-on-settle (Rev 2, owner decision U4): the next sale starts on the
    // grid — never on the previous customer's product — including through
    // lock-after-sale. editingLineId is its own state; clear it explicitly.
    setDetailProduct(null);
    setModifierProduct(null);
    setEditingLineId(null);

    // Lock screen after sale if enabled
    if (useSettingsStore.getState().lockAfterSale) {
      useOperatorStore.getState().lock();
    }
  }, [clearCart, clearLastReceipt]);
```

and replace `handleRecall` (lines 1303-1313) with:

```tsx
  const handleRecall = useCallback(
    async (id: string) => {
      const tx = await recallTransaction(id);
      if (!tx) return;

      // Atomic replace — avoids the per-item setState loop that amplified BG3.
      useCartStore.getState().replaceCart(tx.items, tx.transactionDiscount);
      // Rev 2 (U3): the recalled cart invalidates any in-flight customize-EDIT
      // (a dangling editingLineId would make Confirm a silent no-op). The
      // DETAIL pane is deliberately NOT cleared — it is product context, not
      // cart state (spec §4).
      setModifierProduct(null);
      setEditingLineId(null);
      setShowHeldModal(false);
    },
    [recallTransaction],
  );
```

(e) Replace the product-pane container (lines 1502-1535) with:

```tsx
      {/* Product pane — grid | detail | customize (cart-always-foreground v1).
          The cart column above is the untouched sibling flex child, so pane
          views can never occlude it (spec §1, Approach A). ToastSmartPrompts
          stays OUTSIDE the host: inline, non-occluding, cart-relevant (§4). */}
      <div className="flex flex-[7] flex-col overflow-hidden bg-surface-canvas p-2">
        <ProductPaneHost
          detailProduct={detailProduct}
          modifierProduct={null /* Task 4 flips this to modifierProduct + the extracted composer */}
          onCloseDetail={() => setDetailProduct(null)}
          renderDetail={(product) => (
            <ProductDetailSheet
              variant="pane"
              product={product}
              onClose={() => setDetailProduct(null)}
              locationStock={locationStock[product.id]}
              hardBlockOutOfStock={posStockPolicy === 'block'}
              activeTab={detailTab}
              onTabChange={setDetailTab}
            />
          )}
          renderCustomize={() => null /* Task 4 renders ModifierComposerSheet here */}
        >
          {isFnB && consumptionMode === 'SUR_PLACE' && (
            <div className="mb-2">
              <TableSelector
                selectedTableId={selectedTableId}
                onSelectTable={setSelectedTableId}
              />
            </div>
          )}
          <ProductGrid
            products={products}
            categories={categories}
            onAddToCart={handleAddToCart}
            onCustomize={handleCustomize}
            onViewDetails={handleViewDetails}
            cartProductIds={cartProductIds}
            cartQuantities={cartQuantities}
            isLoading={productsLoading}
            locationStock={locationStock}
            hardBlockOutOfStock={posStockPolicy === 'block'}
            consumptionModeToggle={isFnB ? (
              <ConsumptionModeToggle
                value={consumptionMode}
                onChange={handleConsumptionModeChange}
              />
            ) : undefined}
            filters={filtresFilters}
            onFiltersChange={setFiltresFilters}
            customerSkinType={customerSkinType}
          />
        </ProductPaneHost>
        {(smartPromptsVariant === 'toast' || smartPromptsVariant === 'both') && (
          <ToastSmartPrompts {...smartPromptsSharedProps} />
        )}
      </div>
```

(The `ProductGrid` props are copied unchanged from the current lines 1511-1531 — only the wrapper changes. Rev 2, C5: the re-authored wrapper line migrates the old `bg-gray-50` to `bg-surface-canvas` — repo rule 18 requires token migration on touched lines.)

**Hidden-grid device fallback (Rev 2, U6):** `display:none` + TanStack Virtual scroll restoration is browser-behavioral and invisible to jsdom. If the Task 8 device/playwright pass shows the grid returning scrolled-to-top (or virtualizer thrash) after a pane closes, the named fallback is: capture the grid scroll container's `scrollTop` on hide and restore it on show, or switch `ProductPaneHost`'s hidden branch to `visibility`-based hiding (keeps layout) — contained inside `ProductPaneHost`, no API change.

(f) Delete the overlay mount at lines 1637-1644 (`{/* Product detail drawer — eye icon … */}` + the `<ProductDetailDrawer …/>` element).

- [ ] **Step 4 — GREEN + regressions.** `cd apps/pos && pnpm exec vitest run src/pages/__tests__/HomePage.paneInvariant.test.tsx src/pages/__tests__/HomePage.customerModal.test.tsx src/lib/stock/__tests__/homePageIngressPin.test.ts` — all pass (the ingress pin guards that this rewiring didn't introduce raw store adds; customerModal guards the harnessed HomePage still renders). `pnpm exec tsc --noEmit` — clean. Kill orphaned workers.

- [ ] **Step 5 — commit.**

```bash
git add apps/pos/src/pages/HomePage.tsx apps/pos/src/pages/__tests__/HomePage.paneInvariant.test.tsx
git commit -m "feat(pos): host product detail as an in-pane view — cart always visible (invariant test)

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 4: `ModifierComposerSheet` extraction + customize pane

**Files:**
- Create: `apps/pos/src/components/organisms/ModifierSelectionModal/ModifierComposerSheet.tsx`
- Delete: `apps/pos/src/components/organisms/ModifierSelectionModal/ModifierSelectionModal.tsx`, `apps/pos/src/components/organisms/ModifierSelectionModal/__tests__/ModifierSelectionModal.test.tsx`
- Modify: `apps/pos/src/components/organisms/ModifierSelectionModal/index.ts` (barrel), `apps/pos/src/pages/HomePage.tsx` (import `:63`, host props from Task 3 step (e), modal mount `:1619-1625` — line numbers shift slightly after Task 3; locate by content), `apps/pos/src/locales/fr/pos.json` + `apps/pos/src/locales/en/pos.json` (`modifiers.cancel`, `modifiers.lineGone`), `apps/pos/src/pages/__tests__/HomePage.customerModal.test.tsx` + `apps/pos/src/pages/__tests__/HomePage.paneInvariant.test.tsx` (mock factory updates)
- Test: `apps/pos/src/components/organisms/ModifierSelectionModal/__tests__/ModifierComposerSheet.test.tsx` (new; ports the old suite)

**Interfaces:**
- Consumes: `POSProduct` (`@/types/product`), `ModifierGroup`/`Modifier` (`@/types/modifier`), `SelectedModifier` (`@/types/cart`), `useCurrency` (`@/lib/currency`), `bcadd`/`bcsum` (`apps/pos/src/lib/decimal.ts:22,51` — ALWAYS pass the explicit `decimals` scale to these two, never rely on the default 3) and `bccomp` (`decimal.ts:42` — `bccomp(a, b)` takes NO scale argument; Rev 2, C6), `cn`, `Check`/`X` from lucide-react. Host contract from Task 2 (`renderCustomize`). HomePage's `handleModifierConfirm` (`HomePage.tsx:1008-1022`) already adds-and-closes on confirm; this task hardens its EDIT path (Rev 2, U3 — see Step 6 (b2)).
- Produces:

```tsx
export interface ModifierComposerSheetProps {
  product: POSProduct;
  onConfirm: (selectedModifiers: SelectedModifier[]) => void;
  onClose: () => void;
}
export function ModifierComposerSheet(props: ModifierComposerSheetProps): JSX.Element;
```

Barrel `index.ts` re-exports exactly `ModifierComposerSheet` + `ModifierComposerSheetProps` (the `ModifierSelectionModal` export is removed). New i18n keys: `modifiers.cancel` (fr "Annuler" / en "Cancel") and `modifiers.lineGone` (fr "Ligne introuvable — le panier a changé" / en "Line not found — the cart changed"; Rev 2, U3). Root: `role="region"`, `aria-label={t('modifiers.customize')}`, `data-testid="modifier-composer-sheet"`, `h-full w-full min-w-[680px]`, own header with title + 48px close X. Gating/pricing/quantity semantics byte-identical to the old modal (except decimal-safe math, decision 4).

**Steps:**

- [ ] **Step 1 — failing test.** Create `apps/pos/src/components/organisms/ModifierSelectionModal/__tests__/ModifierComposerSheet.test.tsx` (port of the old suite — the fixtures `toppingsGroup`/`sizeGroup`/`productWithModifiers` are copied verbatim from `ModifierSelectionModal.test.tsx:50-84`):

```tsx
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ModifierComposerSheet, type ModifierComposerSheetProps } from '../ModifierComposerSheet';
import { makeProduct, makeModifierGroup, makeModifier } from '@/test/helpers';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) => {
      if (opts) return `${key}:${JSON.stringify(opts)}`;
      return key;
    },
  }),
}));

vi.mock('@/lib/currency', () => ({
  useCurrency: () => ({
    currency: 'EUR',
    decimals: 2,
    format: (amount: number | string) => {
      const num = typeof amount === 'string' ? parseFloat(amount) : amount;
      return `${num.toFixed(2)} EUR`;
    },
  }),
}));

const toppingsGroup = makeModifierGroup({
  id: 'grp-1',
  name: 'Toppings',
  selection_type: 'multiple',
  min_selections: 0,
  max_selections: 3,
  is_required: false,
  modifiers: [
    makeModifier({ id: 'mod-1', name: 'Extra Cheese', price_adjustment: '2.00', position: 1 }),
    makeModifier({ id: 'mod-2', name: 'Bacon', price_adjustment: '3.00', position: 2 }),
    makeModifier({ id: 'mod-3', name: 'Mushrooms', price_adjustment: '1.50', position: 3 }),
  ],
});

const sizeGroup = makeModifierGroup({
  id: 'grp-2',
  name: 'Size',
  selection_type: 'single',
  min_selections: 1,
  max_selections: 1,
  is_required: true,
  modifiers: [
    makeModifier({ id: 'mod-s', name: 'Small', price_adjustment: '0.00', position: 1 }),
    makeModifier({ id: 'mod-m', name: 'Medium', price_adjustment: '2.00', position: 2 }),
    makeModifier({ id: 'mod-l', name: 'Large', price_adjustment: '4.00', position: 3 }),
  ],
});

const productWithModifiers = makeProduct({
  id: 'burger-1',
  name: 'Classic Burger',
  sale_price: '10.00',
  modifier_groups: [toppingsGroup, sizeGroup],
});

function renderSheet(overrides: Partial<ModifierComposerSheetProps> = {}) {
  const defaults: ModifierComposerSheetProps = {
    product: productWithModifiers,
    onClose: vi.fn(),
    onConfirm: vi.fn(),
  };
  return render(<ModifierComposerSheet {...defaults} {...overrides} />);
}

describe('ModifierComposerSheet (pane-hosted composer — parity with the retired ModifierSelectionModal)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders as a non-modal region with its own header (title + close)', () => {
    const onClose = vi.fn();
    renderSheet({ onClose });
    const sheet = screen.getByTestId('modifier-composer-sheet');
    expect(sheet).toHaveAttribute('role', 'region');
    expect(sheet).not.toHaveAttribute('aria-modal');
    expect(sheet).toHaveClass('h-full');
    expect(sheet).toHaveClass('w-full');
    expect(sheet.className).not.toContain('fixed');
    expect(screen.getByText('modifiers.customize')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'modifiers.cancel' }));
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('renders product name and base price', () => {
    renderSheet();
    expect(screen.getByText('Classic Burger')).toBeInTheDocument();
    expect(screen.getAllByText(/10\.00 EUR/).length).toBeGreaterThanOrEqual(1);
  });

  it('shows all modifier groups and their options simultaneously', () => {
    renderSheet();
    expect(screen.getByText('Toppings')).toBeInTheDocument();
    expect(screen.getByText('Size')).toBeInTheDocument();
    expect(screen.getByText('Extra Cheese')).toBeInTheDocument();
    expect(screen.getByText('Bacon')).toBeInTheDocument();
    expect(screen.getByText('Mushrooms')).toBeInTheDocument();
    expect(screen.getByText('Small')).toBeInTheDocument();
    expect(screen.getByText('Medium')).toBeInTheDocument();
    expect(screen.getByText('Large')).toBeInTheDocument();
  });

  it('toggles modifier selection in multiple mode (total updates)', () => {
    renderSheet();
    fireEvent.click(screen.getByText('Extra Cheese'));
    expect(screen.getByText('12.00 EUR')).toBeInTheDocument();
    fireEvent.click(screen.getByText('Extra Cheese'));
    expect(screen.getByText('10.00 EUR')).toBeInTheDocument();
  });

  it('respects max selections in multiple mode', () => {
    const limitedGroup = makeModifierGroup({ ...toppingsGroup, max_selections: 1 });
    const product = makeProduct({ ...productWithModifiers, modifier_groups: [limitedGroup] });
    renderSheet({ product });
    fireEvent.click(screen.getByText('Extra Cheese'));
    fireEvent.click(screen.getByText('Bacon'));
    // max 1 → the Bacon click was ignored: total = 10 + 2 (cheese)
    expect(screen.getByText('12.00 EUR')).toBeInTheDocument();
  });

  it('disables confirm when a required group is not satisfied', () => {
    renderSheet();
    const addButton = screen.getByText('modifiers.addToCart');
    expect(addButton.closest('button')).toBeDisabled();
    expect(screen.getByText('modifiers.required')).toBeInTheDocument();
  });

  it('enables confirm when all required groups are satisfied', () => {
    renderSheet();
    fireEvent.click(screen.getByText('Small'));
    expect(screen.getByText('modifiers.addToCart').closest('button')).not.toBeDisabled();
  });

  it('calls onConfirm with the selected modifiers', () => {
    const onConfirm = vi.fn();
    renderSheet({ onConfirm });
    fireEvent.click(screen.getByText('Bacon'));
    fireEvent.click(screen.getByText('Medium'));
    fireEvent.click(screen.getByText('modifiers.addToCart'));
    expect(onConfirm).toHaveBeenCalledOnce();
    const selectedModifiers = onConfirm.mock.calls[0]![0];
    expect(selectedModifiers).toHaveLength(2);
    expect(selectedModifiers).toEqual(
      expect.arrayContaining([
        expect.objectContaining({ modifier_id: 'mod-2', name: 'Bacon' }),
        expect.objectContaining({ modifier_id: 'mod-m', name: 'Medium' }),
      ]),
    );
  });

  it('shows price adjustments for modifiers', () => {
    renderSheet();
    expect(screen.getAllByText('+2.00 EUR').length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText('+3.00 EUR')).toBeInTheDocument();
    expect(screen.getByText('+1.50 EUR')).toBeInTheDocument();
  });

  it('resets selections to defaults when the product changes in place', () => {
    const { rerender } = renderSheet();
    fireEvent.click(screen.getByText('Extra Cheese'));
    expect(screen.getByText('12.00 EUR')).toBeInTheDocument();
    const otherProduct = makeProduct({
      id: 'pizza-1',
      name: 'Pizza',
      sale_price: '10.00',
      modifier_groups: [toppingsGroup, sizeGroup],
    });
    rerender(
      <ModifierComposerSheet product={otherProduct} onClose={vi.fn()} onConfirm={vi.fn()} />,
    );
    // Fresh defaults: no toppings selected, back to base price.
    expect(screen.getByText('10.00 EUR')).toBeInTheDocument();
  });
});
```

- [ ] **Step 2 — RED.** `cd apps/pos && pnpm exec vitest run "src/components/organisms/ModifierSelectionModal/__tests__/ModifierComposerSheet.test.tsx"` — fails: cannot resolve `../ModifierComposerSheet`. Kill orphaned workers.

- [ ] **Step 3 — implement the composer.** Create `apps/pos/src/components/organisms/ModifierSelectionModal/ModifierComposerSheet.tsx` (logic lifted from `ModifierSelectionModal.tsx` with the Modal shell replaced by an own header; `parseFloat`-on-money replaced by decimal helpers — decision 4):

```tsx
import { useState, useMemo, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { Check, X } from 'lucide-react';
import { useCurrency } from '@/lib/currency';
import { bcadd, bccomp, bcsum } from '@/lib/decimal';
import { cn } from '@/lib/utils';
import type { POSProduct } from '@/types/product';
import type { ModifierGroup, Modifier } from '@/types/modifier';
import type { SelectedModifier } from '@/types/cart';

/**
 * Cart-always-foreground v1 (spec §2.3) — the modifier composition UI as a
 * pure pane-hosted content component, extracted from the retired
 * ModifierSelectionModal (same pattern as ProductDetailSheet). Gating,
 * pricing, and selection semantics are unchanged; the <Modal> shell is gone.
 * Confirm → onConfirm (host adds + closes); the header X / footer → onClose.
 * NO Esc handling here: composing is a task, closed only explicitly (spec §4).
 */
export interface ModifierComposerSheetProps {
  product: POSProduct;
  onConfirm: (selectedModifiers: SelectedModifier[]) => void;
  onClose: () => void;
}

type SelectionMap = Record<string, Set<string>>;

function getDefaultSelections(groups: ModifierGroup[]): SelectionMap {
  const selections: SelectionMap = {};
  for (const group of groups) {
    const defaults = new Set<string>();
    for (const mod of group.modifiers) {
      if (mod.is_default && mod.is_active) {
        defaults.add(mod.id);
      }
    }
    selections[group.id] = defaults;
  }
  return selections;
}

function isGroupSatisfied(group: ModifierGroup, selected: Set<string>): boolean {
  return selected.size >= group.min_selections;
}

export function ModifierComposerSheet({ product, onConfirm, onClose }: ModifierComposerSheetProps) {
  const { t } = useTranslation('pos');
  const { format, decimals } = useCurrency();
  const groups = product.modifier_groups ?? [];

  const [selections, setSelections] = useState<SelectionMap>(() => getDefaultSelections(groups));

  // Reset to defaults when the product swaps in place (pane host keeps this
  // component mounted across a product change). Render-time reset — the same
  // prev-id pattern the detail sheet's old host used.
  const [prevProductId, setPrevProductId] = useState(product.id);
  if (product.id !== prevProductId) {
    setPrevProductId(product.id);
    setSelections(getDefaultSelections(groups));
  }

  const handleToggleModifier = useCallback(
    (group: ModifierGroup, modifier: Modifier) => {
      setSelections((prev) => {
        const current = new Set(prev[group.id] ?? []);

        if (group.selection_type === 'single') {
          // Radio behavior: replace
          const next = new Set<string>();
          if (!current.has(modifier.id)) {
            next.add(modifier.id);
          }
          return { ...prev, [group.id]: next };
        }

        // Multiple: toggle with max cap
        if (current.has(modifier.id)) {
          current.delete(modifier.id);
        } else if (current.size < group.max_selections) {
          current.add(modifier.id);
        }
        return { ...prev, [group.id]: new Set(current) };
      });
    },
    [],
  );

  const allValid = useMemo(() => {
    return groups.every((g) => isGroupSatisfied(g, selections[g.id] ?? new Set()));
  }, [groups, selections]);

  // Decimal-string total (rule 19: never parseFloat money). Same displayed
  // value as the old float math for every currency-scale input.
  const totalPrice = useMemo(() => {
    const adjustments: string[] = [];
    for (const group of groups) {
      const selected = selections[group.id] ?? new Set<string>();
      for (const mod of group.modifiers) {
        if (selected.has(mod.id)) {
          adjustments.push(mod.price_adjustment);
        }
      }
    }
    return bcadd(product.sale_price ?? '0', bcsum(adjustments, decimals), decimals);
  }, [product.sale_price, groups, selections, decimals]);

  const handleConfirm = useCallback(() => {
    if (!allValid) return;

    const selectedModifiers: SelectedModifier[] = [];
    for (const group of groups) {
      const selected = selections[group.id] ?? new Set();
      for (const mod of group.modifiers) {
        if (selected.has(mod.id)) {
          selectedModifiers.push({
            modifier_id: mod.id,
            modifier_group_id: group.id,
            name: mod.name,
            group_name: group.name,
            price_adjustment: mod.price_adjustment,
          });
        }
      }
    }

    onConfirm(selectedModifiers);
  }, [allValid, groups, selections, onConfirm]);

  return (
    <section
      role="region"
      aria-label={t('modifiers.customize')}
      data-testid="modifier-composer-sheet"
      className="relative flex h-full w-full min-w-[680px] flex-col overflow-hidden rounded-panel bg-surface-overlay shadow-sm"
    >
      {/* Own header — the Modal shell used to provide title + close. */}
      <div className="flex shrink-0 items-center justify-between border-b border-border-subtle px-6 py-4">
        <h2 className="text-xl font-bold text-ink">{t('modifiers.customize')}</h2>
        <button
          type="button"
          onClick={onClose}
          aria-label={t('modifiers.cancel')}
          className="flex h-12 w-12 shrink-0 items-center justify-center rounded-ctl border border-border-subtle bg-surface-raised text-ink-muted active:bg-surface-sunken"
        >
          <X className="h-5 w-5" aria-hidden="true" />
        </button>
      </div>

      <div className="flex min-h-0 flex-1 flex-col px-6 py-4">
        {/* Product header */}
        <div className="mb-4 shrink-0 rounded-card bg-surface-sunken px-4 py-3">
          <h3 className="text-lg font-bold text-ink">{product.name}</h3>
          <p className="text-sm text-ink-muted">
            {t('modifiers.basePrice')}: {format(product.sale_price ?? '0')}
          </p>
        </div>

        {/* All groups visible */}
        <div className="min-h-0 flex-1 overflow-y-auto">
          {groups.map((group) => {
            const selected = selections[group.id] ?? new Set();
            const satisfied = isGroupSatisfied(group, selected);

            return (
              <div key={group.id} className="mb-4">
                <div className="mb-2 flex items-center justify-between">
                  <span className="text-sm font-semibold text-ink">
                    {group.name}
                    {group.is_required && !satisfied && (
                      <span className="ml-1 text-danger">*</span>
                    )}
                  </span>
                  {group.selection_type === 'multiple' && (
                    <span className="text-xs text-ink-muted">
                      {t('modifiers.selectUpTo', { max: group.max_selections })}
                      {' '}({selected.size}/{group.max_selections})
                    </span>
                  )}
                </div>

                <div className="flex flex-wrap gap-2">
                  {group.modifiers
                    .filter((m) => m.is_active)
                    .sort((a, b) => a.position - b.position)
                    .map((modifier) => {
                      const isSelected = selected.has(modifier.id);
                      const priceAdjSign = bccomp(modifier.price_adjustment, '0');

                      return (
                        <button
                          key={modifier.id}
                          onClick={() => handleToggleModifier(group, modifier)}
                          className={cn(
                            'flex min-h-12 items-center gap-2 rounded-pill border-2 px-4 py-2 text-sm font-medium transition-all',
                            isSelected
                              ? 'border-accent bg-accent-tint text-accent-strong'
                              : 'border-border-subtle bg-surface-raised text-ink-muted hover:border-border-strong',
                          )}
                        >
                          {isSelected && (
                            <Check className="h-3.5 w-3.5 text-accent-strong" />
                          )}
                          {modifier.name}
                          {priceAdjSign !== 0 && (
                            <span className={cn(
                              'text-xs',
                              priceAdjSign > 0 ? 'text-ink-muted' : 'text-success-strong',
                            )}>
                              {priceAdjSign > 0 ? '+' : ''}{format(modifier.price_adjustment)}
                            </span>
                          )}
                        </button>
                      );
                    })}
                </div>
              </div>
            );
          })}
        </div>

        {/* Footer: total + add to cart */}
        <div className="mt-2 shrink-0 border-t border-border-subtle pt-2">
          <div className="mb-3 flex items-center justify-between">
            <span className="text-sm text-ink-muted">
              {t('modifiers.selected')}: {Object.values(selections).reduce((sum, s) => sum + s.size, 0)}
            </span>
            <span className="text-xl font-bold text-ink">{format(totalPrice)}</span>
          </div>
          <button
            onClick={handleConfirm}
            disabled={!allValid}
            className="flex min-h-[48px] w-full items-center justify-center gap-2 rounded-ctl bg-action px-6 py-3 text-base font-semibold text-ink-inverse transition-colors hover:bg-action-hover disabled:cursor-not-allowed disabled:opacity-50"
          >
            {t('modifiers.addToCart')}
          </button>
          {!allValid && (
            <p className="mt-2 text-center text-sm text-danger">
              {t('modifiers.required')}
            </p>
          )}
        </div>
      </div>
    </section>
  );
}
```

Two intentional deltas from the source, both preserving behavior: chip buttons `py-2` → `min-h-12` added (touch-target floor; the old `py-2` chips were ~38px) and `if (!allValid || !product) return` → `if (!allValid) return` (`product` is now non-nullable).

Add the i18n keys to BOTH locale files, inside the existing `"modifiers"` object:
- `apps/pos/src/locales/fr/pos.json` → `"cancel": "Annuler"` and `"lineGone": "Ligne introuvable — le panier a changé"`
- `apps/pos/src/locales/en/pos.json` → `"cancel": "Cancel"` and `"lineGone": "Line not found — the cart changed"`

(`modifiers.lineGone` is consumed by HomePage's confirm guard in Step 6 (b2) — Rev 2, U3.)

- [ ] **Step 4 — GREEN (component).** `cd apps/pos && pnpm exec vitest run "src/components/organisms/ModifierSelectionModal/__tests__/ModifierComposerSheet.test.tsx"` — all pass. Kill orphaned workers. Commit:

```bash
git add apps/pos/src/components/organisms/ModifierSelectionModal/ModifierComposerSheet.tsx "apps/pos/src/components/organisms/ModifierSelectionModal/__tests__/ModifierComposerSheet.test.tsx" apps/pos/src/locales/fr/pos.json apps/pos/src/locales/en/pos.json
git commit -m "feat(pos): extract ModifierComposerSheet (pane-hosted composer, decimal-safe totals)

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

- [ ] **Step 5 — failing wiring test.** In `apps/pos/src/pages/__tests__/HomePage.paneInvariant.test.tsx`, replace the `@/components/organisms/ModifierSelectionModal` mock factory with a stub that also surfaces the confirm callback (Rev 2, U3):

```tsx
vi.mock('@/components/organisms/ModifierSelectionModal', () => ({
  ModifierComposerSheet: ({
    product,
    onConfirm,
  }: {
    product: { name: string };
    onConfirm: (selectedModifiers: unknown[]) => void;
  }) => (
    <div data-testid="modifier-composer-stub">
      {product.name}
      <button data-testid="composer-confirm-trigger" onClick={() => onConfirm([])}>
        confirm
      </button>
    </div>
  ),
}));
```

Also (Rev 2, U3): replace the copied `@/components/organisms/TransactionCart` mock with one that surfaces `onEditModifiers` (drives `handleEditModifiers` → the customize-EDIT path):

```tsx
vi.mock('@/components/organisms/TransactionCart', () => ({
  TransactionCart: ({
    customerControl,
    onEditModifiers,
  }: {
    customerControl?: ReactNode;
    onEditModifiers?: (itemId: string) => void;
  }) => (
    <div data-testid="transaction-cart">
      {customerControl}
      <button data-testid="edit-line-trigger" onClick={() => onEditModifiers?.('line-1')}>
        edit line
      </button>
    </div>
  ),
}));
```

and add a sonner mock at the top of the file (with the other module mocks) so the U3 toast is assertable — HomePage does not mount `<Toaster>` (that lives in `App.tsx:440`), so mocking only `toast` is safe:

```tsx
vi.mock('sonner', () => ({
  toast: { error: vi.fn(), success: vi.fn(), warning: vi.fn() },
}));
```

Then append to the `describe('HomePage — pane invariant …')` block:

```tsx
  it('hosts customize as an in-pane view (no fixed-inset overlay) and detail↔customize stay exclusive', async () => {
    render(<HomePage />);
    await act(async () => {
      fireEvent.click(screen.getByTestId('open-customize-trigger'));
    });

    expect(screen.getByTestId('modifier-composer-stub')).toHaveTextContent('Gadget');
    expect(screen.getByTestId('product-pane-customize')).toBeInTheDocument();
    const fixedInsetOverlays = Array.from(document.querySelectorAll('[class]')).filter(
      (el) => el.classList.contains('fixed') && el.classList.contains('inset-0'),
    );
    expect(fixedInsetOverlays).toHaveLength(0);
    expect(screen.getByTestId('transaction-cart')).toBeInTheDocument();
    expect(screen.getByTestId('product-pane-grid')).toHaveClass('hidden');

    // Opening detail closes customize (mutual exclusion, the other direction).
    await act(async () => {
      fireEvent.click(screen.getByTestId('open-detail-trigger'));
    });
    expect(screen.queryByTestId('modifier-composer-stub')).toBeNull();
    expect(screen.getByTestId('product-detail-sheet-stub')).toBeInTheDocument();
  });

  it('recall clears the CUSTOMIZE pane (cart replacement invalidates the in-flight edit, Rev 2 U3/U4)', async () => {
    useHoldStore.setState({
      recallTransaction: vi
        .fn()
        .mockResolvedValue({ items: [], transactionDiscount: undefined }) as never,
    } as never);
    render(<HomePage />);
    await act(async () => {
      fireEvent.click(screen.getByTestId('open-customize-trigger'));
    });
    expect(screen.getByTestId('modifier-composer-stub')).toBeInTheDocument();

    await act(async () => {
      fireEvent.click(screen.getByTestId('recall-trigger'));
    });
    expect(screen.queryByTestId('modifier-composer-stub')).toBeNull();
  });

  it('customize-EDIT confirm on a vanished line toasts and closes — never a silent no-op (Rev 2, U3)', async () => {
    const { toast } = await import('sonner');
    // Seed a cart line + its matching product so handleEditModifiers
    // (HomePage.tsx:996-1006) can open the composer in EDIT mode.
    const gadget = { id: 'p2', name: 'Gadget', sku: 'G1', sale_price: '5.000', stock_quantity: 3 };
    useProductStore.setState({ products: [gadget] } as never);
    useCartStore.setState({
      items: [{ id: 'line-1', product: { id: 'p2', name: 'Gadget' }, quantity: 1 }] as never,
    } as never);

    render(<HomePage />);
    await act(async () => {
      fireEvent.click(screen.getByTestId('edit-line-trigger'));
    });
    expect(screen.getByTestId('modifier-composer-stub')).toBeInTheDocument();

    // The cart stays interactive while composing — the edited line vanishes
    // under the composer (removal / recall / clear).
    act(() => {
      useCartStore.setState({ items: [] } as never);
    });

    await act(async () => {
      fireEvent.click(screen.getByTestId('composer-confirm-trigger'));
    });
    expect(vi.mocked(useCartStore.getState().updateLineModifiers)).not.toHaveBeenCalled();
    expect(vi.mocked(toast.error)).toHaveBeenCalledWith('modifiers.lineGone');
    expect(screen.queryByTestId('modifier-composer-stub')).toBeNull();
  });
```

(The harness mocks `react-i18next` with `t: (key) => key`, so the toast asserts the raw key; `updateLineModifiers` is the `vi.fn()` seeded by `seedStores`.)

Run `cd apps/pos && pnpm exec vitest run src/pages/__tests__/HomePage.paneInvariant.test.tsx` — the new tests fail (customize still renders through the old modal overlay path / `renderCustomize` returns null; the confirm guard doesn't exist yet). Kill orphaned workers.

- [ ] **Step 6 — wire HomePage + delete the modal.** In `apps/pos/src/pages/HomePage.tsx`:

(a) Replace the import (line 63): `import { ModifierSelectionModal } from '@/components/organisms/ModifierSelectionModal';` → `import { ModifierComposerSheet } from '@/components/organisms/ModifierSelectionModal';`

(b) In the `ProductPaneHost` mount from Task 3, replace the two interim props:

```tsx
          modifierProduct={modifierProduct}
```

and:

```tsx
          renderCustomize={(product) => (
            <ModifierComposerSheet
              product={product}
              onConfirm={handleModifierConfirm}
              onClose={() => {
                setModifierProduct(null);
                setEditingLineId(null);
              }}
            />
          )}
```

(`handleModifierConfirm`, `HomePage.tsx:1008-1022`, already performs the gated add / line-modifier update AND closes via `setModifierProduct(null)` + `setEditingLineId(null)` — confirm-closes-back-to-grid per spec §1. Rev 2 adds the U3 guard below; the rest is unchanged.)

(b2) **Vanished-line confirm guard (Rev 2, U3).** The cart is interactive while composing, and `updateLineModifiers` silently no-ops on a missing line id (`cartStore.ts:371-393`) — validate and give feedback instead. Add `import { toast } from 'sonner';` to HomePage's imports (sonner is the app's established toast idiom, cf. `cartIngress.ts:16`; HomePage's `t` comes from the existing `useTranslation()` at `:100`). Replace `handleModifierConfirm` (lines 1008-1022) with:

```tsx
  const handleModifierConfirm = useCallback(
    (selectedModifiers: SelectedModifier[]) => {
      if (!modifierProduct) return;
      if (editingLineId) {
        // Rev 2 (U3): the cart stays interactive while composing — the edited
        // line can vanish under the composer (removal, recall, clear).
        // updateLineModifiers silently no-ops on a missing id, so validate
        // and toast instead of a silent nothing.
        const lineStillExists = useCartStore
          .getState()
          .items.some((item) => item.id === editingLineId);
        if (!lineStillExists) {
          toast.error(t('modifiers.lineGone'));
          setModifierProduct(null);
          setEditingLineId(null);
          return;
        }
        // Editing modifiers on an EXISTING line never changes quantity — ungated.
        updateLineModifiers(editingLineId, selectedModifiers);
      } else {
        // Task 11 — a new modifier-configured line is a stock add: gated.
        void addItemGated(modifierProduct, { selectedModifiers });
      }
      setModifierProduct(null);
      setEditingLineId(null);
    },
    [modifierProduct, editingLineId, updateLineModifiers, t],
  );
```

(c) Delete the `{/* Modifier selection modal */}` mount (the `<ModifierSelectionModal …/>` block, pre-Task-3 lines 1619-1625).

(d) Replace the barrel `apps/pos/src/components/organisms/ModifierSelectionModal/index.ts` with:

```ts
export { ModifierComposerSheet } from './ModifierComposerSheet';
export type { ModifierComposerSheetProps } from './ModifierComposerSheet';
```

(e) `git rm apps/pos/src/components/organisms/ModifierSelectionModal/ModifierSelectionModal.tsx "apps/pos/src/components/organisms/ModifierSelectionModal/__tests__/ModifierSelectionModal.test.tsx"`

(f) In `apps/pos/src/pages/__tests__/HomePage.customerModal.test.tsx`, update the `@/components/organisms/ModifierSelectionModal` mock factory (its lines 153-155) to `{ ModifierComposerSheet: () => null }`.

(g) Guard: `grep -rn "ModifierSelectionModal" apps/pos/src --include='*.ts*'` must return only the directory-name path segments (no symbol references).

- [ ] **Step 7 — GREEN.** `cd apps/pos && pnpm exec vitest run src/pages/__tests__/HomePage.paneInvariant.test.tsx src/pages/__tests__/HomePage.customerModal.test.tsx src/lib/stock/__tests__/homePageIngressPin.test.ts` — all pass. `pnpm exec tsc --noEmit` — clean. Kill orphaned workers.

- [ ] **Step 8 — commit.**

```bash
git add -A apps/pos/src/components/organisms/ModifierSelectionModal apps/pos/src/pages/HomePage.tsx apps/pos/src/pages/__tests__/HomePage.paneInvariant.test.tsx apps/pos/src/pages/__tests__/HomePage.customerModal.test.tsx
git commit -m "feat(pos): customize hosted as in-pane composer; retire ModifierSelectionModal overlay

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 5: Overlay host deletion + `/theme-preview` migration

**Files:**
- Modify: `apps/pos/src/components/pos/ProductDetailDrawer.tsx` (delete the overlay host `ProductDetailDrawer` + `ProductDetailDrawerProps` + `DETAILS_TAB`, lines 18-77 + 43; prune the now-unused `useState` import), `apps/pos/src/components/organisms/ProductDetailDrawer/index.ts` (barrel), `apps/pos/src/pages/ThemePreviewPage.tsx` (import `:30`, state `:369`, grid `onViewDetails` handlers `:766,823,840,855`, overlay-demo button `:769-778`, inline-sheet section `:782-797`, overlay mount `:935-939`)
- Test: `apps/pos/src/components/pos/__tests__/ProductDetailDrawer.test.tsx`, `apps/pos/src/components/pos/__tests__/ProductDetailDrawerMerchandising.test.tsx` (convert to sheet-harness rendering)

**Interfaces:**
- Consumes: `ProductDetailSheet` + `variant` (Task 1), `DetailTab` (`components/pos/ProductDetailDrawer.tsx:34`).
- Produces: barrel exports become exactly

```ts
export { ProductDetailSheet } from '@/components/pos/ProductDetailDrawer';
export type { DetailTab } from '@/components/pos/ProductDetailDrawer';
```

`ProductDetailDrawer` (the overlay component) no longer exists anywhere. `/theme-preview` renders the pane variant in a `~840px` panel with `data-testid="product-pane-preview"` (the old `product-drawer-preview` testid and the `open-product-detail-preview` button are removed), fed by the grid demos' `onViewDetails`.

**Steps:**

- [ ] **Step 1 — convert the drawer test suites to the sheet (failing first).** In `apps/pos/src/components/pos/__tests__/ProductDetailDrawer.test.tsx`:

(a) Change the component import (line 5) to `import { ProductDetailSheet, type DetailTab } from '@/components/pos/ProductDetailDrawer';` and add `useState` to the React imports: `import { useState } from 'react';`

(b) Add a stateful harness directly under the `product` fixture (the suites drive tab clicks, so tab state must live in the harness now that the overlay host is gone):

```tsx
/**
 * The overlay host owned tab state; post-deletion HomePage owns it. This
 * harness reproduces that ownership for the suites that click tabs.
 */
function SheetHarness({
  product: harnessProduct,
  locationStock,
  hardBlockOutOfStock,
  onClose = () => {},
}: {
  product: POSProduct | null;
  locationStock?: Parameters<typeof ProductDetailSheet>[0]['locationStock'];
  hardBlockOutOfStock?: boolean;
  onClose?: () => void;
}) {
  const [tab, setTab] = useState<DetailTab>('details');
  if (!harnessProduct) return null;
  return (
    <ProductDetailSheet
      product={harnessProduct}
      onClose={onClose}
      locationStock={locationStock}
      hardBlockOutOfStock={hardBlockOutOfStock}
      activeTab={tab}
      onTabChange={setTab}
    />
  );
}
```

(c) Mechanically replace every `render(<ProductDetailDrawer isOpen product={…} onClose={() => {}}` with `render(<SheetHarness product={…} onClose={() => {}}` (drop the `isOpen` prop; keep `locationStock`/`hardBlockOutOfStock` args as-is; 22 call sites (Rev 2, C7) — replace ALL, verify with `grep -c '<ProductDetailDrawer' <file>` → 0 afterward). The Task 1 describe block's direct `<ProductDetailSheet …>` renders stay unchanged.

(d) In `apps/pos/src/components/pos/__tests__/ProductDetailDrawerMerchandising.test.tsx`: same import change, add the same `SheetHarness` (verbatim copy — this file has its own mock preamble), and rewrite its single `renderDrawer` helper (`:162-171`) to:

```tsx
function renderDrawer(product: POSProduct | null = currentProduct) {
  return render(<SheetHarness product={product} locationStock={null} />);
}
```

(e) Run `cd apps/pos && pnpm exec vitest run src/components/pos/__tests__/ProductDetailDrawer.test.tsx src/components/pos/__tests__/ProductDetailDrawerMerchandising.test.tsx` — must be GREEN already (the harness renders the same sheet); this step is a conversion, not a behavior change. If anything fails, fix the conversion, not the sheet. Kill orphaned workers.

- [ ] **Step 2 — delete the overlay host (RED for the pin).** In `apps/pos/src/components/pos/ProductDetailDrawer.tsx`: delete `ProductDetailDrawerProps` (lines 18-32), `DETAILS_TAB` (line 43), and the `ProductDetailDrawer` function (lines 45-77). Move the `locationStock` + `hardBlockOutOfStock` doc comments from the deleted props interface onto the matching fields of `ProductDetailSheetProps`. Remove `useState` from the file's react import (the sheet doesn't use it). Update the barrel `apps/pos/src/components/organisms/ProductDetailDrawer/index.ts` to the two-line form shown in **Interfaces**. Then `cd apps/pos && pnpm exec tsc --noEmit` — expect errors ONLY in `ThemePreviewPage.tsx` (the last consumer). That is the RED for step 3.

- [ ] **Step 3 — migrate `/theme-preview`.** In `apps/pos/src/pages/ThemePreviewPage.tsx`:

(a) Import (line 30): `import { ProductDetailSheet, type DetailTab } from '@/components/organisms/ProductDetailDrawer';`

(b) State (line 369): replace `const [detailProduct, setDetailProduct] = useState<POSProduct | null>(null);` with `const [panePreviewProduct, setPanePreviewProduct] = useState<POSProduct>(SELL_PRODUCTS[0]!);` (keep `drawerPreviewTab` at line 371 — rename it `panePreviewTab`/`setPanePreviewTab` for coherence, updating its two uses).

(c) Repoint every `onViewDetails={setDetailProduct}` (lines 766, 823, 840, and 855 — the Tableau density section's `ProductTable`; Rev 2, C1) to `onViewDetails={setPanePreviewProduct}` — tapping the eye on any demo card now swaps the pane preview's product. Verify completeness: `grep -n 'setDetailProduct' apps/pos/src/pages/ThemePreviewPage.tsx` → 0 hits after this step.

(d) Delete the `open-product-detail-preview` button block (lines 769-778) and the `<ProductDetailDrawer …/>` mount (lines 935-939).

(e) Rework the inline-sheet section (lines 782-797) into the pane-variant panel at realistic pane width:

```tsx
        <div className="lg:col-span-2">
          <Section title="Fiche produit — variante pane (~840px, cart-always-foreground)">
            <div
              data-testid="product-pane-preview"
              className="h-[640px] w-[840px] max-w-full overflow-hidden rounded-panel border border-border-subtle bg-surface-canvas p-2"
            >
              <ProductDetailSheet
                variant="pane"
                product={panePreviewProduct}
                onClose={() => undefined}
                locationStock={{ available: '14.0000', incoming_transfer: '0.0000', incoming_po: '0.0000' }}
                activeTab={panePreviewTab}
                onTabChange={setPanePreviewTab}
              />
            </div>
          </Section>
        </div>
```

- [ ] **Step 4 — GREEN.** `cd apps/pos && pnpm exec tsc --noEmit` — clean. `grep -rn "ProductDetailDrawer" apps/pos/src --include='*.tsx' --include='*.ts' | grep -v "components/pos/ProductDetailDrawer\|__tests__/ProductDetailDrawer\|organisms/ProductDetailDrawer"` — no hits (only file-path names survive). `pnpm exec vitest run src/components/pos/__tests__/ProductDetailDrawer.test.tsx src/components/pos/__tests__/ProductDetailDrawerMerchandising.test.tsx src/pages/__tests__/HomePage.paneInvariant.test.tsx` — all pass. Kill orphaned workers.

- [ ] **Step 5 — commit.**

```bash
git add apps/pos/src/components/pos/ProductDetailDrawer.tsx apps/pos/src/components/organisms/ProductDetailDrawer/index.ts apps/pos/src/pages/ThemePreviewPage.tsx apps/pos/src/components/pos/__tests__/ProductDetailDrawer.test.tsx apps/pos/src/components/pos/__tests__/ProductDetailDrawerMerchandising.test.tsx
git commit -m "refactor(pos): delete ProductDetailDrawer overlay host; theme-preview renders the pane variant

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 6: Added-line pulse

**Files:**
- Modify: `apps/pos/src/stores/cartStore.ts` (`CartState` `:39-59`, `initialState` `:228-233`, `addItem` merge path `:273-279` + new-line path `:329`, `updateLineModifiers` `:371-393` (Rev 2, U9), `clearCart` set `:446`, `replaceCart` `:558-562`), `apps/pos/src/components/organisms/TransactionCart/TransactionCart.tsx` (react import `:1`, store import next to `:12`, subscriptions after the `confirmLineDelete` (`:96`) / `cartPosition` (`:97`) pair, scroll container `:218` (Rev 2, U2), both `CartLineItem` call sites `:261` and `:283`), `apps/pos/src/components/molecules/CartLineItem/CartLineItem.tsx` (props `:12-36`, root div `:70-75`, overlay child), `apps/pos/src/index.css` (append after `.ez-tap` block `:451-453`; extend the reduced-motion list `:454-462`)
- Test: `apps/pos/src/stores/__tests__/cartStore.test.ts` (append describe), `apps/pos/src/components/molecules/CartLineItem/CartLineItem.test.tsx` (append describe), `apps/pos/src/components/organisms/TransactionCart/__tests__/TransactionCart.test.tsx` (mock update + append test)

**Interfaces:**
- Consumes: `useCartStore` (`@/stores/cartStore`), existing `CartLineItemProps`, `--action` CSS var (`apps/pos/src/index.css:66`), `ez-tap` animation precedent (`index.css:445-453`).
- Produces:

```ts
// cartStore additions (CartState):
lastAddedLineId: string | null; // line id of the most recent addItem (new line OR merge-increment) OR updateLineModifiers hit (customize-EDIT confirm, Rev 2 U9)
lastAddedNonce: number;         // monotonic; bumps on every stamp so repeat adds re-trigger

// CartLineItemProps addition:
pulseToken?: number; // nonzero = pulse now; a NEW value re-fires; 0/undefined = idle
```

CSS: `@keyframes ez-line-pulse` + `.ez-line-pulse` (blue `--action` background fade, 650ms, reduced-motion exempt). `TransactionCart` maps store state → `pulseToken={item.id === lastAddedLineId ? lastAddedNonce : 0}` on BOTH sale-line render branches, and on `lastAddedNonce` change scrolls the matching line into view — `scrollIntoView({ block: 'nearest', behavior: 'smooth' })`, optional-call-guarded for jsdom (Rev 2, U2: new lines land below the fold on 8+ line tickets; a pulse that fires off-screen is the exact failure this story fixes). No i18n (decorative, `aria-hidden`).

**Steps:**

- [ ] **Step 1 — failing store test.** Append to `apps/pos/src/stores/__tests__/cartStore.test.ts` (inside the top-level `describe('cartStore')`, reusing its `makeProduct` helper at `:5-14`):

```ts
  describe('added-line pulse tracking (cart-always-foreground v1)', () => {
    it('stamps lastAddedLineId and bumps the nonce when a new line is added', () => {
      expect(useCartStore.getState().lastAddedLineId).toBeNull();
      expect(useCartStore.getState().lastAddedNonce).toBe(0);

      useCartStore.getState().addItem(makeProduct());
      const state = useCartStore.getState();
      expect(state.lastAddedLineId).toBe(state.items[0]!.id);
      expect(state.lastAddedNonce).toBe(1);
    });

    it('stamps the MERGED line id and bumps the nonce on a repeat add of the same product', () => {
      const product = makeProduct();
      useCartStore.getState().addItem(product);
      useCartStore.getState().addItem(product);

      const state = useCartStore.getState();
      expect(state.items).toHaveLength(1); // merged, not a second line
      expect(state.lastAddedLineId).toBe(state.items[0]!.id);
      expect(state.lastAddedNonce).toBe(2); // re-fires the pulse on the same line
    });

    it('routes addItemWithDefaults through the same pulse stamp', () => {
      useCartStore.getState().addItemWithDefaults(makeProduct());
      const state = useCartStore.getState();
      expect(state.lastAddedLineId).toBe(state.items[0]!.id);
      expect(state.lastAddedNonce).toBe(1);
    });

    it('stamps the pulse on updateLineModifiers — customize-EDIT confirm (Rev 2, U9)', () => {
      useCartStore.getState().addItem(makeProduct());
      const lineId = useCartStore.getState().items[0]!.id;

      useCartStore.getState().updateLineModifiers(lineId, []);
      const state = useCartStore.getState();
      expect(state.lastAddedLineId).toBe(lineId);
      expect(state.lastAddedNonce).toBe(2); // 1 from addItem, +1 from the edit
    });

    it('does NOT stamp the pulse when updateLineModifiers misses (vanished line stays a no-op)', () => {
      useCartStore.getState().addItem(makeProduct());
      useCartStore.getState().updateLineModifiers('no-such-line', []);
      expect(useCartStore.getState().lastAddedNonce).toBe(1); // unchanged
    });

    it('resets pulse state on clearCart and replaceCart (recalls must not pulse)', () => {
      useCartStore.getState().addItem(makeProduct());
      useCartStore.getState().clearCart('checkout');
      expect(useCartStore.getState().lastAddedLineId).toBeNull();
      expect(useCartStore.getState().lastAddedNonce).toBe(0);

      useCartStore.getState().addItem(makeProduct());
      useCartStore.getState().replaceCart([], undefined);
      expect(useCartStore.getState().lastAddedLineId).toBeNull();
      expect(useCartStore.getState().lastAddedNonce).toBe(0);
    });
  });
```

Note: the suite's `beforeEach` calls `clearCart()`, which (after this task) resets the pulse fields — each test starts from `null`/`0`.

- [ ] **Step 2 — RED.** `cd apps/pos && pnpm exec vitest run src/stores/__tests__/cartStore.test.ts` — the new describe fails (`lastAddedLineId` undefined). Kill orphaned workers.

- [ ] **Step 3 — implement the store.** In `apps/pos/src/stores/cartStore.ts`:

(a) Append to `CartState` (after `cartLinesRemovedThisSession` at line 58):

```ts
  /**
   * Added-line pulse (cart-always-foreground v1, spec §2.5): the line id that
   * received the most recent `addItem` (new line OR merge-increment) or
   * `updateLineModifiers` hit (customize-EDIT confirm, Rev 2 U9), plus a
   * monotonic nonce so a repeat add of the SAME line re-triggers the pulse.
   * Reset on clear/replace — a recalled or hydrated cart must not pulse.
   * Stale ids after removeItem are harmless: the UI matches by live line id.
   */
  lastAddedLineId: string | null;
  lastAddedNonce: number;
```

(b) `initialState` (line 228-233): add `lastAddedLineId: null,` and `lastAddedNonce: 0,`.

(c) `addItem` merge path — replace the return at line 278:

```ts
          return {
            items: newItems,
            cartSessionId,
            cartLinesRemovedThisSession,
            lastAddedLineId: existing.id,
            lastAddedNonce: state.lastAddedNonce + 1,
          };
```

(d) `addItem` new-line path — replace the return at line 329:

```ts
      return {
        items: [...state.items, newItem],
        cartSessionId,
        cartLinesRemovedThisSession,
        lastAddedLineId: newItem.id,
        lastAddedNonce: state.lastAddedNonce + 1,
      };
```

(e) `clearCart` set (line 446): add `lastAddedLineId: null, lastAddedNonce: 0` to the `set({ … })` object.

(f) `replaceCart` (line 561): add `lastAddedLineId: null, lastAddedNonce: 0` to its `set({ … })` object.

(g) **Customize-EDIT confirm stamps the pulse (Rev 2, U9).** Replace `updateLineModifiers` (lines 371-393) with (mapping body unchanged — only the miss-guard and the stamp are new):

```ts
  updateLineModifiers: (lineId: string, newModifiers: SelectedModifier[]) => {
    set((state) => {
      // Rev 2 (U9): a customize-EDIT confirm is a pane-originated cart
      // mutation the operator must confirm — stamp the pulse like addItem.
      // A missing line id stays a no-op AND does not stamp.
      if (!state.items.some((item) => item.id === lineId)) return state;
      return {
        items: state.items.map((item) => {
          if (item.id !== lineId) return item;
          const decimals = getDecimals();
          const currentAdjustment = bcsum(
            item.product.selectedModifiers?.map((m) => m.price_adjustment) ?? [],
            decimals,
          );
          const basePrice = bcsub(item.product.price, currentAdjustment, decimals);
          const newAdjustment = bcsum(newModifiers.map((m) => m.price_adjustment), decimals);
          const priceValue = bcadd(basePrice, newAdjustment, decimals);
          const lineTotal = bcmul(priceValue, String(item.quantity), decimals);
          return {
            ...item,
            product: { ...item.product, selectedModifiers: newModifiers, price: priceValue },
            unit_price: priceValue,
            line_total: lineTotal,
            tax_amount: computeTaxAmount(lineTotal, item.tax_rate),
          };
        }),
        lastAddedLineId: lineId,
        lastAddedNonce: state.lastAddedNonce + 1,
      };
    });
  },
```

- [ ] **Step 4 — GREEN (store).** `cd apps/pos && pnpm exec vitest run src/stores/__tests__/cartStore.test.ts src/stores/__tests__/cartStore.audit.test.ts` — all pass. Kill orphaned workers.

- [ ] **Step 5 — failing UI tests.** Append to `apps/pos/src/components/molecules/CartLineItem/CartLineItem.test.tsx`:

```tsx
describe('CartLineItem — added-line pulse (cart-always-foreground v1)', () => {
  it('renders no pulse overlay without a token', () => {
    const { queryByTestId } = render(
      <CartLineItem item={baseItem} onUpdateQuantity={vi.fn()} onRemove={vi.fn()} />,
    );
    expect(queryByTestId('cart-line-pulse')).toBeNull();
  });

  it('renders the token-based pulse overlay when pulseToken > 0', () => {
    const { getByTestId } = render(
      <CartLineItem item={baseItem} onUpdateQuantity={vi.fn()} onRemove={vi.fn()} pulseToken={1} />,
    );
    const pulse = getByTestId('cart-line-pulse');
    expect(pulse.className).toContain('ez-line-pulse');
    expect(pulse.className).toContain('pointer-events-none');
    expect(pulse).toHaveAttribute('aria-hidden', 'true');
  });

  it('clears the overlay on animationend', () => {
    const { getByTestId, queryByTestId } = render(
      <CartLineItem item={baseItem} onUpdateQuantity={vi.fn()} onRemove={vi.fn()} pulseToken={1} />,
    );
    fireEvent.animationEnd(getByTestId('cart-line-pulse'));
    expect(queryByTestId('cart-line-pulse')).toBeNull();
  });

  it('re-fires when the token changes (repeat add of the same line)', () => {
    const { getByTestId, queryByTestId, rerender } = render(
      <CartLineItem item={baseItem} onUpdateQuantity={vi.fn()} onRemove={vi.fn()} pulseToken={1} />,
    );
    fireEvent.animationEnd(getByTestId('cart-line-pulse'));
    expect(queryByTestId('cart-line-pulse')).toBeNull();
    rerender(
      <CartLineItem item={baseItem} onUpdateQuantity={vi.fn()} onRemove={vi.fn()} pulseToken={2} />,
    );
    expect(getByTestId('cart-line-pulse')).toBeInTheDocument();
  });
});
```

And in `apps/pos/src/components/organisms/TransactionCart/__tests__/TransactionCart.test.tsx`: (a) extend the `CartLineItem` mock (its lines 20-24) to surface the new prop:

```tsx
vi.mock('@/components/molecules/CartLineItem', () => ({
  CartLineItem: ({
    item,
    pulseToken,
  }: {
    item: { id: string; product: { name: string } };
    pulseToken?: number;
  }) => (
    <div data-testid={`cart-line-${item.id}`} data-pulse-token={pulseToken ?? 0}>
      {item.product.name}
    </div>
  ),
}));
```

(b) add imports `import { useCartStore } from '@/stores/cartStore';` and append:

```tsx
describe('TransactionCart — added-line pulse routing', () => {
  beforeEach(() => {
    useCartStore.setState({ lastAddedLineId: null, lastAddedNonce: 0 });
  });

  it('passes the nonce as pulseToken ONLY to the last-added line', () => {
    useCartStore.setState({ lastAddedLineId: 'item-1', lastAddedNonce: 3 });
    renderCart({
      items: [makeCartItem({ id: 'item-1' }), makeCartItem({ id: 'item-2' })],
      itemCount: 2,
    });
    expect(screen.getByTestId('cart-line-item-1')).toHaveAttribute('data-pulse-token', '3');
    expect(screen.getByTestId('cart-line-item-2')).toHaveAttribute('data-pulse-token', '0');
  });

  it('scrolls the just-added line into view on nonce change (Rev 2, U2)', () => {
    // jsdom stubs scrollIntoView as not-implemented — install a spy.
    const scrollSpy = vi.fn();
    Element.prototype.scrollIntoView = scrollSpy;

    useCartStore.setState({ lastAddedLineId: 'item-2', lastAddedNonce: 1 });
    renderCart({
      items: [makeCartItem({ id: 'item-1' }), makeCartItem({ id: 'item-2' })],
      itemCount: 2,
    });
    expect(scrollSpy).toHaveBeenCalledTimes(1);
    expect(scrollSpy).toHaveBeenCalledWith({ block: 'nearest', behavior: 'smooth' });
    // The call target (`this` of the method call) is item-2's line wrapper.
    expect(
      (scrollSpy.mock.contexts[0] as Element).getAttribute('data-cart-line-id'),
    ).toBe('item-2');
  });
});
```

- [ ] **Step 6 — RED.** `cd apps/pos && pnpm exec vitest run src/components/molecules/CartLineItem/CartLineItem.test.tsx "src/components/organisms/TransactionCart/__tests__/TransactionCart.test.tsx"` — the new describes fail (`cart-line-pulse` missing / `data-pulse-token` always 0). Kill orphaned workers.

- [ ] **Step 7 — implement the UI + CSS.**

(a) `apps/pos/src/components/molecules/CartLineItem/CartLineItem.tsx`: add to `CartLineItemProps` (after `confirmDelete` at line 35):

```tsx
  /**
   * Added-line pulse (cart-always-foreground v1): nonzero when this line just
   * received an add while the operator's eye may be in the product pane. A NEW
   * token value re-fires the animation; 0/undefined = idle. Purely decorative
   * (aria-hidden overlay) — parents omit it freely (e.g. /theme-preview).
   */
  pulseToken?: number;
```

Destructure `pulseToken = 0,` in the signature. Add the local state + effect after the `const { format, decimals } = useCurrency();` line (line 51):

```tsx
  // Latch the pulse token so the overlay can clear itself on animationend
  // (and re-mount via `key` when a NEW token arrives for the same line).
  const [activePulse, setActivePulse] = useState(0);
  useEffect(() => {
    if (pulseToken > 0) setActivePulse(pulseToken);
  }, [pulseToken]);
```

(`useState`/`useEffect` are already imported at line 1.) Change the root div (line 71-75) className to include `relative`:

```tsx
      className="relative rounded-card border border-border-subtle bg-surface-raised transition-all duration-150"
```

and insert the overlay as the FIRST child of that root div:

```tsx
      {activePulse > 0 && (
        <div
          key={activePulse}
          data-testid="cart-line-pulse"
          aria-hidden="true"
          className="ez-line-pulse pointer-events-none absolute inset-0 rounded-card"
          onAnimationEnd={() => setActivePulse(0)}
        />
      )}
```

(b) `apps/pos/src/components/organisms/TransactionCart/TransactionCart.tsx`: extend the react import (line 1) to `import { useEffect, useRef, useState, type ReactNode } from 'react';` and add `import { useCartStore } from '@/stores/cartStore';` next to the settingsStore import (line 12). After the `confirmLineDelete` (`:96`) / `cartPosition` (`:97`) subscription pair (Rev 2, C4), add:

```tsx
  // Added-line pulse: the store stamps the last-added line + a nonce; only the
  // matching line receives a nonzero token (cart-always-foreground v1).
  const lastAddedLineId = useCartStore((s) => s.lastAddedLineId);
  const lastAddedNonce = useCartStore((s) => s.lastAddedNonce);

  // Rev 2 (U2): scroll the just-added line into view. The list is
  // overflow-y-auto and new lines append at the bottom — on 8+ line tickets
  // they land below the fold, and a pulse that fires off-screen is the exact
  // "did it work?" failure this story fixes.
  const lineListRef = useRef<HTMLDivElement>(null);
  useEffect(() => {
    if (lastAddedNonce === 0 || lastAddedLineId === null) return;
    const line = lineListRef.current?.querySelector(
      `[data-cart-line-id="${lastAddedLineId}"]`,
    );
    // Optional call — jsdom does not implement scrollIntoView.
    line?.scrollIntoView?.({ block: 'nearest', behavior: 'smooth' });
  }, [lastAddedNonce, lastAddedLineId]);
```

Attach the ref to the scrollable line list (line 218): `<div ref={lineListRef} className="min-h-0 flex-1 overflow-y-auto px-2 py-0.5">`.

At BOTH `<CartLineItem …/>` call sites — the refund-mode "Buying new" branch (line 261) and the normal sale branch (line 283), Rev 2 C4 — wrap the element in a keyed scroll-target div (the `key` moves from `CartLineItem` to the wrapper; each wrapper is a direct child of its `divide-y` container, so the dividers are preserved) and add the token prop. The normal sale branch becomes (the refund-mode branch is identical except for its indentation):

```tsx
            {items.map((item) => (
              <div key={item.id} data-cart-line-id={item.id}>
                <CartLineItem
                  item={item}
                  onUpdateQuantity={onUpdateQuantity}
                  onRemove={onRemoveItem}
                  onQuantityTap={onQuantityTap}
                  onDiscount={onLineDiscount}
                  onEditModifiers={onEditModifiers}
                  onRemoveDiscount={onRemoveLineDiscount}
                  expanded={expandedLineId === item.id}
                  onToggleExpand={toggleLine}
                  confirmDelete={confirmLineDelete}
                  pulseToken={item.id === lastAddedLineId ? lastAddedNonce : 0}
                />
              </div>
            ))}
```

(`ReturnLineItem` lines never pulse — returns are not adds.)

(c) `apps/pos/src/index.css`: append after the `.ez-tap` rule (line 453):

```css
/* Added-line pulse — fires on the cart line that just received an add while
 * the operator's eye is in the product pane (cart-always-foreground v1).
 * Blue = interaction (Strategy A): --action at low alpha, mirroring the
 * ez-tap ring recipe. Ends transparent; the overlay clears on animationend. */
@keyframes ez-line-pulse {
  0%   { background-color: color-mix(in srgb, var(--action) 24%, transparent); }
  100% { background-color: transparent; }
}
.ez-line-pulse {
  animation: ez-line-pulse 650ms ease-out both;
}
```

and add `.ez-line-pulse` to the `prefers-reduced-motion` selector list (lines 454-459). (Under reduced motion the overlay never animates — it stays at the default transparent background, i.e. invisible; `animationend` never firing is harmless.)

- [ ] **Step 8 — GREEN.** `cd apps/pos && pnpm exec vitest run src/components/molecules/CartLineItem/CartLineItem.test.tsx "src/components/organisms/TransactionCart/__tests__/TransactionCart.test.tsx" src/stores/__tests__/cartStore.test.ts` — all pass. `pnpm exec tsc --noEmit` — clean. Kill orphaned workers.

- [ ] **Step 9 — commit.**

```bash
git add apps/pos/src/stores/cartStore.ts apps/pos/src/stores/__tests__/cartStore.test.ts apps/pos/src/components/molecules/CartLineItem/CartLineItem.tsx apps/pos/src/components/molecules/CartLineItem/CartLineItem.test.tsx apps/pos/src/components/organisms/TransactionCart/TransactionCart.tsx "apps/pos/src/components/organisms/TransactionCart/__tests__/TransactionCart.test.tsx" apps/pos/src/index.css
git commit -m "feat(pos): added-line pulse — cart line flashes --action when an add lands

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 7: Overlay policy doc

**Files:**
- Modify: `apps/pos/docs/design-language.md` (append a new §9 after "## 8. Adding a new screen/dir"; add one bullet to "## 7. PR checklist (design)")

**Interfaces:** none (docs only). Source: spec §3 verbatim classification.

**Steps:**

- [ ] **Step 1 — write the section.** Append to `apps/pos/docs/design-language.md`:

```markdown
## 9. Overlay policy — the cart is always in the foreground

The cart is the source of truth. Any surface whose outcome is "something lands
in the cart that the operator must visually confirm" must NOT cover the cart.
(Spec: `docs/superpowers/specs/2026-07-10-pos-cart-always-foreground-design.md`.)

Every new POS surface declares one of three classes in review:

- **(a) Browse / add-to-cart-adjacent** → hosted in the product pane
  (`ProductPaneHost`, `components/pos/ProductPaneHost.tsx`) or a sub-second
  centered disambiguation dialog. **Never `fixed inset-0`.**
  Current: ProductDetailSheet pane, ModifierComposerSheet pane;
  VariantPickerModal + BarcodeChooserModal (transient dialogs, allowed);
  ToastSmartPrompts / SmartPromptCard (inline, compliant).
- **(b) Cart-action takeover** — payment, discounts, quantity, held/recall,
  refunds, customer attach → full-screen allowed; it IS the cart action.
  Current: CashPaymentScreen, AdvancedPaymentsModal, CheckoutSuccessModal,
  DiscountModal, LineDiscountModal, QuantityNumpad, HeldTransactionsModal,
  RefundCheckoutFlow, ReceiptScanConfirmationSheet, ReceiptLocatorScreen,
  CustomerSearchModal, CardPaymentModal, VoucherTenderModal, CashDrawerModal,
  RefundConfirmModal, EndOfDayPreviewModal, SaleDetailModal.
  Note: CustomerSearchModal stays class (b) in v1, but its attach outcome is
  cart-visible — a legitimate class-(a) candidate for v2.
- **(c) System/admin** — shift, PIN, fiscal durability, reports → full-screen
  allowed. Current: OpenShiftScreen, CloseShiftModal, ReportsMenu,
  DurabilityGateModal, LoyaltyEnrollDialog, XReportModal, ZReportModal.

No lint tooling in v1 — the pane host being the easiest path is the
enforcement; reviewers reject an undeclared class-(a) `fixed inset-0` surface.
```

- [ ] **Step 2 — checklist bullet.** In the same file's "## 7. PR checklist (design)" section, append one line: `- [ ] New full-screen surface? Declare its overlay class (a/b/c) — see §9. Class (a) never covers the cart.`

- [ ] **Step 3 — commit.**

```bash
git add apps/pos/docs/design-language.md
git commit -m "docs(pos): codify overlay policy (a/b/c classes) in the design language

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 8: Final verification (targeted suites, static gates, visual + on-device)

**Files:** none created (scratch playwright script stays in the session scratchpad, not committed).

**Interfaces:** consumes everything above; produces the verification evidence for the merge gate.

**Steps:**

- [ ] **Step 1 — targeted vitest (the full list, one run, BY PATH — never the bare suite):**

```bash
cd apps/pos && pnpm exec vitest run \
  src/components/pos/__tests__/ProductDetailDrawer.test.tsx \
  src/components/pos/__tests__/ProductDetailDrawerMerchandising.test.tsx \
  src/components/pos/__tests__/ProductPaneHost.test.tsx \
  src/pages/__tests__/HomePage.paneInvariant.test.tsx \
  src/pages/__tests__/HomePage.customerModal.test.tsx \
  src/pages/__tests__/HomePage.lineDiscountAction.test.ts \
  "src/components/organisms/ModifierSelectionModal/__tests__/ModifierComposerSheet.test.tsx" \
  "src/components/organisms/TransactionCart/__tests__/TransactionCart.test.tsx" \
  src/components/molecules/CartLineItem/CartLineItem.test.tsx \
  src/stores/__tests__/cartStore.test.ts \
  src/stores/__tests__/cartStore.audit.test.ts \
  src/stores/__tests__/holdRecallIntegration.test.ts \
  src/lib/stock/__tests__/cartIngress.test.ts \
  src/lib/stock/__tests__/homePageIngressPin.test.ts
```

All green. Then IMMEDIATELY: `ps aux | grep 'node (vitest' | grep -v grep | awk '{print $2}' | xargs kill 2>/dev/null || true`.

- [ ] **Step 2 — static gates.** `cd apps/pos && pnpm exec tsc --noEmit` (clean) and ESLint on every changed file (color guard + no-parsefloat-on-money + no-hardcoded-step):

```bash
cd apps/pos && pnpm exec eslint \
  src/components/pos/ProductDetailDrawer.tsx \
  src/components/pos/ProductPaneHost.tsx \
  src/components/organisms/ModifierSelectionModal/ModifierComposerSheet.tsx \
  src/components/organisms/ModifierSelectionModal/index.ts \
  src/components/organisms/TransactionCart/TransactionCart.tsx \
  src/components/molecules/CartLineItem/CartLineItem.tsx \
  src/stores/cartStore.ts \
  src/pages/HomePage.tsx \
  src/pages/ThemePreviewPage.tsx
```

- [ ] **Step 3 — playwright visual pass on `/theme-preview`** (the polish-round-2 recipe: scratch script in the session scratchpad using `playwright-core` + `chromium.launch({ channel: 'chrome' })`, NOT committed). Start the dev server (`cd apps/pos && pnpm dev`), navigate to `http://localhost:5173/theme-preview`, viewport 1366×768. Matrix: **light/dark × rounded/sharp** (toggle theme via the NavRail theme button; toggle corners via the `aria-label="corner"` segmented control, `ThemePreviewPage.tsx:412-415`). For each of the 4 combos screenshot `[data-testid="product-pane-preview"]` and eyeball: pane fills the 840px panel with no fixed-size clamp; tabs + close X land inside the panel; no clipped hero price; add button full-width in the 344px aside. Also click an eye icon in the `sell-preview` grid and confirm the pane preview swaps product. Confirm the OLD overlay demo is gone (no full-screen dimmer appears anywhere on the page).

- [ ] **Step 4 — pulse smoke in the browser.** Still on the dev server: the pulse can't be driven from `/theme-preview` (its cart is fixture-fed), so run the real caisse flow per `reference_local_db_per_tenant_demo_launch.md` if the local stack is up — open detail on a product, tap Équivalents, add one, and observe (i) the cart line flash blue once, (ii) the pane stay open, (iii) the cart stepper remain tappable while the pane is open. If the local stack is not available, defer this observation to the owner device pass (Step 5) and SAY SO in the task report.

- [ ] **Step 5 — on-device checklist for the owner (real Tauri, hand over verbatim):**
  1. Open a product's detail (eye icon) — the grid is replaced, the cart stays fully visible on its side.
  2. Équivalents tab → tap an equivalent → the cart line appears/increments WITH a brief blue pulse; the pane stays open.
  3. Add the same equivalent again → the same line pulses again.
  4. While detail is open: change a cart quantity, attach a customer, and press Pay — all work; payment takes over the full screen (allowed, class b); after settle the cart clears AND the pane closes (Rev 2, owner decision U4).
  5. Esc (or X) closes detail back to the grid at the same scroll position.
  6. Tap Personnaliser on a modifier product — composer replaces the grid; cart visible; Esc does NOTHING; Cancel (X) returns to grid; Confirm adds (pulse) and returns to grid.
  7. Scan a barcode while a pane is open — the add lands in the visible cart with the toast.
  8. F&B tenant: TableSelector hides with the grid; smart-prompt toasts stay visible.
  9. Cart-position "end" setting: repeat 1-2 with the cart on the right.
  10. (Rev 2, U2) Build a >6-line ticket, then add from the pane — the cart SCROLLS the affected line into view and it pulses; the confirmation is never off-screen.
  11. (Rev 2, U1) With the detail pane open, trigger a dialog above it (e.g. scan a code that opens the variant picker); press Esc — ONLY the picker closes, the pane stays open.
  12. (Rev 2, U5) Esc on the composer does nothing — this is a DELIBERATE behavior change (the old modal closed on Esc); confirm it feels right in real use.
  13. (Rev 2, U4) Close-on-settle: settle with a pane open — the next sale starts on the GRID, including with lock-after-sale enabled (unlock → grid, not the previous customer's product).

- [ ] **Step 6 — closing commit** (only if Steps 1-4 forced fixes; otherwise nothing to commit). Any fix follows the same TDD loop and lands as `fix(pos): <what> (final verification)` with the co-author trailer. Do NOT push; do NOT merge — the orchestrator reviews first.

---

## Coverage check (spec § → task)

| Spec section | Covered by |
|---|---|
| §1 pane state / grid preservation / cart untouched | Tasks 2, 3 |
| §1 detail pane stays open on add / close semantics | Task 1 (sheet), 3 (wiring; adds already non-closing via `addItemGated`, `ProductDetailDrawer.tsx:210,437,480`) |
| §1 customize confirm-closes / §2.3 extraction | Task 4 |
| §1 scan unaffected / §1 exceptions | No code change (Global Constraints guard); exercised in Task 8 steps 5.4/5.7 |
| §2.1 sheet pane variant / a11y correction | Task 1 |
| §2.2 overlay host deleted + barrel | Task 5 |
| §2.4 ProductPaneHost | Task 2 |
| §2.5 added-line pulse | Task 6 |
| §3 overlay policy codified | Task 7 |
| §4 edge cases (Esc, width floor, TableSelector/Toast, OOS threading, pay/held/refund while open) | Tasks 1-4 code + Task 8 device checklist |
| §5 structural invariant + component tests | Tasks 1-6 suites; §5 theme-preview + playwright + on-device | Task 8 |
