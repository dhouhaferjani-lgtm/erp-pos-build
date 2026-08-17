/**
 * Sub-Spec B: Header must NOT render a device-logout button for any role.
 * Switch and Lock must still be present.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';

const fraudApiMocks = vi.hoisted(() => ({
  fetchFraudSettings: vi.fn(),
  fetchAuthorizedManagers: vi.fn(),
}));
const fraudCacheMocks = vi.hoisted(() => ({
  upsertCompanyFraudSettings: vi.fn(),
  getCompanyFraudSettings: vi.fn(),
}));

vi.mock('react-i18next', () => ({
  initReactI18next: { type: '3rdParty', init: () => {} },
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}));

vi.mock('react-router-dom', () => ({
  useNavigate: () => vi.fn(),
}));

vi.mock('@tauri-apps/plugin-os', () => ({
  platform: vi.fn(() => 'macos'),
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
    LOGIN_TENANT_ID: 'login_tenant_id',
  },
}));

vi.mock('@/lib/echo', () => ({ disconnectEcho: vi.fn() }));
vi.mock('@/lib/device', () => ({ getDeviceId: vi.fn(() => 'device-test') }));
vi.mock('@/lib/api', () => ({
  apiGet: vi.fn(), apiPost: vi.fn(),
  getErrorMessage: (e: unknown) => (e instanceof Error ? e.message : 'unknown'),
  ApiRequestError: class extends Error { constructor(public status: number, msg: string, public code: string) { super(msg); } },
}));
vi.mock('@/lib/fullscreen', () => ({ applyFullscreen: vi.fn() }));
vi.mock('@/lib/printing', () => ({
  printReceipt: vi.fn(),
  getPrintSettingsFromStore: vi.fn().mockReturnValue({}),
  isTauriEnvironment: vi.fn().mockReturnValue(false),
  buildZReceiptData: vi.fn().mockReturnValue({}),
}));
vi.mock('@/lib/buildReceiptData', () => ({ buildReceiptLabels: vi.fn().mockReturnValue({}) }));
vi.mock('@/lib/scan/scanResolutionCache', () => ({ clearScanCache: vi.fn() }));
vi.mock('@/lib/decimal', () => ({
  bcadd: vi.fn(), bccomp: vi.fn(), bcsub: vi.fn(), bcformat: vi.fn().mockReturnValue('0'),
}));
vi.mock('@/lib/currency', () => ({ getCurrencyDecimals: vi.fn().mockReturnValue(2) }));
vi.mock('@/lib/db/repositories/terminalStateRepository', () => ({
  getTerminalState: vi.fn().mockResolvedValue(null),
  setManagerPinThrottle: vi.fn(),
  setManagerPinFailedAttempts: vi.fn(),
}));
vi.mock('@/api/reportApi', () => ({
  generateXReport: vi.fn(), generateZReport: vi.fn(),
}));
vi.mock('@/api/fraudSettingsApi', () => ({
  fetchFraudSettings: fraudApiMocks.fetchFraudSettings,
}));
vi.mock('@/api/managersApi', () => ({
  fetchAuthorizedManagers: fraudApiMocks.fetchAuthorizedManagers,
}));
vi.mock('@/lib/db', () => ({ getDatabase: vi.fn().mockResolvedValue({}) }));
vi.mock('@/lib/db/repositories/companyFraudSettingsCacheRepository', () => ({
  upsertCompanyFraudSettings: fraudCacheMocks.upsertCompanyFraudSettings,
  getCompanyFraudSettings: fraudCacheMocks.getCompanyFraudSettings,
}));
vi.mock('@/lib/db/repositories/operatorPinRepository', () => ({
  getAllOperators: vi.fn().mockResolvedValue([]),
}));
vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }));

// Store mocks
const mockLogout = vi.fn();
const mockLockScreen = vi.fn();
const mockClearOperator = vi.fn();

let mockOperator: { name: string; roles: string[]; id: string } | null = {
  name: 'Test Manager', roles: ['manager'], id: 'op-1',
};
let mockTerminal: {
  id: string;
  code: string;
  fiscal_schema_version: number;
  is_training_mode: boolean;
} | null = null;
let mockShift: {
  id: string;
  shift_number: number;
  opening_cash: string;
  opened_at: string;
  user: { id: string; name: string };
} | null = null;

vi.mock('@/stores/authStore', () => ({
  useAuthStore: <T,>(selector: (s: unknown) => T): T => {
    return selector({
      logout: mockLogout,
      user: { id: 'u-1', tenantId: 't-1' },
      companyId: 'co-1',
      companies: [],
      unbindDevice: vi.fn(),
    });
  },
}));

vi.mock('@/stores/operatorStore', () => ({
  useOperatorStore: <T,>(selector: (s: unknown) => T): T => {
    return selector({
      operator: mockOperator,
      lock: mockLockScreen,
      clearOperator: mockClearOperator,
    });
  },
}));

vi.mock('@/stores/terminalStore', () => ({
  useTerminalStore: <T,>(selector: (s: unknown) => T): T => {
    return selector({ terminal: mockTerminal, shift: mockShift, closeShift: vi.fn() });
  },
  fiscalShiftIdForReceipt: (shift: { id: string }) => shift.id.toLowerCase(),
}));

vi.mock('@/stores/settingsStore', () => ({
  useSettingsStore: <T,>(selector: (s: unknown) => T): T => {
    return selector({ fullscreen: false });
  },
}));

vi.mock('@/stores/connectivityStore', () => ({
  useConnectivityStore: <T,>(selector: (s: unknown) => T): T => {
    return selector({ isOnline: true });
  },
}));

vi.mock('@/stores/syncStore', () => ({
  useSyncStore: <T,>(selector: (s: unknown) => T): T => {
    return selector({ pendingReceiptCount: 0, isSyncing: false, triggerSync: vi.fn() });
  },
}));

vi.mock('@/stores/printerStore', () => ({
  usePrinterStore: <T,>(selector: (s: unknown) => T): T => {
    return selector({ printerConfig: null });
  },
}));

vi.mock('@/stores/cartStore', () => ({
  useCartStore: { getState: () => ({ clearCart: vi.fn() }) },
}));

vi.mock('@/stores/paymentStore', () => ({
  usePaymentStore: { getState: () => ({ clearVoucherTenders: vi.fn(), reset: vi.fn(), discardPendingSubmission: vi.fn() }) },
}));

vi.mock('@/stores/productStore', () => ({
  useProductStore: { getState: () => ({ reset: vi.fn() }) },
}));

vi.mock('@/stores/refundFlowStore', () => ({
  useRefundFlowStore: { getState: () => ({ clearAll: vi.fn() }) },
}));

vi.mock('@/stores/refundDraftStore', () => ({
  useRefundDraftStore: { getState: () => ({ clearDraftState: vi.fn() }) },
}));

vi.mock('@/components/atoms/SyncButton/SyncButton', () => ({
  SyncButton: () => null,
}));

vi.mock('@/components/pos/EndOfDayPreviewModal', () => ({
  EndOfDayPreviewModal: (props: {
    isOpen: boolean;
    onClose: () => void;
    cashCountPolicyResolved?: boolean;
    fraudSettings?: { require_blind_cash_count: boolean } | null;
  }) =>
    props.isOpen ? (
      <div>
        <div data-testid="eod-policy-state">
          {String(props.cashCountPolicyResolved)}:
          {props.fraudSettings == null
            ? 'none'
            : String(props.fraudSettings.require_blind_cash_count)}
        </div>
        <button type="button" onClick={props.onClose}>close-eod</button>
      </div>
    ) : null,
}));

vi.mock('@/components/pos/ReportsMenu', () => ({
  ReportsMenu: () => null,
}));

vi.mock('@/components/pos/XReportModal', () => ({
  XReportModal: () => null,
}));

vi.mock('@/components/organisms/CashDrawerModal', () => ({
  CashDrawerModal: () => null,
}));

import { Header } from '../Header';

describe('Header (Sub-Spec B)', () => {
  beforeEach(() => {
    mockOperator = { name: 'Test Manager', roles: ['manager'], id: 'op-1' };
    mockTerminal = null;
    mockShift = null;
    fraudApiMocks.fetchFraudSettings.mockReset();
    fraudApiMocks.fetchFraudSettings.mockResolvedValue({
      cashVarianceOverSoft: '5.00',
      cashVarianceOverHard: '20.00',
      cashVarianceUnderSoft: '5.00',
      cashVarianceUnderHard: '20.00',
      requireBlindCashCount: true,
      requireManagerPinAboveHard: true,
      cashVarianceEmailSeverity: 'critical',
    });
    fraudApiMocks.fetchAuthorizedManagers.mockReset();
    fraudApiMocks.fetchAuthorizedManagers.mockResolvedValue([]);
    fraudCacheMocks.upsertCompanyFraudSettings.mockReset();
    fraudCacheMocks.upsertCompanyFraudSettings.mockResolvedValue(undefined);
    fraudCacheMocks.getCompanyFraudSettings.mockReset();
    fraudCacheMocks.getCompanyFraudSettings.mockResolvedValue(null);
  });

  it('renders no device-logout button for a manager operator', () => {
    render(<Header />);
    expect(screen.queryByTitle('header.logout')).toBeNull();
  });

  it('renders no device-logout button for a cashier operator', () => {
    mockOperator = { name: 'Cashier', roles: ['cashier'], id: 'op-2' };
    render(<Header />);
    expect(screen.queryByTitle('header.logout')).toBeNull();
  });

  it('renders no device-logout button for an admin operator', () => {
    mockOperator = { name: 'Admin', roles: ['admin'], id: 'op-3' };
    render(<Header />);
    expect(screen.queryByTitle('header.logout')).toBeNull();
  });

  it('renders no device-logout button for an owner operator', () => {
    mockOperator = { name: 'Owner', roles: ['owner'], id: 'op-4' };
    render(<Header />);
    expect(screen.queryByTitle('header.logout')).toBeNull();
  });

  it('renders no device-logout button when operator is null', () => {
    mockOperator = null;
    render(<Header />);
    expect(screen.queryByTitle('header.logout')).toBeNull();
  });

  it('still renders the Switch button', () => {
    render(<Header />);
    expect(screen.getByTitle('header.switch')).toBeInTheDocument();
  });

  it('still renders the Lock button', () => {
    render(<Header />);
    expect(screen.getByTitle('header.lock')).toBeInTheDocument();
  });

  it('keeps the EOD policy unresolved and empty until the refresh completes', async () => {
    mockTerminal = {
      id: 'terminal-1',
      code: 'T1',
      fiscal_schema_version: 3,
      is_training_mode: false,
    };
    mockShift = {
      id: 'shift-1',
      shift_number: 1,
      opening_cash: '100.00',
      opened_at: '2026-08-17T08:00:00Z',
      user: { id: 'op-1', name: 'Test Manager' },
    };

    let resolveSettings: ((value: {
      cashVarianceOverSoft: string;
      cashVarianceOverHard: string;
      cashVarianceUnderSoft: string;
      cashVarianceUnderHard: string;
      requireBlindCashCount: boolean;
      requireManagerPinAboveHard: boolean;
      cashVarianceEmailSeverity: string;
    }) => void) | undefined;
    fraudApiMocks.fetchFraudSettings.mockImplementationOnce(
      () =>
        new Promise((resolve) => {
          resolveSettings = resolve;
        }),
    );

    render(<Header />);
    fireEvent.click(screen.getByTitle('shift.opening'));

    expect(screen.getByTestId('eod-policy-state')).toHaveTextContent('false:none');

    resolveSettings?.({
      cashVarianceOverSoft: '5.00',
      cashVarianceOverHard: '20.00',
      cashVarianceUnderSoft: '5.00',
      cashVarianceUnderHard: '20.00',
      requireBlindCashCount: true,
      requireManagerPinAboveHard: true,
      cashVarianceEmailSeverity: 'critical',
    });

    await waitFor(() => {
      expect(screen.getByTestId('eod-policy-state')).toHaveTextContent('true:true');
    });
  });

  it('clears a stale non-blind policy before a later blind-policy refresh', async () => {
    mockTerminal = {
      id: 'terminal-1',
      code: 'T1',
      fiscal_schema_version: 3,
      is_training_mode: false,
    };
    mockShift = {
      id: 'shift-1',
      shift_number: 1,
      opening_cash: '100.00',
      opened_at: '2026-08-17T08:00:00Z',
      user: { id: 'op-1', name: 'Test Manager' },
    };
    fraudApiMocks.fetchFraudSettings.mockResolvedValueOnce({
      cashVarianceOverSoft: '5.00',
      cashVarianceOverHard: '20.00',
      cashVarianceUnderSoft: '5.00',
      cashVarianceUnderHard: '20.00',
      requireBlindCashCount: false,
      requireManagerPinAboveHard: true,
      cashVarianceEmailSeverity: 'critical',
    });

    render(<Header />);
    fireEvent.click(screen.getByTitle('shift.opening'));
    await waitFor(() => {
      expect(screen.getByTestId('eod-policy-state')).toHaveTextContent('true:false');
    });
    fireEvent.click(screen.getByText('close-eod'));

    let resolveSettings: ((value: {
      cashVarianceOverSoft: string;
      cashVarianceOverHard: string;
      cashVarianceUnderSoft: string;
      cashVarianceUnderHard: string;
      requireBlindCashCount: boolean;
      requireManagerPinAboveHard: boolean;
      cashVarianceEmailSeverity: string;
    }) => void) | undefined;
    fraudApiMocks.fetchFraudSettings.mockImplementationOnce(
      () =>
        new Promise((resolve) => {
          resolveSettings = resolve;
        }),
    );

    fireEvent.click(screen.getByTitle('shift.opening'));
    expect(screen.getByTestId('eod-policy-state')).toHaveTextContent('false:none');

    resolveSettings?.({
      cashVarianceOverSoft: '5.00',
      cashVarianceOverHard: '20.00',
      cashVarianceUnderSoft: '5.00',
      cashVarianceUnderHard: '20.00',
      requireBlindCashCount: true,
      requireManagerPinAboveHard: true,
      cashVarianceEmailSeverity: 'critical',
    });
    await waitFor(() => {
      expect(screen.getByTestId('eod-policy-state')).toHaveTextContent('true:true');
    });
  });

  it('reports the EOD policy resolved but unavailable when online and cache reads fail', async () => {
    mockTerminal = {
      id: 'terminal-1',
      code: 'T1',
      fiscal_schema_version: 3,
      is_training_mode: false,
    };
    mockShift = {
      id: 'shift-1',
      shift_number: 1,
      opening_cash: '100.00',
      opened_at: '2026-08-17T08:00:00Z',
      user: { id: 'op-1', name: 'Test Manager' },
    };
    fraudApiMocks.fetchFraudSettings.mockRejectedValueOnce(new Error('offline'));
    fraudCacheMocks.getCompanyFraudSettings.mockResolvedValueOnce(null);

    render(<Header />);
    fireEvent.click(screen.getByTitle('shift.opening'));

    await waitFor(() => {
      expect(screen.getByTestId('eod-policy-state')).toHaveTextContent('true:none');
    });
    expect(fraudCacheMocks.getCompanyFraudSettings).toHaveBeenCalledWith(
      expect.anything(),
      'co-1',
    );
  });

  it('re-arms policy loading immediately when the terminal record changes', async () => {
    mockTerminal = {
      id: 'terminal-1',
      code: 'T1',
      fiscal_schema_version: 3,
      is_training_mode: false,
    };
    mockShift = {
      id: 'shift-1',
      shift_number: 1,
      opening_cash: '100.00',
      opened_at: '2026-08-17T08:00:00Z',
      user: { id: 'op-1', name: 'Test Manager' },
    };

    const view = render(<Header />);
    fireEvent.click(screen.getByTitle('shift.opening'));
    await waitFor(() => {
      expect(screen.getByTestId('eod-policy-state')).toHaveTextContent('true:true');
    });

    fraudApiMocks.fetchFraudSettings.mockImplementationOnce(
      () => new Promise(() => {}),
    );
    mockTerminal = { ...mockTerminal, code: 'T1-refreshed' };
    view.rerender(<Header />);

    expect(screen.getByTestId('eod-policy-state')).toHaveTextContent('false:true');
  });
});
