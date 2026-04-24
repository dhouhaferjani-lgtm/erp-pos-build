import { useRef, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { RefreshCw } from 'lucide-react';
import { useSyncStore } from '@/stores/syncStore';
import { cn } from '@/lib/utils';

const DEBOUNCE_MS = 500;

function formatTimeAgo(
  timestamp: number | null,
  t: (key: string, opts?: Record<string, unknown>) => string,
): string | null {
  if (!timestamp) return null;
  const diffMs = Date.now() - timestamp;
  const diffMin = Math.floor(diffMs / 60_000);

  if (diffMin < 1) return t('sync.lastSync', { time: '<1m' });
  if (diffMin < 60) return t('sync.lastSync', { time: `${diffMin}m` });
  const diffHours = Math.floor(diffMin / 60);
  return t('sync.lastSync', { time: `${diffHours}h` });
}

export function SyncButton() {
  const { t } = useTranslation('pos');
  const isSyncing = useSyncStore((s) => s.isSyncing);
  const lastSyncAt = useSyncStore((s) => s.lastSyncAt);
  const triggerSync = useSyncStore((s) => s.triggerSync);
  const lastClickAt = useRef<number>(0);

  const handleClick = useCallback(() => {
    const now = Date.now();
    if (now - lastClickAt.current < DEBOUNCE_MS) {
      // Silent swallow — the fiscal chain cannot survive a double-fire.
      console.info('[fiscal]', {
        op: 'SyncButton.debounceBlocked',
        since_last_ms: now - lastClickAt.current,
      });
      return;
    }
    lastClickAt.current = now;
    triggerSync();
  }, [triggerSync]);

  const timeAgo = formatTimeAgo(lastSyncAt, t);

  return (
    <button
      onClick={handleClick}
      disabled={isSyncing}
      className={cn(
        'flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-50',
      )}
      aria-label={t('sync.syncNow')}
    >
      <RefreshCw className={cn('h-3.5 w-3.5', isSyncing && 'animate-spin')} />
      <span className="hidden sm:inline">
        {isSyncing ? t('sync.syncing') : t('sync.syncNow')}
      </span>
      {timeAgo && <span className="text-xs text-gray-500">{timeAgo}</span>}
    </button>
  );
}
