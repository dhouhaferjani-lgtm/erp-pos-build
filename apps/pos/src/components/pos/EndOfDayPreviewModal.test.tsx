import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, within, fireEvent, waitFor, act } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { EndOfDayPreviewModal, type CompanyFraudSettings } from './EndOfDayPreviewModal';
import { TOLERANCE_AUTO_ACCEPT_LIMIT_PER_SHIFT } from '@/lib/payment/cashRounding';
import enPos from '@/locales/en/pos.json';
import frPos from '@/locales/fr/pos.json';

// ── Mocks ─────────────────────────────────────────────────────────────────────

const modalI18nState = vi.hoisted(() => ({ locale: 'en' as 'en' | 'fr' }));

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
        'reports.endOfDay.toleranceAutoAcceptsUsed': 'Auto-accepts used',
        'reports.endOfDay.toleranceAutoAcceptsValue':
          `${String(opts?.used ?? '')} / ${String(opts?.limit ?? '')}`,
        'reports.endOfDay.toleranceAutoAcceptsUnknown': `Unknown / ${String(opts?.limit ?? '')}`,
        'reports.endOfDay.netCashRounding': 'Net cash rounding',
        'reports.vatRate': 'Rate',
        'reports.vatNet': 'Net',
        'reports.vatVat': 'VAT',
        'reports.vatGross': 'Gross',
        'reports.paymentType': 'Method',
        'reports.paymentCount': 'Count',
        'reports.paymentAmount': 'Amount',
        'reports.loading': 'Generating report...',
        'cash_count.policy_unavailable':
          modalI18nState.locale === 'fr'
            ? "Impossible de fermer ce service : la politique de comptage de caisse n'a pas été synchronisée sur cet appareil. Connectez-vous au réseau, puis réessayez."
            : 'Cannot close this shift: the cash-count policy has not been synced to this device. Connect to the network once, then retry the close.',
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
  cash_rounding_summary: null,
  tolerance_auto_accept_count: 0,
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
  fraudSettings?: CompanyFraudSettings | null;
  cashCountPolicyResolved?: boolean;
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
        fraudSettings={
          Object.prototype.hasOwnProperty.call(opts, 'fraudSettings')
            ? opts.fraudSettings
            : baseFraudSettings
        }
        cashCountPolicyResolved={opts.cashCountPolicyResolved}
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
async function enterTenderActual(code: string, value: string) {
  fireEvent.click(screen.getByTestId(`tender-actual-input-${code}`));
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

async function enterCashActual(value: string = '130') {
  await enterTenderActual('CASH', value);
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('EndOfDayPreviewModal', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    modalI18nState.locale = 'en';
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

    it('SECURITY: withholds preview values while policy is unresolved or unavailable', async () => {
      const { rerender } = renderModalWithCashCount({
        fraudSettings: null,
        cashCountPolicyResolved: false,
      });
      await waitFor(() => {
        expect(mockBuildEndOfDayPreview).toHaveBeenCalledOnce();
      });

      expect(screen.getByText('Generating report...')).toBeInTheDocument();
      expect(screen.queryByText('Expected Cash')).not.toBeInTheDocument();
      expect(screen.queryByText('130.00')).not.toBeInTheDocument();

      rerender(
        <MemoryRouter>
          <EndOfDayPreviewModal
            isOpen
            onClose={vi.fn()}
            shift={sampleShift}
            terminalId="term-1"
            onConfirmAndClose={vi.fn().mockResolvedValue(confirmResult)}
            fraudSettings={null}
            cashCountPolicyResolved
            authorizedManagers={[]}
            cashierUserId="user-1"
            onVerifyManagerPin={vi.fn().mockResolvedValue({ valid: true })}
            managerPinThrottle={{ until: null, failedAttempts: 0 }}
            onManagerPinThrottleUpdate={vi.fn()}
          />
        </MemoryRouter>,
      );

      expect(screen.getByText(/Cannot close this shift/)).toBeInTheDocument();
      expect(screen.queryByText('Expected Cash')).not.toBeInTheDocument();
      expect(screen.queryByText('130.00')).not.toBeInTheDocument();
    });

    it('renders the unavailable-policy message in French and pins both locale files', async () => {
      const english =
        'Cannot close this shift: the cash-count policy has not been synced to this device. Connect to the network once, then retry the close.';
      const french =
        "Impossible de fermer ce service : la politique de comptage de caisse n'a pas été synchronisée sur cet appareil. Connectez-vous au réseau, puis réessayez.";
      expect(enPos.cash_count.policy_unavailable).toBe(english);
      expect(frPos.cash_count.policy_unavailable).toBe(french);

      modalI18nState.locale = 'fr';
      renderModalWithCashCount({
        fraudSettings: null,
        cashCountPolicyResolved: true,
      });

      expect(await screen.findByText(french)).toBeInTheDocument();
      expect(screen.queryByText(english)).not.toBeInTheDocument();
    });

    it('preserves a preview-build error when policy is also unavailable', async () => {
      mockBuildEndOfDayPreview.mockRejectedValueOnce(new Error('preview exploded'));
      renderModalWithCashCount({
        fraudSettings: null,
        cashCountPolicyResolved: true,
      });

      expect(await screen.findByText('preview exploded')).toBeInTheDocument();
      expect(screen.queryByText(/cash-count policy/)).not.toBeInTheDocument();
    });

    it('SECURITY: withholds financial preview amounts until blind counts are committed', async () => {
      mockBuildEndOfDayPreview.mockResolvedValueOnce({
        ...samplePreview,
        payment_methods: [
          ...samplePreview.payment_methods,
          {
            payment_method_id: 'pm-check',
            payment_method_code: 'CHECK',
            is_physical: true,
            total_amount: '430.00',
            transaction_count: 1,
          },
        ],
      });
      renderModalWithCashCount({
        fraudSettings: { ...baseFraudSettings, require_blind_cash_count: true },
      });
      const paymentSummary = (await screen.findByText('Payments')).parentElement!;

      expect(within(paymentSummary).queryByText('30.00')).not.toBeInTheDocument();
      expect(within(paymentSummary).queryByText('15.00')).not.toBeInTheDocument();
      expect(within(paymentSummary).queryByText('430.00')).not.toBeInTheDocument();
      expect(screen.queryByText('45.00')).not.toBeInTheDocument();
      expect(screen.queryByText('37.82')).not.toBeInTheDocument();
      expect(screen.queryByText('7.18')).not.toBeInTheDocument();

      await act(async () => {
        await enterTenderActual('CASH', '130');
      });
      await act(async () => {
        await enterTenderActual('CHECK', '430');
      });
      fireEvent.click(screen.getByTestId('commit-counts-button'));

      expect(within(paymentSummary).getByText('30.00')).toBeInTheDocument();
      expect(within(paymentSummary).getByText('15.00')).toBeInTheDocument();
      expect(within(paymentSummary).getByText('430.00')).toBeInTheDocument();
      expect(screen.getAllByText('45.00')).toHaveLength(2);
      expect(screen.getAllByText('37.82')).toHaveLength(2);
      expect(screen.getAllByText('7.18')).toHaveLength(2);
    });

    it('SECURITY: resets the parent commit boundary across a policy refresh', async () => {
      const blindSettings = { ...baseFraudSettings, require_blind_cash_count: true };
      const refreshedBlindSettings = { ...blindSettings };
      mockBuildEndOfDayPreview.mockResolvedValueOnce({
        ...samplePreview,
        payment_methods: [
          ...samplePreview.payment_methods,
          {
            payment_method_id: 'pm-check',
            payment_method_code: 'CHECK',
            is_physical: true,
            total_amount: '430.00',
            transaction_count: 1,
          },
        ],
      });
      const view = renderModalWithCashCount({
        fraudSettings: blindSettings,
        cashCountPolicyResolved: true,
      });
      let paymentSummary = (await screen.findByText('Payments')).parentElement!;

      await act(async () => {
        await enterTenderActual('CASH', '130');
        await enterTenderActual('CHECK', '430');
      });
      fireEvent.click(screen.getByTestId('commit-counts-button'));
      expect(within(paymentSummary).getByText('430.00')).toBeInTheDocument();

      const renderAtPolicyState = (resolved: boolean) => (
        <MemoryRouter>
          <EndOfDayPreviewModal
            isOpen
            onClose={vi.fn()}
            shift={sampleShift}
            terminalId="term-1"
            onConfirmAndClose={vi.fn().mockResolvedValue(confirmResult)}
            fraudSettings={resolved ? refreshedBlindSettings : null}
            cashCountPolicyResolved={resolved}
            authorizedManagers={[]}
            cashierUserId="user-1"
            onVerifyManagerPin={vi.fn().mockResolvedValue({ valid: true })}
            managerPinThrottle={{ until: null, failedAttempts: 0 }}
            onManagerPinThrottleUpdate={vi.fn()}
          />
        </MemoryRouter>
      );

      view.rerender(renderAtPolicyState(false));
      expect(screen.getByText('Generating report...')).toBeInTheDocument();

      view.rerender(renderAtPolicyState(true));
      paymentSummary = screen.getByText('Payments').parentElement!;
      expect(within(paymentSummary).queryByText('430.00')).not.toBeInTheDocument();
      expect(screen.queryByText('Expected')).not.toBeInTheDocument();
      expect(screen.getByTestId('commit-counts-button')).toBeDisabled();
      expect(screen.getByTestId('end-of-day-confirm-button')).toBeDisabled();
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

  // ── Cash rounding + auto-accept budget (fix round 1, owner ruling) ─────────

  describe('cash rounding summary row', () => {
    it('renders the signed net adjustment and the rounded-receipt count', async () => {
      mockBuildEndOfDayPreview.mockResolvedValueOnce({
        ...samplePreview,
        cash_rounding_summary: { totalAdjustment: '-0.020', receiptCount: 2 },
      });
      renderModal();
      await screen.findByText('Confirm and Close Day');

      const row = screen.getByTestId('cash-rounding-summary');
      expect(within(row).getByText('Net cash rounding')).toBeInTheDocument();
      // The sign must survive: a round-DOWN must not read as a round-up.
      expect(row).toHaveTextContent('-0.020');
      expect(row).toHaveTextContent('(2)');
    });

    it('renders a POSITIVE adjustment without inventing a minus', async () => {
      mockBuildEndOfDayPreview.mockResolvedValueOnce({
        ...samplePreview,
        cash_rounding_summary: { totalAdjustment: '0.030', receiptCount: 1 },
      });
      renderModal();
      await screen.findByText('Confirm and Close Day');

      const row = screen.getByTestId('cash-rounding-summary');
      expect(row).toHaveTextContent('0.030');
      expect(row).not.toHaveTextContent('-0.030');
    });

    it('renders NO row at all when nothing rounded (never a zero row)', async () => {
      mockBuildEndOfDayPreview.mockResolvedValueOnce({
        ...samplePreview,
        cash_rounding_summary: null,
      });
      renderModal();
      await screen.findByText('Confirm and Close Day');

      expect(screen.queryByTestId('cash-rounding-summary')).not.toBeInTheDocument();
      expect(screen.queryByText('Net cash rounding')).not.toBeInTheDocument();
    });
  });

  describe('tolerance auto-accept budget row', () => {
    it('renders spent against the imported §8.1 limit, not a hardcoded 10', async () => {
      mockBuildEndOfDayPreview.mockResolvedValueOnce({
        ...samplePreview,
        tolerance_auto_accept_count: 3,
      });
      renderModal();
      await screen.findByText('Confirm and Close Day');

      const row = screen.getByTestId('tolerance-auto-accept-budget');
      expect(within(row).getByText('Auto-accepts used')).toBeInTheDocument();
      expect(row).toHaveTextContent(`3 / ${String(TOLERANCE_AUTO_ACCEPT_LIMIT_PER_SHIFT)}`);
    });

    it('renders an unspent budget as 0 / limit', async () => {
      mockBuildEndOfDayPreview.mockResolvedValueOnce({
        ...samplePreview,
        tolerance_auto_accept_count: 0,
      });
      renderModal();
      await screen.findByText('Confirm and Close Day');

      expect(screen.getByTestId('tolerance-auto-accept-budget')).toHaveTextContent(
        `0 / ${String(TOLERANCE_AUTO_ACCEPT_LIMIT_PER_SHIFT)}`,
      );
    });

    it('renders a shiftless (null) count as UNKNOWN, never as full headroom', async () => {
      // The gate treats a null shift as the budget FULLY SPENT, so a "0 / 10"
      // here would promise headroom the very next short tender is refused.
      mockBuildEndOfDayPreview.mockResolvedValueOnce({
        ...samplePreview,
        tolerance_auto_accept_count: null,
      });
      renderModal();
      await screen.findByText('Confirm and Close Day');

      const row = screen.getByTestId('tolerance-auto-accept-budget');
      expect(row).toHaveTextContent(`Unknown / ${String(TOLERANCE_AUTO_ACCEPT_LIMIT_PER_SHIFT)}`);
      expect(row).not.toHaveTextContent(`0 / ${String(TOLERANCE_AUTO_ACCEPT_LIMIT_PER_SHIFT)}`);
    });
  });
});
