import { memo, useCallback, useRef, type KeyboardEvent } from 'react';
import { cn } from '@/lib/utils';
import { tokens } from '@/lib/designTokens';
import { useCurrency } from '@/lib/currency';
import { ArrowUpRight, Check, Eye, SlidersHorizontal } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useProductImage } from '@/lib/images/useProductImage';
import { bccomp, bcsum } from '@/lib/decimal';
import { formatAvailableQty } from '@/lib/stock/stockGate';
import { ProductThumb, StockBadge } from '@/components/ui';
import { useStockDisplay } from '@/components/organisms/ProductGrid/useStockDisplay';
import {
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
  onViewDetails?: (product: POSProduct) => void;
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

interface ViewDetailsButtonProps {
  className: string;
  label: string;
  product: POSProduct;
  onViewDetails: (product: POSProduct) => void;
}

function ViewDetailsButton({
  className,
  label,
  product,
  onViewDetails,
}: ViewDetailsButtonProps) {
  return (
    <button
      type="button"
      data-testid="view-details-button"
      aria-label={label}
      onClick={(e) => {
        e.stopPropagation();
        onViewDetails(product);
      }}
      onKeyDown={(e) => {
        // The card root is role=button (onKeyDown=activate); Enter/Space on
        // this inner button must NOT bubble up and add the product to cart.
        if (e.key === 'Enter' || e.key === ' ') {
          e.stopPropagation();
          e.preventDefault();
          onViewDetails(product);
        }
      }}
      className={cn(
        'flex items-center justify-center rounded-sm bg-surface-raised/90 text-ink-muted shadow-sm backdrop-blur-sm transition-colors hover:text-ink active:bg-surface-sunken',
        className,
      )}
      title={label}
    >
      <Eye className="h-4 w-4" />
    </button>
  );
}

function ProductCardInner({
  product,
  onAddToCart,
  onCustomize,
  onViewDetails,
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

  // Stock chrome — three rendering paths, extracted to `useStockDisplay`
  // (see `locationStock` prop docs) so `ProductListRow` shares the exact
  // same derivation.
  const { isOutOfStock, stockLabel, status, isActivationBlocked } = useStockDisplay(
    product,
    locationStock,
    hardBlockOutOfStock,
  );

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

  const nameMinHClass =
    displayMode === 'grid' ? CARD_NAME_MIN_H_CLASS_GRID : CARD_NAME_MIN_H_CLASS_VISUAL;

  // Tap-confirm ring pulse — applied via direct DOM manipulation to avoid a
  // React state update inside event handlers (which triggers act() warnings in
  // tests). The animation is purely cosmetic; no re-render is needed.
  const cardRef = useRef<HTMLDivElement>(null);

  const activate = useCallback(() => {
    if (!isActivationBlocked) {
      if (cardRef.current) {
        const el = cardRef.current;
        el.classList.remove('ez-tap');
        // Force reflow so the animation restarts on rapid taps.
        void el.offsetWidth;
        el.classList.add('ez-tap');
        setTimeout(() => {
          el.classList.remove('ez-tap');
        }, 420);
      }
      onAddToCart(product);
    }
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

  // Card surface — three visual states:
  // 1. Out-of-stock (any policy): desaturated/dimmed. Cursor differs by policy.
  // 2. In-cart: full action (blue) border + action-subtle background. Selection
  //    is ALWAYS blue (Strategy A) — accent/green are reserved for the brand
  //    wordmark and stock/money, never for the selected state.
  // 3. Default: raised surface, action border on hover.
  const cardSurface = isOutOfStock
    ? cn(
        'border-subtle bg-surface-sunken text-ink-faint opacity-70',
        isActivationBlocked ? 'cursor-not-allowed' : 'cursor-pointer',
      )
    : isInCart
      ? 'cursor-pointer border-action bg-action-subtle shadow-sm'
      : 'cursor-pointer border-subtle bg-surface-raised hover:border-action hover:shadow-md';

  const viewDetailsLabel = t('products.viewDetails');

  return (
    <div
      ref={cardRef}
      role="button"
      tabIndex={isActivationBlocked ? -1 : 0}
      aria-disabled={isActivationBlocked}
      aria-label={product.name}
      onClick={activate}
      onKeyDown={onKeyDown}
      className={cn(
        // Card is CONTENT-SIZED (mock has no fixed height); `h-full` makes it
        // fill the grid track so cards in a row equalize (align-items:stretch)
        // and the virtualizer measures the real row height — no clipping.
        'relative flex h-full flex-col rounded-card border-[1.5px] text-left outline-none',
        displayMode === 'visual' ? 'p-2.5' : 'px-3 py-2.5',
        'transition-all duration-150 active:scale-[0.96] focus-visible:ring-2 focus-visible:ring-action',
        displayMode === 'visual' ? 'items-center text-center' : 'items-start',
        cardSurface,
      )}
    >
      {/* 3px top action (blue) bar — visible in the in-cart/selected state */}
      {isInCart && (
        <span
          aria-hidden
          className="absolute top-0 right-3.5 left-3.5 h-[3px] rounded-b-pill bg-action"
        />
      )}

      {isInCart && displayMode === 'visual' && (
        <span
          data-testid="in-cart-badge"
          className={cn(
            tokens.badge.neutral,
            'absolute top-1.5 left-1.5 border-action/40 bg-action-subtle text-action',
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

      {/* Visual mode: ProductThumb on top. Compact (grid) mode: no thumb. */}
      {displayMode === 'visual' && (
        <div
          data-testid="product-visual-tile"
          className="relative mb-2 h-[88px] w-full shrink-0 overflow-hidden rounded-tile"
        >
          <ProductThumb
            name={product.name}
            category={product.category}
            imageUrl={imageSrc}
            size={88}
            fullWidth
          />
          {/* Eye overlay on the full image tile (mock: top-left 7px inset). */}
          {onViewDetails && (
            <ViewDetailsButton
              className="absolute left-[7px] top-[7px] h-[29px] w-[29px] border border-subtle"
              label={viewDetailsLabel}
              product={product}
              onViewDetails={onViewDetails}
            />
          )}
        </div>
      )}

      {displayMode === 'grid' ? (
        <div className="flex w-full items-start gap-2">
          <div className="min-w-0 flex-1">
            {/* Brand name in caps — rendered only when present. */}
            {product.brand_name && (
              <p
                className={cn(
                  // Task 11 — 10px read as washed-out; bumped to 11px for legibility.
                  'w-full truncate text-[11px] font-bold leading-[1.2] tracking-[0.05em] uppercase',
                  isOutOfStock ? 'text-ink-faint' : 'text-ink-muted',
                )}
              >
                {product.brand_name}
              </p>
            )}

            <h3
              title={product.name}
              className={cn(
                // Fixed two-line slot: line-clamp-2 caps the visible text and the
                // min-height pins the box to exactly two lines, so a long name can
                // never leak a sliced third line nor push the price/stock rows up.
                'w-full line-clamp-2 overflow-hidden text-[13.5px] leading-[1.3] font-semibold',
                nameMinHClass,
                isOutOfStock ? 'text-ink-faint' : 'text-ink',
              )}
            >
              {product.name}
            </h3>
          </div>

          <div
            data-testid="compact-card-header-actions"
            className="flex shrink-0 items-center gap-1"
          >
            {isInCart && (
              <span
                data-testid="in-cart-badge"
                className={cn(
                  tokens.badge.neutral,
                  'border-action/40 bg-action-subtle text-action',
                )}
                title={t('products.inCart')}
              >
                <Check className="h-3 w-3" aria-hidden="true" />
                {t('products.inCart')}
              </span>
            )}
            {onViewDetails && (
              <ViewDetailsButton
                className="h-7 w-7 border-0 bg-surface-sunken"
                label={viewDetailsLabel}
                product={product}
                onViewDetails={onViewDetails}
              />
            )}
          </div>
        </div>
      ) : (
        <>
          {/* Brand name in caps — rendered only when present. */}
          {product.brand_name && (
            <p
              className={cn(
                // Task 11 — 10px read as washed-out; bumped to 11px for legibility.
                'w-full text-[11px] font-bold leading-[1.2] tracking-[0.05em] uppercase',
                isOutOfStock ? 'text-ink-faint' : 'text-ink-muted',
              )}
            >
              {product.brand_name}
            </p>
          )}

          <h3
            title={product.name}
            className={cn(
              // Fixed two-line slot: line-clamp-2 caps the visible text and the
              // min-height pins the box to exactly two lines, so a long name can
              // never leak a sliced third line nor push the price/stock rows up.
              'w-full line-clamp-2 overflow-hidden text-[13.5px] leading-[1.3] font-semibold',
              nameMinHClass,
              isOutOfStock ? 'text-ink-faint' : 'text-ink',
            )}
          >
            {product.name}
          </h3>
        </>
      )}

      {/* Price + stock share ONE row (mock layout: space-between), anchored to
          the card bottom via mt-auto so prices align across a row. */}
      <div
        data-testid="price-stock-block"
        className={cn(
          'mt-auto flex w-full gap-2',
          displayMode === 'visual'
            ? 'flex-col items-start pt-2'
            : 'items-center justify-between border-t border-border-subtle pt-[9px]',
        )}
      >
        <p
          data-testid="price-row"
          className={cn(
            // Prices are data, not action — always ink, never accent/action
            // (designTokens.ts §1: "prices always use text-ink").
            'shrink-0 font-mono text-[15px] font-semibold tabular-nums',
            isOutOfStock ? 'text-ink-faint' : 'text-ink',
          )}
        >
          {format(product.sale_price ?? '0')}
        </p>

        {/* Right group: stock badge + (compact-mode) eye. In visual mode the eye
            lives on the thumb, so only the badge shows here. */}
        <div
          className={cn(
            'flex items-center gap-1.5',
            displayMode === 'visual' ? 'max-w-full' : 'shrink-0',
          )}
        >
          {stockLabel !== null && status !== null && (
            <StockBadge
              data-testid="stock-row"
              status={status}
              className="max-w-full shrink-0 px-[7px] py-[3px] text-[10.5px] font-semibold"
            >
              {stockLabel}
            </StockBadge>
          )}
        </div>
      </div>

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
