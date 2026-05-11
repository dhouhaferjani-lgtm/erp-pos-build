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
});
