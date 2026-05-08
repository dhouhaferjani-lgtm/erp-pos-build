/**
 * T1.1 Step 1.4 — connectivity monitor placement.
 *
 * BEFORE this fix: `connectivityStore.startMonitoring()` ran from AppShell,
 * which only mounts inside the authenticated branch — so LoginPage never
 * saw connectivity state and the cashier couldn't tell whether their
 * login was failing because of credentials or because the API was
 * unreachable.
 *
 * AFTER: monitoring runs from MainApp (which mounts before LoginPage in
 * the route tree), and AppShell no longer starts its own monitor.
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render } from '@testing-library/react';

vi.mock('react-i18next', () => ({
  initReactI18next: { type: '3rdParty', init: () => {} },
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}));

vi.mock('@/lib/api', () => ({
  apiGet: vi.fn().mockResolvedValue([]),
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

vi.mock('@/lib/printing', () => ({
  isTauriEnvironment: vi.fn(() => false),
}));

vi.mock('@/lib/fullscreen', () => ({
  applyFullscreen: vi.fn(),
  useFullscreenEscapeKey: vi.fn(),
  useFullscreenWatchdog: vi.fn(),
}));

vi.mock('@/lib/customerDisplay', () => ({
  openCustomerDisplay: vi.fn(),
  sendIdleScreen: vi.fn(),
}));

vi.mock('@/hooks/useCustomerDisplaySync', () => ({
  useCustomerDisplaySync: vi.fn(),
}));

vi.mock('@/pages/LoginPage', () => ({
  LoginPage: () => <div data-testid="login-page">login</div>,
}));
vi.mock('@/pages/TerminalSetupPage', () => ({
  TerminalSetupPage: () => <div>terminal-setup</div>,
}));
vi.mock('@/pages/PinEntryPage', () => ({
  PinEntryPage: () => <div>pin-entry</div>,
}));
vi.mock('@/pages/PinSetupPage', () => ({
  PinSetupPage: () => <div>pin-setup</div>,
}));
vi.mock('@/pages/CustomerDisplayPage', () => ({
  CustomerDisplayPage: () => <div>cfd</div>,
}));
vi.mock('@/pages/HomePage', () => ({
  HomePage: () => <div>home</div>,
}));
vi.mock('@/components/Header', () => ({
  Header: () => <div>header</div>,
}));
vi.mock('@/components/ErrorBoundary', () => ({
  ErrorBoundary: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

// Mock the connectivity store so we can spy on startMonitoring.
const startMonitoringSpy = vi.fn(() => () => {});
vi.mock('@/stores/connectivityStore', () => ({
  useConnectivityStore: Object.assign(
    (selector: (s: { isOnline: boolean }) => unknown) =>
      selector({ isOnline: true }),
    {
      getState: () => ({ startMonitoring: startMonitoringSpy }),
    },
  ),
}));

// syncStore is touched by AppShell's visibilitychange effect; stub it.
vi.mock('@/stores/syncStore', () => ({
  useSyncStore: Object.assign(
    (selector: (s: { lastSyncAt: number | null }) => unknown) =>
      selector({ lastSyncAt: null }),
    {
      getState: () => ({ lastSyncAt: null, triggerSync: vi.fn() }),
    },
  ),
}));

import { MainApp } from '../App';
import { AppShell } from '@/components/AppShell';
import { useAuthStore } from '@/stores/authStore';
import { useTerminalStore } from '@/stores/terminalStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { useSettingsStore } from '@/stores/settingsStore';
import { useCustomerDisplayStore } from '@/stores/customerDisplayStore';
import { MemoryRouter } from 'react-router-dom';

describe('T1.1 Step 1.4 — connectivity monitor placement', () => {
  beforeEach(() => {
    startMonitoringSpy.mockClear();
    startMonitoringSpy.mockReturnValue(() => {});

    // Seed minimal store state so AppRouter (which MainApp transitively
    // renders) doesn't crash. We do NOT care which sub-screen renders;
    // only whether startMonitoring fires.
    useAuthStore.setState({
      isInitialized: true,
      isAuthenticated: false,
      isLoading: false,
      companies: [],
      companyId: null,
      user: null,
      token: null,
      serverUrl: 'http://localhost:8002',
    } as never);
    useAuthStore.setState({ initialize: async () => {} } as never);

    useTerminalStore.setState({ terminal: null, isLoading: false } as never);
    useOperatorStore.setState({
      operator: null,
      isLocked: false,
      hasPins: null,
    } as never);
    useSettingsStore.setState({ fullscreen: false } as never);
    useCustomerDisplayStore.setState({
      enabled: false,
      monitorIndex: null,
      idleImagePath: null,
      isOpen: false,
    } as never);
  });

  it('T1.1: MainApp starts connectivity monitoring on mount', () => {
    render(<MainApp />);
    expect(startMonitoringSpy).toHaveBeenCalledTimes(1);
  });

  it('T1.1: AppShell does NOT start its own connectivity monitoring', () => {
    useOperatorStore.setState({
      operator: { id: 'op1' },
      isLocked: false,
      hasPins: true,
    } as never);

    render(
      <MemoryRouter>
        <AppShell />
      </MemoryRouter>,
    );
    expect(startMonitoringSpy).not.toHaveBeenCalled();
  });
});
