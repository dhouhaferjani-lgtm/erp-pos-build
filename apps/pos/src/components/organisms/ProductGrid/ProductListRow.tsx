import { memo } from 'react';
import { useTranslation } from 'react-i18next';
import { Eye, Plus } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useCurrency } from '@/lib/currency';
import { useProductImage } from '@/lib/images/useProductImage';
import { ProductThumb, StockBadge } from '@/components/ui';
import { useStockDisplay } from './useStockDisplay';
import type { POSProduct } from '@/types/product';
import type { LocationStockDisplay } from '@/lib/stock/gridStock';

/**
 * Task 12 — "Liste" touch-density row: the search/scan workhorse view for
 * 5-10k SKUs. Shares `useStockDisplay` with `ProductCard` (DRY — same three
 * `locationStock` paths, same gating, same labels).
 */
export interface ProductListRowProps {
  product: POSProduct;
  onAddToCart: (product: POSProduct) => void;
  onViewDetails?: (product: POSProduct) => void;
  /** See `ProductCardProps.locationStock` doc for the three-state contract. */
  locationStock?: LocationStockDisplay | null;
  /** Defaults to true (fail-safe for retail) — see `ProductCardProps`. */
  hardBlockOutOfStock?: boolean;
}

function ProductListRowInner({
  product,
  onAddToCart,
  onViewDetails,
  locationStock,
  hardBlockOutOfStock = true,
}: ProductListRowProps) {
  const { t } = useTranslation('pos');
  const { format } = useCurrency();
  const localImage = useProductImage(product.id, product.image_url);
  const imageSrc = localImage ?? product.image_url;

  const { stockLabel, status, isActivationBlocked } = useStockDisplay(
    product,
    locationStock,
    hardBlockOutOfStock,
  );

  const addLabel = t('productDetail.addToCart');
  const viewDetailsLabel = t('products.viewDetails');

  const handleAdd = () => {
    if (!isActivationBlocked) {
      onAddToCart(product);
    }
  };

  return (
    <div
      data-testid="product-list-row"
      className={cn(
        'flex min-h-[48px] w-full items-center gap-3 border-b border-border-subtle px-2 py-1.5',
        isActivationBlocked && 'opacity-70',
      )}
    >
      <ProductThumb name={product.name} category={product.category} imageUrl={imageSrc} size={36} />

      <div className="min-w-0 flex-1">
        {product.brand_name && (
          <p
            className={cn(
              'w-full truncate text-[10px] font-bold leading-[1.2] tracking-[0.05em] uppercase',
              status === 'out' ? 'text-ink-faint' : 'text-ink-muted',
            )}
          >
            {product.brand_name}
          </p>
        )}
        <p
          title={product.name}
          className={cn(
            'w-full truncate text-[13.5px] font-semibold leading-[1.3]',
            status === 'out' ? 'text-ink-faint' : 'text-ink',
          )}
        >
          {product.name}
        </p>
      </div>

      {stockLabel !== null && status !== null && (
        <StockBadge
          data-testid="stock-row"
          status={status}
          className="shrink-0 px-[7px] py-[3px] text-[10.5px] font-semibold"
        >
          {stockLabel}
        </StockBadge>
      )}

      <p
        data-testid="price-row"
        className={cn(
          // Prices are data, not action — always ink, never accent/action
          // (designTokens.ts §1: "prices always use text-ink").
          'w-16 shrink-0 text-right font-mono text-[15px] font-semibold tabular-nums',
          status === 'out' ? 'text-ink-faint' : 'text-ink',
        )}
      >
        {format(product.sale_price ?? '0')}
      </p>

      {onViewDetails && (
        <button
          type="button"
          data-testid="view-details-button"
          aria-label={viewDetailsLabel}
          onClick={(e) => {
            e.stopPropagation();
            onViewDetails(product);
          }}
          className="flex h-10 w-10 shrink-0 items-center justify-center rounded-sm text-ink-muted transition-colors hover:text-ink active:bg-surface-sunken"
          title={viewDetailsLabel}
        >
          <Eye className="h-4 w-4" />
        </button>
      )}

      <button
        type="button"
        data-testid="add-button"
        aria-label={addLabel}
        aria-disabled={isActivationBlocked}
        onClick={(e) => {
          e.stopPropagation();
          handleAdd();
        }}
        className={cn(
          'flex h-12 w-16 shrink-0 items-center justify-center rounded-lg font-semibold transition-colors',
          isActivationBlocked
            ? 'cursor-not-allowed bg-surface-sunken text-ink-faint'
            : 'cursor-pointer bg-action text-ink-inverse hover:bg-action-hover active:bg-action-strong',
        )}
        title={addLabel}
      >
        <Plus className="h-5 w-5" />
      </button>
    </div>
  );
}

export const ProductListRow = memo(ProductListRowInner);
