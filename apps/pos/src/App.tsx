import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { useAuthStore } from '@/stores/authStore';
import { useBootstrapStore } from '@/stores/bootstrapStore';
import { useConnectivityStore } from '@/stores/connectivityStore';
import { useTerminalStore } from '@/stores/terminalStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { useSettingsStore } from '@/stores/settingsStore';
import { useCustomerDisplayStore } from '@/stores/customerDisplayStore';
import { isTauriEnvironment } from '@/lib/printing';
import { applyFullscreen, useFullscreenEscapeKey, useFullscreenWatchdog } from '@/lib/fullscreen';
import { openCustomerDisplay, sendIdleScreen } from '@/lib/customerDisplay';
import { ErrorBoundary } from '@/components/ErrorBoundary';
import { AppShell } from '@/components/AppShell';
import { BootstrapErrorScreen } from '@/components/BootstrapErrorScreen';
import { useBootstrapErrorTelemetry } from '@/hooks/useBootstrapErrorTelemetry';
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

  const terminal = useTerminalStore((s) => s.terminal);
  const terminalLoading = useTerminalStore((s) => s.isLoading);

  const operator = useOperatorStore((s) => s.operator);
  const isLocked = useOperatorStore((s) => s.isLocked);
  const hasPins = useOperatorStore((s) => s.hasPins);

  // T2.4 Day 2 — replace the three independent `useEffect` orchestrators
  // with the bootstrap state machine. The machine's `start()` walks the
  // four init phases (auth → companies → terminal → pins), each wrapped
  // in a 15s timeout and gated by the same auth/company prerequisites
  // the old AppRouter checked. On failure it tags `phase='error'` and
  // surfaces the BootstrapErrorScreen below; on a logged-out boot the
  // gate stops at 'ready' with no error and the existing conditional
  // renders take over (LoginPage etc.).
  const bootstrapPhase = useBootstrapStore((s) => s.phase);
  const bootstrapError = useBootstrapStore((s) => s.error);
  const bootstrapLastSuccess = useBootstrapStore((s) => s.lastSuccessfulPhase);
  const startBootstrap = useBootstrapStore((s) => s.start);
  const retryBootstrap = useBootstrapStore((s) => s.retry);

  useBootstrapErrorTelemetry();

  // Mount-time kickoff. The store's single-flight `running` guard
  // ensures a second invocation during a still-in-flight start() is a
  // no-op, so the dep-change effect below is safe to call retry() while
  // the cold-boot run is still walking phases.
  useEffect(() => {
    void startBootstrap();
  }, [startBootstrap]);

  // Dependent-state resume. The state machine stops at 'ready' (no
  // error) whenever a `canEnterPhase` gate is unsatisfied — e.g. a
  // logged-out boot stops at 'ready' after the authenticating phase
  // because fetching-companies needs isAuthenticated=true. When the
  // cashier then logs in via LoginPage, isAuthenticated flips and this
  // effect fires retry(), which resumes from
  // `nextPhaseAfter(lastSuccessfulPhase)` so we don't re-run auth.
  //
  // Guards (each closes a real failure mode):
  //   - `bootstrapPhase !== 'ready'` — prevents racing with an in-flight
  //     start() and dispatching while the BootstrapErrorScreen is up
  //     waiting for user input.
  //   - `!isAuthenticated` (Codex PR #108 r2 P1, session safety) — after
  //     logout, authStore.logout() calls bootstrapStore.reset() which
  //     sets phase='ready' and lastSuccessfulPhase=null. Without this
  //     guard the effect would immediately call retry() → re-run
  //     authenticating → authStore.initialize() reads storage. Because
  //     logout's `removeStoredValue` calls are fire-and-forget, the
  //     re-read can hit stale TOKEN/USER and restore the just-signed-out
  //     session. The gate "have prerequisites flipped to a state where
  //     bootstrap CAN make progress" is the correct semantic — retry
  //     only after the cashier successfully logs in again.
  //   - `bootstrapLastSuccess === 'checking-pins'` — keeps the effect a
  //     no-op once every phase has already run; otherwise each post-boot
  //     terminal-store mutation (sync-tick `refreshTerminalRecord` etc.)
  //     would queue a retry that the store guard turns into a no-op.
  useEffect(() => {
    if (bootstrapPhase !== 'ready') return;
    if (!isAuthenticated) return;
    if (bootstrapLastSuccess === 'checking-pins') return;
    void retryBootstrap();
    // Codex PR #108 r5 P2 — `companies` MUST be in the deps. The
    // orphan empty-companies cached-session boot path stops bootstrap
    // before terminal init (PR #108 r4 P2 gate). If the fetching-companies
    // phase later repopulates the company list and validates the existing
    // companyId, only `companies` changes (companyId and terminal stay
    // as-is) — without this dep, the effect would never re-fire and
    // bootstrap would never advance, leaving the app on TerminalSetupPage
    // instead of initializing the cached terminal/PIN state.
  }, [bootstrapPhase, bootstrapLastSuccess, isAuthenticated, companies, companyId, terminal, retryBootstrap]);

  // Bootstrap failure takes precedence over every other render branch —
  // the cashier needs the error surface and recovery affordances, not
  // a stale spinner or a forward-routed UX.
  if (bootstrapPhase === 'error' && bootstrapError !== null) {
    return <BootstrapErrorScreen />;
  }

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

  // Empty-company recovery is owned by bootstrapStore's
  // `fetching-companies` phase. While that refresh is pending, keep the
  // terminal setup routes unmounted so they cannot call terminal APIs
  // without a usable company context.
  if (isAuthenticated && companies.length === 0) {
    return (
      <div className="flex h-screen items-center justify-center bg-gray-50">
        <div className="text-center">
          <div className="mx-auto h-8 w-8 animate-spin rounded-full border-4 border-blue-600 border-t-transparent" />
          <p className="mt-3 text-sm text-gray-500">{t('loading')}</p>
        </div>
      </div>
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
