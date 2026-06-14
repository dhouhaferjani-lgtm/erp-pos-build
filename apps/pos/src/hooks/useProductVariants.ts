import { useEffect, useState } from 'react';
import { getDatabase } from '@/lib/db';
import { getVariantsForProduct } from '@/lib/db/repositories/variantRepository';
import { fetchProductVariants } from '@/api/variantApi';
import { useConnectivityStore } from '@/stores/connectivityStore';
import { useAuthStore } from '@/stores/authStore';
import type { POSProductVariant } from '@/types/product';

/**
 * FV3 — Local-first variant hook.
 *
 * Priority order:
 *   1. Read local SQLite product_variants (always, as first step).
 *   2. If local rows exist → status 'local'; also fire a background re-fetch
 *      when online so the cache stays warm (result replaces state if non-empty).
 *   3. If no local rows and online → cold-fetch from /products/{id}/variants,
 *      status 'cold-fetch' during wait, then 'local' on success.
 *   4. If no local rows and offline → status 'offline-empty', variants [].
 *
 * The hook never throws; errors yield status 'error' with variants [].
 */

export type VariantStatus =
  | 'idle'
  | 'loading'
  | 'local'
  | 'cold-fetch'
  | 'offline-empty'
  | 'error';

export function useProductVariants(productId: string | null): {
  variants: POSProductVariant[];
  isLoading: boolean;
  status: VariantStatus;
} {
  const isOnline = useConnectivityStore((s) => s.isOnline);
  const [variants, setVariants] = useState<POSProductVariant[]>([]);
  const [status, setStatus] = useState<VariantStatus>('idle');

  useEffect(() => {
    if (!productId) {
      setVariants([]);
      setStatus('idle');
      return;
    }

    let cancelled = false;
    setStatus('loading');

    void (async () => {
      const companyId = useAuthStore.getState().companyId;
      if (!companyId) {
        if (!cancelled) {
          setVariants([]);
          setStatus('error');
        }
        return;
      }

      const db = await getDatabase(companyId);
      const local = await getVariantsForProduct(db, productId);
      if (cancelled) return;

      if (local.length > 0) {
        setVariants(local);
        setStatus('local');

        // Background re-fetch to keep the cache warm while showing local data
        // immediately (non-blocking; errors are silently swallowed).
        if (isOnline) {
          try {
            await fetchProductVariants(productId);
            if (!cancelled) {
              const reread = await getVariantsForProduct(db, productId);
              if (!cancelled && reread.length > 0) {
                setVariants(reread);
              }
            }
          } catch {
            /* keep local data — background refresh failed */
          }
        }
        return;
      }

      // No local rows — decide by connectivity.
      if (isOnline) {
        setStatus('cold-fetch');
        try {
          const fresh = await fetchProductVariants(productId);
          if (!cancelled) {
            setVariants(fresh);
            setStatus('local');
          }
        } catch {
          if (!cancelled) {
            setVariants([]);
            setStatus('error');
          }
        }
      } else {
        setVariants([]);
        setStatus('offline-empty');
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [productId, isOnline]);

  return {
    variants,
    isLoading: status === 'loading' || status === 'cold-fetch',
    status,
  };
}
