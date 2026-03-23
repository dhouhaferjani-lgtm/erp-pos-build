import { useTranslation } from 'react-i18next';
import { RefreshCw } from 'lucide-react';
import { useSyncStore } from '@/stores/syncStore';
import { cn } from '@/lib/utils';

function formatTimeAgo(timestamp: number | null, t: (key: string, opts?: Record<string, unknown>) => string): string | null {
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

  const timeAgo = formatTimeAgo(lastSyncAt, t);

  return (
    <button
      onClick={triggerSync}
      disabled={isSyncing}
      className={cn(
        'flex min-h-[44px] items-center gap-2 rounded-lg bg-primary-800 px-3 py-2 text-sm font-medium text-primary-200 hover:bg-primary-700 disabled:opacity-50 disabled:cursor-not-allowed',
      )}
    >
      <RefreshCw
        className={cn('h-4 w-4', isSyncing && 'animate-spin')}
      />
      <span className="hidden sm:inline">
        {isSyncing ? t('sync.syncing') : t('sync.syncNow')}
      </span>
      {timeAgo && (
        <span className="text-xs text-primary-300">
          {timeAgo}
        </span>
      )}
    </button>
  );
}
