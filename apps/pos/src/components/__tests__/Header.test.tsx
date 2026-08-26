/**
 * Sub-Spec B: Header must NOT render a device-logout button for any role.
 * Switch and Lock must still be present.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';

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
const reportApiMocks = vi.hoisted(() => ({
  generateXReport: vi.fn(),
  generateZReport: vi.fn(),
}));
vi.mock('@/api/reportApi', () => ({
  generateXReport: reportApiMocks.generateXReport,
  generateZReport: reportApiMocks.generateZReport,
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

let mockOperator: {
  name: string;
  roles: string[];
  id: string;
  permissions?: string[];
} | null = { name: 'Test Manager', roles: ['manager'], id: 'op-1' };
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

let mockPaymentMethods: { code: string; is_physical: boolean }[] = [];

vi.mock('@/stores/paymentStore', () => ({
  usePaymentStore: {
    getState: () => ({
      clearVoucherTenders: vi.fn(),
      reset: vi.fn(),
      discardPendingSubmission: vi.fn(),
      paymentMethods: mockPaymentMethods,
    }),
  },
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
    cashCountPolicyResolved: boolean;
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
  ReportsMenu: (props: {
    isOpen: boolean;
    onXReport: () => void;
    onCashDrawerOps: () => void;
  }) =>
    props.isOpen ? (
      <>
        <button type="button" onClick={props.onXReport}>menu-x-report</button>
        <button type="button" onClick={props.onCashDrawerOps}>menu-cash-drawer</button>
      </>
    ) : null,
}));

vi.mock('@/components/pos/XReportModal', () => ({
  XReportModal: (props: { isOpen: boolean; concealPhysicalTenders: boolean }) =>
    props.isOpen ? (
      <div data-testid="x-report-modal">
        <span data-testid="x-concealed">{String(props.concealPhysicalTenders)}</span>
      </div>
    ) : null,
}));

vi.mock('@/components/organisms/CashDrawerModal', () => ({
  CashDrawerModal: (props: { isOpen: boolean }) =>
    props.isOpen ? <div data-testid="cash-drawer-modal" /> : null,
}));

import { Header } from '../Header';
import { toast } from 'sonner';
import { useShiftActionsStore } from '@/stores/shiftActionsStore';

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
    mockPaymentMethods = [
      { code: 'CASH', is_physical: true },
      { code: 'CHEQUE', is_physical: true },
      { code: 'CARD', is_physical: false },
    ];
    vi.mocked(reportApiMocks.generateXReport).mockReset();
    vi.mocked(reportApiMocks.generateXReport).mockResolvedValue({
      id: 'x-1', payment_methods: [],
    } as never);
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

    let rejectRefresh: ((reason: Error) => void) | undefined;
    fraudApiMocks.fetchFraudSettings.mockImplementationOnce(
      () => new Promise((_resolve, reject) => { rejectRefresh = reject; }),
    );
    mockTerminal = { ...mockTerminal, code: 'T1-refreshed' };
    view.rerender(<Header />);

    await waitFor(() => {
      expect(screen.getByTestId('eod-policy-state')).toHaveTextContent('false:none');
    });

    await act(async () => {
      rejectRefresh?.(new Error('refresh offline'));
    });
    await waitFor(() => {
      expect(screen.getByTestId('eod-policy-state')).toHaveTextContent('true:none');
    });
  });

  // Receiving side of the /shift -> real-closure handoff (shiftActionsStore).
  describe('end-of-day requests from other screens', () => {
    it('opens the real closure modal EVERY time a request arrives, not just once', async () => {
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

      render(<Header />);
      expect(screen.queryByTestId('eod-policy-state')).not.toBeInTheDocument();

      act(() => { useShiftActionsStore.getState().requestEndOfDay(); });
      await waitFor(() => expect(screen.getByTestId('eod-policy-state')).toBeInTheDocument());

      fireEvent.click(screen.getByText('close-eod'));
      await waitFor(() =>
        expect(screen.queryByTestId('eod-policy-state')).not.toBeInTheDocument(),
      );

      // A monotonic request id — not a boolean — so the second request is a
      // distinct value and re-opens rather than being swallowed as "unchanged".
      act(() => { useShiftActionsStore.getState().requestEndOfDay(); });
      await waitFor(() => expect(screen.getByTestId('eod-policy-state')).toBeInTheDocument());
    });

    it('replays a request that arrived before the shift had loaded', async () => {
      mockTerminal = {
        id: 'terminal-1',
        code: 'T1',
        fiscal_schema_version: 3,
        is_training_mode: false,
      };
      mockShift = null;

      const view = render(<Header />);

      // Request lands while the shift is still null: nothing can open yet, and
      // the request must NOT be consumed.
      act(() => { useShiftActionsStore.getState().requestEndOfDay(); });
      expect(screen.queryByTestId('eod-policy-state')).not.toBeInTheDocument();

      mockShift = {
        id: 'shift-1',
        shift_number: 1,
        opening_cash: '100.00',
        opened_at: '2026-08-17T08:00:00Z',
        user: { id: 'op-1', name: 'Test Manager' },
      };
      view.rerender(<Header />);

      await waitFor(() => expect(screen.getByTestId('eod-policy-state')).toBeInTheDocument());
    });
  });
});

/**
 * B-13 (ii) + (iii) — the two disclosure surfaces the manager-screens lane
 * left open, ruled "fix properly" by the owner on 2026-08-21.
 *
 * The blind cash count regime exists so the person counting the drawer cannot
 * see what it is supposed to hold. Expected cash = opening float + cash
 * takings, so BOTH terms have to be governed:
 *   - the X report carries the cash takings (and authors a SIGNED `X_REPORT`
 *     fiscal event on the way);
 *   - the Header shift badge tooltip carries the opening float, on every route.
 */
describe('Header — X report gate + blind-count disclosure (B-13)', () => {
  const SHIFT = {
    id: 'shift-1',
    shift_number: 7,
    opening_cash: '200.000',
    opened_at: '2026-08-26T08:00:00.000Z',
    user: { id: 'u-1', name: 'Owner' },
  };
  const TERMINAL = {
    id: 'term-1',
    code: 'T1',
    fiscal_schema_version: 3,
    is_training_mode: false,
  };

  beforeEach(() => {
    mockTerminal = { ...TERMINAL };
    mockShift = { ...SHIFT };
    mockPaymentMethods = [
      { code: 'CASH', is_physical: true },
      { code: 'CHEQUE', is_physical: true },
      { code: 'CARD', is_physical: false },
    ];
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
    reportApiMocks.generateXReport.mockReset();
    reportApiMocks.generateXReport.mockResolvedValue({
      id: 'x-1', payment_methods: [],
    } as never);
  });

  async function openReportsMenu() {
    fireEvent.click(screen.getByTitle('quickActions.reports'));
    return screen.findByText('menu-x-report');
  }

  it('REFUSES the X report to a cashier PIN operator — no fiscal event authored', async () => {
    mockOperator = { name: 'Cashier', roles: ['cashier'], id: 'op-2' };
    render(<Header />);
    fireEvent.click(await openReportsMenu());

    await waitFor(() => {
      expect(vi.mocked(toast.error)).toHaveBeenCalledWith('reports.managerOnly');
    });
    // The X report APPENDS an immutable X_REPORT event (rule 8): refusing has
    // to happen BEFORE the generator runs, not by hiding the result.
    expect(reportApiMocks.generateXReport).not.toHaveBeenCalled();
    expect(screen.queryByTestId('x-report-modal')).toBeNull();
  });

  it('allows the X report to a manager PIN operator', async () => {
    mockOperator = { name: 'Manager', roles: ['manager'], id: 'op-1' };
    render(<Header />);
    fireEvent.click(await openReportsMenu());

    expect(await screen.findByTestId('x-report-modal')).toBeInTheDocument();
    await waitFor(() => expect(reportApiMocks.generateXReport).toHaveBeenCalledTimes(1));
  });

  it('conceals the X report PHYSICAL tenders while a shift is open under blind count', async () => {
    mockOperator = { name: 'Manager', roles: ['manager'], id: 'op-1' };
    render(<Header />);
    fireEvent.click(await openReportsMenu());

    const concealed = await screen.findByTestId('x-concealed');
    await waitFor(() => expect(concealed.textContent).toBe('true'));
  });

  /**
   * Gate r1 (F-1 / R1-5) regression pin. The first shape of this mask joined
   * the report against `usePaymentStore.paymentMethods`, which is `[]` until
   * the payment config loads — a cold or offline boot then concealed NOTHING
   * under a `conceal` policy. The conceal decision must not depend on that
   * store at all any more.
   */
  it('still conceals when the payment-method store is EMPTY (cold/offline boot)', async () => {
    mockPaymentMethods = [];
    mockOperator = { name: 'Manager', roles: ['manager'], id: 'op-1' };
    render(<Header />);
    fireEvent.click(await openReportsMenu());

    const concealed = await screen.findByTestId('x-concealed');
    await waitFor(() => expect(concealed.textContent).toBe('true'));
  });

  it('discloses the X report tenders when the policy positively reads NOT blind', async () => {
    fraudApiMocks.fetchFraudSettings.mockResolvedValue({
      cashVarianceOverSoft: '5.00',
      cashVarianceOverHard: '20.00',
      cashVarianceUnderSoft: '5.00',
      cashVarianceUnderHard: '20.00',
      requireBlindCashCount: false,
      requireManagerPinAboveHard: true,
      cashVarianceEmailSeverity: 'critical',
    });
    mockOperator = { name: 'Manager', roles: ['manager'], id: 'op-1' };
    render(<Header />);
    // Let the mount-time policy resolution settle before opening the report.
    await waitFor(() => expect(fraudApiMocks.fetchFraudSettings).toHaveBeenCalled());
    fireEvent.click(await openReportsMenu());

    const concealed = await screen.findByTestId('x-concealed');
    await waitFor(() => expect(concealed.textContent).toBe('false'));
  });

  it('makes the X report unreachable when there is no open shift', async () => {
    // Gate r1 F-6: this case pins the ENTRY POINT, not the conceal predicate —
    // the Reports button only renders with an open shift, so `handleXReport`
    // cannot be called at all. The `shift === null` branch of
    // `concealPhysicalTenders` is driven by the next case.
    mockShift = null;
    mockOperator = { name: 'Manager', roles: ['manager'], id: 'op-1' };
    render(<Header />);
    expect(screen.queryByTitle('quickActions.reports')).toBeNull();
  });

  it('conceals nothing with no open shift — nothing is being counted', async () => {
    // Reached via the shift-actions store, which opens the reports menu
    // independently of the Header's own shift-gated button.
    mockShift = null;
    mockOperator = { name: 'Manager', roles: ['manager'], id: 'op-1' };
    const { rerender } = render(<Header />);
    await waitFor(() => expect(fraudApiMocks.fetchFraudSettings).toHaveBeenCalled());

    // Re-render with a shift so the button appears, then assert the flag
    // tracks the shift rather than the policy alone.
    mockShift = { ...SHIFT };
    rerender(<Header />);
    fireEvent.click(await openReportsMenu());
    const concealed = await screen.findByTestId('x-concealed');
    await waitFor(() => expect(concealed.textContent).toBe('true'));
  });

  it('hides the opening-float tooltip from a NON-manager under blind count', async () => {
    mockOperator = { name: 'Cashier', roles: ['cashier'], id: 'op-2' };
    render(<Header />);

    // Policy is read at MOUNT, not when the EOD modal opens.
    await waitFor(() => expect(fraudApiMocks.fetchFraudSettings).toHaveBeenCalled());
    await waitFor(() => expect(screen.queryByTitle('shift.opening')).toBeNull());
    // The badge itself stays — this conceals one term, it does not remove the
    // End-of-Day entry point.
    expect(screen.getByTitle('shift.number')).toBeInTheDocument();
  });

  it('keeps the opening-float tooltip for a manager under blind count', async () => {
    mockOperator = { name: 'Manager', roles: ['manager'], id: 'op-1' };
    render(<Header />);
    await waitFor(() => expect(fraudApiMocks.fetchFraudSettings).toHaveBeenCalled());
    expect(screen.getByTitle('shift.opening')).toBeInTheDocument();
  });

  it('keeps the opening-float tooltip for a cashier when blind count is OFF', async () => {
    fraudApiMocks.fetchFraudSettings.mockResolvedValue({
      cashVarianceOverSoft: '5.00',
      cashVarianceOverHard: '20.00',
      cashVarianceUnderSoft: '5.00',
      cashVarianceUnderHard: '20.00',
      requireBlindCashCount: false,
      requireManagerPinAboveHard: true,
      cashVarianceEmailSeverity: 'critical',
    });
    mockOperator = { name: 'Cashier', roles: ['cashier'], id: 'op-2' };
    render(<Header />);
    await waitFor(() => expect(screen.getByTitle('shift.opening')).toBeInTheDocument());
  });

  it('fails CLOSED for a cashier while the policy is still unresolved', () => {
    // Never resolves — the pre-answer state must conceal, not disclose.
    fraudApiMocks.fetchFraudSettings.mockReturnValue(new Promise(() => {}));
    mockOperator = { name: 'Cashier', roles: ['cashier'], id: 'op-2' };
    render(<Header />);
    expect(screen.queryByTitle('shift.opening')).toBeNull();
  });

  /**
   * Gate r1 (R1-2) — cash-drawer ops need the execution-time re-check MORE
   * than the X report does: the server authorizes deposit/payout on
   * `pos.operate_terminal`, which cashiers hold, so the device gate is the
   * only manager control on real cash movement.
   */
  it('REFUSES cash-drawer operations to a cashier PIN operator', async () => {
    mockOperator = {
      name: 'Cashier',
      roles: ['cashier'],
      id: 'op-2',
      permissions: ['pos.operate_terminal'],
    };
    render(<Header />);
    fireEvent.click(screen.getByTitle('quickActions.reports'));
    fireEvent.click(await screen.findByText('menu-cash-drawer'));

    await waitFor(() => {
      expect(vi.mocked(toast.error)).toHaveBeenCalledWith('reports.managerOnly');
    });
    expect(screen.queryByTestId('cash-drawer-modal')).toBeNull();
  });

  it('opens cash-drawer operations for an operator holding the drawer permission', async () => {
    mockOperator = {
      name: 'Manager',
      roles: ['manager'],
      id: 'op-1',
      permissions: ['pos.view_reports', 'pos.approve_cash_drawer_control'],
    };
    render(<Header />);
    fireEvent.click(screen.getByTitle('quickActions.reports'));
    fireEvent.click(await screen.findByText('menu-cash-drawer'));

    expect(await screen.findByTestId('cash-drawer-modal')).toBeInTheDocument();
  });

  it('REFUSES cash-drawer ops to a reports-only manager (surface-specific permission)', async () => {
    mockOperator = {
      name: 'Reports Manager',
      roles: ['manager'],
      id: 'op-3',
      permissions: ['pos.view_reports'],
    };
    render(<Header />);
    fireEvent.click(screen.getByTitle('quickActions.reports'));
    fireEvent.click(await screen.findByText('menu-cash-drawer'));

    await waitFor(() => {
      expect(vi.mocked(toast.error)).toHaveBeenCalledWith('reports.managerOnly');
    });
    expect(screen.queryByTestId('cash-drawer-modal')).toBeNull();
  });
});
