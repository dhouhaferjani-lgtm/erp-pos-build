import { useQuery } from '@tanstack/react-query';
import { fetchProductVariants } from '@/api/variantApi';
import type { POSProductVariant } from '@/types/product';

/**
 * Fetch the sellable variants for a product on demand (when the cashier taps a
 * variant-bearing product). Only enabled when a `productId` is supplied so the
 * query stays idle for non-variant flows. Results are cached briefly — variant
 * catalogs change rarely within a shift, and a stale stock count is corrected
 * server-side at sell time.
 */
export function useProductVariants(productId: string | null) {
  return useQuery<POSProductVariant[]>({
    queryKey: ['pos', 'product-variants', productId],
    queryFn: () => fetchProductVariants(productId as string),
    enabled: productId !== null && productId !== '',
    staleTime: 30_000,
  });
}
