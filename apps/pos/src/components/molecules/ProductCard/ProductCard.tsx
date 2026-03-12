import { cn } from '@/lib/utils';
import { useCurrency } from '@/lib/currency';
import { Package, SlidersHorizontal } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import type { POSProduct } from '@/types/product';

export interface ProductCardProps {
  product: POSProduct;
  onAddToCart: (product: POSProduct) => void;
  isInCart?: boolean;
  displayMode?: 'grid' | 'visual';
}

export function ProductCard({
  product,
  onAddToCart,
  isInCart = false,
  displayMode = 'grid',
}: ProductCardProps) {
  const { t } = useTranslation('pos');
  const { format } = useCurrency();
  const isOutOfStock = product.stock_quantity <= 0;
  const isLowStock = product.stock_quantity > 0 && product.stock_quantity <= 10;
  const hasModifiers = (product.modifier_groups?.length ?? 0) > 0;

  if (displayMode === 'visual') {
    return (
      <button
        onClick={() => !isOutOfStock && onAddToCart(product)}
        disabled={isOutOfStock}
        className={cn(
          'relative flex min-h-[160px] flex-col items-center rounded-xl border-2 p-4 text-center',
          'transition-all duration-150 active:scale-[0.95]',
          isOutOfStock
            ? 'cursor-not-allowed border-gray-200 bg-gray-50 opacity-60'
            : isInCart
              ? 'border-l-4 border-l-primary-500 border-t-gray-200 border-r-gray-200 border-b-gray-200 bg-white shadow-sm'
              : 'border-gray-200 bg-white hover:border-primary-300 hover:shadow-md',
        )}
      >
        {/* Modifier indicator */}
        {hasModifiers && (
          <div className="absolute top-2 right-2">
            <SlidersHorizontal className="h-4 w-4 text-primary-500" />
          </div>
        )}

        {/* Image area */}
        {product.image_url ? (
          <img
            src={product.image_url}
            alt={product.name}
            className="mb-3 h-20 w-20 rounded-xl object-cover"
          />
        ) : (
          <div className="mb-3 flex h-20 w-20 items-center justify-center rounded-xl bg-gray-100">
            <Package className="h-8 w-8 text-gray-400" />
          </div>
        )}

        {/* Name - flex-1 to take available space */}
        <div className="flex-1 min-h-0">
          <h3 className="line-clamp-2 text-sm font-semibold text-gray-900">
            {product.name}
          </h3>
        </div>

        {/* Price - natural flow, no mt-auto */}
        <p className="pt-2 text-lg font-bold text-primary-600">
          {format(product.sale_price ?? '0')}
        </p>

        {/* Stock indicator */}
        <p
          className={cn(
            'mt-1 text-xs',
            isOutOfStock
              ? 'font-medium text-red-600'
              : isLowStock
                ? 'font-medium text-amber-600'
                : 'text-green-600',
          )}
        >
          {isOutOfStock
            ? t('products.outOfStock')
            : isLowStock
              ? t('products.lowStock')
              : t('products.stock', { count: product.stock_quantity })}
        </p>
      </button>
    );
  }

  // Grid mode (default)
  return (
    <button
      onClick={() => !isOutOfStock && onAddToCart(product)}
      disabled={isOutOfStock}
      className={cn(
        'relative flex min-h-[110px] flex-col items-start rounded-xl border-2 p-4 text-left',
        'transition-all duration-150 active:scale-[0.95]',
        isOutOfStock
          ? 'cursor-not-allowed border-gray-200 bg-gray-50 opacity-60'
          : isInCart
            ? 'border-l-4 border-l-primary-500 border-t-gray-200 border-r-gray-200 border-b-gray-200 bg-white shadow-sm'
            : 'border-gray-200 bg-white hover:border-primary-300 hover:shadow-md',
      )}
    >
      {/* Modifier indicator */}
      {hasModifiers && (
        <div className="absolute top-2 right-2">
          <SlidersHorizontal className="h-4 w-4 text-primary-500" />
        </div>
      )}

      {/* Name - flex-1 to take available space, prevents price overlap */}
      <h3 className="flex-1 line-clamp-2 text-base font-semibold text-gray-900">
        {product.name}
      </h3>

      {/* Price - natural flow, no mt-auto */}
      <p className="pt-2 text-lg font-bold text-primary-600">
        {format(product.sale_price ?? '0')}
      </p>

      {/* Stock indicator */}
      <p
        className={cn(
          'mt-1 text-xs',
          isOutOfStock
            ? 'font-medium text-red-600'
            : isLowStock
              ? 'font-medium text-amber-600'
              : 'text-green-600',
        )}
      >
        {isOutOfStock
          ? t('products.outOfStock')
          : isLowStock
            ? t('products.lowStock')
            : t('products.stock', { count: product.stock_quantity })}
      </p>
    </button>
  );
}
