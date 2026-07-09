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
import { useSettingsStore } from '@/stores/settingsStore';
import { useStockDisplay } from '@/components/organisms/ProductGrid/useStockDisplay';
import { NearExpirySlot } from '@/components/organisms/ProductGrid/NearExpirySlot';
import {
  CARD_NAME_MIN_H_CLASS_GRID,
  CARD_NAME_MIN_H_CLASS_VISUAL,
} from './cardSizing';
import type { POSProduct } from '@/types/product';
import type { LocationStockDisplay } from '@/lib/stock/gridStock';

/** Quantity scale — ALWAYS pass explicitly (decimal.ts defaults to 3). */
const QTY_SCALE = 4;

/**
 * Task 16 skin-type dot palette (whole-branch review fix B). Strategy A
 * reserves `accent` for brand chrome, `success` for stock/money, and
 * `action` for interaction/selection — none apply to a purely decorative
 * per-product tag, so this indicator must not use any of them. There is no
 * dedicated skin-type palette in the design system, so this reuses five of
 * the seven `--cat-*` category-tint CSS vars (`src/index.css`) purely for
 * VISUAL distinction between dots — the colors carry no category meaning
 * here, only "five values that read as different from each other".
 * `--cat-corps-fg` is excluded (it is byte-identical to `--success`) and
 * `--cat-hygiene-fg` is excluded (byte-identical to the 'blue' `--accent`
 * preset) — using either would reintroduce the exact collision this fix
 * removes for those themes. The remaining five map 1:1 to the five
 * `SkinType` values (see `smart-prompts:skin_type.*`); the mapping itself is
 * arbitrary, not semantic.
 */
const SKIN_TYPE_DOT_CLASS: Record<string, string> = {
  normal: 'bg-[var(--cat-cheveux-fg)]',
  oily: 'bg-[var(--cat-solaire-fg)]',
  dry: 'bg-[var(--cat-visage-fg)]',
  combination: 'bg-[var(--cat-bebe-fg)]',
  sensitive: 'bg-[var(--cat-complements-fg)]',
};
/** Any skin-type value outside the known five falls back to one neutral dot. */
const SKIN_TYPE_DOT_FALLBACK_CLASS = 'bg-ink-faint';

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
  /**
   * Owner polish 2026-07-09 (sub-task a): quantity of this product currently
   * in the cart, shown in the in-cart count chip (the chip carries
   * information, not decoration). Optional — when absent but `isInCart` is
   * true, the chip falls back to a check glyph. Quantities in the cart are
   * plain JS numbers (see `CartItem.quantity`); this prop only DISPLAYS the
   * value — no arithmetic, no parseFloat/Number() parsing.
   */
  cartQuantity?: number;
}

interface ViewDetailsButtonProps {
  className: string;
  label: string;
  product: POSProduct;
  onViewDetails: (product: POSProduct) => void;
  /**
   * Owner polish 2026-07-09 (sub-task c) — vitrine tile overlay variant: the
   * BUTTON is a ≥48px transparent hit target (touch-first, always visible)
   * while the VISIBLE glyph is a small ghost chip anchored in its corner, so
   * a wall of 16 cards doesn't read as 16 identical white squares. The
   * default (non-overlay) rendering is unchanged — Liste/Tableau and the
   * compact-card eye keep their owner-approved look.
   */
  overlay?: boolean;
}

function ViewDetailsButton({
  className,
  label,
  product,
  onViewDetails,
  overlay = false,
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
        overlay
          ? // Transparent 48px hit target; the ghost chip below is the visual.
            'group flex items-start justify-start p-[5px]'
          : 'flex items-center justify-center rounded-sm bg-surface-raised/90 text-ink-muted shadow-sm backdrop-blur-sm transition-colors hover:text-ink active:bg-surface-sunken',
        className,
      )}
      title={label}
    >
      {overlay ? (
        <span
          data-testid="view-details-glyph"
          className="flex h-6 w-6 items-center justify-center rounded-sm bg-surface-raised/60 text-ink-muted backdrop-blur-[2px] transition-colors group-hover:text-ink group-active:bg-surface-sunken/80"
        >
          <Eye className="h-3.5 w-3.5" />
        </span>
      ) : (
        <Eye className="h-4 w-4" />
      )}
    </button>
  );
}

/**
 * In-cart count chip (owner polish 2026-07-09, sub-task a). Selection-family
 * (blue `--action`) because it marks the selected/in-cart state; shows the
 * cart QUANTITY when known (information), a check glyph otherwise. Purely
 * informational — not an interactive target, so no 48px constraint.
 */
function InCartChip({
  quantity,
  label,
  className,
}: {
  quantity?: number;
  label: string;
  className?: string;
}) {
  return (
    <span
      data-testid="in-cart-badge"
      title={label}
      aria-label={label}
      className={cn(
        'inline-flex h-5 min-w-5 items-center justify-center rounded-pill bg-action px-1.5 text-[11px] font-bold tabular-nums text-ink-inverse',
        className,
      )}
    >
      {quantity !== undefined ? String(quantity) : <Check className="h-3 w-3" aria-hidden="true" />}
    </span>
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
  cartQuantity,
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

  // Task 16 — optional-field toggle (default off): small skin-type indicator
  // dots on the visual (vitrine) tile, sourced from parapharmacy metadata.
  // Renders nothing when the flag is off or when the product has no
  // (non-empty) suitable_skin_types.
  const showSkinTypeOnTiles = useSettingsStore((s) => s.showSkinTypeOnTiles);
  const skinTypes = product.parapharmacy_metadata?.suitable_skin_types ?? [];
  const showSkinTypeDots = showSkinTypeOnTiles && displayMode === 'visual' && skinTypes.length > 0;

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
  // 2. In-cart: the blue action border is THE selection signal (Strategy A —
  //    accent/green are reserved for the brand wordmark and stock/money).
  //    Owner polish 2026-07-09 (sub-task a): the background tint and top
  //    accent bar were REMOVED — one calm signal (border) + the count chip.
  // 3. Default: raised surface, action border on hover.
  const cardSurface = isOutOfStock
    ? cn(
        'border-subtle bg-surface-sunken text-ink-faint opacity-70',
        isActivationBlocked ? 'cursor-not-allowed' : 'cursor-pointer',
      )
    : isInCart
      ? 'cursor-pointer border-action bg-surface-raised shadow-sm'
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
        // Owner polish 2026-07-09 (sub-task b): text block is LEFT-aligned in
        // both modes — a centered visual card had no scanning axis.
        'items-start',
        cardSurface,
      )}
    >
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

      {/* Visual mode: ProductThumb on top. Compact (grid) mode: no thumb.
          Owner polish 2026-07-09 (sub-task b): tile shortened 88→72px so the
          placeholder stops dominating ~45% of the card height. */}
      {displayMode === 'visual' && (
        <div
          data-testid="product-visual-tile"
          className="relative mb-2 h-[72px] w-full shrink-0 overflow-hidden rounded-tile"
        >
          <ProductThumb
            name={product.name}
            category={product.category}
            imageUrl={imageSrc}
            size={72}
            fullWidth
          />
          {/* Eye overlay (sub-task c): 48px transparent hit target at the
              tile's top-left, small ghost glyph — always visible, tertiary. */}
          {onViewDetails && (
            <ViewDetailsButton
              overlay
              className="absolute left-0 top-0 z-[1] h-12 w-12"
              label={viewDetailsLabel}
              product={product}
              onViewDetails={onViewDetails}
            />
          )}
          {/* In-cart count chip (sub-task a): anchored INSIDE the tile at the
              top-right, z-raised above the image — it can never peek out from
              behind the placeholder (the old top-bar/badge z-order glitch). */}
          {isInCart && (
            <InCartChip
              quantity={cartQuantity}
              label={t('products.inCart')}
              className="absolute right-[7px] top-[7px] z-[1]"
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
                // Typography = shared productName recipe (sub-task d).
                'w-full',
                tokens.productName.base,
                tokens.productName.clamp2,
                nameMinHClass,
                isOutOfStock ? tokens.productName.inkDisabled : tokens.productName.ink,
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
              <InCartChip quantity={cartQuantity} label={t('products.inCart')} />
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
          {/* Brand name in caps — rendered only when present. Truncates now
              that the block is left-aligned (a long brand must not wrap). */}
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
              // Typography = shared productName recipe (sub-task d).
              'w-full',
              tokens.productName.base,
              tokens.productName.clamp2,
              nameMinHClass,
              isOutOfStock ? tokens.productName.inkDisabled : tokens.productName.ink,
            )}
          >
            {product.name}
          </h3>

          {showSkinTypeDots && (
            <div
              data-testid="skin-type-dots"
              aria-label={skinTypes.join(', ')}
              className="mt-0.5 flex items-center gap-1"
            >
              {skinTypes.map((skinType) => (
                <span
                  key={skinType}
                  data-testid="skin-type-dot"
                  title={skinType}
                  className={cn(
                    'h-1.5 w-1.5 shrink-0 rounded-full',
                    SKIN_TYPE_DOT_CLASS[skinType] ?? SKIN_TYPE_DOT_FALLBACK_CLASS,
                  )}
                />
              ))}
            </div>
          )}
        </>
      )}

      {/* Price + stock share ONE row (mock layout: space-between), anchored to
          the card bottom via mt-auto so prices align across a row. */}
      <div
        data-testid="price-stock-block"
        className={cn(
          'mt-auto flex w-full gap-2',
          // pt-2 → pt-1.5 (sub-task b): tighter name→price rhythm; the row gap
          // between cards stays generous so groups still read first.
          displayMode === 'visual'
            ? 'flex-col items-start pt-1.5'
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

      {/* Spec 2 reserved slot — renders nothing today, see NearExpirySlot doc. */}
      <NearExpirySlot product={product} />

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
