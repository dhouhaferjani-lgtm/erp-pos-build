/**
 * T1.1 Step 1.2 / production-readiness fold — empty-companies recovery.
 *
 * Verifies the folded ownership:
 *   `isAuthenticated && companies.length === 0` → bootstrapStore owns the
 *   company refresh, and BootstrapErrorScreen owns Retry / Sign out. This
 *   must still preempt TerminalSetupPage so a user whose companies failed
 *   to load does not call terminal APIs with no usable company context.
 *
 * The recovery branch closes the auth-persistence-orphan window from
 * Step 1.1 (a network drop after /auth/login but before /user/companies
 * resolves) AND covers legitimate cases like an admin revoking the user
 * from all companies between sessions.
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen, fireEvent, act, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

vi.mock('react-i18next', () => ({
  initReactI18next: { type: '3rdParty', init: () => {} },
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}));

vi.mock('@/lib/api', () => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
  ApiRequestError: class ApiRequestError extends Error {
    constructor(
      public readonly status: number,
      public readonly apiMessage: string,
      public readonly code: string,
    ) {
      super(apiMessage);
      this.name = 'ApiRequestError';
    }
  },
  getErrorMessage: (e: unknown) => (e instanceof Error ? e.message : 'unknown'),
}));

vi.mock('@/lib/storage', () => ({
  getStoredValue: vi.fn().mockResolvedValue(null),
  setStoredValue: vi.fn().mockResolvedValue(undefined),
  removeStoredValue: vi.fn().mockResolvedValue(undefined),
  StorageKeys: {
    TOKEN: 'auth_token',
    USER: 'user',
    COMPANY_ID: 'company_id',
    COMPANIES: 'companies',
    TERMINAL: 'terminal',
    PENDING_TERMINAL_ID: 'pending_terminal_id',
  },
}));

vi.mock('@/lib/echo', () => ({
  disconnectEcho: vi.fn(),
}));

const migrationMocks = vi.hoisted(() => ({
  runC2BareCartLineDump: vi.fn(),
}));

vi.mock('@/lib/migration/c2BareCartLineDump', () => ({
  runC2BareCartLineDump: migrationMocks.runC2BareCartLineDump,
}));

vi.mock('@tauri-apps/plugin-os', () => ({
  platform: vi.fn(() => 'macos'),
}));

vi.mock('@/lib/device', () => ({
  getDeviceId: vi.fn(() => 'device-test'),
}));

// Stub heavy page components so AppRouter can mount without them.
vi.mock('@/pages/LoginPage', () => ({
  LoginPage: () => <div data-testid="login-page">login</div>,
}));
vi.mock('@/pages/TerminalSetupPage', () => ({
  TerminalSetupPage: () => <div data-testid="terminal-setup-page">terminal-setup</div>,
}));
vi.mock('@/pages/PinEntryPage', () => ({
  PinEntryPage: () => <div data-testid="pin-entry-page">pin-entry</div>,
}));
vi.mock('@/pages/PinSetupPage', () => ({
  PinSetupPage: () => <div data-testid="pin-setup-page">pin-setup</div>,
}));
vi.mock('@/pages/CustomerDisplayPage', () => ({
  CustomerDisplayPage: () => <div data-testid="customer-display-page">cfd</div>,
}));
vi.mock('@/components/AppShell', () => ({
  AppShell: () => <div data-testid="app-shell">shell</div>,
}));
vi.mock('@/components/ErrorBoundary', () => ({
  ErrorBoundary: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

import { AppRouter } from '../App';
import { useAuthStore } from '@/stores/authStore';
import { useBootstrapStore } from '@/stores/bootstrapStore';
import { useTerminalStore } from '@/stores/terminalStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { useHoldStore } from '@/stores/holdStore';
import { apiGet } from '@/lib/api';

function renderRouter() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <AppRouter />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('AppRouter — empty-companies bootstrap recovery fold', () => {
  beforeEach(() => {
    vi.clearAllMocks();

    // Default apiGet to resolve with an empty companies array so the real
    // fetchCompanies (when not overridden by a test) doesn't crash on an
    // undefined return.
    vi.mocked(apiGet).mockResolvedValue([] as never);

    // Seed the empty-companies state we need to exercise.
    useAuthStore.setState({
      user: { id: 'u1', name: 'Test', email: 't@e.com' } as never,
      token: 'jwt-test',
      serverUrl: 'http://localhost:8002',
      companyId: null,
      companies: [],
      isAuthenticated: true,
      isLoading: false,
      isInitialized: true,
    });
    // Override initialize so the AppRouter useEffect doesn't reset state.
    useAuthStore.setState({ initialize: async () => {} } as never);

    useBootstrapStore.setState({
      phase: 'idle',
      error: null,
      lastSuccessfulPhase: null,
      running: false,
    } as never);

    useTerminalStore.setState({
      terminal: null,
      isLoading: false,
    } as never);

    useOperatorStore.setState({
      operator: null,
      isLocked: false,
      hasPins: null,
    } as never);
    useHoldStore.setState({
      heldTransactions: [],
      isLoading: false,
      error: null,
    });

    migrationMocks.runC2BareCartLineDump.mockResolvedValue({
      alreadyRan: true,
      deferred: false,
      dumpedCount: 0,
      dumpedIds: [],
      keptIds: [],
    });
  });

  it('renders BootstrapErrorScreen for isAuthenticated && companies.length === 0 when refresh stays empty', async () => {
    renderRouter();

    // Bootstrap recovery must render — TerminalSetupPage must NOT.
    await waitFor(() => {
      expect(screen.getByTestId('bootstrap-error-screen')).toBeInTheDocument();
    });
    expect(screen.queryByTestId('terminal-setup-page')).not.toBeInTheDocument();
  });

  it('blocks TerminalSetupPage while the empty-company refresh is still in flight', async () => {
    const fetchCompaniesSpy = vi.fn(() => new Promise<void>(() => {}));
    useAuthStore.setState({ fetchCompanies: fetchCompaniesSpy } as never);

    renderRouter();

    await waitFor(() => {
      expect(fetchCompaniesSpy).toHaveBeenCalledTimes(1);
    });

    expect(screen.queryByTestId('terminal-setup-page')).not.toBeInTheDocument();
    expect(screen.queryByTestId('bootstrap-error-screen')).not.toBeInTheDocument();
  });

  it('BootstrapErrorScreen Retry button re-runs fetchCompanies', async () => {
    const fetchCompaniesSpy = vi.fn().mockResolvedValue(undefined);
    useAuthStore.setState({ fetchCompanies: fetchCompaniesSpy } as never);

    renderRouter();

    const retryButton = await screen.findByTestId('bootstrap-retry');
    // First call is the bootstrap start; clear it so we measure the click only.
    fetchCompaniesSpy.mockClear();
    await act(async () => {
      fireEvent.click(retryButton);
    });
    await waitFor(() => {
      expect(fetchCompaniesSpy).toHaveBeenCalledTimes(1);
    });
  });

  it('BootstrapErrorScreen Sign-out button calls logout', async () => {
    const logoutSpy = vi.fn();
    useAuthStore.setState({ logout: logoutSpy } as never);

    renderRouter();

    const signOutButton = await screen.findByTestId('bootstrap-signout');
    await act(async () => {
      fireEvent.click(signOutButton);
    });
    expect(logoutSpy).toHaveBeenCalledTimes(1);
  });

  it('bootstrap start auto-runs fetchCompanies on mount for empty-company state', async () => {
    const fetchCompaniesSpy = vi.fn().mockResolvedValue(undefined);
    useAuthStore.setState({ fetchCompanies: fetchCompaniesSpy } as never);

    renderRouter();

    await waitFor(() => {
      expect(fetchCompaniesSpy).toHaveBeenCalledTimes(1);
    });
  });

  it('blocks AppShell until the C2 bare-cart migration finishes after bootstrap is ready', async () => {
    let resolveMigration: () => void = () => {};
    migrationMocks.runC2BareCartLineDump.mockReturnValue(
      new Promise((resolve) => {
        resolveMigration = () => resolve({
          alreadyRan: false,
          deferred: false,
          dumpedCount: 0,
          dumpedIds: [],
          keptIds: [],
        });
      }),
    );

    useAuthStore.setState({
      companyId: 'company-1',
      companies: [{ id: 'company-1', name: 'Shop' }],
      isAuthenticated: true,
      isInitialized: true,
      isLoading: false,
    } as never);
    useBootstrapStore.setState({
      phase: 'ready',
      error: null,
      lastSuccessfulPhase: 'checking-pins',
      start: async () => {},
      retry: async () => {},
    } as never);
    useTerminalStore.setState({
      terminal: { id: 'terminal-1', name: 'Main' },
      isLoading: false,
    } as never);
    useOperatorStore.setState({
      operator: { id: 'operator-1', name: 'Cashier' },
      isLocked: false,
      hasPins: true,
    } as never);

    renderRouter();

    await waitFor(() => {
      // Codex PR #118 r6 P2 — terminalId is now passed so the migration
      // scopes its completion state + held-transaction scan per-terminal.
      expect(migrationMocks.runC2BareCartLineDump).toHaveBeenCalledWith({
        companyId: 'company-1',
        terminalId: 'terminal-1',
      });
    });
    expect(screen.queryByTestId('app-shell')).not.toBeInTheDocument();

    await act(async () => {
      resolveMigration();
    });

    await waitFor(() => {
      expect(screen.getByTestId('app-shell')).toBeInTheDocument();
    });
  });

  it('fails open when the C2 migration defers so cached/offline boots can continue', async () => {
    migrationMocks.runC2BareCartLineDump.mockResolvedValue({
      alreadyRan: false,
      deferred: true,
      dumpedCount: 0,
      dumpedIds: [],
      keptIds: [],
    });

    useAuthStore.setState({
      companyId: 'company-1',
      companies: [{ id: 'company-1', name: 'Shop' }],
      isAuthenticated: true,
      isInitialized: true,
      isLoading: false,
    } as never);
    useBootstrapStore.setState({
      phase: 'ready',
      error: null,
      lastSuccessfulPhase: 'checking-pins',
      start: async () => {},
      retry: async () => {},
    } as never);
    useTerminalStore.setState({
      terminal: { id: 'terminal-1', name: 'Main' },
      isLoading: false,
    } as never);
    useOperatorStore.setState({
      operator: { id: 'operator-1', name: 'Cashier' },
      isLocked: false,
      hasPins: true,
    } as never);

    renderRouter();

    await waitFor(() => {
      // Codex PR #118 r6 P2 — terminalId is now passed so the migration
      // scopes its completion state + held-transaction scan per-terminal.
      expect(migrationMocks.runC2BareCartLineDump).toHaveBeenCalledWith({
        companyId: 'company-1',
        terminalId: 'terminal-1',
      });
    });
    await waitFor(() => {
      expect(screen.getByTestId('app-shell')).toBeInTheDocument();
    });
  });

  it('removes dumped in-memory held carts when a deferred C2 migration retry succeeds after AppShell mounts', async () => {
    vi.useFakeTimers();
    const loadHeldTransactions = vi.fn().mockResolvedValue(undefined);
    useHoldStore.setState({
      heldTransactions: [
        {
          id: 'dumped-held',
          label: 'Pre-C2',
          items: [],
          subtotal: 0,
          total: 0,
          itemCount: 0,
          heldAt: '2026-05-11T00:00:00.000Z',
        },
        {
          id: 'kept-held',
          label: 'Post-C2',
          items: [],
          subtotal: 0,
          total: 0,
          itemCount: 0,
          heldAt: '2026-05-11T00:01:00.000Z',
        },
      ],
      loadHeldTransactions,
    } as never);
    migrationMocks.runC2BareCartLineDump
      .mockResolvedValueOnce({
        alreadyRan: false,
        deferred: true,
        dumpedCount: 0,
        dumpedIds: [],
        keptIds: [],
      })
      .mockResolvedValueOnce({
        alreadyRan: false,
        deferred: false,
        dumpedCount: 1,
        dumpedIds: ['dumped-held'],
        keptIds: ['kept-held'],
      });

    useAuthStore.setState({
      companyId: 'company-1',
      companies: [{ id: 'company-1', name: 'Shop' }],
      isAuthenticated: true,
      isInitialized: true,
      isLoading: false,
    } as never);
    useBootstrapStore.setState({
      phase: 'ready',
      error: null,
      lastSuccessfulPhase: 'checking-pins',
      start: async () => {},
      retry: async () => {},
    } as never);
    useTerminalStore.setState({
      terminal: { id: 'terminal-1', name: 'Main' },
      isLoading: false,
    } as never);
    useOperatorStore.setState({
      operator: { id: 'operator-1', name: 'Cashier' },
      isLocked: false,
      hasPins: true,
    } as never);

    try {
      renderRouter();

      await act(async () => {
        await Promise.resolve();
      });
      expect(screen.getByTestId('app-shell')).toBeInTheDocument();
      expect(migrationMocks.runC2BareCartLineDump).toHaveBeenCalledTimes(1);

      await act(async () => {
        vi.advanceTimersByTime(30_000);
        await Promise.resolve();
      });

      expect(migrationMocks.runC2BareCartLineDump).toHaveBeenCalledTimes(2);
      expect(useHoldStore.getState().heldTransactions.map((tx) => tx.id)).toEqual(['kept-held']);
      expect(loadHeldTransactions).toHaveBeenCalledTimes(1);
    } finally {
      vi.useRealTimers();
    }
  });
});
