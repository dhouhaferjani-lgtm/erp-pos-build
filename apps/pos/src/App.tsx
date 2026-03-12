import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { useAuthStore } from '@/stores/authStore';
import { useTerminalStore } from '@/stores/terminalStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { useSettingsStore } from '@/stores/settingsStore';
import { isTauriEnvironment } from '@/lib/printing';
import { ErrorBoundary } from '@/components/ErrorBoundary';
import { AppShell } from '@/components/AppShell';
import { LoginPage } from '@/pages/LoginPage';
import { TerminalSetupPage } from '@/pages/TerminalSetupPage';
import { PinEntryPage } from '@/pages/PinEntryPage';
import { PinSetupPage } from '@/pages/PinSetupPage';

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 60_000,
      retry: 1,
    },
  },
});

function AppRouter() {
  const { t } = useTranslation('common');
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated);
  const isInitialized = useAuthStore((s) => s.isInitialized);
  const authLoading = useAuthStore((s) => s.isLoading);
  const companyId = useAuthStore((s) => s.companyId);
  const companies = useAuthStore((s) => s.companies);
  const initAuth = useAuthStore((s) => s.initialize);

  const terminal = useTerminalStore((s) => s.terminal);
  const terminalLoading = useTerminalStore((s) => s.isLoading);
  const initTerminal = useTerminalStore((s) => s.initialize);

  const operator = useOperatorStore((s) => s.operator);
  const isLocked = useOperatorStore((s) => s.isLocked);
  const hasPins = useOperatorStore((s) => s.hasPins);
  const checkHasPins = useOperatorStore((s) => s.checkHasPins);

  useEffect(() => {
    void initAuth();
  }, [initAuth]);

  useEffect(() => {
    if (isAuthenticated && companyId) {
      void initTerminal();
    }
  }, [isAuthenticated, companyId, initTerminal]);

  useEffect(() => {
    if (isAuthenticated && companyId && terminal && hasPins === null) {
      void checkHasPins();
    }
  }, [isAuthenticated, companyId, terminal, hasPins, checkHasPins]);

  if (!isInitialized || authLoading) {
    return (
      <div className="flex h-screen items-center justify-center bg-gray-50">
        <div className="text-center">
          <div className="mx-auto h-8 w-8 animate-spin rounded-full border-4 border-blue-600 border-t-transparent" />
          <p className="mt-3 text-sm text-gray-500">{t('loading')}</p>
        </div>
      </div>
    );
  }

  if (!isAuthenticated) {
    return (
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route path="*" element={<Navigate to="/login" replace />} />
      </Routes>
    );
  }

  // Needs company selection
  if (companies.length > 1 && !companyId) {
    return (
      <Routes>
        <Route path="*" element={<LoginPage />} />
      </Routes>
    );
  }

  // Needs terminal setup
  if (!terminal && !terminalLoading) {
    return (
      <Routes>
        <Route path="/setup" element={<TerminalSetupPage />} />
        <Route path="*" element={<Navigate to="/setup" replace />} />
      </Routes>
    );
  }

  // Waiting for hasPins check
  if (!operator && hasPins === null) {
    return (
      <div className="flex h-screen items-center justify-center bg-gray-50">
        <div className="text-center">
          <div className="mx-auto h-8 w-8 animate-spin rounded-full border-4 border-blue-600 border-t-transparent" />
          <p className="mt-3 text-sm text-gray-500">{t('loading')}</p>
        </div>
      </div>
    );
  }

  // First-time setup: no PINs exist in tenant
  if (!operator && hasPins === false) {
    return <PinSetupPage />;
  }

  // Needs operator PIN
  if (!operator || isLocked) {
    return <PinEntryPage isLocked={isLocked} />;
  }

  return (
    <Routes>
      <Route path="/*" element={<AppShell />} />
    </Routes>
  );
}

export function App() {
  const fullscreen = useSettingsStore((s) => s.fullscreen);

  // Apply fullscreen on startup if the setting is enabled
  useEffect(() => {
    if (!fullscreen) return;
    const applyFullscreen = async () => {
      try {
        if (isTauriEnvironment()) {
          const { getCurrentWindow } = await import('@tauri-apps/api/window');
          await getCurrentWindow().setFullscreen(true);
        }
      } catch {
        // Ignore — not critical
      }
    };
    void applyFullscreen();
  }, [fullscreen]);

  return (
    <ErrorBoundary>
      <QueryClientProvider client={queryClient}>
        <BrowserRouter>
          <AppRouter />
        </BrowserRouter>
      </QueryClientProvider>
    </ErrorBoundary>
  );
}
