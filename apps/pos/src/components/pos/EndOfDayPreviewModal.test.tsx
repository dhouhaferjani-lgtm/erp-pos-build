import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, within, fireEvent, waitFor, act } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { EndOfDayPreviewModal, type CompanyFraudSettings } from './EndOfDayPreviewModal';

// ── Mocks ─────────────────────────────────────────────────────────────────────

vi.mock('react-i18next', () => ({
  initReactI18next: { type: '3rdParty', init: () => {} },
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) => {
      const map: Record<string, string> = {
        'reports.endOfDay.title': 'End of Day Preview',
        'reports.endOfDay.subtitle': 'Review today\'s totals before closing the shift.',
        'reports.endOfDay.salesCount': 'Receipts',
        'reports.endOfDay.grossSales': 'Gross Sales',
        'reports.endOfDay.netSales': 'Net Sales',
        'reports.endOfDay.taxAmount': 'VAT',
        'reports.endOfDay.cashReconciliation': 'Cash Reconciliation',
        'reports.endOfDay.openingCash': 'Opening Cash',
        'reports.endOfDay.expectedCash': 'Expected Cash',
        'reports.endOfDay.vatBreakdown': 'VAT Breakdown',
        'reports.endOfDay.payments': 'Payments',
        'reports.endOfDay.cancel': 'Cancel',
        'reports.endOfDay.confirmLabel': 'Confirm and Close Day',
        'reports.endOfDay.confirming': 'Closing shift...',
        'reports.endOfDay.successTitle': 'Day Closed',
        'reports.endOfDay.successZNumber': 'Z report',
        'reports.endOfDay.successReused': 'Already had a Z report.',
        'reports.endOfDay.printReceipt': 'Print Receipt',
        'reports.endOfDay.done': 'Done',
        'reports.vatRate': 'Rate',
        'reports.vatNet': 'Net',
        'reports.vatVat': 'VAT',
        'reports.vatGross': 'Gross',
        'reports.paymentType': 'Method',
        'reports.paymentCount': 'Count',
        'reports.paymentAmount': 'Amount',
        'reports.loading': 'Generating report...',
        'shift.number': `Shift #${String(opts?.number ?? '')}`,
      };
      // Fall back to defaultValue when provided (covers cash_count.* keys),
      // otherwise return the key.
      return map[key] ?? (opts?.defaultValue as string) ?? key;
    },
  }),
}));

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}));

vi.mock('@/api/toleranceApi', () => ({
  fetchToleranceReceiptsForShift: vi.fn().mockResolvedValue([]),
}));

vi.mock('@/lib/currency', () => ({
  useCurrency: () => ({
    format: (v: number | string) => String(v),
    currency: 'EUR',
    decimals: 2,
  }),
  getCurrencyDecimals: (code: string) => (code === 'TND' ? 3 : code === 'JPY' ? 0 : 2),
  formatCurrency: (v: number | string) => String(v),
}));

const mockBuildEndOfDayPreview = vi.fn();
vi.mock('@/lib/offline/endOfDayPreview', () => ({
  buildEndOfDayPreview: (...args: unknown[]) => mockBuildEndOfDayPreview(...args),
}));

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn().mockResolvedValue({}),
}));

vi.mock('@/stores/authStore', () => ({
  useAuthStore: {
    getState: () => ({ companyId: 'company-1' }),
  },
}));

// ── Fixtures ──────────────────────────────────────────────────────────────────

const samplePreview = {
  sales_count: 3,
  gross_sales: '45.00',
  net_sales: '37.82',
  tax_amount: '7.18',
  opening_cash: '100.00',
  expected_cash: '130.00',
  variance: null,
  tolerance_summary: null,
  vat_breakdown: [
    { tax_rate: 19, net_amount: '37.82', vat_amount: '7.18', gross_amount: '45.00' },
  ],
  payment_methods: [
    { payment_method_id: 'pm-cash', payment_method_code: 'CASH', is_physical: true, total_amount: '30.00', transaction_count: 2 },
    { payment_method_id: 'pm-card', payment_method_code: 'CARD', is_physical: false, total_amount: '15.00', transaction_count: 1 },
  ],
};

const sampleShift = {
  id: 'shift-1',
  terminal_id: 'term-1',
  shift_number: 1,
  status: 'OPEN' as const,
  opening_cash: '100.00',
  opened_at: '2026-04-23T08:00:00Z',
  user: { id: 'user-1', name: 'Alice' },
};

const confirmResult = { formattedZNumber: 'Z0001', wasReused: false };

const baseFraudSettings: CompanyFraudSettings = {
  cash_variance_over_soft: '5.00',
  cash_variance_over_hard: '20.00',
  cash_variance_under_soft: '5.00',
  cash_variance_under_hard: '20.00',
  require_blind_cash_count: false,
  require_manager_pin_above_hard: false,
};

// ── Helpers ───────────────────────────────────────────────────────────────────

function renderModal(
  onConfirmAndClose = vi.fn().mockResolvedValue(confirmResult),
  onClose = vi.fn(),
  onPrintReceipt?: () => void,
) {
  return render(
    <MemoryRouter>
      <EndOfDayPreviewModal
        isOpen
        onClose={onClose}
        shift={sampleShift}
        terminalId="term-1"
        onConfirmAndClose={onConfirmAndClose}
        onPrintReceipt={onPrintReceipt}
      />
    </MemoryRouter>,
  );
}

interface CashCountRenderOpts {
  fraudSettings?: CompanyFraudSettings;
  onConfirmAndClose?: ReturnType<typeof vi.fn>;
  onClose?: ReturnType<typeof vi.fn>;
  onVerifyManagerPin?: ReturnType<typeof vi.fn>;
}

function renderModalWithCashCount(opts: CashCountRenderOpts = {}) {
  const onConfirmAndClose =
    opts.onConfirmAndClose ?? vi.fn().mockResolvedValue(confirmResult);
  const onClose = opts.onClose ?? vi.fn();
  const onVerifyManagerPin =
    opts.onVerifyManagerPin ?? vi.fn().mockResolvedValue({ valid: true });
  const onManagerPinThrottleUpdate = vi.fn();
  const result = render(
    <MemoryRouter>
      <EndOfDayPreviewModal
        isOpen
        onClose={onClose}
        shift={sampleShift}
        terminalId="term-1"
        onConfirmAndClose={onConfirmAndClose}
        fraudSettings={opts.fraudSettings ?? baseFraudSettings}
        authorizedManagers={[
          { id: 'mgr-1', name: 'Mgr One' },
          { id: 'mgr-2', name: 'Mgr Two' },
        ]}
        cashierUserId="user-1"
        onVerifyManagerPin={onVerifyManagerPin}
        managerPinThrottle={{ until: null, failedAttempts: 0 }}
        onManagerPinThrottleUpdate={onManagerPinThrottleUpdate}
      />
    </MemoryRouter>,
  );
  return { ...result, onConfirmAndClose, onClose, onVerifyManagerPin };
}

// Enter a cash actual via the on-screen numpad. Defaults to "130"
// (matches expected_cash → balanced).
// Each digit needs an await tick so React re-renders CurrencyNumpad with
// the updated value before the next click (appendDigit captures value at
// render time via useCallback, so rapid clicks without a flush would each
// see the same stale value and overwrite instead of append).
// Clicks are scoped to cash-count-numpad-panel to avoid ambiguity when
// the manager PIN numpad also appears (e.g. when variance > hard).
async function enterCashActual(value: string = '130') {
  fireEvent.click(screen.getByTestId('tender-actual-input-CASH'));
  await Promise.resolve(); // wait for numpad panel to render
  const numpadPanel = screen.getByTestId('cash-count-numpad-panel');
  for (const ch of value) {
    if (ch === '.') {
      fireEvent.click(within(numpadPanel).getByTestId('numpad-dot'));
    } else {
      fireEvent.click(within(numpadPanel).getByTestId(`numpad-digit-${ch}`));
    }
    await Promise.resolve(); // flush after each digit so value prop updates
  }
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('EndOfDayPreviewModal', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockBuildEndOfDayPreview.mockResolvedValue(samplePreview);
  });

  it('renders totals from buildEndOfDayPreview after loading', async () => {
    renderModal();
    expect(await screen.findByText('Gross Sales')).toBeInTheDocument();
    expect(screen.getByText('Cash Reconciliation')).toBeInTheDocument();
    expect(screen.getByText('3')).toBeInTheDocument();
    const allText = document.body.textContent ?? '';
    expect(allText).toContain('45.00');
    expect(allText).toContain('100.00');
    expect(allText).toContain('130.00');
  });

  it('formats VAT rates at the display boundary without changing preview data', async () => {
    mockBuildEndOfDayPreview.mockResolvedValueOnce({
      ...samplePreview,
      vat_breakdown: [
        { tax_rate: 19.1234, net_amount: '37.82', vat_amount: '7.18', gross_amount: '45.00' },
      ],
    });

    renderModal();

    expect(await screen.findByText('19.12%')).toBeInTheDocument();
    expect(screen.queryByText('19.1234%')).not.toBeInTheDocument();
  });

  it('Cancel button closes the modal without calling onConfirmAndClose', async () => {
    const onConfirmAndClose = vi.fn();
    const onClose = vi.fn();
    renderModal(onConfirmAndClose, onClose);

    expect(await screen.findByText('Confirm and Close Day')).toBeInTheDocument();
    fireEvent.click(screen.getByText('Cancel'));

    expect(onClose).toHaveBeenCalledOnce();
    expect(onConfirmAndClose).not.toHaveBeenCalled();
  });

  it('Confirm button triggers onConfirmAndClose exactly once then shows success screen', async () => {
    const onConfirmAndClose = vi.fn().mockResolvedValue(confirmResult);
    renderModal(onConfirmAndClose);

    expect(await screen.findByText('Confirm and Close Day')).toBeInTheDocument();

    await act(async () => {
      fireEvent.click(screen.getByText('Confirm and Close Day'));
    });

    await waitFor(() => {
      expect(screen.getByText('Day Closed')).toBeInTheDocument();
    });

    expect(onConfirmAndClose).toHaveBeenCalledOnce();
    expect(screen.getByText('Z0001')).toBeInTheDocument();
  });

  it('while onConfirmAndClose is in-flight, Cancel is disabled and confirming label is shown', async () => {
    let resolveConfirm!: (val: typeof confirmResult) => void;
    const slowConfirm = vi.fn().mockImplementation(
      () => new Promise<typeof confirmResult>((resolve) => { resolveConfirm = resolve; }),
    );

    renderModal(slowConfirm);

    expect(await screen.findByText('Confirm and Close Day')).toBeInTheDocument();
    fireEvent.click(screen.getByText('Confirm and Close Day'));

    await waitFor(() => {
      expect(screen.getByText('Closing shift...')).toBeInTheDocument();
    });

    const cancelButton = screen.getByText('Cancel');
    expect(cancelButton).toBeDisabled();

    await act(async () => { resolveConfirm(confirmResult); });
  });

  it('success screen shows Z number and Done button, no back button', async () => {
    renderModal();

    expect(await screen.findByText('Confirm and Close Day')).toBeInTheDocument();

    await act(async () => {
      fireEvent.click(screen.getByText('Confirm and Close Day'));
    });

    await waitFor(() => {
      expect(screen.getByText('Done')).toBeInTheDocument();
    });

    expect(screen.queryByText('Cancel')).not.toBeInTheDocument();
    expect(screen.queryByText('Confirm and Close Day')).not.toBeInTheDocument();
  });

  it('shows wasReused hint when the Z was already generated for this shift', async () => {
    const reusedResult = { formattedZNumber: 'Z0001', wasReused: true };
    const onConfirmAndClose = vi.fn().mockResolvedValue(reusedResult);
    renderModal(onConfirmAndClose);

    expect(await screen.findByText('Confirm and Close Day')).toBeInTheDocument();

    await act(async () => {
      fireEvent.click(screen.getByText('Confirm and Close Day'));
    });

    await waitFor(() => {
      expect(screen.getByText('Already had a Z report.')).toBeInTheDocument();
    });
  });

  // ── New tests for cash-count integration ──────────────────────────────────

  describe('cash-count mode', () => {
    it('hides cash reconciliation section in legacy mode (no fraudSettings)', async () => {
      renderModal();
      expect(await screen.findByText('Confirm and Close Day')).toBeInTheDocument();
      expect(
        screen.queryByTestId('cash-reconciliation-section'),
      ).not.toBeInTheDocument();
    });

    it('renders cash reconciliation section when fraudSettings is provided', async () => {
      renderModalWithCashCount();
      expect(await screen.findByText('Confirm and Close Day')).toBeInTheDocument();
      expect(screen.getByTestId('cash-reconciliation-section')).toBeInTheDocument();
      expect(screen.getByTestId('cash-count-table')).toBeInTheDocument();
    });

    it('Confirm is disabled until physical tender actuals are filled', async () => {
      renderModalWithCashCount();
      await screen.findByText('Confirm and Close Day');

      const confirmBtn = screen.getByTestId('end-of-day-confirm-button');
      expect(confirmBtn).toBeDisabled();

      // Enter cash actual matching expected (130.00 → balanced, no reason needed)
      await act(async () => {
        await enterCashActual('130');
      });

      await waitFor(() => {
        expect(confirmBtn).not.toBeDisabled();
      });
    });

    it('variance > soft requires a reason before Confirm enables', async () => {
      renderModalWithCashCount();
      await screen.findByText('Confirm and Close Day');

      // Enter actual = 100 → expected 130 → under by 30 → exceeds soft (5) → warning
      await act(async () => {
        await enterCashActual('100');
      });

      const confirmBtn = screen.getByTestId('end-of-day-confirm-button');
      expect(confirmBtn).toBeDisabled();
      expect(screen.getByTestId('variance-reason-section')).toBeInTheDocument();

      // Provide a reason
      await act(async () => {
        fireEvent.change(screen.getByTestId('variance-reason-input'), {
          target: { value: 'Customer error' },
        });
      });

      await waitFor(() => {
        expect(confirmBtn).not.toBeDisabled();
      });
    });

    it('variance > hard with require_manager_pin_above_hard requires PIN', async () => {
      const onVerifyManagerPin = vi.fn().mockResolvedValue({ valid: true });
      renderModalWithCashCount({
        fraudSettings: {
          ...baseFraudSettings,
          require_manager_pin_above_hard: true,
        },
        onVerifyManagerPin,
      });
      await screen.findByText('Confirm and Close Day');

      // Enter actual = 50 → expected 130 → under by 80 → exceeds hard (20) → critical
      await act(async () => {
        await enterCashActual('50');
      });

      const confirmBtn = screen.getByTestId('end-of-day-confirm-button');
      expect(confirmBtn).toBeDisabled();
      // Both reason and manager PIN should be required
      expect(screen.getByTestId('variance-reason-section')).toBeInTheDocument();
      expect(screen.getByTestId('manager-pin-section')).toBeInTheDocument();

      // Fill reason
      await act(async () => {
        fireEvent.change(screen.getByTestId('variance-reason-input'), {
          target: { value: 'Cash drawer mishap' },
        });
      });
      // Still disabled — need manager PIN
      expect(confirmBtn).toBeDisabled();

      // Enter PIN (4 digits) and verify — scope to manager-pin-section to
      // avoid ambiguity with the still-open cash numpad panel.
      const pinSection = screen.getByTestId('manager-pin-section');
      await act(async () => {
        for (const d of ['1', '2', '3', '4']) {
          fireEvent.click(within(pinSection).getByTestId(`numpad-digit-${d}`));
          await Promise.resolve();
        }
      });
      await act(async () => {
        fireEvent.click(within(pinSection).getByTestId('manager-pin-verify'));
      });

      await waitFor(() => {
        expect(screen.getByTestId('manager-verified')).toBeInTheDocument();
      });
      expect(onVerifyManagerPin).toHaveBeenCalledOnce();
      await waitFor(() => {
        expect(confirmBtn).not.toBeDisabled();
      });
    });

    it('blind mode Phase 1 hides Expected/Variance until Commit, then reveals', async () => {
      renderModalWithCashCount({
        fraudSettings: { ...baseFraudSettings, require_blind_cash_count: true },
      });
      await screen.findByText('Confirm and Close Day');

      // Phase 1: expected hidden
      expect(screen.queryByText('Expected')).not.toBeInTheDocument();
      // Commit button visible but disabled until physical tender filled
      const commitBtn = screen.getByTestId('commit-counts-button');
      expect(commitBtn).toBeDisabled();

      await act(async () => {
        await enterCashActual('130');
      });

      await waitFor(() => {
        expect(commitBtn).not.toBeDisabled();
      });

      // Click commit → Phase 2 transition
      await act(async () => {
        fireEvent.click(commitBtn);
      });

      // Expected column should now be visible
      await waitFor(() => {
        expect(screen.getByText('Expected')).toBeInTheDocument();
      });
      // Commit button should be gone
      expect(screen.queryByTestId('commit-counts-button')).not.toBeInTheDocument();
    });

    it('SECURITY: does not leak expected cash via the legacy card during a blind count', async () => {
      // The legacy "Expected Cash" summary card showed the expected total
      // unconditionally; with a (blind) cash-count reconciliation active it must
      // not render, or it would defeat the blind count. The new section owns the
      // blind-aware reveal.
      renderModalWithCashCount({
        fraudSettings: { ...baseFraudSettings, require_blind_cash_count: true },
      });
      await screen.findByText('Confirm and Close Day');
      expect(screen.queryByText('Expected Cash')).not.toBeInTheDocument();
      // The expected value (130.00) must not appear anywhere pre-commit.
      expect(screen.queryByText(/130\.00/)).not.toBeInTheDocument();
    });

    it('shows the legacy expected-cash summary only when there is no cash-count reconciliation', async () => {
      renderModal();
      await screen.findByText('Confirm and Close Day');
      expect(screen.getByText('Expected Cash')).toBeInTheDocument();
    });

    it('onConfirmAndClose receives the cashCountPayload as 2nd arg', async () => {
      const onConfirmAndClose = vi.fn().mockResolvedValue(confirmResult);
      renderModalWithCashCount({ onConfirmAndClose });
      await screen.findByText('Confirm and Close Day');

      await act(async () => {
        await enterCashActual('130');
      });

      const confirmBtn = screen.getByTestId('end-of-day-confirm-button');
      await waitFor(() => {
        expect(confirmBtn).not.toBeDisabled();
      });

      await act(async () => {
        fireEvent.click(confirmBtn);
      });

      await waitFor(() => {
        expect(onConfirmAndClose).toHaveBeenCalledOnce();
      });

      const [previewArg, payloadArg] = onConfirmAndClose.mock.calls[0]!;
      expect(previewArg).toEqual(samplePreview);
      expect(payloadArg).not.toBeNull();
      expect(payloadArg.blindCountUsed).toBe(false);
      expect(payloadArg.varianceReason).toBeNull(); // balanced
      expect(payloadArg.managerUserId).toBeNull();
      expect(payloadArg.cashCounts).toHaveLength(1);
      expect(payloadArg.cashCounts[0]).toMatchObject({
        payment_method_id: 'pm-cash',
        currency_code: 'EUR',
      });
      expect(payloadArg.cashCounts[0].actual_amount).toMatch(/^130/);
    });

    it('legacy mode: onConfirmAndClose receives null payload as 2nd arg', async () => {
      const onConfirmAndClose = vi.fn().mockResolvedValue(confirmResult);
      renderModal(onConfirmAndClose);

      await screen.findByText('Confirm and Close Day');
      await act(async () => {
        fireEvent.click(screen.getByText('Confirm and Close Day'));
      });

      await waitFor(() => {
        expect(onConfirmAndClose).toHaveBeenCalledOnce();
      });
      const [, payloadArg] = onConfirmAndClose.mock.calls[0]!;
      expect(payloadArg).toBeNull();
    });
  });

  // ── Tolerance row visibility ───────────────────────────────────────────────

  describe('tolerance row', () => {
    it('hides the tolerance row when writeoffCount is 0 (zero-shape state)', async () => {
      mockBuildEndOfDayPreview.mockResolvedValueOnce({
        ...samplePreview,
        tolerance_summary: { totalAmount: '0.000', currencyCode: 'EUR', writeoffCount: 0 },
      });
      renderModal();
      await screen.findByText('Confirm and Close Day');
      expect(screen.queryByTestId('tolerance-drill')).not.toBeInTheDocument();
    });

    it('hides the tolerance row when tolerance_summary is null', async () => {
      mockBuildEndOfDayPreview.mockResolvedValueOnce({
        ...samplePreview,
        tolerance_summary: null,
      });
      renderModal();
      await screen.findByText('Confirm and Close Day');
      expect(screen.queryByTestId('tolerance-drill')).not.toBeInTheDocument();
    });

    it('renders the tolerance row when writeoffCount > 0 (post-v2 state)', async () => {
      mockBuildEndOfDayPreview.mockResolvedValueOnce({
        ...samplePreview,
        tolerance_summary: { totalAmount: '0.840', currencyCode: 'EUR', writeoffCount: 12 },
      });
      renderModal();
      await screen.findByText('Confirm and Close Day');
      expect(screen.getByTestId('tolerance-drill')).toBeInTheDocument();
    });
  });
});
