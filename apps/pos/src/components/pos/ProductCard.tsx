import { cn } from '@/lib/utils';
import { useCurrency } from '@/lib/currency';
import { Package } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import type { POSProduct } from '@/types/product';

interface ProductCardProps {
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

  if (displayMode === 'visual') {
    return (
      <button
        onClick={() => !isOutOfStock && onAddToCart(product)}
        disabled={isOutOfStock}
        className={cn(
          'relative flex min-h-[160px] flex-col items-center rounded-xl border-2 p-4 text-center',
          'transition-all duration-150 active:scale-[0.95]',
          isOutOfStock
            ? 'cursor-not-allowed border-gray-200 bg-gray-50 opacity-70'
            : isInCart
              ? 'border-l-4 border-l-primary-500 border-t-gray-200 border-r-gray-200 border-b-gray-200 bg-white shadow-sm'
              : 'border-gray-200 bg-white hover:border-primary-300 hover:shadow-md',
        )}
      >
        {/* Image area */}
        {product.image_url ? (
          <img
            src={product.image_url}
            alt={product.name}
            className="mb-3 h-20 w-20 rounded-xl object-cover"
          />
        ) : (
          <div className="mb-3 flex h-20 w-20 items-center justify-center rounded-xl bg-gray-100">
            <Package className="h-8 w-8 text-gray-500" />
          </div>
        )}

        {/* Name */}
        <h3 className="line-clamp-2 text-sm font-semibold text-gray-900">
          {product.name}
        </h3>

        {/* Price */}
        <p className="mt-auto pt-2 text-lg font-bold text-primary-600">
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
        'relative flex min-h-[100px] flex-col items-start rounded-xl border-2 p-4 text-left',
        'transition-all duration-150 active:scale-[0.95]',
        isOutOfStock
          ? 'cursor-not-allowed border-gray-200 bg-gray-50 opacity-70'
          : isInCart
            ? 'border-l-4 border-l-primary-500 border-t-gray-200 border-r-gray-200 border-b-gray-200 bg-white shadow-sm'
            : 'border-gray-200 bg-white hover:border-primary-300 hover:shadow-md',
      )}
    >
      {/* Name */}
      <h3 className="line-clamp-2 text-base font-semibold text-gray-900">
        {product.name}
      </h3>

      {/* Price */}
      <p className="mt-auto pt-2 text-lg font-bold text-primary-600">
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
