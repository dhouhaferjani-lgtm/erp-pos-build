import { useEffect, useCallback, lazy, Suspense } from 'react';
import { Route, Routes, Navigate } from 'react-router-dom';
import { Header } from './Header';
import { HomePage } from '@/pages/HomePage';
import { useOperatorStore } from '@/stores/operatorStore';
import { useSettingsStore } from '@/stores/settingsStore';
import { useConnectivityStore } from '@/stores/connectivityStore';
import { useSyncStore } from '@/stores/syncStore';
import { useCustomerDisplaySync } from '@/hooks/useCustomerDisplaySync';

const SettingsPage = lazy(() =>
  import('@/pages/SettingsPage').then((m) => ({ default: m.SettingsPage })),
);

const TodaySalesPage = lazy(() =>
  import('@/components/pos/TodaySalesPanel').then((m) => ({ default: m.TodaySalesPage })),
);

const ZReportListPage = lazy(() =>
  import('@/pages/ZReportListPage').then((m) => ({ default: m.ZReportListPage })),
);

export function AppShell() {
  const resetActivityTimer = useOperatorStore((s) => s.resetActivityTimer);
  const lock = useOperatorStore((s) => s.lock);
  const operator = useOperatorStore((s) => s.operator);

  // Sync cart/checkout state to customer-facing display
  useCustomerDisplaySync();

  const handleActivity = useCallback(() => {
    resetActivityTimer();
  }, [resetActivityTimer]);

  // Start connectivity monitoring
  useEffect(() => {
    const stopMonitoring = useConnectivityStore.getState().startMonitoring();
    return stopMonitoring;
  }, []);

  // Trigger sync when app regains visibility after >1 min
  useEffect(() => {
    const STALE_THRESHOLD_MS = 60_000; // 1 minute

    function handleVisibilityChange() {
      if (document.visibilityState === 'visible') {
        const { lastSyncAt, triggerSync } = useSyncStore.getState();
        const elapsed = lastSyncAt ? Date.now() - lastSyncAt : Infinity;
        if (elapsed > STALE_THRESHOLD_MS) {
          triggerSync();
        }
      }
    }

    document.addEventListener('visibilitychange', handleVisibilityChange);
    return () => {
      document.removeEventListener('visibilitychange', handleVisibilityChange);
    };
  }, []);

  useEffect(() => {
    if (!operator) return;

    const events = ['mousedown', 'keydown', 'touchstart'] as const;
    for (const event of events) {
      window.addEventListener(event, handleActivity);
    }

    const interval = setInterval(() => {
      const timeout = useSettingsStore.getState().inactivityTimeout;
      if (timeout === 0) return; // "Never" — skip check
      const { lastActivity } = useOperatorStore.getState();
      if (Date.now() - lastActivity > timeout * 1000) {
        lock();
      }
    }, 10_000);

    return () => {
      for (const event of events) {
        window.removeEventListener(event, handleActivity);
      }
      clearInterval(interval);
    };
  }, [operator, handleActivity, lock]);

  return (
    <div className="flex h-screen flex-col bg-gray-50">
      <Header />
      <main className="flex-1 overflow-hidden">
        <Suspense fallback={null}>
          <Routes>
            <Route path="/" element={<HomePage />} />
            <Route path="/settings" element={<SettingsPage />} />
            <Route path="/sales" element={<TodaySalesPage />} />
            <Route path="/reports/z" element={<ZReportListPage />} />
            <Route path="*" element={<Navigate to="/" replace />} />
          </Routes>
        </Suspense>
      </main>
    </div>
  );
}
