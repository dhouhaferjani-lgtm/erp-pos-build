/**
 * Task 12 — stock staleness hint, rendered beside the SyncButton's
 * "Last sync" affordance in the Header.
 *
 * Reads `stock_last_sync` from sync_metadata (device-time ISO string written
 * by `pullLocationStock` after every successful stock pull) and renders
 * `pos:stock.asOf` with the SAME relative-time format the SyncButton uses.
 *
 * Renders NOTHING when:
 *   - the metadata is absent (Menu tenants never pull stock; a terminal that
 *     has never pulled has no key);
 *   - the local DB is unavailable (browser / non-Tauri dev);
 *   - there is no active company.
 *
 * Re-reads whenever `lastSyncAt` changes — the sync tick that bumps it is the
 * same cycle that runs pullLocationStock, so the hint tracks tick cadence
 * without its own timer (parity with the SyncButton, which also only
 * re-formats on re-render).
 *
 * FU-10 — fail toward warning on prolonged staleness: the timestamp keeps
 * showing the last SUCCESSFUL pull (a stock-pull error never overwrites it),
 * but once that age exceeds 15 minutes the hint turns amber and gains a
 * "stock may be stale" title, so a long server outage reads as degraded
 * rather than silently ageing in the neutral gray.
 */
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useSyncStore } from '@/stores/syncStore';
import { useAuthStore } from '@/stores/authStore';
import { getDatabase } from '@/lib/db';
import { getSyncMetadata } from '@/lib/db/repositories/syncLogRepository';
import { formatRelativeTime, isOlderThan } from '@/lib/relativeTime';

const STOCK_LAST_SYNC_KEY = 'stock_last_sync';

/** Age beyond which the freshness hint turns amber (FU-10). */
const STALE_THRESHOLD_MS = 15 * 60_000;

export function StockFreshness() {
  const { t } = useTranslation('pos');
  const companyId = useAuthStore((s) => s.companyId);
  const lastSyncAt = useSyncStore((s) => s.lastSyncAt);
  const [stockSyncAt, setStockSyncAt] = useState<number | null>(null);

  useEffect(() => {
    let cancelled = false;
    void (async () => {
      if (!companyId) return;
      try {
        const db = await getDatabase(companyId);
        const raw = await getSyncMetadata(db, STOCK_LAST_SYNC_KEY);
        if (cancelled) return;
        if (raw === null) {
          setStockSyncAt(null);
          return;
        }
        const parsed = Date.parse(raw);
        setStockSyncAt(Number.isFinite(parsed) ? parsed : null);
      } catch {
        // DB read failed. Leave `stockSyncAt` untouched (FU-10 keep-last-good):
        // on a terminal that never read successfully (browser / non-Tauri dev,
        // or a failed first read) it stays null → renders nothing; after a prior
        // good read, a transient error keeps showing the last-good time, which
        // ages into the amber stale tint rather than vanishing.
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [companyId, lastSyncAt]);

  const time = formatRelativeTime(stockSyncAt);
  if (time === null) return null;

  // FU-10: amber once the last-good pull is older than 15 minutes. The
  // Date.now() read lives in isOlderThan (parity with formatRelativeTime) to
  // keep the impure call out of the render body.
  const isStale = isOlderThan(stockSyncAt, STALE_THRESHOLD_MS);

  return (
    <span
      data-testid="stock-freshness"
      title={isStale ? t('stock.staleTitle') : undefined}
      className={`hidden text-xs sm:inline ${isStale ? 'text-amber-600' : 'text-gray-500'}`}
    >
      {t('stock.asOf', { time })}
    </span>
  );
}
