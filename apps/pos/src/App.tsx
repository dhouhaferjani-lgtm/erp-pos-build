import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { useAuthStore } from '@/stores/authStore';
import { useConnectivityStore } from '@/stores/connectivityStore';
import { serializeErrorForLog } from '@/lib/errorLogging';
import { useTerminalStore } from '@/stores/terminalStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { useSettingsStore } from '@/stores/settingsStore';
import { useCustomerDisplayStore } from '@/stores/customerDisplayStore';
import { isTauriEnvironment } from '@/lib/printing';
import { applyFullscreen, useFullscreenEscapeKey, useFullscreenWatchdog } from '@/lib/fullscreen';
import { openCustomerDisplay, sendIdleScreen } from '@/lib/customerDisplay';
import { ErrorBoundary } from '@/components/ErrorBoundary';
import { AppShell } from '@/components/AppShell';
import { CustomerDisplayPage } from '@/pages/CustomerDisplayPage';
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

/** Detect if this window is the customer display (secondary window). */
const isCustomerDisplayWindow = window.location.pathname === '/customer-display';

export function AppRouter() {
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

  // T1.1 Step 1.2: empty-companies recovery branch.
  // Reachable when (a) /user/companies failed mid-login (Step 1.1's window
  // is now closed for new logins, but a pre-Step-1.1 orphan may still be
  // cached on disk and rehydrated by initialize()), or (b) an admin has
  // legitimately revoked the user from every company between sessions.
  // Without this branch the user falls through to TerminalSetupPage,
  // which throws on the first apiGet('/terminals?company_id=...') because
  // companyId is null.
  if (isAuthenticated && companies.length === 0) {
    return <CompanyRecoveryScreen />;
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

/**
 * T1.1 Step 1.2 — recovery screen rendered when isAuthenticated but
 * companies are empty. Auto-runs `fetchCompanies` once on mount; surfaces
 * the failure (typed-fields-only — banner-opacity contract from T0.1) with
 * Retry + Sign-out affordances.
 */
function CompanyRecoveryScreen() {
  const { t } = useTranslation('common');
  const fetchCompanies = useAuthStore((s) => s.fetchCompanies);
  const logout = useAuthStore((s) => s.logout);

  const [isLoading, setIsLoading] = useState(false);
  const [errorPayload, setErrorPayload] = useState<{
    errorName: string | undefined;
    message: string;
  } | null>(null);

  // Sanitize the error message: serializeErrorForLog returns a typed shape
  // but the `message` field can still carry vendor / API content. Truncate
  // to a bounded length to keep the cashier UI opaque against URL leaks
  // and stack traces (T0.1 banner-opacity contract).
  function sanitize(raw: string): string {
    return raw.slice(0, 120);
  }

  async function runFetch() {
    setIsLoading(true);
    setErrorPayload(null);
    try {
      await fetchCompanies();
    } catch (error) {
      const payload = serializeErrorForLog(error);
      console.error('[POS][AppRouter][companyRecovery] fetchCompanies failed', payload);
      setErrorPayload({
        errorName: payload.errorName,
        message: sanitize(payload.message),
      });
    } finally {
      setIsLoading(false);
    }
  }

  useEffect(() => {
    void runFetch();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <div
      className="flex h-screen items-center justify-center bg-gray-50"
      data-testid="company-recovery-screen"
    >
      <div className="w-full max-w-md rounded-lg bg-white p-8 shadow-md text-center">
        <h2 className="text-xl font-bold text-gray-900">
          {t('auth.companyRecovery.title')}
        </h2>
        <p className="mt-2 text-sm text-gray-500">
          {t('auth.companyRecovery.message')}
        </p>

        {errorPayload && (
          <div className="mt-4 rounded-md bg-red-50 p-3 text-left text-xs text-red-700">
            <div className="font-mono font-medium">
              {errorPayload.errorName ?? 'Error'}
            </div>
            <div className="mt-1 break-words">{errorPayload.message}</div>
          </div>
        )}

        <button
          type="button"
          data-testid="company-recovery-retry"
          disabled={isLoading}
          onClick={() => void runFetch()}
          className="mt-6 w-full rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
        >
          {isLoading
            ? t('auth.companyRecovery.retrying')
            : t('auth.companyRecovery.retry')}
        </button>
        <button
          type="button"
          data-testid="company-recovery-signout"
          onClick={() => logout()}
          className="mt-3 w-full text-sm text-gray-500 hover:text-gray-700 underline"
        >
          {t('auth.companyRecovery.signOut')}
        </button>
      </div>
    </div>
  );
}

export function App() {
  // Customer display window — render directly without auth/providers
  if (isCustomerDisplayWindow) {
    return <CustomerDisplayPage />;
  }

  return <MainApp />;
}

export function MainApp() {
  const fullscreen = useSettingsStore((s) => s.fullscreen);
  const cfdEnabled = useCustomerDisplayStore((s) => s.enabled);
  const cfdMonitorIndex = useCustomerDisplayStore((s) => s.monitorIndex);
  const cfdIdleImagePath = useCustomerDisplayStore((s) => s.idleImagePath);
  const setIsOpen = useCustomerDisplayStore((s) => s.setIsOpen);

  // Apply borderless fullscreen when the setting is enabled.
  useFullscreenEscapeKey();
  useFullscreenWatchdog();
  useEffect(() => {
    void applyFullscreen(fullscreen);
  }, [fullscreen]);

  // T1.1 Step 1.4: start connectivity monitoring at the MainApp level so
  // LoginPage (which mounts before any authenticated screen) can read
  // isOnline and surface the offline panel. Previously this lived inside
  // AppShell, which only mounts after authentication — so a cashier hit
  // by a network outage at boot saw a generic credentials error instead
  // of the "no connection" affordance. The customer-display window
  // returns directly from App() before reaching MainApp, so it does not
  // spin up a redundant monitor.
  useEffect(() => {
    const stopMonitoring = useConnectivityStore.getState().startMonitoring();
    return stopMonitoring;
  }, []);

  // Auto-open customer display on startup if enabled
  useEffect(() => {
    if (!cfdEnabled || !isTauriEnvironment()) return;
    const autoOpen = async () => {
      try {
        await openCustomerDisplay(cfdMonitorIndex ?? undefined);
        setIsOpen(true);
        await sendIdleScreen(cfdIdleImagePath);
      } catch (error) {
        console.warn('Customer display failed to open:', error);
        useCustomerDisplayStore.getState().setEnabled(false);
        setIsOpen(false);
      }
    };
    void autoOpen();
  }, [cfdEnabled, cfdMonitorIndex, cfdIdleImagePath, setIsOpen]);

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
