/**
 * Sub-Spec B: SettingsPage Device & Security section — manager-gated.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';

vi.mock('react-i18next', () => ({
  initReactI18next: { type: '3rdParty', init: () => {} },
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}));

vi.mock('react-router-dom', () => ({
  useNavigate: () => vi.fn(),
}));

vi.mock('@tauri-apps/plugin-os', () => ({ platform: vi.fn(() => 'macos') }));

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
    LOGIN_TENANT_ID: 'login_tenant_id',
  },
}));

vi.mock('@/lib/echo', () => ({ disconnectEcho: vi.fn() }));
vi.mock('@/lib/device', () => ({ getDeviceId: vi.fn(() => 'device-test') }));
vi.mock('@/lib/api', () => ({
  apiGet: vi.fn(), apiPost: vi.fn(),
  getErrorMessage: (e: unknown) => (e instanceof Error ? e.message : 'unknown'),
  ApiRequestError: class extends Error {
    constructor(public status: number, msg: string, public code: string) { super(msg); }
  },
}));
vi.mock('@/lib/fullscreen', () => ({ applyFullscreen: vi.fn() }));
vi.mock('@/lib/printing', () => ({
  discoverPrinters: vi.fn().mockResolvedValue([]),
  printTestPage: vi.fn(),
  getPrintSettingsFromStore: vi.fn().mockReturnValue({}),
  isTauriEnvironment: vi.fn().mockReturnValue(false),
}));
vi.mock('@/lib/scan/scanResolutionCache', () => ({ clearScanCache: vi.fn() }));

vi.mock('@/components/settings/CashDrawerSettings', () => ({
  CashDrawerSettings: () => null,
}));
vi.mock('@/components/settings/ScannerSettings', () => ({
  ScannerSettings: () => null,
}));
vi.mock('@/components/settings/PrinterAdvancedSettings', () => ({
  PrinterAdvancedSettings: () => null,
}));
vi.mock('@/components/settings/CustomerDisplaySettings', () => ({
  CustomerDisplaySettings: () => null,
}));

// Mock the Modal component (used for the unbind confirm dialog)
vi.mock('@/components/pos/Modal', () => ({
  Modal: ({ isOpen, children, footer, title }: {
    isOpen: boolean; children: React.ReactNode; footer?: React.ReactNode; title: string;
  }) => {
    if (!isOpen) return null;
    return (
      <div data-testid="modal" aria-label={title}>
        {children}
        {footer}
      </div>
    );
  },
}));

// Spy on teardownPosSessionStores
const teardownSpy = vi.fn();
vi.mock('@/lib/session/teardownPosSession', () => ({
  teardownPosSessionStores: () => teardownSpy(),
}));

// Auth store mock
const unbindSpy = vi.fn();
vi.mock('@/stores/authStore', () => ({
  useAuthStore: <T,>(selector: (s: unknown) => T): T => {
    return selector({ serverUrl: 'http://localhost:8002', unbindDevice: () => unbindSpy() });
  },
}));

// Operator store — operator roles are controlled per test
let mockOperatorRoles: string[] = ['manager'];

function setOperatorRoles(roles: string[]) {
  mockOperatorRoles = roles;
}

vi.mock('@/stores/operatorStore', () => ({
  useOperatorStore: <T,>(selector: (s: unknown) => T): T => {
    return selector({ operator: { id: 'op-1', name: 'Test Op', roles: mockOperatorRoles } });
  },
}));

vi.mock('@/stores/settingsStore', () => ({
  useSettingsStore: <T,>(selector: (s: unknown) => T): T => {
    return selector({
      displayMode: 'grid', language: 'en', touchMode: false, fullscreen: false,
      setDisplayMode: vi.fn(), setLanguage: vi.fn(), setTouchMode: vi.fn(), setFullscreen: vi.fn(),
      inactivityTimeout: 300, lockAfterSale: false,
      setInactivityTimeout: vi.fn(), setLockAfterSale: vi.fn(),
    });
  },
  SUPPORTED_LANGUAGES: [{ code: 'en', label: 'English' }, { code: 'fr', label: 'Français' }],
}));

vi.mock('@/stores/printerStore', () => ({
  usePrinterStore: <T,>(selector: (s: unknown) => T): T => {
    return selector({ printerConfig: null, autoPrint: false, setPrinterConfig: vi.fn(), setAutoPrint: vi.fn(), clearPrinterConfig: vi.fn() });
  },
}));

const mockTerminalStoreState = { terminal: null as unknown, shift: null as unknown, reset: vi.fn() };
vi.mock('@/stores/terminalStore', () => ({
  useTerminalStore: Object.assign(
    <T,>(selector: (s: unknown) => T): T => selector(mockTerminalStoreState),
    { getState: () => mockTerminalStoreState },
  ),
}));

vi.mock('@/stores/connectivityStore', () => ({
  useConnectivityStore: <T,>(selector: (s: unknown) => T): T => {
    return selector({ isOnline: true });
  },
}));

import { SettingsPage } from '../SettingsPage';

describe('SettingsPage – Device & Security (Sub-Spec B)', () => {
  beforeEach(() => {
    setOperatorRoles(['manager']);
    teardownSpy.mockReset();
    unbindSpy.mockReset();
  });

  it('shows Device & Security section (incl. unbind) only for a manager operator', () => {
    setOperatorRoles(['manager']);
    render(<SettingsPage />);
    expect(screen.getByTestId('device-security-section')).toBeInTheDocument();
    expect(screen.getByTestId('device-unbind-button')).toBeInTheDocument();
  });

  it('hides Device & Security for a cashier operator', () => {
    setOperatorRoles(['cashier']);
    render(<SettingsPage />);
    expect(screen.queryByTestId('device-security-section')).toBeNull();
    expect(screen.queryByTestId('device-unbind-button')).toBeNull();
  });

  it('confirming unbind runs teardown then unbindDevice', () => {
    setOperatorRoles(['manager']);
    render(<SettingsPage />);
    fireEvent.click(screen.getByTestId('device-unbind-button'));
    fireEvent.click(screen.getByTestId('device-unbind-confirm'));
    expect(teardownSpy).toHaveBeenCalledTimes(1);
    expect(unbindSpy).toHaveBeenCalledTimes(1);
  });

  it('cancelling unbind does not run teardown or unbindDevice', () => {
    setOperatorRoles(['manager']);
    render(<SettingsPage />);
    fireEvent.click(screen.getByTestId('device-unbind-button'));
    // The modal is open — click cancel
    const cancelBtn = screen.getByTestId('device-unbind-cancel');
    fireEvent.click(cancelBtn);
    expect(teardownSpy).not.toHaveBeenCalled();
    expect(unbindSpy).not.toHaveBeenCalled();
  });
});
