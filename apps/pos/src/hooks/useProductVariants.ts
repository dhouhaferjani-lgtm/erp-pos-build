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
 *   2. If local rows exist → return immediately with status 'local'.
 *      No background refresh: the 60-second pullProductVariants sync keeps
 *      local data fresh; an extra network call here is wasted and violates
 *      offline-first (FV3-H1).
 *   3. If no local rows and online → cold-fetch from /products/{id}/variants,
 *      status 'cold-fetch' during wait, then 'local' on success.
 *   4. If no local rows and offline → status 'offline-empty', variants [].
 *
 * The hook never throws; any error (including db-read failure) yields
 * status 'error' with variants [] (FV3-M1).
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

    // FV3-M1: outer try/catch catches any db-read failure (getDatabase or
    // getVariantsForProduct rejecting) so status never stays stuck at 'loading'.
    void (async () => {
      try {
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

        // FV3-H1: local hit → return immediately. Do NOT fire a background
        // refresh — fetchProductVariants only returns JSON and does not write
        // to SQLite, so the re-read would return the same unchanged rows.
        // The periodic pullProductVariants sync (every 60s) is responsible for
        // keeping the local cache warm.
        if (local.length > 0) {
          setVariants(local);
          setStatus('local');
          return;
        }

        // No local rows — decide by connectivity.
        if (isOnline) {
          setStatus('cold-fetch');
          const fresh = await fetchProductVariants(productId);
          if (!cancelled) {
            setVariants(fresh);
            setStatus('local');
          }
        } else {
          if (!cancelled) {
            setVariants([]);
            setStatus('offline-empty');
          }
        }
      } catch {
        // Catches db-read failures (getDatabase / getVariantsForProduct) and
        // cold-fetch network errors alike — both map to status 'error'.
        if (!cancelled) {
          setVariants([]);
          setStatus('error');
        }
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
