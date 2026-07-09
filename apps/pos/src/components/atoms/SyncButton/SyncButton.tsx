import { useRef, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { RefreshCw } from 'lucide-react';
import { useSyncStore } from '@/stores/syncStore';
import { cn } from '@/lib/utils';
import { formatRelativeTime } from '@/lib/relativeTime';

const DEBOUNCE_MS = 500;

function formatTimeAgo(
  timestamp: number | null,
  t: (key: string, opts?: Record<string, unknown>) => string,
): string | null {
  const time = formatRelativeTime(timestamp);
  if (time === null) return null;
  return t('sync.lastSync', { time });
}

export function SyncButton() {
  const { t } = useTranslation('pos');
  const isSyncing = useSyncStore((s) => s.isSyncing);
  const lastSyncAt = useSyncStore((s) => s.lastSyncAt);
  // T1.3 Step 4.3: tristate signal — the amber dot renders only when
  // the most recent tick degraded. Pre-T1.3 SyncButton showed the
  // green "Last sync 5m ago" affordance even after a tick that failed
  // to push receipts or pull payment config.
  // TODO(go-live-followup): animated transition between sync states (green
  //   ↔ amber) deferred from T1.3 PR #90. Today the dot pops in/out without
  //   easing, which is jarring when a tick degrades mid-cashier-session.
  //   Pure-CSS opacity/scale transition on the dot's mount/unmount would fix
  //   it without touching state.
  const lastSyncResult = useSyncStore((s) => s.lastSyncResult);
  const isDegraded = lastSyncResult?.degraded === true;
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

  // T1.3 Codex round-1 finding 1: the button's aria-label MUST
  // incorporate the degraded state — pre-fix screen-reader users
  // heard "Sync Now" even when the most recent tick degraded, so
  // the amber dot's silent visual cue had no audible counterpart.
  const buttonAriaLabel = isDegraded
    ? `${t('sync.syncNow')}. ${t('sync.degradedTitle')}`
    : t('sync.syncNow');

  return (
    <button
      onClick={handleClick}
      disabled={isSyncing}
      className={cn(
        'flex items-center gap-1.5 rounded-ctl px-2.5 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-50',
      )}
      aria-label={buttonAriaLabel}
    >
      <RefreshCw className={cn('h-3.5 w-3.5', isSyncing && 'animate-spin')} />
      <span className="hidden sm:inline">
        {isSyncing ? t('sync.syncing') : t('sync.syncNow')}
      </span>
      {isDegraded && (
        <span
          data-testid="sync-degraded-dot"
          // T1.3 Codex round-2 finding 6: the button's aria-label now
          // carries the degraded announcement (round-1 MAJOR-1 fix),
          // so the dot itself is purely visual — hide it from screen
          // readers to avoid a redundant double-announcement of the
          // degraded state.
          aria-hidden="true"
          title={t('sync.degradedTitle')}
          className="inline-block h-1.5 w-1.5 rounded-full bg-amber-500"
        />
      )}
      {timeAgo && <span className="text-xs text-gray-500">{timeAgo}</span>}
    </button>
  );
}
