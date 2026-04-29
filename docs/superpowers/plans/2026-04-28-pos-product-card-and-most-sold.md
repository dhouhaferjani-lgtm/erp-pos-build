# POS ProductCard Refactor + "Most-Sold" Sort Button — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop product names visually overlapping the price/stock block in the desktop POS grid, and replace the static "Popular" pill row with a toggle button that re-orders the grid by most-sold-first using locally-tracked sales data.

**Architecture:**
- Frontend-only change confined to `apps/pos`. No API, server, or shared-types changes.
- Card layout stays a CSS-flex column but gains explicit `shrink-0` rows, a `min-h` that matches actual content (2-line name + price + stock), `title` for hover tooltip, and a single source of truth for row height shared with the virtualizer. The outer clickable wrapper switches from a `<button>` to a `<div role="button">` with explicit keyboard handlers so the inner "customize" `<button>` is no longer nested inside another `<button>` (the current code has invalid HTML and is being fixed as part of this refactor).
- "Most-sold" sort reads `offline_receipts.lines` (already SQLite-persisted, JSON blob) for a 30-day window, aggregates units sold per receipt-line `product_id` (or `composite_item_id` when present — `receiptService.ts` writes whichever is appropriate, but never both), and exposes the result as a `useMostSoldCounts()` hook. The sort is a UI toggle in the product-grid header — no schema changes, fully offline-native, and trivially reversible.
- The aggregation repository targets a minimal `DbExecutor` interface (`select<T>(sql, params)`) so the same code runs against the production `@tauri-apps/plugin-sql` `Database` and against the existing `SqliteTestAdapter` test harness used by `migration22.integration.test.ts`. No new test infrastructure.

**Tech Stack:** React 19 / Vite 7 / TypeScript strict, Tailwind 4, Vitest, Tauri SQL plugin (existing offline DB), TanStack Virtual.

**Out of scope (deliberate):**
- Per-category color coding on cards (future iteration, called out by user).
- A server-side per-product sales-count endpoint and sync — deferred. The local 30-day aggregation is correct because the cashier only needs "what sells well *here, recently*"; cross-shift sync is a future optimization.
- Refactoring the legacy `apps/pos/src/components/pos/ProductCard.tsx` and `ProductGrid.tsx` files — they are unused (HomePage wires `@/components/organisms/ProductGrid` which uses `@/components/molecules/ProductCard`). Leave them alone.
- Image-on/image-off setting — already controlled by the existing `displayMode` toggle (`grid` vs `visual`).

**Industry-standard patterns being applied (Square, Toast, Lightspeed, Clover, Shopify POS):**
1. 2-line name with ellipsis (`line-clamp-2`) — keep.
2. `title` attribute on the name for full-text-on-hover. — add.
3. Price + stock rendered as `shrink-0` rows so they never visually overlap the name when the card is forced taller. — add.
4. Card minimum height accommodates 2-line name + price + stock + padding without stretching artifacts (~140px in grid mode, ~190px in visual mode). — adjust.
5. Virtualizer row height kept in sync with card min-height as a single exported constant. — refactor.

---

## File map

**Modify**
- `apps/pos/src/components/molecules/ProductCard/ProductCard.tsx` — fix layout, add title, tighten min-h.
- `apps/pos/src/components/molecules/ProductCard/__tests__/ProductCard.test.tsx` — add tests for title attribute and price-row not-overlapping.
- `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx` — remove popular pill row, add Most-Sold toggle, consume `useMostSoldCounts`, share row-height constants.
- `apps/pos/src/components/organisms/ProductGrid/__tests__/ProductGrid.test.tsx` — adjust test for popular-row removal, add tests for toggle + ordering.
- `apps/pos/src/locales/en/pos.json`, `apps/pos/src/locales/fr/pos.json` — drop `products.popular`, add `products.sortByMostSold`, `products.sortDefault`, `products.fullName` (tooltip aria).

**Create**
- `apps/pos/src/components/molecules/ProductCard/cardSizing.ts` — exports `CARD_MIN_H_GRID`, `CARD_MIN_H_VISUAL`, `ROW_HEIGHT_GRID`, `ROW_HEIGHT_VISUAL`, `GAP` as a single source of truth.
- `apps/pos/src/lib/db/repositories/productSalesAggregateRepository.ts` — read-only aggregate over `offline_receipts.lines` for a date window, returning `Map<productId, unitsSold>`.
- `apps/pos/src/lib/db/repositories/__tests__/productSalesAggregateRepository.test.ts` — Vitest covering empty DB, parses lines correctly, respects date window, sums quantity (not just receipt count).
- `apps/pos/src/hooks/useMostSoldCounts.ts` — hook wrapping the repository call with 5-minute memoization keyed by company + window.
- `apps/pos/src/hooks/__tests__/useMostSoldCounts.test.ts` — tests for cache, refetch, and offline behaviour.

**Delete (after migration)**
- The "Popular items row" block inside `ProductGrid.tsx` (lines 272-297 of the current file) and the `popularProducts` / `POPULAR_COUNT` constants.

---

## Task 1: Extract card sizing constants

**Files:**
- Create: `apps/pos/src/components/molecules/ProductCard/cardSizing.ts`

- [ ] **Step 1: Write the constants file**

```ts
// apps/pos/src/components/molecules/ProductCard/cardSizing.ts

/**
 * Single source of truth for product-card and product-grid sizing.
 *
 * The virtualizer's row height MUST equal CARD_MIN_H_* + GAP for the grid
 * to avoid clipping the bottom of cards on long product names.
 */
export const GAP = 12;

/** Grid mode (text-first card): name (2 lines) + price + stock + p-4. */
export const CARD_MIN_H_GRID = 140;

/** Visual mode (image card): image (80px) + name (2 lines) + price + stock + p-4.
 *  Kept at 220 to match the existing virtualizer estimate — do NOT lower without
 *  visual verification at 1366×768, 1280×720, and 1024×600. */
export const CARD_MIN_H_VISUAL = 220;

export const ROW_HEIGHT_GRID = CARD_MIN_H_GRID;
export const ROW_HEIGHT_VISUAL = CARD_MIN_H_VISUAL;

/**
 * Tailwind JIT class literals — kept here so a single string-literal
 * appears in the source for the scanner to extract. Never compute these
 * at runtime from CARD_MIN_H_*; the JIT will not pick up dynamic strings.
 */
export const CARD_MIN_H_CLASS_GRID = 'min-h-[140px]';
export const CARD_MIN_H_CLASS_VISUAL = 'min-h-[220px]';
```

- [ ] **Step 2: Commit**

```bash
git add apps/pos/src/components/molecules/ProductCard/cardSizing.ts
git commit -m "refactor(pos): extract product-card sizing constants to one module"
```

---

## Task 2: Fix ProductCard layout + add tooltip

**Files:**
- Modify: `apps/pos/src/components/molecules/ProductCard/ProductCard.tsx`
- Test: `apps/pos/src/components/molecules/ProductCard/__tests__/ProductCard.test.tsx`

- [ ] **Step 1: Add a failing test for the `title` tooltip, shrink-0 rows, and the no-nested-button refactor**

Append to `apps/pos/src/components/molecules/ProductCard/__tests__/ProductCard.test.tsx`:

```tsx
import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { I18nextProvider } from 'react-i18next';
import i18n from '@/test/i18n';
import { ProductCard } from '../ProductCard';
import type { POSProduct } from '@/types/product';

const longNameProduct: POSProduct = {
  id: 'p1',
  name: 'Aquarium Water Conditioner — Tropical Edition 250ml',
  sku: 'AWC-250',
  sale_price: '32.000',
  stock_quantity: 5,
  barcode: null,
};

describe('ProductCard layout regressions', () => {
  it('renders the full product name in a `title` attribute for hover tooltip (grid mode)', () => {
    render(
      <I18nextProvider i18n={i18n}>
        <ProductCard product={longNameProduct} onAddToCart={vi.fn()} displayMode="grid" />
      </I18nextProvider>,
    );
    const heading = screen.getByRole('heading', { level: 3 });
    expect(heading.getAttribute('title')).toBe(longNameProduct.name);
  });

  it('renders the full product name in a `title` attribute for hover tooltip (visual mode)', () => {
    render(
      <I18nextProvider i18n={i18n}>
        <ProductCard product={longNameProduct} onAddToCart={vi.fn()} displayMode="visual" />
      </I18nextProvider>,
    );
    const heading = screen.getByRole('heading', { level: 3 });
    expect(heading.getAttribute('title')).toBe(longNameProduct.name);
  });

  it('marks the price row with shrink-0 so it cannot be squeezed by a long name (grid)', () => {
    const { container } = render(
      <I18nextProvider i18n={i18n}>
        <ProductCard product={longNameProduct} onAddToCart={vi.fn()} displayMode="grid" />
      </I18nextProvider>,
    );
    const priceRow = container.querySelector('[data-testid="price-row"]');
    expect(priceRow).not.toBeNull();
    expect(priceRow?.className).toMatch(/\bshrink-0\b/);
  });

  it('marks the stock row with shrink-0 so it stays anchored at the bottom (grid)', () => {
    const { container } = render(
      <I18nextProvider i18n={i18n}>
        <ProductCard product={longNameProduct} onAddToCart={vi.fn()} displayMode="grid" />
      </I18nextProvider>,
    );
    const stockRow = container.querySelector('[data-testid="stock-row"]');
    expect(stockRow).not.toBeNull();
    expect(stockRow?.className).toMatch(/\bshrink-0\b/);
  });

  it('renders the outer card as role="button" rather than a <button> so the inner customize button is not nested', () => {
    const productWithModifiers: POSProduct = {
      ...longNameProduct,
      modifier_groups: [{ id: 'g1', name: 'Size', is_required: true, modifiers: [] }] as never,
    };
    const { container } = render(
      <I18nextProvider i18n={i18n}>
        <ProductCard
          product={productWithModifiers}
          onAddToCart={vi.fn()}
          onCustomize={vi.fn()}
          displayMode="grid"
        />
      </I18nextProvider>,
    );
    // The outer card must NOT be a <button> element.
    const cardRoot = container.firstElementChild!;
    expect(cardRoot.tagName).not.toBe('BUTTON');
    expect(cardRoot.getAttribute('role')).toBe('button');
    expect(cardRoot.getAttribute('tabindex')).toBe('0');
    // No <button> appears inside another <button>.
    expect(container.querySelector('button button')).toBeNull();
    // The inner customize control IS still a real <button> for accessibility.
    const customize = container.querySelector('[data-testid="customize-button"]');
    expect(customize?.tagName).toBe('BUTTON');
  });

  it('fires onAddToCart when the outer card is activated via Enter or Space', async () => {
    const onAdd = vi.fn();
    const { container } = render(
      <I18nextProvider i18n={i18n}>
        <ProductCard product={longNameProduct} onAddToCart={onAdd} displayMode="grid" />
      </I18nextProvider>,
    );
    const card = container.firstElementChild as HTMLElement;
    card.focus();
    card.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
    expect(onAdd).toHaveBeenCalledTimes(1);
    card.dispatchEvent(new KeyboardEvent('keydown', { key: ' ', bubbles: true }));
    expect(onAdd).toHaveBeenCalledTimes(2);
  });
});
```

- [ ] **Step 2: Run tests, expect failures**

Run: `cd apps/pos && pnpm test -- --run src/components/molecules/ProductCard`
Expected: 6 new failures (no `title` attribute, no `data-testid` markers, outer is still a `<button>`, no keyboard handler).

- [ ] **Step 3: Update `ProductCard.tsx` to satisfy the tests**

Replace the body of `ProductCardInner` so both modes (grid and visual) emit the same row structure:

```tsx
import { memo, useCallback, type KeyboardEvent } from 'react';
import { cn } from '@/lib/utils';
import { useCurrency } from '@/lib/currency';
import { Package, SlidersHorizontal } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useProductImage } from '@/lib/images/useProductImage';
import {
  CARD_MIN_H_CLASS_GRID,
  CARD_MIN_H_CLASS_VISUAL,
} from './cardSizing';
import type { POSProduct } from '@/types/product';

export interface ProductCardProps {
  product: POSProduct;
  onAddToCart: (product: POSProduct) => void;
  onCustomize?: (product: POSProduct) => void;
  isInCart?: boolean;
  displayMode?: 'grid' | 'visual';
}

function ProductCardInner({
  product,
  onAddToCart,
  onCustomize,
  isInCart = false,
  displayMode = 'grid',
}: ProductCardProps) {
  const { t } = useTranslation('pos');
  const { format } = useCurrency();
  const localImage = useProductImage(product.id, product.image_url);
  const imageSrc = localImage ?? product.image_url;
  const isOutOfStock = product.stock_quantity <= 0;
  const isLowStock = product.stock_quantity > 0 && product.stock_quantity <= 10;
  const hasModifiers = (product.modifier_groups?.length ?? 0) > 0;

  const stockLabel = isOutOfStock
    ? t('products.outOfStock')
    : isLowStock
      ? t('products.lowStock')
      : t('products.stock', { count: product.stock_quantity });

  const stockTone = isOutOfStock
    ? 'font-medium text-red-600'
    : isLowStock
      ? 'font-medium text-amber-600'
      : 'text-green-600';

  // Literal-only Tailwind class strings (Tailwind JIT requires literals).
  const minHClass = displayMode === 'grid' ? CARD_MIN_H_CLASS_GRID : CARD_MIN_H_CLASS_VISUAL;

  const activate = useCallback(() => {
    if (!isOutOfStock) onAddToCart(product);
  }, [isOutOfStock, onAddToCart, product]);

  const onKeyDown = useCallback(
    (e: KeyboardEvent<HTMLDivElement>) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        activate();
      }
    },
    [activate],
  );

  return (
    <div
      role="button"
      tabIndex={isOutOfStock ? -1 : 0}
      aria-disabled={isOutOfStock}
      aria-label={product.name}
      onClick={activate}
      onKeyDown={onKeyDown}
      className={cn(
        'relative flex flex-col rounded-xl border-2 p-4 text-left outline-none',
        minHClass,
        'transition-all duration-150 active:scale-[0.95] focus-visible:ring-2 focus-visible:ring-primary-500',
        displayMode === 'visual' ? 'items-center text-center' : 'items-start',
        isOutOfStock
          ? 'cursor-not-allowed border-gray-200 bg-gray-50 opacity-60'
          : isInCart
            ? 'border-l-4 border-l-primary-500 border-t-gray-200 border-r-gray-200 border-b-gray-200 bg-white shadow-sm'
            : 'cursor-pointer border-gray-200 bg-white hover:border-primary-300 hover:shadow-md',
      )}
    >
      {/* Modifier customize button — now a real <button>, no longer nested. */}
      {hasModifiers && onCustomize && (
        <button
          type="button"
          data-testid="customize-button"
          onClick={(e) => {
            e.stopPropagation();
            onCustomize(product);
          }}
          className="absolute top-1.5 right-1.5 flex h-10 w-10 items-center justify-center rounded-full bg-primary-100/90 text-primary-600 shadow-sm transition-colors hover:bg-primary-200 active:bg-primary-300"
          title={t('products.customize')}
        >
          <SlidersHorizontal className="h-5 w-5" />
        </button>
      )}
      {hasModifiers && !onCustomize && (
        <div className="absolute top-2 right-2">
          <SlidersHorizontal className="h-4 w-4 text-primary-500" />
        </div>
      )}

      {/* Image area (visual mode only) */}
      {displayMode === 'visual' && (
        <div className="mb-3 shrink-0">
          {imageSrc ? (
            <img
              src={imageSrc}
              alt=""
              className="h-20 w-20 rounded-xl object-cover"
            />
          ) : (
            <div className="flex h-20 w-20 items-center justify-center rounded-xl bg-gray-100">
              <Package className="h-8 w-8 text-gray-400" />
            </div>
          )}
        </div>
      )}

      {/* Name — flex-1 + line-clamp-2 + title for full text on hover */}
      <h3
        title={product.name}
        className={cn(
          'flex-1 min-h-0 line-clamp-2 font-semibold text-gray-900',
          displayMode === 'visual' ? 'text-sm' : 'text-base',
        )}
      >
        {product.name}
      </h3>

      {/* Price row — shrink-0 so it cannot be squeezed beneath the name */}
      <p
        data-testid="price-row"
        className="shrink-0 pt-2 text-lg font-bold text-primary-600"
      >
        {format(product.sale_price ?? '0')}
      </p>

      {/* Stock row — shrink-0, anchored at the bottom */}
      <p
        data-testid="stock-row"
        className={cn('shrink-0 mt-1 text-xs', stockTone)}
      >
        {stockLabel}
      </p>
    </div>
  );
}

export const ProductCard = memo(ProductCardInner);
```

Notes for the implementer:
- The outer wrapper is intentionally a `<div role="button">`, not a `<button>`. The current code nests the customize `<button>` inside the outer `<button>`, which is invalid HTML. Keep the keyboard handler — `Enter` and `Space` must trigger `onAddToCart`, otherwise we regress accessibility.
- Tailwind classes for `min-h` MUST be literal strings. Use only `CARD_MIN_H_CLASS_GRID` (`'min-h-[140px]'`) and `CARD_MIN_H_CLASS_VISUAL` (`'min-h-[220px]'`) imported from `cardSizing.ts`. Never compute them with template literals from numeric constants — Tailwind's JIT scanner will not extract them and the styles will silently disappear in production builds.

- [ ] **Step 4: Run tests, expect 6 new tests to pass and existing tests to remain green**

Run: `cd apps/pos && pnpm test -- --run src/components/molecules/ProductCard`
Expected: all green. If existing tests asserted `getByRole('button')` returns the outer card, update them to query the customize button explicitly via `data-testid="customize-button"` or to look for `role=button` on the card root.

- [ ] **Step 5: Manual viewport sanity check**

Run `pnpm dev` from `apps/pos`. Log into the iziPOS retail tenant. Open DevTools and resize to 1366×768, 1280×720, and 1024×600. Add an item to cart. Confirm:
- The longest pet-shop product names ("Clumping Lavender Cat Litter…") wrap to two lines without overlapping the price.
- Hover the name → full text appears as a tooltip.
- The Pay button stays pinned (regression check from PR #65).

- [ ] **Step 6: Commit**

```bash
git add apps/pos/src/components/molecules/ProductCard/ProductCard.tsx \
        apps/pos/src/components/molecules/ProductCard/__tests__/ProductCard.test.tsx
git commit -m "fix(pos): pin price/stock rows and tooltip the full product name"
```

---

## Task 3: Build local sales-count repository

**Files:**
- Create: `apps/pos/src/lib/db/repositories/productSalesAggregateRepository.ts`
- Test: `apps/pos/src/lib/db/repositories/__tests__/productSalesAggregateRepository.test.ts`

- [ ] **Step 1: Write the failing repository test using the existing SqliteTestAdapter harness**

This mirrors the setup used by `apps/pos/src/lib/db/__tests__/migration22.integration.test.ts`. Do NOT use `Database.load(':memory:')` (the runtime client is `@tauri-apps/plugin-sql` which doesn't run in node) and do NOT import `runMigrations` (it's a private function inside `apps/pos/src/lib/db.ts`). Iterate the exported `migrations` array against the adapter directly.

```ts
// apps/pos/src/lib/db/repositories/__tests__/productSalesAggregateRepository.test.ts
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import { migrations } from '@/lib/db/migrations';
import { aggregateProductSales } from '../productSalesAggregateRepository';

// Node 22.5+ ships node:sqlite; older versions skip the suite (matches migration22 test).
const nodeSqliteAvailable = (() => {
  try {
    return Boolean(require('node:sqlite').DatabaseSync);
  } catch {
    return false;
  }
})();

const d = nodeSqliteAvailable ? describe : describe.skip;

async function applyAllMigrations(adapter: SqliteTestAdapter): Promise<void> {
  for (const m of migrations) {
    if (m.run) {
      await m.run(adapter);
    } else if (m.sql) {
      await adapter.execute(m.sql);
    }
  }
}

/**
 * Insert one offline_receipts row using the column set defined in
 * apps/pos/src/lib/db/migrations.ts. `linesJson` is the exact string
 * `receiptService.ts` writes into the column.
 */
async function insertReceipt(
  adapter: SqliteTestAdapter,
  args: { id: string; idempotencyKey: string; linesJson: string; createdAtSql?: string },
): Promise<void> {
  const createdAt = args.createdAtSql ?? "datetime('now')";
  await adapter.execute(
    `INSERT INTO offline_receipts (
       id, idempotency_key, receipt_number, terminal_id, terminal_code,
       operator_id, operator_name, lines, subtotal, tax_amount, total,
       currency, fiscal_hash, previous_hash, hash_sequence,
       payment_method_id, payment_repository_id, status, created_at
     ) VALUES (?, ?, 'R-1', 't', 'tc', 'op', 'Op', ?, '0', '0', '0', 'EUR', 'h', 'p', 1, 'pm', 'pr', 'synced', ${createdAt})`,
    [args.id, args.idempotencyKey, args.linesJson],
  );
}

d('aggregateProductSales', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    await applyAllMigrations(adapter);
  });

  afterEach(() => {
    adapter.close();
  });

  it('returns an empty Map when there are no receipts', async () => {
    const counts = await aggregateProductSales(adapter, { sinceDays: 30 });
    expect(counts.size).toBe(0);
  });

  it('sums the quantity field across the receipt-line shape receiptService writes (product_id at top level)', async () => {
    await insertReceipt(adapter, {
      id: 'r1',
      idempotencyKey: 'k1',
      linesJson: JSON.stringify([
        { product_id: 'p1', name: 'A', sku: 'A', quantity: 3, unit_price: '1.000', line_total: '3.000' },
        { product_id: 'p2', name: 'B', sku: 'B', quantity: 1, unit_price: '1.000', line_total: '1.000' },
      ]),
    });
    await insertReceipt(adapter, {
      id: 'r2',
      idempotencyKey: 'k2',
      linesJson: JSON.stringify([
        { product_id: 'p1', name: 'A', sku: 'A', quantity: 2, unit_price: '1.000', line_total: '2.000' },
      ]),
    });

    const counts = await aggregateProductSales(adapter, { sinceDays: 30 });
    expect(counts.get('p1')).toBe(5);
    expect(counts.get('p2')).toBe(1);
  });

  it('aggregates composite_item_id under the same key when product_id is absent', async () => {
    await insertReceipt(adapter, {
      id: 'r3',
      idempotencyKey: 'k3',
      linesJson: JSON.stringify([
        { composite_item_id: 'c1', name: 'Combo', sku: 'C', quantity: 4, unit_price: '5.000', line_total: '20.000' },
      ]),
    });
    const counts = await aggregateProductSales(adapter, { sinceDays: 30 });
    expect(counts.get('c1')).toBe(4);
  });

  it('respects the sinceDays window', async () => {
    await insertReceipt(adapter, {
      id: 'r-old',
      idempotencyKey: 'k-old',
      linesJson: JSON.stringify([{ product_id: 'p1', name: 'A', sku: 'A', quantity: 100, unit_price: '1', line_total: '1' }]),
      createdAtSql: "datetime('now', '-60 days')",
    });
    await insertReceipt(adapter, {
      id: 'r-new',
      idempotencyKey: 'k-new',
      linesJson: JSON.stringify([{ product_id: 'p1', name: 'A', sku: 'A', quantity: 2, unit_price: '1', line_total: '1' }]),
      createdAtSql: "datetime('now', '-1 days')",
    });

    const counts30 = await aggregateProductSales(adapter, { sinceDays: 30 });
    expect(counts30.get('p1')).toBe(2);

    const counts90 = await aggregateProductSales(adapter, { sinceDays: 90 });
    expect(counts90.get('p1')).toBe(102);
  });

  it('skips malformed JSON without throwing', async () => {
    await insertReceipt(adapter, {
      id: 'r-bad',
      idempotencyKey: 'k-bad',
      linesJson: '{not-json',
    });
    const counts = await aggregateProductSales(adapter, { sinceDays: 30 });
    expect(counts.size).toBe(0);
  });
});
```

- [ ] **Step 2: Run, expect failure (module not found)**

Run: `cd apps/pos && pnpm test -- --run src/lib/db/repositories/__tests__/productSalesAggregateRepository`
Expected: FAIL — `Cannot find module '../productSalesAggregateRepository'`.

- [ ] **Step 3: Write the repository**

```ts
// apps/pos/src/lib/db/repositories/productSalesAggregateRepository.ts

/**
 * Minimal executor surface satisfied by both `@tauri-apps/plugin-sql`'s
 * `Database` (production) and `SqliteTestAdapter` (tests). Avoids a hard
 * dependency on the Tauri client, which doesn't run in node test envs.
 */
export interface DbExecutor {
  select<T>(sql: string, params?: unknown[]): Promise<T>;
}

export interface AggregateOptions {
  /** Window size in days; rows older than (now - sinceDays) are ignored. */
  sinceDays: number;
}

/**
 * Receipt line shape as written by `apps/pos/src/lib/offline/receiptService.ts`.
 * Either `product_id` or `composite_item_id` is set, never both — depending on
 * `cartItem.product.sellableType`. We aggregate under whichever is present so
 * the sort order matches the IDs used by `POSProduct.id` in the grid.
 */
interface ReceiptLine {
  product_id?: unknown;
  composite_item_id?: unknown;
  quantity?: unknown;
}

/**
 * Aggregate units sold per product from offline_receipts.lines (JSON blob)
 * within a recent date window. Pure read-only — never mutates the receipts.
 *
 * Returns a Map<productOrCompositeId, totalQuantity>. Malformed JSON rows
 * are skipped.
 */
export async function aggregateProductSales(
  db: DbExecutor,
  { sinceDays }: AggregateOptions,
): Promise<Map<string, number>> {
  const rows = await db.select<{ lines: string }[]>(
    `SELECT lines FROM offline_receipts
     WHERE created_at >= datetime('now', ?)`,
    [`-${sinceDays} days`],
  );

  const counts = new Map<string, number>();
  for (const row of rows) {
    let parsed: unknown;
    try {
      parsed = JSON.parse(row.lines);
    } catch {
      continue;
    }
    if (!Array.isArray(parsed)) continue;

    for (const raw of parsed as ReceiptLine[]) {
      const rawId = typeof raw.product_id === 'string'
        ? raw.product_id
        : typeof raw.composite_item_id === 'string'
          ? raw.composite_item_id
          : null;
      const qty = raw.quantity;
      if (rawId === null || typeof qty !== 'number' || !Number.isFinite(qty)) {
        continue;
      }
      counts.set(rawId, (counts.get(rawId) ?? 0) + qty);
    }
  }
  return counts;
}
```

- [ ] **Step 4: Run, expect green**

Run: `cd apps/pos && pnpm test -- --run src/lib/db/repositories/__tests__/productSalesAggregateRepository`
Expected: all 5 tests pass (or skip when `node:sqlite` is unavailable, matching the `migration22` pattern).

- [ ] **Step 5: Commit**

```bash
git add apps/pos/src/lib/db/repositories/productSalesAggregateRepository.ts \
        apps/pos/src/lib/db/repositories/__tests__/productSalesAggregateRepository.test.ts
git commit -m "feat(pos): aggregate product sales counts from offline_receipts"
```

---

## Task 4: useMostSoldCounts hook

**Files:**
- Create: `apps/pos/src/hooks/useMostSoldCounts.ts`
- Test: `apps/pos/src/hooks/__tests__/useMostSoldCounts.test.ts`

- [ ] **Step 1: Write the failing hook test**

```ts
// apps/pos/src/hooks/__tests__/useMostSoldCounts.test.ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { useMostSoldCounts } from '../useMostSoldCounts';

vi.mock('@/lib/db/repositories/productSalesAggregateRepository', () => ({
  aggregateProductSales: vi.fn(async () => new Map([['p1', 5], ['p2', 2]])),
}));

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn(async () => ({} as never)),
}));

describe('useMostSoldCounts', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('returns counts on mount', async () => {
    const { result } = renderHook(() => useMostSoldCounts({ companyId: 'c1', enabled: true }));
    await waitFor(() => {
      expect(result.current.counts.size).toBe(2);
    });
    expect(result.current.counts.get('p1')).toBe(5);
  });

  it('returns an empty Map when disabled (no DB read)', async () => {
    const repo = await import('@/lib/db/repositories/productSalesAggregateRepository');
    const { result } = renderHook(() => useMostSoldCounts({ companyId: 'c1', enabled: false }));
    await waitFor(() => {
      expect(result.current.counts.size).toBe(0);
    });
    expect(repo.aggregateProductSales).not.toHaveBeenCalled();
  });
});
```

- [ ] **Step 2: Run, expect failure**

Run: `cd apps/pos && pnpm test -- --run src/hooks/__tests__/useMostSoldCounts`
Expected: FAIL — module not found.

- [ ] **Step 3: Implement the hook**

```ts
// apps/pos/src/hooks/useMostSoldCounts.ts
import { useEffect, useRef, useState } from 'react';
import { getDatabase } from '@/lib/db';
import { aggregateProductSales } from '@/lib/db/repositories/productSalesAggregateRepository';

const CACHE_TTL_MS = 5 * 60 * 1000; // 5 minutes
const WINDOW_DAYS = 30;

interface CacheEntry {
  counts: Map<string, number>;
  fetchedAt: number;
}

const cache = new Map<string, CacheEntry>();

export interface UseMostSoldCountsOptions {
  companyId: string | null;
  enabled: boolean;
}

export interface UseMostSoldCountsResult {
  counts: Map<string, number>;
  isLoading: boolean;
}

export function useMostSoldCounts({ companyId, enabled }: UseMostSoldCountsOptions): UseMostSoldCountsResult {
  const [counts, setCounts] = useState<Map<string, number>>(() => new Map());
  const [isLoading, setIsLoading] = useState(false);
  const aborted = useRef(false);

  useEffect(() => {
    aborted.current = false;
    if (!enabled || !companyId) {
      setCounts(new Map());
      return;
    }

    const cacheKey = `${companyId}:${WINDOW_DAYS}`;
    const cached = cache.get(cacheKey);
    if (cached && Date.now() - cached.fetchedAt < CACHE_TTL_MS) {
      setCounts(cached.counts);
      return;
    }

    setIsLoading(true);
    void (async () => {
      try {
        const db = await getDatabase(companyId);
        const next = await aggregateProductSales(db, { sinceDays: WINDOW_DAYS });
        if (aborted.current) return;
        cache.set(cacheKey, { counts: next, fetchedAt: Date.now() });
        setCounts(next);
      } catch {
        // Offline-first: an aggregation failure is non-fatal — fall back to empty map
        if (!aborted.current) setCounts(new Map());
      } finally {
        if (!aborted.current) setIsLoading(false);
      }
    })();

    return () => {
      aborted.current = true;
    };
  }, [companyId, enabled]);

  return { counts, isLoading };
}
```

- [ ] **Step 4: Run, expect green**

Run: `cd apps/pos && pnpm test -- --run src/hooks/__tests__/useMostSoldCounts`
Expected: 2 tests pass.

- [ ] **Step 5: Commit**

```bash
git add apps/pos/src/hooks/useMostSoldCounts.ts apps/pos/src/hooks/__tests__/useMostSoldCounts.test.ts
git commit -m "feat(pos): add useMostSoldCounts hook with 5-min cache"
```

---

## Task 5: Add i18n keys

**Files:**
- Modify: `apps/pos/src/locales/en/pos.json`
- Modify: `apps/pos/src/locales/fr/pos.json`

- [ ] **Step 1: Update `en/pos.json`**

Find the `products` block. Replace the `popular` key and add the new ones:

```json
"products": {
  "...": "...",
  "sortByMostSold": "Sort by most sold",
  "sortDefault": "Default order",
  "fullName": "Full product name"
}
```

Remove `"popular": "Popular"`.

- [ ] **Step 2: Update `fr/pos.json` mirroring the same keys**

```json
"products": {
  "...": "...",
  "sortByMostSold": "Trier par plus vendus",
  "sortDefault": "Ordre par défaut",
  "fullName": "Nom complet du produit"
}
```

Remove `"popular": "Populaires"`.

- [ ] **Step 3: Run all i18n-touching tests**

Run: `cd apps/pos && pnpm test -- --run src/components/organisms/ProductGrid`
Expected: ProductGrid tests still pass; one or two may now reference a missing key — fix the test in Task 6.

- [ ] **Step 4: Commit**

```bash
git add apps/pos/src/locales/en/pos.json apps/pos/src/locales/fr/pos.json
git commit -m "i18n(pos): replace 'Popular' label with most-sold sort labels"
```

---

## Task 6: Replace Popular row with Most-Sold sort toggle

**Files:**
- Modify: `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx`
- Test: `apps/pos/src/components/organisms/ProductGrid/__tests__/ProductGrid.test.tsx`

- [ ] **Step 1: Add a failing test for the toggle behaviour**

Append to `ProductGrid.test.tsx`. Note the Zustand mock — the production code calls `useAuthStore` as a hook with a selector (`useAuthStore((s) => s.companyId)`), so the mock must be a callable that runs the selector against state, with `.getState` attached for any non-hook callers.

```tsx
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { I18nextProvider } from 'react-i18next';
import i18n from '@/test/i18n';
import { ProductGrid } from '../ProductGrid';
import type { POSProduct } from '@/types/product';

vi.mock('@/hooks/useMostSoldCounts', () => ({
  useMostSoldCounts: () => ({
    counts: new Map([
      ['p-bestseller', 100],
      ['p-mid', 5],
    ]),
    isLoading: false,
  }),
}));

vi.mock('@/stores/authStore', () => {
  const state = { companyId: 'c1' as string | null };
  const useAuthStore = ((selector: (s: typeof state) => unknown) =>
    selector(state)) as unknown as {
      (selector: (s: typeof state) => unknown): unknown;
      getState: () => typeof state;
    };
  useAuthStore.getState = () => state;
  return { useAuthStore };
});

const products: POSProduct[] = [
  { id: 'p-zilch', name: 'Zilch', sku: 'Z', sale_price: '1.000', stock_quantity: 5, barcode: null },
  { id: 'p-bestseller', name: 'Bestseller', sku: 'B', sale_price: '1.000', stock_quantity: 5, barcode: null },
  { id: 'p-mid', name: 'Mid', sku: 'M', sale_price: '1.000', stock_quantity: 5, barcode: null },
];

function renderGrid(extra?: Partial<React.ComponentProps<typeof ProductGrid>>) {
  return render(
    <I18nextProvider i18n={i18n}>
      <ProductGrid
        products={products}
        categories={[]}
        onAddToCart={vi.fn()}
        cartProductIds={[]}
        {...extra}
      />
    </I18nextProvider>,
  );
}

describe('ProductGrid most-sold sort + popular-row removal', () => {
  it('reorders products by most-sold-first when the sort button is clicked', () => {
    renderGrid();

    // Sort button is a real button (not the card role="button" wrappers).
    const toggle = screen.getByRole('button', { name: /sort by most sold/i });
    fireEvent.click(toggle);

    // Cards now expose role="button" via the refactored ProductCard. Query by
    // aria-label which equals the product name.
    const cards = screen.getAllByRole('button', { name: /Bestseller|Mid|Zilch/ });
    expect(cards[0]).toHaveAccessibleName('Bestseller'); // count 100 → first
    expect(cards[1]).toHaveAccessibleName('Mid');        // count 5
    expect(cards[2]).toHaveAccessibleName('Zilch');      // count 0 → last (alpha tiebreak)
  });

  it('toggles back to default order on a second click', () => {
    renderGrid();
    const toggle = screen.getByRole('button', { name: /sort by most sold/i });
    fireEvent.click(toggle); // most-sold
    fireEvent.click(toggle); // back to default

    const cards = screen.getAllByRole('button', { name: /Bestseller|Mid|Zilch/ });
    // Default sort is alphabetical (no `position` field on these fixtures).
    expect(cards[0]).toHaveAccessibleName('Bestseller');
    expect(cards[1]).toHaveAccessibleName('Mid');
    expect(cards[2]).toHaveAccessibleName('Zilch');
  });

  it('does NOT render the legacy Popular pill row', () => {
    renderGrid();
    expect(screen.queryByText(/^popular$/i)).toBeNull();
    expect(screen.queryByText(/^populaires$/i)).toBeNull();
  });
});
```

- [ ] **Step 2: Run, expect failures**

Run: `cd apps/pos && pnpm test -- --run src/components/organisms/ProductGrid`
Expected: 2 failures.

- [ ] **Step 3: Modify `ProductGrid.tsx`**

Apply these changes (full replacement of the relevant sections — remove the `popularProducts` memo + the popular row JSX entirely; add `sortMode` state and the toggle button next to the display-mode toggle).

```tsx
// Top of file:
import { useAuthStore } from '@/stores/authStore';
import { useMostSoldCounts } from '@/hooks/useMostSoldCounts';
import {
  CARD_MIN_H_GRID,
  CARD_MIN_H_VISUAL,
  GAP,
} from '@/components/molecules/ProductCard/cardSizing';
import { TrendingUp } from 'lucide-react';

// Replace inline ROW_HEIGHT_GRID/ROW_HEIGHT_VISUAL/GAP with the imported constants.

// Remove:
//   const POPULAR_COUNT = 8;
//   const popularProducts = useMemo(() => …);
// and the JSX block for the popular pill row.

type SortMode = 'default' | 'mostSold';

export function ProductGrid({ /* …existing props… */ }: ProductGridProps) {
  // …existing hooks…
  const [sortMode, setSortMode] = useState<SortMode>('default');
  const companyId = useAuthStore((s) => s.companyId);
  const { counts: salesCounts } = useMostSoldCounts({
    companyId,
    enabled: sortMode === 'mostSold',
  });

  // Replace the existing `sortedProducts` memo with one that branches on sortMode:
  const sortedProducts = useMemo(() => {
    const base = [...products];
    if (sortMode === 'mostSold') {
      return base.sort((a, b) => {
        const ca = salesCounts.get(a.id) ?? 0;
        const cb = salesCounts.get(b.id) ?? 0;
        if (cb !== ca) return cb - ca;
        return a.name.localeCompare(b.name);
      });
    }
    return base.sort((a, b) => {
      if (a.position !== undefined && b.position !== undefined) {
        return a.position - b.position;
      }
      if (a.position !== undefined) return -1;
      if (b.position !== undefined) return 1;
      return a.name.localeCompare(b.name);
    });
  }, [products, sortMode, salesCounts]);
}
```

In the JSX for the search/toolbar row (where the display-mode toggle lives), append a sort toggle button:

```tsx
<button
  onClick={() => setSortMode((m) => (m === 'mostSold' ? 'default' : 'mostSold'))}
  className={cn(
    'flex h-12 items-center gap-2 rounded-lg border px-4 text-sm font-medium transition-colors',
    sortMode === 'mostSold'
      ? 'border-primary-500 bg-primary-50 text-primary-700'
      : 'border-gray-300 bg-white text-gray-600 hover:bg-gray-50',
  )}
  title={sortMode === 'mostSold'
    ? t('products.sortDefault')
    : t('products.sortByMostSold')}
  aria-pressed={sortMode === 'mostSold'}
>
  <TrendingUp className="h-5 w-5" />
  {sortMode === 'mostSold'
    ? t('products.sortDefault')
    : t('products.sortByMostSold')}
</button>
```

Place it directly before the display-mode toggle inside the toolbar `<div className="flex items-center gap-2">` block.

Delete the entire popular pill row JSX (current lines 272-297 of `ProductGrid.tsx`).

- [ ] **Step 4: Run grid tests, expect green**

Run: `cd apps/pos && pnpm test -- --run src/components/organisms/ProductGrid`
Expected: existing tests + 2 new tests all pass.

- [ ] **Step 5: Run full POS test suite**

Run: `cd apps/pos && pnpm test -- --run`
Expected: full suite green.

- [ ] **Step 6: Manual smoke test**

`pnpm dev`, log in, ring up several sales of a single product, then close + reopen the cart. Click the "Sort by most sold" button. The product you sold most should jump to the top of the grid. Click again → grid returns to default order.

- [ ] **Step 7: Commit**

```bash
git add apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx \
        apps/pos/src/components/organisms/ProductGrid/__tests__/ProductGrid.test.tsx
git commit -m "feat(pos): replace Popular pill row with Most-Sold sort toggle"
```

---

## Task 7: Typecheck + lint sweep + push PR

**Files:**
- N/A (verification only)

- [ ] **Step 1: Typecheck**

```bash
cd apps/pos && pnpm typecheck
```
Expected: 0 errors.

- [ ] **Step 2: ESLint**

```bash
cd apps/pos && pnpm lint
```
Expected: 0 errors. Fix anything that surfaces (likely an unused import for `POPULAR_COUNT`).

- [ ] **Step 3: Push branch and open PR**

```bash
git push -u origin fix/pos-product-card-and-most-sold
gh pr create --base main \
  --title "feat(pos): product-card layout polish + Most-Sold sort toggle" \
  --body "$(cat <<'EOF'
## Summary
- Pin product-card price/stock rows so long names never overlap them, and add a hover tooltip showing the full name (matches Square / Toast / Lightspeed POS conventions).
- Replace the static \"Popular\" pill row with a toggle button that re-orders the grid by most-sold-first. Counts are aggregated locally from \`offline_receipts.lines\` over the last 30 days, so the feature works fully offline.
- Single source of truth for card + virtualizer row heights in \`cardSizing.ts\`.

## Test plan
- [ ] \`pnpm test\` green (ProductCard 4 new tests, ProductGrid 2 new tests, repo + hook tests).
- [ ] Manual: long pet-shop product names wrap to two lines without visual overlap at 1366×768, 1280×720, 1024×600.
- [ ] Manual: hover the name → tooltip shows the full text.
- [ ] Manual: ring up the same product several times, click \"Sort by most sold\" → that product is first; click again → default order restored.
- [ ] Pay button stays pinned (PR #65 regression check).

## Out of scope
- Per-category color coding on cards (future iteration, agreed with owner).
- Server-side per-product sales rollup; local 30-day window is sufficient.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

- [ ] **Step 4: Wait for CI, then merge after review**

---

## Self-review notes

- Spec coverage: card-overlap fix (Task 2), tooltip (Task 2), nested-button HTML fix (Task 2), Popular row removal + Most-Sold button (Task 6), local sales aggregation (Task 3), hook with cache (Task 4), i18n (Task 5), verification + PR (Task 7). Color coding deferred per user.
- No placeholders. Every step has either runnable code, runnable shell, or an explicit verification step.
- Type consistency: `ProductCardProps`, `POSProduct`, `DbExecutor`, `aggregateProductSales`, `useMostSoldCounts`, `SortMode` are introduced once and reused identically downstream.
- DB-test pattern: `SqliteTestAdapter` from `apps/pos/src/lib/db/__tests__/helpers/sqliteTestAdapter.ts`, iterating the exported `migrations` array directly. The repo function targets a minimal `DbExecutor` interface (`select<T>`) that both the adapter and the production `Database` satisfy. Do not import `runMigrations` from `@/lib/db.ts` — it is a private function.
- Receipt-line shape: `receiptService.ts` writes `product_id` (or `composite_item_id` when `sellableType === 'composite_item'`) at the TOP LEVEL of each line, NOT under `product.id`. `aggregateProductSales` reads either key.
- Tailwind class literals: only `'min-h-[140px]'` and `'min-h-[220px]'` appear as literal strings (in `cardSizing.ts`). The component imports them by name. Never compute these strings via template literals from numeric constants — Tailwind's JIT scanner will not extract them and the styles will silently disappear in production.
- Outer card wrapper is `<div role="button" tabIndex>` with explicit `Enter`/`Space` handling, so the inner customize `<button>` is no longer nested in another `<button>`. Existing tests that asserted `getByRole('button')` against the card root still work because `role="button"` produces the same role; tests querying for the customize control should use `data-testid="customize-button"`.
