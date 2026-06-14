import { useTranslation } from 'react-i18next';
import { useCurrency } from '@/lib/currency';
import { X, Package } from 'lucide-react';
import { cn } from '@/lib/utils';
import { bccomp } from '@/lib/decimal';
import { formatAvailableQty } from '@/lib/stock/stockGate';
import { useProductStore } from '@/stores/productStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { useTerminalStore } from '@/stores/terminalStore';
import { CrossLocationStockSection } from '@/components/organisms/CrossLocationStockSection/CrossLocationStockSection';
import type { POSProduct } from '@/types/product';
import type { LocationStockDisplay } from '@/lib/stock/gridStock';

interface ProductDetailDrawerProps {
  isOpen: boolean;
  onClose: () => void;
  product: POSProduct | null;
  /** Same slice semantics as ProductCard: object -> location-aware; null -> exempt (no chrome); undefined -> legacy fallback. */
  locationStock?: LocationStockDisplay | null;
}

export function ProductDetailDrawer({
  isOpen,
  onClose,
  product,
  locationStock,
}: ProductDetailDrawerProps) {
  const { t } = useTranslation('pos');
  const { format } = useCurrency();

  // F8 — cross-location stock gate. ALL hooks must run before the early return
  // below to keep hook order stable. The section itself renders only when both
  // the company flag and the operator permission are present (canView).
  const allowCrossLocation = useProductStore(
    (s) => s.companyConfig?.allow_cross_location_stock_view === true,
  );
  const canViewCrossLocation = useOperatorStore(
    (s) => s.operator?.permissions?.includes('pos.view_cross_location_stock') ?? false,
  );
  const currentLocationId = useTerminalStore((s) => s.terminal?.location.id ?? null);

  if (!product) return null;

  const hasSlice = locationStock !== undefined && locationStock !== null;
  const exempt = locationStock === null;

  const available = hasSlice ? locationStock!.available : null;
  const isOut = available !== null ? bccomp(available, '0') <= 0 : product.stock_quantity <= 0;
  const isLow = !isOut && (available !== null ? bccomp(available, '10') <= 0 : product.stock_quantity <= 10);
  const stockTone = isOut ? 'text-red-600 bg-red-50' : isLow ? 'text-amber-600 bg-amber-50' : 'text-green-600 bg-green-50';
  const stockText = available !== null ? formatAvailableQty(available) : String(product.stock_quantity);

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
            {!exempt && (
              <div className="flex items-center justify-between text-sm">
                <span className="text-gray-500">{t('productDetail.stock')}</span>
                <span
                  data-testid="drawer-stock-row"
                  className={cn(
                    'rounded-full px-2.5 py-0.5 text-xs font-semibold',
                    stockTone,
                  )}
                >
                  {stockText}
                </span>
              </div>
            )}

            {/* Tax rate */}
            {product.tax_rate && (
              <div className="flex justify-between text-sm">
                <span className="text-gray-500">{t('common:tax')}</span>
                <span className="font-medium text-gray-900">{product.tax_rate}%</span>
              </div>
            )}
          </div>

          {/* F8 — cross-location stock distribution (gated inside the section) */}
          <CrossLocationStockSection
            product={product}
            canView={allowCrossLocation && canViewCrossLocation}
            currentLocationId={currentLocationId}
          />
        </div>
      </div>
    </>
  );
}
