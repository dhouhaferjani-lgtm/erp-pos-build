import { useEffect, useCallback } from 'react';
import { Route, Routes } from 'react-router-dom';
import { Header } from './Header';
import { HomePage } from '@/pages/HomePage';
import { useOperatorStore } from '@/stores/operatorStore';

export function AppShell() {
  const lockTimeoutMs = useOperatorStore((s) => s.lockTimeoutMs);
  const resetActivityTimer = useOperatorStore((s) => s.resetActivityTimer);
  const lock = useOperatorStore((s) => s.lock);
  const operator = useOperatorStore((s) => s.operator);

  const handleActivity = useCallback(() => {
    resetActivityTimer();
  }, [resetActivityTimer]);

  useEffect(() => {
    if (!operator) return;

    const events = ['mousedown', 'keydown', 'touchstart', 'mousemove'] as const;
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
        <Routes>
          <Route path="/" element={<HomePage />} />
        </Routes>
      </main>
    </div>
  );
}
