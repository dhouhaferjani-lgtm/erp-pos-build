import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { EndOfDayPreviewModal } from './EndOfDayPreviewModal';

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
      return map[key] ?? key;
    },
  }),
}));

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}));

vi.mock('@/lib/currency', () => ({
  useCurrency: () => ({ format: (v: number | string) => String(v) }),
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
  vat_breakdown: [
    { tax_rate: 19, net_amount: '37.82', vat_amount: '7.18', gross_amount: '45.00' },
  ],
  payment_methods: [
    { payment_type: 'CASH', total_amount: '30.00', transaction_count: 2 },
    { payment_type: 'CARD', total_amount: '15.00', transaction_count: 1 },
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

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('EndOfDayPreviewModal', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockBuildEndOfDayPreview.mockResolvedValue(samplePreview);
  });

  it('renders totals from buildEndOfDayPreview after loading', async () => {
    renderModal();
    // Wait for preview to load — check section labels first
    expect(await screen.findByText('Gross Sales')).toBeInTheDocument();
    expect(screen.getByText('Cash Reconciliation')).toBeInTheDocument();
    // 3 sales
    expect(screen.getByText('3')).toBeInTheDocument();
    // The values rendered by SummaryCard via format()
    const allText = document.body.textContent ?? '';
    expect(allText).toContain('45.00'); // gross sales
    expect(allText).toContain('100.00'); // opening cash
    expect(allText).toContain('130.00'); // expected cash
  });

  it('Cancel button closes the modal without calling onConfirmAndClose', async () => {
    const onConfirmAndClose = vi.fn();
    const onClose = vi.fn();
    renderModal(onConfirmAndClose, onClose);

    // Wait for preview to load
    expect(await screen.findByText('Confirm and Close Day')).toBeInTheDocument();

    fireEvent.click(screen.getByText('Cancel'));

    expect(onClose).toHaveBeenCalledOnce();
    expect(onConfirmAndClose).not.toHaveBeenCalled();
  });

  it('Confirm button triggers onConfirmAndClose exactly once then shows success screen', async () => {
    const onConfirmAndClose = vi.fn().mockResolvedValue(confirmResult);
    renderModal(onConfirmAndClose);

    // Wait for preview
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

    // Should show in-flight state
    await waitFor(() => {
      expect(screen.getByText('Closing shift...')).toBeInTheDocument();
    });

    const cancelButton = screen.getByText('Cancel');
    expect(cancelButton).toBeDisabled();

    // Resolve the promise
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
});
