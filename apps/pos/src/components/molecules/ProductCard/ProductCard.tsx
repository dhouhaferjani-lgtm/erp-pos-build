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

      <h3
        title={product.name}
        className={cn(
          'flex-1 min-h-0 line-clamp-2 font-semibold text-gray-900',
          displayMode === 'visual' ? 'text-sm' : 'text-base',
        )}
      >
        {product.name}
      </h3>

      <p
        data-testid="price-row"
        className="shrink-0 pt-2 text-lg font-bold text-primary-600"
      >
        {format(product.sale_price ?? '0')}
      </p>

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
