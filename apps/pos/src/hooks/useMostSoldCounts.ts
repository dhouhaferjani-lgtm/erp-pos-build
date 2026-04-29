import { useEffect, useRef, useState } from 'react';
import { getDatabase } from '@/lib/db';
import { aggregateProductSales } from '@/lib/db/repositories/productSalesAggregateRepository';

const CACHE_TTL_MS = 5 * 60 * 1000; // 5 minutes
const WINDOW_DAYS = 30;

interface CacheEntry {
  counts: Map<string, number>;
  fetchedAt: number;
}

const cache = new Map<string, CacheEntry>();

export interface UseMostSoldCountsOptions {
  companyId: string | null;
  enabled: boolean;
}

export interface UseMostSoldCountsResult {
  counts: Map<string, number>;
  isLoading: boolean;
}

export function useMostSoldCounts({ companyId, enabled }: UseMostSoldCountsOptions): UseMostSoldCountsResult {
  const [counts, setCounts] = useState<Map<string, number>>(() => new Map());
  const [isLoading, setIsLoading] = useState(false);
  const aborted = useRef(false);

  useEffect(() => {
    aborted.current = false;
    if (!enabled || !companyId) {
      setCounts(new Map());
      return;
    }

    const cacheKey = `${companyId}:${WINDOW_DAYS}`;
    const cached = cache.get(cacheKey);
    if (cached && Date.now() - cached.fetchedAt < CACHE_TTL_MS) {
      setCounts(cached.counts);
      return;
    }

    setIsLoading(true);
    void (async () => {
      try {
        const db = await getDatabase(companyId);
        const next = await aggregateProductSales(db, { sinceDays: WINDOW_DAYS });
        if (aborted.current) return;
        cache.set(cacheKey, { counts: next, fetchedAt: Date.now() });
        setCounts(next);
      } catch {
        if (!aborted.current) setCounts(new Map());
      } finally {
        if (!aborted.current) setIsLoading(false);
      }
    })();

    return () => {
      aborted.current = true;
    };
  }, [companyId, enabled]);

  return { counts, isLoading };
}
