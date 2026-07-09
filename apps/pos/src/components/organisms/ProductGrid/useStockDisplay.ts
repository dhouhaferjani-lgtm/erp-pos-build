import { useTranslation } from 'react-i18next';
import { bccomp } from '@/lib/decimal';
import { formatAvailableQty } from '@/lib/stock/stockGate';
import type { StockStatus } from '@/components/ui';
import type { POSProduct } from '@/types/product';
import type { LocationStockDisplay } from '@/lib/stock/gridStock';

/**
 * Task 12 — shared stock-derivation, extracted verbatim from `ProductCard`
 * so `ProductListRow` can reuse the exact same three rendering paths without
 * duplicating the decimal-string logic (DRY; precision contract: quantities
 * stay decimal STRINGS end-to-end, never `Number()`/`parseFloat`).
 *
 * `locationStock` has three states (see `ProductCardProps.locationStock`
 * doc for the full rationale):
 *   - `undefined` → no join ran; legacy `stock_quantity` number rendering.
 *   - `null`      → stock-exempt: no chrome at all, never gated.
 *   - object      → location-aware `available` string via `bccomp` /
 *                   `formatAvailableQty`.
 *
 * This hook does NOT cover the "arriving" (incoming transfer/PO) badge —
 * that stays call-site-specific and is unchanged in `ProductCard`.
 */

/**
 * i18next reserves `count` for pluralisation and types it as `number`, but
 * `products.stock` has no plural forms — `count` is interpolation-only
 * here. Quantities are decimal STRINGS end-to-end, so we widen the option
 * type instead of coercing the quantity to a float.
 */
type TranslateWithStringCount = (key: string, opts: { count: string }) => string;

export interface StockDisplay {
  isOutOfStock: boolean;
  isLowStock: boolean;
  /** Rendered stock label, or `null` when the product is stock-exempt. */
  stockLabel: string | null;
  /** Badge status, or `null` when the product is stock-exempt (no badge). */
  status: StockStatus | null;
  /** True only when out-of-stock AND the caller enforces the 'block' policy. */
  isActivationBlocked: boolean;
}

export function useStockDisplay(
  product: POSProduct,
  locationStock: LocationStockDisplay | null | undefined,
  hardBlockOutOfStock = true,
): StockDisplay {
  const { t } = useTranslation('pos');

  let isOutOfStock = false;
  let isLowStock = false;
  let stockLabel: string | null = null;

  if (locationStock === undefined) {
    // Legacy path — unchanged for Menu tenants (999) and browser dev.
    isOutOfStock = product.stock_quantity <= 0;
    isLowStock = product.stock_quantity > 0 && product.stock_quantity <= 10;
    // Number ALWAYS shown for ok/low (Task 10 — unified badge format); only
    // `out` renders a wordless label. Badge colour still keys off isLowStock.
    stockLabel = isOutOfStock
      ? t('products.outOfStock')
      : t('products.stock', { count: product.stock_quantity });
  } else if (locationStock !== null) {
    isOutOfStock = bccomp(locationStock.available, '0') <= 0;
    isLowStock = !isOutOfStock && bccomp(locationStock.available, '10') <= 0;
    stockLabel = isOutOfStock
      ? t('products.outOfStock')
      : (t as unknown as TranslateWithStringCount)('products.stock', {
          count: formatAvailableQty(locationStock.available),
        });
  }
  // locationStock === null → exempt: stockLabel stays null, no gating.

  const status: StockStatus | null =
    stockLabel === null ? null : isOutOfStock ? 'out' : isLowStock ? 'low' : 'ok';

  // Activation refuses only under 'block' policy (Codex final-review P1):
  // under 'warn'/'off' the tap must reach the stock gate, which allows the
  // add and surfaces the warning toast.
  const isActivationBlocked = isOutOfStock && hardBlockOutOfStock;

  return { isOutOfStock, isLowStock, stockLabel, status, isActivationBlocked };
}
