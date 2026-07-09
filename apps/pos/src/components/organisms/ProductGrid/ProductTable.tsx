import { memo, useCallback, useEffect, useRef, useState } from 'react';
import type { CSSProperties, KeyboardEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { useVirtualizer } from '@tanstack/react-virtual';
import { ArrowUpRight, Eye, Plus } from 'lucide-react';
import { cn } from '@/lib/utils';
import { tokens } from '@/lib/designTokens';
import { useCurrency } from '@/lib/currency';
import { useProductImage } from '@/lib/images/useProductImage';
import { ProductThumb, StockBadge } from '@/components/ui';
import { bccomp, bcsum } from '@/lib/decimal';
import { formatAvailableQty } from '@/lib/stock/stockGate';
import { useStockDisplay } from './useStockDisplay';
import { NearExpirySlot } from './NearExpirySlot';
import type { POSProduct } from '@/types/product';
import type { GridLocationStockMap, LocationStockDisplay } from '@/lib/stock/gridStock';

/**
 * Task 13 — "Tableau" desktop-dense view: the mouse+keyboard station's
 * workhorse for 5-10k SKUs. Columns: Code · Produit · Catégorie · Stock · Prix
 * · action.
 *
 * Structure choice — a div-based ARIA grid (`role="table"` / `role="row"` /
 * `role="columnheader"` / `role="gridcell"`), NOT a literal `<table>`.
 * Virtualizing `<tbody>` rows with absolutely-positioned rows collapses native
 * table layout; the div-grid keeps the sticky header, measured virtual rows,
 * and keyboard nav all working with CSS grid columns for alignment. The SAME
 * `gridTemplateColumns` string aligns the header and every data row.
 *
 * Owner feedback (2026-07-08) — the eye/"view details" affordance was
 * MISSING here (only a hidden `onDoubleClick`, undiscoverable). The action
 * column now holds a visible eye button (desktop-dense ~40px target) beside
 * the `+` add button; the action track widened from 48px → 96px to fit both
 * without clipping, in the SAME `GRID_COLS` string the header uses.
 *
 * Reuses `useStockDisplay` (the three `locationStock` paths — DRY with
 * `ProductCard` / `ProductListRow`) and `useCurrency`. Quantities stay decimal
 * STRINGS end-to-end (`bccomp` / `bcsum`, never `Number()` / `parseFloat`).
 */

const QTY_SCALE = 4;

/**
 * Shared column track for header + data rows. `minmax(0,1fr)` lets the
 * Produit column absorb slack while its name truncates; fixed-ish tracks keep
 * Code / Catégorie / Stock / Prix / action aligned across all rows.
 */
const GRID_COLS =
  'minmax(84px,110px) minmax(0,1fr) minmax(96px,150px) minmax(96px,140px) minmax(96px,132px) 96px';

/** Estimated row height (px) — real height is measured via `measureElement`. */
const ROW_ESTIMATE = 44;

/**
 * Stable default for the `locationStock` prop — an inline `{}` default would be
 * a new object every render (see `ProductGrid`'s `EMPTY_LOCATION_STOCK`).
 */
const EMPTY_LOCATION_STOCK: GridLocationStockMap = {};

export interface ProductTableProps {
  products: POSProduct[];
  onAddToCart: (product: POSProduct) => void;
  onViewDetails?: (product: POSProduct) => void;
  /**
   * Task 12 — per-product location-stock display map. Look up per product by
   * `product.id`; `undefined` = no join ran (legacy `stock_quantity` path),
   * `null` = stock-exempt, object = location-aware `available` string. `{}`
   * for Menu tenants / browser dev → every lookup undefined → legacy path.
   */
  locationStock?: GridLocationStockMap;
  /** Threaded to `useStockDisplay`; default true (fail-safe for retail). */
  hardBlockOutOfStock?: boolean;
}

interface ProductTableRowProps {
  product: POSProduct;
  index: number;
  rowCount: number;
  isFocused: boolean;
  locationStock: LocationStockDisplay | null | undefined;
  hardBlockOutOfStock: boolean;
  onAddToCart: (product: POSProduct) => void;
  onViewDetails?: (product: POSProduct) => void;
  onMoveFocus: (nextIndex: number) => void;
  /** Absolute-positioning style from the virtualizer (transform translateY). */
  style: CSSProperties;
  /** `virtualizer.measureElement` — dynamic row-height measurement. */
  measureRef: (node: HTMLElement | null) => void;
}

const ProductTableRow = memo(function ProductTableRow({
  product,
  index,
  rowCount,
  isFocused,
  locationStock,
  hardBlockOutOfStock,
  onAddToCart,
  onViewDetails,
  onMoveFocus,
  style,
  measureRef,
}: ProductTableRowProps) {
  const { t } = useTranslation('pos');
  const { format } = useCurrency();
  const localImage = useProductImage(product.id, product.image_url);
  const imageSrc = localImage ?? product.image_url;

  const { stockLabel, status, isActivationBlocked } = useStockDisplay(
    product,
    locationStock,
    hardBlockOutOfStock,
  );

  // Arriving indicator — only on the location-aware path (slice is an object),
  // when a transfer and/or purchase order is inbound. Decimal strings summed
  // exactly with bcsum (never float-added).
  let incomingTotal: string | null = null;
  if (locationStock != null) {
    const total = bcsum([locationStock.incoming_transfer, locationStock.incoming_po], QTY_SCALE);
    if (bccomp(total, '0') > 0) {
      incomingTotal = formatAvailableQty(total);
    }
  }

  const addLabel = t('productDetail.addToCart');
  const viewDetailsLabel = t('products.viewDetails');

  const activate = useCallback(() => {
    if (!isActivationBlocked) {
      onAddToCart(product);
    }
  }, [isActivationBlocked, onAddToCart, product]);

  const handleKeyDown = (e: KeyboardEvent<HTMLDivElement>) => {
    switch (e.key) {
      case 'ArrowDown':
        e.preventDefault();
        onMoveFocus(index + 1);
        break;
      case 'ArrowUp':
        e.preventDefault();
        onMoveFocus(index - 1);
        break;
      case 'Home':
        e.preventDefault();
        onMoveFocus(0);
        break;
      case 'End':
        e.preventDefault();
        onMoveFocus(rowCount - 1);
        break;
      case 'Enter':
      case ' ':
        e.preventDefault();
        activate();
        break;
      default:
        break;
    }
  };

  const isOut = status === 'out';

  return (
    <div
      ref={measureRef}
      role="row"
      data-testid="product-table-row"
      data-rowindex={index}
      aria-rowindex={index + 2 /* 1-based + header row */}
      tabIndex={isFocused ? 0 : -1}
      onKeyDown={handleKeyDown}
      onDoubleClick={onViewDetails ? () => onViewDetails(product) : undefined}
      className={cn(
        'absolute left-0 top-0 grid w-full items-center border-b border-border-subtle',
        'text-[13px] transition-colors',
        'hover:bg-surface-raised',
        'focus:outline-none focus-visible:z-[1] focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-action',
        isActivationBlocked && 'opacity-70',
      )}
      style={{ ...style, gridTemplateColumns: GRID_COLS }}
    >
      {/* Code */}
      <div
        role="gridcell"
        className="truncate px-2 py-1.5 font-mono text-[11.5px] text-ink-muted"
        title={product.sku}
      >
        {product.sku}
      </div>

      {/* Produit — brand (uppercase, muted) + truncating name */}
      <div role="gridcell" className="flex min-w-0 items-center gap-2 px-2 py-1.5">
        <ProductThumb
          name={product.name}
          category={product.category}
          imageUrl={imageSrc}
          size={26}
        />
        <div className="min-w-0 flex-1">
          {product.brand_name && (
            <p
              className={cn(
                'w-full truncate text-[9.5px] font-bold uppercase leading-[1.2] tracking-[0.05em]',
                isOut ? 'text-ink-faint' : 'text-ink-muted',
              )}
            >
              {product.brand_name}
            </p>
          )}
          <p
            title={product.name}
            className={cn(
              // Shared productName recipe (owner polish 2026-07-09, sub-task
              // d): 13→13.5px so the name reads the same in every density.
              'w-full',
              tokens.productName.base,
              tokens.productName.clamp1,
              isOut ? tokens.productName.inkDisabled : tokens.productName.ink,
            )}
          >
            {product.name}
          </p>
        </div>
      </div>

      {/* Catégorie */}
      <div
        role="gridcell"
        className="truncate px-2 py-1.5 text-[12px] text-ink-muted"
        title={product.category ?? undefined}
      >
        {product.category ?? '—'}
      </div>

      {/* Stock — unified badge + optional arriving indicator */}
      <div role="gridcell" className="flex min-w-0 items-center gap-1.5 px-2 py-1.5">
        {stockLabel !== null && status !== null && (
          <StockBadge
            status={status}
            hideDot
            className="shrink-0 px-[7px] py-[2px] text-[10.5px] font-semibold"
          >
            {stockLabel}
          </StockBadge>
        )}
        {incomingTotal !== null && (
          <span
            data-testid="incoming-badge"
            className="inline-flex shrink-0 items-center gap-0.5 text-[10.5px] font-medium text-action"
          >
            <ArrowUpRight className="h-3 w-3" aria-hidden="true" />
            {incomingTotal}
          </span>
        )}
        {/* Spec 2 reserved slot — renders nothing today, see NearExpirySlot doc. */}
        <NearExpirySlot product={product} />
      </div>

      {/* Prix — data, never accent: always text-ink (designTokens.ts §1) */}
      <div
        role="gridcell"
        data-testid="price-row"
        className={cn(
          'px-2 py-1.5 text-right font-mono text-[13.5px] font-semibold tabular-nums',
          isOut ? 'text-ink-faint' : 'text-ink',
        )}
      >
        {format(product.sale_price ?? '0')}
      </div>

      {/* Action — view-details eye + the add affordance. tabIndex -1 on both:
          roving focus stays on the row (Enter/Space adds); the buttons are
          mouse/touch affordances. */}
      <div role="gridcell" className="flex items-center justify-center gap-1 px-1 py-1">
        {onViewDetails && (
          <button
            type="button"
            data-testid="view-details-button"
            tabIndex={-1}
            aria-label={viewDetailsLabel}
            onClick={(e) => {
              e.stopPropagation();
              onViewDetails(product);
            }}
            className="flex h-10 w-10 shrink-0 items-center justify-center rounded-ctl bg-surface-sunken text-ink-muted transition-colors hover:bg-border-subtle hover:text-ink active:bg-border-subtle"
            title={viewDetailsLabel}
          >
            <Eye className="h-4 w-4" />
          </button>
        )}

        <button
          type="button"
          data-testid="add-button"
          tabIndex={-1}
          aria-label={addLabel}
          aria-disabled={isActivationBlocked}
          onClick={(e) => {
            e.stopPropagation();
            activate();
          }}
          className={cn(
            'flex h-8 w-8 shrink-0 items-center justify-center rounded-sm transition-colors',
            isActivationBlocked
              ? 'cursor-not-allowed bg-surface-sunken text-ink-faint'
              : 'cursor-pointer bg-action text-ink-inverse hover:bg-action-hover active:bg-action-strong',
          )}
          title={addLabel}
        >
          <Plus className="h-4 w-4" />
        </button>
      </div>
    </div>
  );
});

export function ProductTable({
  products,
  onAddToCart,
  onViewDetails,
  locationStock = EMPTY_LOCATION_STOCK,
  hardBlockOutOfStock = true,
}: ProductTableProps) {
  const { t } = useTranslation('pos');
  const scrollRef = useRef<HTMLDivElement>(null);
  const [focusedIndex, setFocusedIndex] = useState(0);

  // Only steal DOM focus after an explicit keyboard move — never on mount
  // (which would hijack focus from wherever the user was).
  const pendingFocus = useRef(false);

  const rowCount = products.length;

  const virtualizer = useVirtualizer({
    count: rowCount,
    getScrollElement: () => scrollRef.current,
    estimateSize: () => ROW_ESTIMATE,
    overscan: 8,
  });

  const moveFocus = useCallback(
    (nextIndex: number) => {
      const clamped = Math.max(0, Math.min(nextIndex, rowCount - 1));
      pendingFocus.current = true;
      setFocusedIndex(clamped);
      virtualizer.scrollToIndex(clamped);
    },
    [rowCount, virtualizer],
  );

  // Move real DOM focus to the newly-focused row once it is rendered. Guarded
  // by `pendingFocus` so it fires only for keyboard navigation, and clamped so
  // a shrinking product list never points past the end.
  useEffect(() => {
    if (!pendingFocus.current) return;
    pendingFocus.current = false;
    const el = scrollRef.current?.querySelector<HTMLElement>(
      `[data-rowindex="${focusedIndex}"]`,
    );
    el?.focus();
  }, [focusedIndex]);

  const effectiveFocused = rowCount === 0 ? -1 : Math.min(focusedIndex, rowCount - 1);

  return (
    <div
      role="table"
      aria-label={t('table.ariaLabel')}
      aria-rowcount={rowCount + 1}
      data-testid="product-table"
      className="flex h-full min-h-0 flex-col"
    >
      <div ref={scrollRef} className="min-h-0 flex-1 overflow-y-auto bg-surface-sunken">
        {/* Sticky header row — pinned while data rows scroll. */}
        <div
          role="row"
          aria-rowindex={1}
          className="sticky top-0 z-10 grid items-center border-b border-border-strong bg-surface-raised text-[11px] font-semibold uppercase tracking-[0.04em] text-ink-muted"
          style={{ gridTemplateColumns: GRID_COLS }}
        >
          <div role="columnheader" className="px-2 py-2">
            {t('table.colCode')}
          </div>
          <div role="columnheader" className="px-2 py-2">
            {t('table.colProduct')}
          </div>
          <div role="columnheader" className="px-2 py-2">
            {t('table.colCategory')}
          </div>
          <div role="columnheader" className="px-2 py-2">
            {t('table.colStock')}
          </div>
          <div role="columnheader" className="px-2 py-2 text-right">
            {t('table.colPrice')}
          </div>
          <div role="columnheader" className="px-1 py-2">
            <span className="sr-only">{t('table.colAction')}</span>
          </div>
        </div>

        {/* Virtualized body — absolute rows inside a spacer of total height. */}
        <div
          role="rowgroup"
          className="relative w-full"
          style={{ height: `${virtualizer.getTotalSize()}px` }}
        >
          {virtualizer.getVirtualItems().map((virtualRow) => {
            const product = products[virtualRow.index];
            if (!product) return null;
            return (
              <ProductTableRow
                key={product.id}
                product={product}
                index={virtualRow.index}
                rowCount={rowCount}
                isFocused={virtualRow.index === effectiveFocused}
                locationStock={locationStock[product.id]}
                hardBlockOutOfStock={hardBlockOutOfStock}
                onAddToCart={onAddToCart}
                onViewDetails={onViewDetails}
                onMoveFocus={moveFocus}
                measureRef={virtualizer.measureElement}
                style={{ transform: `translateY(${virtualRow.start}px)` }}
              />
            );
          })}
        </div>
      </div>
    </div>
  );
}
