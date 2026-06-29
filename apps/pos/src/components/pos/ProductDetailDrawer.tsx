import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useCurrency } from '@/lib/currency';
import { X, Package } from 'lucide-react';
import { cn } from '@/lib/utils';
import { bccomp } from '@/lib/decimal';
import { formatAvailableQty } from '@/lib/stock/stockGate';
import { useProductStore, hasModule } from '@/stores/productStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { useTerminalStore } from '@/stores/terminalStore';
import { CrossLocationStockSection } from '@/components/organisms/CrossLocationStockSection/CrossLocationStockSection';
import { Tabs, type TabItem } from '@/components/ui/Tabs';
import { ProductThumb } from '@/components/ui/ProductThumb';
import { addItemGated } from '@/lib/stock/cartIngress';
import type { POSProduct } from '@/types/product';
import type { LocationStockDisplay } from '@/lib/stock/gridStock';

interface ProductDetailDrawerProps {
  isOpen: boolean;
  onClose: () => void;
  product: POSProduct | null;
  /** Same slice semantics as ProductCard: object -> location-aware; null -> exempt (no chrome); undefined -> legacy fallback. */
  locationStock?: LocationStockDisplay | null;
}

type MerchandiseTab = 'equivalents' | 'complements' | 'routine';

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
  const currentLocationId = useTerminalStore((s) => s.terminal?.location?.id ?? null);

  // Task 28 — merchandising tabs (module-gated). All hooks run unconditionally
  // before the early return so hook order is stable.
  const [activeTab, setActiveTab] = useState<MerchandiseTab>('equivalents');
  // Reset to the first tab when a different product is shown in the same drawer
  // instance. Inline prev-prop comparison (React's recommended pattern) instead
  // of a useEffect — avoids the extra commit with a stale tab.
  const [prevProductId, setPrevProductId] = useState<string | undefined>(product?.id);
  if (product?.id !== prevProductId) {
    setPrevProductId(product?.id);
    setActiveTab('equivalents');
  }
  const companyConfig = useProductStore((s) => s.companyConfig);
  const getByIds = useProductStore((s) => s.getByIds);
  const allProducts = useProductStore((s) => s.products);
  const hasMerchandising = hasModule(companyConfig, 'Merchandising');

  if (!product) return null;

  const hasSlice = locationStock !== undefined && locationStock !== null;
  const exempt = locationStock === null;

  const available = hasSlice ? locationStock!.available : null;
  const isOut = available !== null ? bccomp(available, '0') <= 0 : product.stock_quantity <= 0;
  const isLow = !isOut && (available !== null ? bccomp(available, '10') <= 0 : product.stock_quantity <= 10);
  const stockTone = isOut ? 'text-red-600 bg-red-50' : isLow ? 'text-amber-600 bg-amber-50' : 'text-green-600 bg-green-50';
  const stockText = available !== null ? formatAvailableQty(available) : String(product.stock_quantity);

  // Task 28 — compute merchandising data (product is non-null here)
  const meta = product.parapharmacy_metadata;
  const showMerchandisingTabs = hasMerchandising && meta != null;

  const equivalents = showMerchandisingTabs ? getByIds(meta.equivalent_product_ids) : [];
  const complements = showMerchandisingTabs ? getByIds(meta.complement_product_ids) : [];

  /**
   * Reconstruct the routine(s) this product belongs to.
   * For each routine_id referenced in this product's routine_refs, find ALL
   * products in the in-memory catalog that share that routine_id, then order
   * them by step_order.  Multiple routines are sorted by routine_id first so
   * the list is stable across renders.
   */
  const routineSteps: { product: POSProduct; step_label: string; step_order: number; routine_id: string }[] =
    showMerchandisingTabs
      ? (() => {
          const myRoutineIds = new Set(meta.routine_refs.map((r) => r.routine_id));
          if (myRoutineIds.size === 0) return [];
          const steps: { product: POSProduct; step_label: string; step_order: number; routine_id: string }[] = [];
          for (const p of allProducts) {
            const refs = p.parapharmacy_metadata?.routine_refs ?? [];
            for (const ref of refs) {
              if (myRoutineIds.has(ref.routine_id)) {
                steps.push({
                  product: p,
                  step_label: ref.step_label,
                  step_order: ref.step_order,
                  routine_id: ref.routine_id,
                });
              }
            }
          }
          steps.sort((a, b) =>
            a.routine_id !== b.routine_id
              ? a.routine_id.localeCompare(b.routine_id)
              : a.step_order - b.step_order,
          );
          return steps;
        })()
      : [];

  const merchandisingTabs: TabItem<MerchandiseTab>[] = [
    {
      id: 'equivalents',
      label: t('productDetail.merchandising.equivalents'),
      count: equivalents.length,
    },
    {
      id: 'complements',
      label: t('productDetail.merchandising.complements'),
      count: complements.length,
    },
    {
      id: 'routine',
      label: t('productDetail.merchandising.routine'),
      count: routineSteps.length,
    },
  ];

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

          {/* Task 28 — Merchandising tabs (module-gated) */}
          {showMerchandisingTabs && (
            <div className="mt-6 border-t border-border-subtle pt-4">
              <Tabs
                tabs={merchandisingTabs}
                value={activeTab}
                onChange={setActiveTab}
                ariaLabel={t('productDetail.merchandising.ariaLabel')}
              />

              <div className="mt-3 space-y-1">
                {/* Équivalents panel */}
                {activeTab === 'equivalents' && (
                  <>
                    {equivalents.length === 0 ? (
                      <p className="py-6 text-center text-sm text-ink-muted">
                        {t('productDetail.merchandising.empty')}
                      </p>
                    ) : (
                      equivalents.map((p) => (
                        <MerchandiseRow
                          key={p.id}
                          product={p}
                          format={format}
                          addLabel={t('productDetail.merchandising.add')}
                        />
                      ))
                    )}
                  </>
                )}

                {/* Compléments panel */}
                {activeTab === 'complements' && (
                  <>
                    {complements.length === 0 ? (
                      <p className="py-6 text-center text-sm text-ink-muted">
                        {t('productDetail.merchandising.empty')}
                      </p>
                    ) : (
                      complements.map((p) => (
                        <MerchandiseRow
                          key={p.id}
                          product={p}
                          format={format}
                          addLabel={t('productDetail.merchandising.add')}
                        />
                      ))
                    )}
                  </>
                )}

                {/* Routine panel */}
                {activeTab === 'routine' && (
                  <>
                    {routineSteps.length === 0 ? (
                      <p className="py-6 text-center text-sm text-ink-muted">
                        {t('productDetail.merchandising.empty')}
                      </p>
                    ) : (
                      routineSteps.map((step, i) => (
                        <button
                          key={`${step.routine_id}-${step.product.id}-${i}`}
                          type="button"
                          data-testid="routine-step-row"
                          onClick={() => { void addItemGated(step.product); }}
                          className="flex w-full items-center gap-3 rounded-lg px-2 py-2 text-left transition-colors hover:bg-surface-raised active:bg-surface-sunken"
                        >
                          {/* Step order badge */}
                          <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-surface-sunken text-xs font-semibold text-ink-muted tabular-nums">
                            {step.step_order}
                          </span>
                          <ProductThumb
                            name={step.product.name}
                            category={step.product.category}
                            imageUrl={step.product.image_url}
                            size={40}
                          />
                          <div className="min-w-0 flex-1">
                            <p className="truncate text-xs text-ink-muted">{step.step_label}</p>
                            <p className="truncate text-sm font-medium text-ink">{step.product.name}</p>
                          </div>
                          <span
                            data-testid="merch-add-btn"
                            className="shrink-0 text-xs font-semibold text-accent"
                          >
                            {t('productDetail.merchandising.add')}
                          </span>
                        </button>
                      ))
                    )}
                  </>
                )}
              </div>
            </div>
          )}
        </div>
      </div>
    </>
  );
}

// ---------------------------------------------------------------------------
// MerchandiseRow — shared row component for Équivalents and Compléments tabs.
// ---------------------------------------------------------------------------
interface MerchandiseRowProps {
  product: POSProduct;
  format: (value: string | number) => string;
  addLabel: string;
}

function MerchandiseRow({ product, format, addLabel }: MerchandiseRowProps) {
  return (
    <button
      type="button"
      onClick={() => { void addItemGated(product); }}
      className="flex w-full items-center gap-3 rounded-lg px-2 py-2 text-left transition-colors hover:bg-surface-raised active:bg-surface-sunken"
    >
      <ProductThumb
        name={product.name}
        category={product.category}
        imageUrl={product.image_url}
        size={40}
      />
      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-medium text-ink">{product.name}</p>
        {product.sale_price != null && (
          <p className="text-xs text-ink-muted">{format(product.sale_price)}</p>
        )}
      </div>
      <span
        data-testid="merch-add-btn"
        className="shrink-0 text-xs font-semibold text-accent"
      >
        {addLabel}
      </span>
    </button>
  );
}
