/**
 * Sub-Spec B: SettingsPage Device & Security section — manager-gated.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, act } from '@testing-library/react';

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

// Auth store mock — unbindDevice is async (FIX 1)
const unbindSpy = vi.fn().mockResolvedValue(undefined);
vi.mock('@/stores/authStore', () => ({
  useAuthStore: <T,>(selector: (s: unknown) => T): T => {
    return selector({ serverUrl: 'http://localhost:8002', unbindDevice: () => unbindSpy() });
  },
}));

// Operator store — operator roles are controlled per test
let mockOperatorRoles: string[] | null = ['manager'];
let mockOperatorPermissions: string[] | undefined;

function setOperatorRoles(roles: string[] | null) {
  mockOperatorRoles = roles;
  mockOperatorPermissions = undefined;
}

/** Gate r2: an operator carrying explicit server permissions. */
function setOperator(roles: string[], permissions: string[]) {
  mockOperatorRoles = roles;
  mockOperatorPermissions = permissions;
}

vi.mock('@/stores/operatorStore', () => ({
  useOperatorStore: <T,>(selector: (s: unknown) => T): T => {
    return selector({
      operator: mockOperatorRoles !== null
        ? {
            id: 'op-1',
            name: 'Test Op',
            roles: mockOperatorRoles,
            permissions: mockOperatorPermissions,
          }
        : null,
    });
  },
}));

vi.mock('@/stores/settingsStore', () => ({
  useSettingsStore: <T,>(selector: (s: unknown) => T): T => {
    return selector({
      displayMode: 'liste', language: 'en', touchMode: false, fullscreen: false,
      setDisplayMode: vi.fn(), setLanguage: vi.fn(), setTouchMode: vi.fn(), setFullscreen: vi.fn(),
      inactivityTimeout: 300, lockAfterSale: false,
      setInactivityTimeout: vi.fn(), setLockAfterSale: vi.fn(),
      // Task 16 — optional-field toggles (default off).
      showSkuOnRows: false, showSkinTypeOnTiles: false,
      setShowSkuOnRows: vi.fn(), setShowSkinTypeOnTiles: vi.fn(),
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

const recordAuditEventSpy = vi.fn().mockResolvedValue(undefined);
vi.mock('@/lib/audit/recordAuditEvent', () => ({
  recordAuditEvent: (...args: unknown[]) => recordAuditEventSpy(...args),
}));

import { SettingsPage } from '../SettingsPage';

describe('SettingsPage – Device & Security (Sub-Spec B)', () => {
  beforeEach(() => {
    setOperatorRoles(['manager']);
    teardownSpy.mockReset();
    unbindSpy.mockReset();
    unbindSpy.mockResolvedValue(undefined);
  });

  // --- FIX 3: fail-closed for operator === null ---
  it('hides Device & Security section for operator === null', () => {
    setOperatorRoles(null);
    render(<SettingsPage />);
    expect(screen.queryByTestId('device-security-section')).toBeNull();
    expect(screen.queryByTestId('device-unbind-button')).toBeNull();
  });

  it('hides Device & Security section for roles === [] (operator present, no manager role)', () => {
    // Operator exists but has no roles — isManagerRole([]) === false → section hidden.
    setOperatorRoles([]);
    render(<SettingsPage />);
    expect(screen.queryByTestId('device-security-section')).toBeNull();
    expect(screen.queryByTestId('device-unbind-button')).toBeNull();
  });

  it('shows Device & Security section (incl. unbind) only for a manager operator', () => {
    setOperatorRoles(['manager']);
    render(<SettingsPage />);
    expect(screen.getByTestId('device-security-section')).toBeInTheDocument();
    expect(screen.getByTestId('device-unbind-button')).toBeInTheDocument();
  });

  it('shows Device & Security section for admin operator', () => {
    setOperatorRoles(['admin']);
    render(<SettingsPage />);
    expect(screen.getByTestId('device-security-section')).toBeInTheDocument();
    expect(screen.getByTestId('device-unbind-button')).toBeInTheDocument();
  });

  it('shows Device & Security section for owner operator', () => {
    setOperatorRoles(['owner']);
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

  /**
   * Gate r2 addendum (fiscal r2-1) — device unbind must NOT ride on a
   * reporting permission.
   *
   * `pos.view_reports` is seeded to `manager` AND `accountant`
   * (`RolesAndPermissionsSeeder.php:619`, `:837`), and `pinHolders` applies no
   * role filter, so an accountant with a till PIN is a PIN operator. Read
   * parity is deliberate; unbinding the terminal — which tears down the POS
   * session, clears the token and the stored terminal, and forces
   * re-provisioning mid-shift — is not. It keys on `pos.manage_terminals`.
   */
  it('hides Device & Security from an ACCOUNTANT operator who can read reports', () => {
    setOperator(['accountant'], ['pos.view_receipts', 'pos.view_reports']);
    render(<SettingsPage />);
    expect(screen.queryByTestId('device-security-section')).toBeNull();
    expect(screen.queryByTestId('device-unbind-button')).toBeNull();
  });

  it('shows Device & Security to an operator holding pos.manage_terminals', () => {
    setOperator(['manager'], ['pos.view_reports', 'pos.manage_terminals']);
    render(<SettingsPage />);
    expect(screen.getByTestId('device-security-section')).toBeInTheDocument();
    expect(screen.getByTestId('device-unbind-button')).toBeInTheDocument();
  });

  it('hides Device & Security from a manager-NAMED role without pos.manage_terminals', () => {
    setOperator(['manager'], ['pos.view_reports']);
    render(<SettingsPage />);
    expect(screen.queryByTestId('device-security-section')).toBeNull();
  });

  // --- FIX 3: change-terminal hidden for cashier ---
  it('"Change terminal" control hidden for a cashier operator', () => {
    // With a terminal set, the change-terminal button appears inside the manager-gated section.
    // For cashier it should not appear.
    setOperatorRoles(['cashier']);
    render(<SettingsPage />);
    // The change terminal button is inside the manager-gated section, so it won't appear.
    expect(screen.queryByText('terminal.changeTerminal')).toBeNull();
  });

  // --- FIX 2: confirm-time guard — modal hidden / actions blocked when isManager is false ---
  it('FIX 2: modal does not render at all for non-manager — even if showUnbindConfirm were true', () => {
    // For cashier, the unbind button is hidden (so modal can't be triggered),
    // and the modal itself is also gated on isManager.
    setOperatorRoles(['cashier']);
    render(<SettingsPage />);
    expect(screen.queryByTestId('modal')).toBeNull();
  });

  // --- FIX 2: role changes to cashier between open and confirm → actions NOT called ---
  it('FIX 2: if operator role becomes cashier after modal opens → teardown and unbind NOT called', async () => {
    // Render as manager first so the section and button appear.
    setOperatorRoles(['manager']);
    const { rerender } = render(<SettingsPage />);

    // Open the unbind confirm modal.
    fireEvent.click(screen.getByTestId('device-unbind-button'));
    expect(screen.getByTestId('modal')).toBeInTheDocument();

    // Simulate operator role change to cashier before confirming.
    setOperatorRoles(['cashier']);
    rerender(<SettingsPage />);

    // Modal is now gated off — it disappears because isManager is false.
    // So confirm button is gone — teardown/unbind must not be called.
    expect(screen.queryByTestId('device-unbind-confirm')).toBeNull();
    expect(teardownSpy).not.toHaveBeenCalled();
    expect(unbindSpy).not.toHaveBeenCalled();
  });

  // --- Normal confirm path ---
  it('confirming unbind calls teardownPosSessionStores BEFORE unbindDevice (order)', async () => {
    setOperatorRoles(['manager']);
    const callOrder: string[] = [];
    teardownSpy.mockImplementation(() => { callOrder.push('teardown'); });
    unbindSpy.mockImplementation(async () => { callOrder.push('unbind'); });

    render(<SettingsPage />);
    fireEvent.click(screen.getByTestId('device-unbind-button'));

    await act(async () => {
      fireEvent.click(screen.getByTestId('device-unbind-confirm'));
    });

    expect(teardownSpy).toHaveBeenCalledTimes(1);
    expect(unbindSpy).toHaveBeenCalledTimes(1);
    // teardown must come BEFORE unbind.
    expect(callOrder).toEqual(['teardown', 'unbind']);
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

  // --- Task 7 (audit): pos.terminal_change ---
  describe('pos.terminal_change audit emit', () => {
    let confirmSpy: ReturnType<typeof vi.fn>;

    beforeEach(() => {
      setOperatorRoles(['manager']);
      recordAuditEventSpy.mockClear();
      recordAuditEventSpy.mockResolvedValue(undefined);
      mockTerminalStoreState.reset.mockClear();
      mockTerminalStoreState.terminal = {
        id: 'terminal-42',
        name: 'Front Desk',
        location: { name: 'Main' },
      };
      confirmSpy = vi.fn().mockReturnValue(true);
      window.confirm = confirmSpy as unknown as typeof window.confirm;
    });

    afterEach(() => {
      mockTerminalStoreState.terminal = null;
    });

    it('emits pos.terminal_change with previous_terminal_id, captured before reset()', () => {
      render(<SettingsPage />);
      fireEvent.click(screen.getByText('terminal.changeTerminal'));

      expect(mockTerminalStoreState.reset).toHaveBeenCalledTimes(1);
      const call = recordAuditEventSpy.mock.calls.find(
        (c) => (c[0] as Record<string, unknown>).type === 'pos.terminal_change',
      );
      expect(call).toBeDefined();
      const arg = call![0] as Record<string, unknown>;
      expect(arg.aggregateType).toBe('Terminal');
      expect(arg.aggregateId).toBe('terminal-42');
      expect((arg.payload as Record<string, unknown>).previous_terminal_id).toBe('terminal-42');
    });

    it('does NOT emit when the confirm dialog is cancelled', () => {
      confirmSpy.mockReturnValue(false);
      render(<SettingsPage />);
      fireEvent.click(screen.getByText('terminal.changeTerminal'));

      expect(mockTerminalStoreState.reset).not.toHaveBeenCalled();
      expect(
        recordAuditEventSpy.mock.calls.some(
          (c) => (c[0] as Record<string, unknown>).type === 'pos.terminal_change',
        ),
      ).toBe(false);
    });

    it('does not break the change-terminal flow when the emit rejects', () => {
      recordAuditEventSpy.mockRejectedValue(new Error('audit down'));
      render(<SettingsPage />);
      expect(() => fireEvent.click(screen.getByText('terminal.changeTerminal'))).not.toThrow();
      expect(mockTerminalStoreState.reset).toHaveBeenCalledTimes(1);
    });
  });
});
