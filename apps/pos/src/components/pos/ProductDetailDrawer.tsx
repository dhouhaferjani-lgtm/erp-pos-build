import { useTranslation } from 'react-i18next';
import { useCurrency } from '@/lib/currency';
import { X, Package } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { POSProduct } from '@/types/product';

interface ProductDetailDrawerProps {
  isOpen: boolean;
  onClose: () => void;
  product: POSProduct | null;
}

export function ProductDetailDrawer({
  isOpen,
  onClose,
  product,
}: ProductDetailDrawerProps) {
  const { t } = useTranslation('pos');
  const { format } = useCurrency();

  if (!product) return null;

  const stockColor =
    product.stock_quantity <= 0
      ? 'text-red-600 bg-red-50'
      : product.stock_quantity <= 10
        ? 'text-amber-600 bg-amber-50'
        : 'text-green-600 bg-green-50';

  return (
    <>
      {/* Backdrop */}
      {isOpen && (
        <div
          className="fixed inset-0 z-40 bg-black/30"
          onClick={onClose}
        />
      )}

      {/* Drawer */}
      <div
        className={cn(
          'fixed inset-y-0 right-0 z-50 w-80 transform bg-white shadow-2xl transition-transform duration-300',
          isOpen ? 'translate-x-0' : 'translate-x-full',
        )}
      >
        {/* Header */}
        <div className="flex items-center justify-between border-b border-gray-200 px-4 py-4">
          <h2 className="text-lg font-bold text-gray-900">
            {t('productDetail.title')}
          </h2>
          <button
            onClick={onClose}
            className="flex h-10 w-10 items-center justify-center rounded-full text-gray-400 hover:bg-gray-100 hover:text-gray-600"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        {/* Content */}
        <div className="flex-1 overflow-y-auto p-4">
          {/* Product image / icon */}
          <div className="mb-4 flex items-center justify-center rounded-xl bg-gray-50 p-8">
            {product.image_url ? (
              <img
                src={product.image_url}
                alt={product.name}
                className="h-24 w-24 rounded-lg object-cover"
              />
            ) : (
              <Package className="h-16 w-16 text-gray-300" />
            )}
          </div>

          {/* Name */}
          <h3 className="text-xl font-bold text-gray-900">{product.name}</h3>

          {/* Price */}
          <p className="mt-1 text-2xl font-bold text-blue-600">
            {format(product.sale_price ?? 0)}
          </p>

          {/* Info rows */}
          <div className="mt-6 space-y-4">
            {/* SKU */}
            {product.sku && (
              <div className="flex justify-between text-sm">
                <span className="text-gray-500">SKU</span>
                <span className="font-medium text-gray-900">{product.sku}</span>
              </div>
            )}

            {/* Barcode */}
            {product.barcode && (
              <div className="flex justify-between text-sm">
                <span className="text-gray-500">{t('barcode.inputLabel')}</span>
                <span className="font-medium text-gray-900">{product.barcode}</span>
              </div>
            )}

            {/* Category */}
            {product.category && (
              <div className="flex justify-between text-sm">
                <span className="text-gray-500">{t('products.allCategories')}</span>
                <span className="font-medium text-gray-900">{product.category}</span>
              </div>
            )}

            {/* Stock */}
            <div className="flex items-center justify-between text-sm">
              <span className="text-gray-500">{t('productDetail.stock')}</span>
              <span
                className={cn(
                  'rounded-full px-2.5 py-0.5 text-xs font-semibold',
                  stockColor,
                )}
              >
                {product.stock_quantity}
              </span>
            </div>

            {/* Tax rate */}
            {product.tax_rate && (
              <div className="flex justify-between text-sm">
                <span className="text-gray-500">{t('common:tax')}</span>
                <span className="font-medium text-gray-900">{product.tax_rate}%</span>
              </div>
            )}
          </div>
        </div>
      </div>
    </>
  );
}
