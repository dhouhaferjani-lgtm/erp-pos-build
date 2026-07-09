import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { RefreshCw } from 'lucide-react';
import { formatAvailableQty } from '@/lib/stock/stockGate';
import { formatRelativeTime } from '@/lib/relativeTime';
import { useCrossLocationStock } from '@/hooks/useCrossLocationStock';
import { useProductVariants } from '@/hooks/useProductVariants';
import type { POSProduct } from '@/types/product';

interface Props {
  product: POSProduct;
  canView: boolean;
  currentLocationId: string | null;
}

/**
 * Task F8 — cross-location stock distribution panel for the product detail
 * drawer. Gated entirely by `canView` (the drawer computes it from the company
 * flag + operator permission). For variant-bearing products a selector lets the
 * cashier drill into a specific variant; non-variant products query at
 * product-grain (variantId null). All quantities go through `formatAvailableQty`
 * and the "as of" hint reuses `formatRelativeTime` — which expects an epoch-ms
 * number, so the ISO `fetchedAt` is parsed via `Date.parse()` (same pattern as
 * StockFreshness / useCrossLocationStock).
 */
export function CrossLocationStockSection({ product, canView, currentLocationId }: Props) {
  const { t } = useTranslation('pos');
  const hasVariants = product.has_variants === true;
  const { variants } = useProductVariants(hasVariants && canView ? product.id : null);
  const [selectedVariant, setSelectedVariant] = useState<string | null>(null);

  const variantId = useMemo(() => {
    if (!hasVariants) return null;
    if (selectedVariant) return selectedVariant;
    const def = variants.find((v) => v.is_default) ?? variants[0];
    return def?.id ?? null;
  }, [hasVariants, selectedVariant, variants]);

  const enabled = canView && (!hasVariants || variantId !== null);
  const { data, fetchedAt, isStale, isLoading, error, refresh } = useCrossLocationStock(
    product.id,
    variantId,
    currentLocationId,
    enabled,
  );

  // The "as of" hint reuses formatRelativeTime (epoch-ms input); fetchedAt is an
  // ISO string from the wire/cache, so parse it the same way useCrossLocationStock does.
  const relativeTime = fetchedAt ? formatRelativeTime(Date.parse(fetchedAt)) : null;

  if (!canView) return null;

  return (
    <section className="mt-4 border-t border-gray-200 pt-3" data-testid="xloc-section">
      <div className="mb-2 flex items-center justify-between">
        <h3 className="text-sm font-semibold text-gray-700">{t('crossLocationStock.title')}</h3>
        <button
          type="button"
          onClick={refresh}
          className="inline-flex items-center gap-1 text-xs text-gray-500 hover:text-gray-700"
          title={t('crossLocationStock.refresh')}
        >
          <RefreshCw className="h-3.5 w-3.5" /> {t('crossLocationStock.refresh')}
        </button>
      </div>

      {hasVariants && (
        <select
          aria-label={t('crossLocationStock.variant')}
          className="mb-2 w-full rounded-sm border border-gray-200 px-2 py-1 text-sm"
          value={variantId ?? ''}
          onChange={(e) => setSelectedVariant(e.target.value)}
        >
          {variants.map((v) => (
            <option key={v.id} value={v.id}>
              {product.name}
              {v.name_suffix}
            </option>
          ))}
        </select>
      )}

      {fetchedAt && (
        <p className={`mb-1 text-xs ${isStale ? 'text-amber-600' : 'text-gray-500'}`}>
          {t('crossLocationStock.asOf', { time: relativeTime ?? '' })}
        </p>
      )}

      {isLoading && <p className="text-xs text-gray-500">{t('crossLocationStock.loading')}</p>}
      {!isLoading && error === 'offline-no-cache' && (
        <p className="text-xs text-gray-500">{t('crossLocationStock.offlineNoCache')}</p>
      )}

      {data && (
        <table className="w-full text-sm" data-testid="xloc-table">
          <thead>
            <tr className="text-left text-xs text-gray-500">
              <th className="py-1">{t('crossLocationStock.location')}</th>
              <th className="py-1 text-right">{t('crossLocationStock.onHand')}</th>
              <th className="py-1 text-right">{t('crossLocationStock.incoming')}</th>
            </tr>
          </thead>
          <tbody>
            {data.locations.map((row) => (
              <tr key={row.location_id} className={row.is_current ? 'font-semibold' : ''}>
                <td className="py-0.5">
                  {row.is_current ? `→ ${row.location_name}` : row.location_name}
                </td>
                <td className="py-0.5 text-right">{formatAvailableQty(row.on_hand)}</td>
                <td className="py-0.5 text-right">{formatAvailableQty(row.incoming_transfer)}</td>
              </tr>
            ))}
            <tr className="border-t border-gray-200 font-semibold">
              <td className="py-1">{t('crossLocationStock.total')}</td>
              <td className="py-1 text-right">{formatAvailableQty(data.totals.on_hand)}</td>
              <td className="py-1 text-right">{formatAvailableQty(data.totals.incoming_transfer)}</td>
            </tr>
          </tbody>
        </table>
      )}
    </section>
  );
}
