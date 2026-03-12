import { useEffect, useCallback, lazy, Suspense } from 'react';
import { Route, Routes, Navigate } from 'react-router-dom';
import { Header } from './Header';
import { HomePage } from '@/pages/HomePage';
import { useOperatorStore } from '@/stores/operatorStore';
import { useConnectivityStore } from '@/stores/connectivityStore';

const SettingsPage = lazy(() =>
  import('@/pages/SettingsPage').then((m) => ({ default: m.SettingsPage })),
);

export function AppShell() {
  const lockTimeoutMs = useOperatorStore((s) => s.lockTimeoutMs);
  const resetActivityTimer = useOperatorStore((s) => s.resetActivityTimer);
  const lock = useOperatorStore((s) => s.lock);
  const operator = useOperatorStore((s) => s.operator);

  const handleActivity = useCallback(() => {
    resetActivityTimer();
  }, [resetActivityTimer]);

  // Start connectivity monitoring
  useEffect(() => {
    const stopMonitoring = useConnectivityStore.getState().startMonitoring();
    return stopMonitoring;
  }, []);

  useEffect(() => {
    if (!operator) return;

    const events = ['mousedown', 'keydown', 'touchstart'] as const;
    for (const event of events) {
      window.addEventListener(event, handleActivity);
    }

    const interval = setInterval(() => {
      const { lastActivity } = useOperatorStore.getState();
      if (Date.now() - lastActivity > lockTimeoutMs) {
        lock();
      }
    }, 10_000);

    return () => {
      for (const event of events) {
        window.removeEventListener(event, handleActivity);
      }
      clearInterval(interval);
    };
  }, [operator, lockTimeoutMs, handleActivity, lock]);

  return (
    <div className="flex h-screen flex-col bg-gray-50">
      <Header />
      <main className="flex-1 overflow-hidden">
        <Suspense fallback={null}>
          <Routes>
            <Route path="/" element={<HomePage />} />
            <Route path="/settings" element={<SettingsPage />} />
            <Route path="*" element={<Navigate to="/" replace />} />
          </Routes>
        </Suspense>
      </main>
    </div>
  );
}
