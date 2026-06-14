/**
 * Task F7 — cross-location stock distribution hook.
 *
 * On (productId, variantId) change while enabled=true:
 *   - Online  → fetchStockDistribution, upsertDistribution with fetched_at = as_of
 *   - Offline or fetch throws → read cache via getDistribution
 *
 * Exposes { data, source, fetchedAt, isStale, isLoading, error, refresh }.
 *
 * isStale uses isOlderThan from @/lib/relativeTime.  That function expects a
 * numeric epoch-ms timestamp, so fetchedAt (ISO string from the wire + cache)
 * is converted via Date.parse() before the staleness check — the same pattern
 * as StockFreshness.tsx.
 */
import { useCallback, useEffect, useState } from 'react';
import { getDatabase } from '@/lib/db';
import { fetchStockDistribution } from '@/api/stockDistributionApi';
import {
  getDistribution,
  upsertDistribution,
} from '@/lib/db/repositories/crossLocationStockRepository';
import { useConnectivityStore } from '@/stores/connectivityStore';
import { useAuthStore } from '@/stores/authStore';
import { isOlderThan } from '@/lib/relativeTime';
import type { StockDistribution } from '@/types/stockDistribution';

/** Match the FU-10 threshold in StockFreshness.tsx */
const STALE_MS = 15 * 60_000;

export type CrossLocationStockError = 'offline-no-cache' | 'fetch-failed' | null;

export interface UseCrossLocationStockResult {
  data: StockDistribution | null;
  source: 'live' | 'cache' | null;
  fetchedAt: string | null;
  isStale: boolean;
  isLoading: boolean;
  error: CrossLocationStockError;
  refresh: () => void;
}

/**
 * @param productId        - UUID of the product (null disables the hook)
 * @param variantId        - UUID of the variant, or null for product-grain
 * @param currentLocationId - UUID of the caller's location (marks is_current)
 * @param enabled          - when false the hook does nothing (initial state returned)
 */
export function useCrossLocationStock(
  productId: string | null,
  variantId: string | null,
  currentLocationId: string | null,
  enabled: boolean,
): UseCrossLocationStockResult {
  const isOnline = useConnectivityStore((s) => s.isOnline);
  const companyId = useAuthStore((s) => s.companyId);

  const [data, setData] = useState<StockDistribution | null>(null);
  const [source, setSource] = useState<'live' | 'cache' | null>(null);
  const [fetchedAt, setFetchedAt] = useState<string | null>(null);
  const [isLoading, setLoading] = useState(false);
  const [error, setError] = useState<CrossLocationStockError>(null);
  // Bumped by refresh() to re-run the effect without changing other deps
  const [nonce, setNonce] = useState(0);

  const refresh = useCallback(() => setNonce((n) => n + 1), []);

  useEffect(() => {
    if (!enabled || !productId || !companyId) return;

    let cancelled = false;

    async function load(): Promise<void> {
      setLoading(true);
      setError(null);

      const db = await getDatabase(companyId as string);

      if (isOnline) {
        try {
          const live = await fetchStockDistribution(
            productId as string,
            variantId,
            currentLocationId,
          );
          if (cancelled) return;
          setData(live);
          setSource('live');
          setFetchedAt(live.as_of);
          // Cache the fresh payload; fetched_at is the server's as_of timestamp
          await upsertDistribution(
            db,
            live.product_id,
            live.variant_id,
            live.variant_label,
            JSON.stringify(live),
            live.as_of,
          );
          return;
        } catch {
          // fall through to cache read below
        }
      }

      // Offline path, or live fetch failed
      const cached = await getDistribution(db, productId as string, variantId);
      if (cancelled) return;

      let parsed: StockDistribution | null = null;
      if (cached) {
        try {
          parsed = JSON.parse(cached.payload) as StockDistribution;
        } catch {
          // Corrupt cache row (truncated write / schema drift): treat as a
          // cache miss rather than letting the parse throw out of load() and
          // silently swallow the failure into an empty section.
          parsed = null;
        }
      }

      if (parsed) {
        setData(parsed);
        setSource('cache');
        setFetchedAt(cached!.fetched_at);
        // Only mark fetch-failed when we were online and the request threw;
        // a pure offline path is not an error (data is simply from cache).
        setError(isOnline ? 'fetch-failed' : null);
      } else {
        setData(null);
        setSource(null);
        setFetchedAt(null);
        setError(isOnline ? 'fetch-failed' : 'offline-no-cache');
      }
    }

    void load().finally(() => {
      if (!cancelled) setLoading(false);
    });

    return () => {
      cancelled = true;
    };
  }, [enabled, productId, variantId, currentLocationId, companyId, isOnline, nonce]);

  return {
    data,
    source,
    fetchedAt,
    // isOlderThan expects a numeric epoch-ms timestamp (see relativeTime.ts).
    // Convert the ISO fetchedAt string the same way StockFreshness.tsx does.
    isStale: fetchedAt !== null && isOlderThan(Date.parse(fetchedAt), STALE_MS),
    isLoading,
    error,
    refresh,
  };
}
