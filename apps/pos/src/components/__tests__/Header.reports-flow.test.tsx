import { beforeEach, describe, expect, it, vi } from 'vitest';
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter, useLocation } from 'react-router-dom';
import { Header } from '../Header';
import { useAuthStore } from '@/stores/authStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { useTerminalStore, type Shift, type Terminal } from '@/stores/terminalStore';
import { generateZReport } from '@/api/reportApi';
import { useShiftActionsStore } from '@/stores/shiftActionsStore';

const mocks = vi.hoisted(() => ({
  preview: vi.fn(),
  generateZReport: vi.fn(),
  generateXReport: vi.fn(),
  fetchFraudSettings: vi.fn(),
  fetchAuthorizedManagers: vi.fn(),
  printReceipt: vi.fn(),
  verifyScopedManagerPin: vi.fn(),
  toastError: vi.fn(),
  tauri: false,
  // Switchable so a test can press Print with NO printer configured — the
  // real-world sequence that used to burn the first (non-dispatched) print.
  printerConfig: null as { connection_type: 'network'; address: string; name: string } | null,
}));

vi.mock('react-i18next', () => ({
  initReactI18next: { type: '3rdParty', init: () => {} },
  useTranslation: () => ({ t: (key: string) => key }),
}));
vi.mock('@tauri-apps/plugin-os', () => ({ platform: () => 'macos' }));
vi.mock('@/lib/storage', () => ({
  getStoredValue: vi.fn().mockResolvedValue(null),
  setStoredValue: vi.fn().mockResolvedValue(undefined),
  removeStoredValue: vi.fn().mockResolvedValue(undefined),
  StorageKeys: { SHIFT: 'shift' },
}));
vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn().mockResolvedValue({
    select: vi.fn().mockResolvedValue([]),
    execute: vi.fn().mockResolvedValue({ rowsAffected: 1 }),
  }),
}));
vi.mock('@/lib/offline/endOfDayPreview', () => ({ buildEndOfDayPreview: mocks.preview }));
vi.mock('@/api/reportApi', () => ({
  generateZReport: mocks.generateZReport,
  generateXReport: mocks.generateXReport,
  ReauthenticationRequiredError: class extends Error {},
}));
vi.mock('@/api/fraudSettingsApi', () => ({
  fetchFraudSettings: mocks.fetchFraudSettings,
  refreshFraudSettingsCache: vi.fn(),
}));
vi.mock('@/api/managersApi', () => ({ fetchAuthorizedManagers: mocks.fetchAuthorizedManagers }));
vi.mock('@/lib/printing', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/printing')>();
  return { ...actual, printReceipt: mocks.printReceipt, isTauriEnvironment: () => mocks.tauri };
});
// PrinterConfig is { connection_type, address, name } (lib/printing.ts:298) and
// getPrintSettingsFromStore() reads `settings` off the same store, so the stub
// must carry both slices.
vi.mock('sonner', () => ({ toast: { error: mocks.toastError, success: vi.fn() } }));
vi.mock('@/stores/printerStore', () => ({
  usePrinterStore: {
    getState: () => ({
      printerConfig: mocks.printerConfig,
      settings: {
        paperWidth: '80mm' as const,
        cutMode: 'partial' as const,
        copies: 1,
        footerText: '',
        encoding: 'cp1252' as const,
      },
    }),
  },
}));
vi.mock('@/lib/operatorApproval/scopedManagerPin', () => ({
  verifyScopedManagerPin: mocks.verifyScopedManagerPin,
}));
vi.mock('@/api/toleranceApi', () => ({ fetchToleranceReceiptsForShift: vi.fn().mockResolvedValue([]) }));

const terminal: Terminal = {
  id: 'terminal-1', code: 'T1', name: 'Disposable test terminal', type: 'fixed',
  is_active: true, fiscal_schema_version: 3, is_training_mode: false, hardware_identifier: null,
  location: { id: 'loc-1', code: 'L1', name: 'Test location', tax_id: null, vat_number: null, legal_identifiers: null },
};
const shift: Shift = {
  id: '00000000-0000-4000-8000-000000000001', terminal_id: 'terminal-1', shift_number: 1, status: 'OPEN',
  opening_cash: '100.00', opened_at: '2026-09-16T08:00:00Z',
  user: { id: 'cashier-1', name: 'Test cashier' },
};
const preview = {
  sales_count: 1, gross_sales: '30.00', net_sales: '25.00', tax_amount: '5.00',
  opening_cash: '100.00', expected_cash: '130.00', variance: null,
  refunds_count: 0, refunds_amount: '0.00', refund_vat_amount: '0.00',
  tolerance_summary: null, cash_rounding_summary: null, tolerance_auto_accept_count: 0,
  vat_breakdown: [{ tax_rate: 20, net_amount: '25.00', vat_amount: '5.00', gross_amount: '30.00' }],
  payment_methods: [{ payment_method_id: 'pm-cash', payment_method_code: 'CASH', payment_method_name: 'Cash', is_physical: true, total_amount: '30.00', transaction_count: 1 }],
};

function LocationProbe() {
  return <output aria-label="Current route">{useLocation().pathname}</output>;
}

function renderHeader() {
  return render(<MemoryRouter><Header /><LocationProbe /></MemoryRouter>);
}

async function countCash() {
  fireEvent.click(await screen.findByTestId('tender-actual-input-CASH'));
  await Promise.resolve();
  const panel = screen.getByTestId('cash-count-numpad-panel');
  for (const digit of '130') {
    fireEvent.click(within(panel).getByTestId(`numpad-digit-${digit}`));
    await Promise.resolve();
  }
}

async function confirmCountedShift() {
  fireEvent.click(screen.getByRole('button', { name: 'shift.number' }));
  const confirm = await screen.findByTestId('end-of-day-confirm-button');
  expect(confirm).toBeDisabled();
  await act(countCash);
  fireEvent.click(screen.getByTestId('commit-counts-button'));
  await waitFor(() => expect(confirm).toBeEnabled());
  fireEvent.click(confirm);
}

describe('Header report and close flow', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mocks.tauri = false;
    mocks.preview.mockResolvedValue(preview);
    mocks.fetchAuthorizedManagers.mockResolvedValue([]);
    mocks.printReceipt.mockResolvedValue(undefined);
    mocks.printerConfig = {
      connection_type: 'network',
      address: '127.0.0.1:9100',
      name: 'Test printer',
    };
    mocks.verifyScopedManagerPin.mockReset();
    mocks.generateZReport.mockResolvedValue({ formatted_z_number: 'Z0001', was_reused: false, cash_counts: [] });
    mocks.fetchFraudSettings.mockResolvedValue({
      cashVarianceOverSoft: '5.00', cashVarianceOverHard: '20.00',
      cashVarianceUnderSoft: '5.00', cashVarianceUnderHard: '20.00',
      requireBlindCashCount: true, requireManagerPinAboveHard: true,
      cashVarianceEmailSeverity: 'critical',
    });
    useAuthStore.setState({
      companyId: 'company-1', isAuthenticated: true,
      user: { id: 'owner-1', name: 'Owner', email: 'owner@example.test', tenantId: 'tenant-1', phone: null, status: 'active', locale: 'en', timezone: 'UTC', roles: ['admin'], permissions: [], emailVerified: true },
      companies: [{ id: 'company-1', name: 'Test company', legalName: 'Test company', countryCode: 'FR', currency: 'EUR', locale: 'en', timezone: 'UTC' }],
    });
    useOperatorStore.setState({
      operator: { id: 'cashier-1', name: 'Test cashier', email: 'cashier@example.test', roles: ['cashier'], permissions: ['pos.view_receipts', 'pos.manage_shifts', 'pos.generate_z_report'], can_discount: false, max_discount_percent: null },
      isLocked: false,
    });
    useTerminalStore.setState({ terminal, shift, isLoading: false });
  });

  it('opens the cashier report icon menu and sales without closing or logging out', async () => {
    renderHeader();
    fireEvent.click(screen.getByRole('button', { name: 'quickActions.reports' }));
    expect(screen.getByRole('button', { name: 'reports.todaySales' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'reports.xReport' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'reports.zList.title' })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'reports.todaySales' }));
    expect(screen.getByRole('status', { name: 'Current route' })).toHaveTextContent('/sales');
    expect(useTerminalStore.getState().shift?.id).toBe('00000000-0000-4000-8000-000000000001');
    expect(useOperatorStore.getState().operator?.id).toBe('cashier-1');
    expect(useAuthStore.getState().isAuthenticated).toBe(true);
    expect(generateZReport).not.toHaveBeenCalled();
  });

  it('keeps the generated Z confirmation visible after the counted shift closes', async () => {
    renderHeader();
    expect(generateZReport).not.toHaveBeenCalled();
    await confirmCountedShift();

    await waitFor(() => expect(useTerminalStore.getState().shift).toBeNull());
    expect(await screen.findByText('Z0001')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'reports.endOfDay.successTitle' })).toBeInTheDocument();
    expect(useOperatorStore.getState().operator?.id).toBe('cashier-1');
    expect(useAuthStore.getState().isAuthenticated).toBe(true);
    expect(generateZReport).toHaveBeenCalledTimes(1);
    fireEvent.click(screen.getByRole('button', { name: 'reports.endOfDay.done' }));
    expect(screen.queryByText('Z0001')).not.toBeInTheDocument();
    expect(screen.getByText('header.noShift')).toBeInTheDocument();
  });

  it('cancels the count without closing the shift or generating a Z', async () => {
    renderHeader();
    fireEvent.click(screen.getByRole('button', { name: 'shift.number' }));
    await screen.findByTestId('end-of-day-confirm-button');
    fireEvent.click(screen.getByRole('button', { name: 'reports.endOfDay.cancel' }));
    expect(screen.queryByTestId('end-of-day-confirm-button')).not.toBeInTheDocument();
    expect(useTerminalStore.getState().shift?.id).toBe(shift.id);
    expect(generateZReport).not.toHaveBeenCalled();
  });

  it('keeps the shift open and displays the error when Z generation fails', async () => {
    mocks.generateZReport.mockRejectedValueOnce(new Error('Local fiscal write failed'));
    renderHeader();
    await confirmCountedShift();
    expect(await screen.findByText('Local fiscal write failed')).toBeInTheDocument();
    expect(useTerminalStore.getState().shift?.id).toBe(shift.id);
    expect(screen.queryByText('Z0001')).not.toBeInTheDocument();
  });

  it('discards an unfinished count when a different shift becomes active', async () => {
    renderHeader();
    fireEvent.click(screen.getByRole('button', { name: 'shift.number' }));
    await screen.findByTestId('end-of-day-confirm-button');
    await act(countCash);
    fireEvent.click(screen.getByTestId('commit-counts-button'));
    await waitFor(() => expect(screen.getByTestId('end-of-day-confirm-button')).toBeEnabled());

    await act(async () => {
      useTerminalStore.setState({ shift: { ...shift, id: '00000000-0000-4000-8000-000000000002' } });
    });

    expect(screen.queryByTestId('end-of-day-confirm-button')).not.toBeInTheDocument();
    expect(generateZReport).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'shift.number' }));
    expect(await screen.findByTestId('end-of-day-confirm-button')).toBeDisabled();
    expect(await screen.findByTestId('commit-counts-button')).toBeDisabled();
  });

  it('serves a /shift end-of-day request that arrived before the terminal was loaded', async () => {
    useTerminalStore.setState({ terminal: null, shift });
    renderHeader();
    await act(async () => {
      useShiftActionsStore.getState().requestEndOfDay();
    });
    expect(screen.queryByTestId('end-of-day-confirm-button')).not.toBeInTheDocument();
    await act(async () => {
      useTerminalStore.setState({ terminal });
    });
    expect(await screen.findByTestId('end-of-day-confirm-button')).toBeInTheDocument();
  });

  it('prints the Z after close and marks the second print as a reprint (newly reachable path)', async () => {
    mocks.tauri = true;
    renderHeader();
    await confirmCountedShift();
    expect(await screen.findByText('Z0001')).toBeInTheDocument();

    // A press that never reaches a printer must not consume the first print.
    mocks.printerConfig = null;
    fireEvent.click(screen.getByRole('button', { name: 'reports.endOfDay.printReceipt' }));
    expect(mocks.printReceipt).not.toHaveBeenCalled();
    expect(mocks.toastError).toHaveBeenCalledWith('settings.noPrinterConfigured');

    mocks.printerConfig = {
      connection_type: 'network',
      address: '127.0.0.1:9100',
      name: 'Test printer',
    };
    fireEvent.click(screen.getByRole('button', { name: 'reports.endOfDay.printReceipt' }));
    fireEvent.click(screen.getByRole('button', { name: 'reports.endOfDay.printReceipt' }));

    expect(mocks.printReceipt).toHaveBeenCalledTimes(2);
    // buildZReceiptData maps formattedZNumber -> receipt_number (printing.ts:265)
    expect(mocks.printReceipt.mock.calls[0]?.[0]).toMatchObject({
      is_reprint: false,
      receipt_number: 'Z0001',
    });
    expect(mocks.printReceipt.mock.calls[1]?.[0]).toMatchObject({
      is_reprint: true,
      receipt_number: 'Z0001',
    });
    expect(useTerminalStore.getState().shift).toBeNull();
  });

  it('keeps the approving manager name on a post-close print even after the terminal record is refreshed', async () => {
    mocks.tauri = true;
    // First open resolves the manager list; the refresh that follows the
    // terminal-record replacement is still in flight when Print is pressed —
    // exactly the window in which `authorizedManagers` collapses to [].
    mocks.fetchAuthorizedManagers
      .mockResolvedValueOnce([{ id: 'mgr-1', name: 'Mgr One' }])
      .mockReturnValue(new Promise(() => {}));
    mocks.verifyScopedManagerPin.mockResolvedValue({ id: 'mgr-1', name: 'Mgr One' });
    renderHeader();
    fireEvent.click(screen.getByRole('button', { name: 'shift.number' }));
    const confirm = await screen.findByTestId('end-of-day-confirm-button');
    // actual 50 vs expected 130 -> under by 80 > hard 20 -> reason + manager PIN
    await act(async () => {
      fireEvent.click(await screen.findByTestId('tender-actual-input-CASH'));
      await Promise.resolve();
      const panel = screen.getByTestId('cash-count-numpad-panel');
      for (const d of '50') {
        fireEvent.click(within(panel).getByTestId(`numpad-digit-${d}`));
        await Promise.resolve();
      }
    });
    fireEvent.click(screen.getByTestId('commit-counts-button'));
    await act(async () => {
      fireEvent.change(await screen.findByTestId('variance-reason-input'), {
        target: { value: 'Drawer mishap' },
      });
    });
    const pin = await screen.findByTestId('manager-pin-section');
    await act(async () => {
      for (const d of '1234') {
        fireEvent.click(within(pin).getByTestId(`numpad-digit-${d}`));
        await Promise.resolve();
      }
      fireEvent.click(within(pin).getByTestId('manager-pin-verify'));
    });
    await waitFor(() => expect(screen.getByTestId('manager-verified')).toBeInTheDocument());
    await waitFor(() => expect(confirm).toBeEnabled());
    fireEvent.click(confirm);
    expect(await screen.findByText('Z0001')).toBeInTheDocument();

    // Simulate refreshTerminalRecord replacing the terminal object (same id):
    await act(async () => {
      useTerminalStore.setState({ terminal: { ...terminal } });
    });
    expect(screen.getByText('Z0001')).toBeInTheDocument(); // scope check compares ids

    fireEvent.click(screen.getByRole('button', { name: 'reports.endOfDay.printReceipt' }));
    expect(mocks.printReceipt.mock.calls[0]?.[0]).toMatchObject({ manager_name: 'Mgr One' });
  });

  it.each(['company', 'terminal', 'operator', 'shift'])('clears the closed result when %s changes', async (scope) => {
    renderHeader();
    await confirmCountedShift();
    expect(await screen.findByText('Z0001')).toBeInTheDocument();
    const originalOperator = useOperatorStore.getState().operator;

    await act(async () => {
      if (scope === 'company') useAuthStore.setState({ companyId: 'company-2' });
      if (scope === 'terminal') useTerminalStore.setState({ terminal: { ...terminal, id: 'terminal-2', location: { ...terminal.location, id: 'loc-2' } } });
      if (scope === 'operator') useOperatorStore.setState({ operator: originalOperator ? { ...originalOperator, id: 'cashier-2' } : null });
      if (scope === 'shift') useTerminalStore.setState({ shift: { ...shift, id: '00000000-0000-4000-8000-000000000002' } });
    });
    expect(screen.queryByText('Z0001')).not.toBeInTheDocument();

    await act(async () => {
      useAuthStore.setState({ companyId: 'company-1' });
      useTerminalStore.setState({ terminal, shift: null });
      useOperatorStore.setState({ operator: originalOperator });
    });
    expect(screen.queryByText('Z0001')).not.toBeInTheDocument();
  });
});
