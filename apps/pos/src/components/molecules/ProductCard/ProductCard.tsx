import { memo, useCallback, type KeyboardEvent } from 'react';
import { cn } from '@/lib/utils';
import { tokens } from '@/lib/designTokens';
import { useCurrency } from '@/lib/currency';
import { ArrowUpRight, Check, Package, SlidersHorizontal } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useProductImage } from '@/lib/images/useProductImage';
import { bccomp, bcsum } from '@/lib/decimal';
import { formatAvailableQty } from '@/lib/stock/stockGate';
import {
  CARD_MIN_H_CLASS_GRID,
  CARD_MIN_H_CLASS_VISUAL,
  CARD_NAME_MIN_H_CLASS_GRID,
  CARD_NAME_MIN_H_CLASS_VISUAL,
} from './cardSizing';
import type { POSProduct } from '@/types/product';
import type { LocationStockDisplay } from '@/lib/stock/gridStock';

/** Quantity scale — ALWAYS pass explicitly (decimal.ts defaults to 3). */
const QTY_SCALE = 4;

/**
 * i18next reserves `count` for pluralisation and types it as `number`, but
 * `products.stock` / `stock.incoming` have no plural forms — `count` is
 * interpolation-only there. Quantities are decimal STRINGS end-to-end
 * (precision contract: never `Number()`/`parseFloat` a quantity), so we widen
 * the option type instead of coercing the quantity to a float.
 */
type TranslateWithStringCount = (key: string, opts: { count: string }) => string;

export interface ProductCardProps {
  product: POSProduct;
  onAddToCart: (product: POSProduct) => void;
  onCustomize?: (product: POSProduct) => void;
  isInCart?: boolean;
  displayMode?: 'grid' | 'visual';
  /**
   * Task 12 — location-stock display slice (`productStore.locationStock`).
   *   - object    → location-aware rendering (bccomp on decimal strings);
   *   - null      → stock-exempt (service / composite sellable): NO stock
   *                 chrome at all, never gated;
   *   - undefined → no join ran (Menu tenants / browser dev): legacy
   *                 `stock_quantity` rendering verbatim — Menu display
   *                 chrome (the 999 path) UNCHANGED.
   */
  locationStock?: LocationStockDisplay | null;
  /**
   * Whether an out-of-stock tile refuses activation. True only under the
   * 'block' policy — under 'warn'/'off' the tile stays tappable (the stock
   * gate surfaces the warning); the out-of-stock STYLING shows regardless.
   * Defaults to true (fail-safe for retail).
   */
  hardBlockOutOfStock?: boolean;
}

function ProductCardInner({
  product,
  onAddToCart,
  onCustomize,
  isInCart = false,
  displayMode = 'grid',
  locationStock,
  hardBlockOutOfStock = true,
}: ProductCardProps) {
  const { t } = useTranslation('pos');
  const { format } = useCurrency();
  const localImage = useProductImage(product.id, product.image_url);
  const imageSrc = localImage ?? product.image_url;
  const hasModifiers = (product.modifier_groups?.length ?? 0) > 0;

  // Stock chrome — three rendering paths (see `locationStock` prop docs).
  let isOutOfStock = false;
  let isLowStock = false;
  let stockLabel: string | null = null;
  if (locationStock === undefined) {
    // Legacy path — unchanged for Menu tenants (999) and browser dev.
    isOutOfStock = product.stock_quantity <= 0;
    isLowStock = product.stock_quantity > 0 && product.stock_quantity <= 10;
    stockLabel = isOutOfStock
      ? t('products.outOfStock')
      : isLowStock
        ? t('products.lowStock')
        : t('products.stock', { count: product.stock_quantity });
  } else if (locationStock !== null) {
    isOutOfStock = bccomp(locationStock.available, '0') <= 0;
    isLowStock = !isOutOfStock && bccomp(locationStock.available, '10') <= 0;
    stockLabel = isOutOfStock
      ? t('products.outOfStock')
      : isLowStock
        ? t('products.lowStock')
        : (t as unknown as TranslateWithStringCount)('products.stock', {
            count: formatAvailableQty(locationStock.available),
          });
  }
  // locationStock === null → exempt: stockLabel stays null, no gating.

  // Arriving badge — only on the location-aware path, when anything is
  // incoming (branch transfer and/or purchase order).
  let incomingTotal: string | null = null;
  let incomingTitle = '';
  if (locationStock != null) {
    const total = bcsum(
      [locationStock.incoming_transfer, locationStock.incoming_po],
      QTY_SCALE,
    );
    if (bccomp(total, '0') > 0) {
      incomingTotal = formatAvailableQty(total);
      const parts: string[] = [];
      if (bccomp(locationStock.incoming_transfer, '0') > 0) {
        parts.push(
          `${t('stock.incomingFromTransfer')}: ${formatAvailableQty(locationStock.incoming_transfer)}`,
        );
      }
      if (bccomp(locationStock.incoming_po, '0') > 0) {
        parts.push(
          `${t('stock.incomingOnOrder')}: ${formatAvailableQty(locationStock.incoming_po)}`,
        );
      }
      incomingTitle = parts.join(' · ');
    }
  }

  // In-stock count is data, not a money/sync confirmation → ink-muted, never
  // green. Low-stock gets a warning dot + warning-strong text (rendered as a
  // dot + label below). Out-of-stock is a NEUTRAL badge (see JSX), not red.
  const minHClass = displayMode === 'grid' ? CARD_MIN_H_CLASS_GRID : CARD_MIN_H_CLASS_VISUAL;
  const nameMinHClass =
    displayMode === 'grid' ? CARD_NAME_MIN_H_CLASS_GRID : CARD_NAME_MIN_H_CLASS_VISUAL;

  // Activation refuses only under 'block' policy (Codex final-review P1):
  // under 'warn'/'off' the tap must reach the stock gate, which allows the
  // add and surfaces the warning toast.
  const isActivationBlocked = isOutOfStock && hardBlockOutOfStock;

  const activate = useCallback(() => {
    if (!isActivationBlocked) onAddToCart(product);
  }, [isActivationBlocked, onAddToCart, product]);

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
      tabIndex={isActivationBlocked ? -1 : 0}
      aria-disabled={isActivationBlocked}
      aria-label={product.name}
      onClick={activate}
      onKeyDown={onKeyDown}
      className={cn(
        'relative flex flex-col rounded-xl border-2 p-4 text-left outline-none',
        minHClass,
        'transition-all duration-150 active:scale-[0.95] focus-visible:ring-2 focus-visible:ring-action',
        displayMode === 'visual' ? 'items-center text-center' : 'items-start',
        // Out-of-stock + hard-blocked: desaturate the whole card (sunken
        // surface, faint ink) instead of a red treatment — "unavailable", not
        // "error".
        isActivationBlocked
          ? 'cursor-not-allowed border-subtle bg-surface-sunken text-ink-faint opacity-70'
          : isInCart
            ? // Selected: full action border + a corner badge (below). No
              // asymmetric side-stripe.
              'cursor-pointer border-action bg-surface-raised shadow-sm'
            : 'cursor-pointer border-subtle bg-surface-raised hover:border-action hover:shadow-md',
      )}
    >
      {isInCart && (
        <span
          data-testid="in-cart-badge"
          className={cn(
            tokens.badge.neutral,
            'absolute top-1.5 left-1.5 border-action bg-action-subtle text-action-strong',
          )}
          title={t('products.inCart')}
        >
          <Check className="h-3 w-3" aria-hidden="true" />
          {t('products.inCart')}
        </span>
      )}

      {hasModifiers && onCustomize && (
        <button
          type="button"
          data-testid="customize-button"
          onClick={(e) => {
            e.stopPropagation();
            onCustomize(product);
          }}
          className="absolute top-1.5 right-1.5 flex h-10 w-10 items-center justify-center rounded-full bg-action-subtle text-action shadow-sm transition-colors hover:bg-action-subtle/70 active:bg-action-subtle"
          title={t('products.customize')}
        >
          <SlidersHorizontal className="h-5 w-5" />
        </button>
      )}
      {hasModifiers && !onCustomize && (
        <div className="absolute top-2 right-2">
          <SlidersHorizontal className="h-4 w-4 text-action" />
        </div>
      )}

      {displayMode === 'visual' && (
        <div className="mb-3 shrink-0">
          {imageSrc ? (
            <img
              src={imageSrc}
              alt=""
              className="h-20 w-20 rounded-xl object-cover"
            />
          ) : (
            <div className="flex h-20 w-20 items-center justify-center rounded-xl bg-surface-sunken">
              <Package className="h-8 w-8 text-ink-faint" />
            </div>
          )}
        </div>
      )}

      <h3
        title={product.name}
        className={cn(
          // Fixed two-line slot: line-clamp-2 caps the visible text and the
          // min-height pins the box to exactly two lines, so a long name can
          // never leak a sliced third line nor push the price/stock rows up.
          'w-full line-clamp-2 overflow-hidden font-semibold',
          nameMinHClass,
          isActivationBlocked ? 'text-ink-faint' : 'text-ink',
          displayMode === 'visual' ? 'text-sm' : 'text-base',
        )}
      >
        {product.name}
      </h3>

      <p
        data-testid="price-row"
        className={cn(
          'shrink-0 pt-2 text-lg font-bold tabular-nums',
          isActivationBlocked ? 'text-ink-faint' : 'text-ink',
        )}
      >
        {format(product.sale_price ?? '0')}
      </p>

      {stockLabel !== null && (
        <p
          data-testid="stock-row"
          className={cn(
            'shrink-0 mt-1 inline-flex items-center gap-1 text-xs',
            isOutOfStock
              ? 'font-medium text-ink-muted'
              : isLowStock
                ? 'font-medium text-warning-strong'
                : 'text-ink-muted',
          )}
        >
          {isLowStock && (
            <span
              aria-hidden="true"
              className="inline-block h-1.5 w-1.5 rounded-full bg-warning"
            />
          )}
          {stockLabel}
        </p>
      )}

      {incomingTotal !== null && (
        <p
          data-testid="incoming-badge"
          title={incomingTitle}
          className="shrink-0 mt-0.5 inline-flex items-center gap-0.5 text-xs font-medium text-action"
        >
          <ArrowUpRight className="h-3 w-3" aria-hidden="true" />
          {(t as unknown as TranslateWithStringCount)('stock.incoming', {
            count: incomingTotal,
          })}
        </p>
      )}
    </div>
  );
}

export const ProductCard = memo(ProductCardInner);
