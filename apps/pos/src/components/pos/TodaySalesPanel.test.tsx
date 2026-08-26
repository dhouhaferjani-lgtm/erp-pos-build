import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { toast } from 'sonner';
import { TodaySalesPage } from './TodaySalesPanel';

// ── Static mocks ──────────────────────────────────────────────────────────────

vi.mock('react-i18next', () => ({
  initReactI18next: { type: '3rdParty', init: () => {} },
  useTranslation: () => ({
    t: (key: string) => {
      const map: Record<string, string> = {
        'reports.todaySales': "Today's Sales",
        'reports.netSalesExclRefunds': 'Net sales (excl. refunds)',
        'reports.receiptCount': 'Receipts',
        'reports.avgSaleTicket': 'Avg sale ticket',
        'reports.returns': 'Returns',
        'reports.noReceipts': 'No receipts yet this shift',
        'reports.reprint': 'Reprint',
        'reports.receiptNo': 'Receipt #',
        'reports.time': 'Time',
        'reports.type': 'Type',
        'reports.items': 'Items',
        'reports.total': 'Total',
        'reports.paymentMethod': 'Payment',
        'reports.status': 'Status',
        'reports.actions': 'Actions',
        'reports.typeSale': 'Sale',
        'reports.typeReturn': 'Return',
        'reports.noShiftOpen': "Open a shift to see today's sales.",
        'reports.noReceiptsYet': 'No receipts for this shift yet.',
        'reports.fetchError': 'Could not load receipts. Check your connection and try again.',
        'voidReturn.void': 'Void',
      };
      return map[key] ?? key;
    },
  }),
}));

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}));

vi.mock('@/lib/printing', () => ({
  printReceiptAsPdf: vi.fn().mockResolvedValue(undefined),
}));

// `decimals: 2` exercises the real scale threading: intermediates run at
// decimals + 1 and the headline is rounded ONCE at the presentation boundary,
// so a correct implementation renders '105.00', not a stray '105.000'.
vi.mock('@/lib/currency', () => ({
  useCurrency: () => ({ format: (v: string) => String(v), decimals: 2 }),
}));

// ── Dynamic mocks ─────────────────────────────────────────────────────────────

const mockUseTerminalStore = vi.fn();
vi.mock('@/stores/terminalStore', () => ({
  useTerminalStore: (selector: (s: Record<string, unknown>) => unknown) =>
    mockUseTerminalStore(selector),
}));

const mockUseCashDisclosure = vi.fn();
vi.mock('@/hooks/useCashDisclosure', () => ({
  useCashDisclosure: (...args: unknown[]) => mockUseCashDisclosure(...args),
}));

let mockOperator: { id: string; name: string; roles: string[]; permissions?: string[] } | null =
  null;
vi.mock('@/stores/operatorStore', () => ({
  useOperatorStore: (selector: (s: Record<string, unknown>) => unknown) =>
    selector({ operator: mockOperator }),
}));

vi.mock('@/stores/authStore', () => ({
  useAuthStore: (selector: (s: Record<string, unknown>) => unknown) =>
    selector({ companyId: 'co-1' }),
}));

const mockFetchShiftReceipts = vi.fn();
vi.mock('@/api/reportApi', () => ({
  fetchShiftReceipts: (...args: unknown[]) => mockFetchShiftReceipts(...args),
}));

// ── Fixtures ──────────────────────────────────────────────────────────────────

const sampleReceipts = [
  {
    id: 'r-1',
    receipt_number: 'REC-001',
    receipt_type: 'sale',
    total: '50.00',
    subtotal: '42.02',
    tax_amount: '7.98',
    is_voided: false,
    posted_at: '2026-03-12T10:30:00Z',
    payments: [{ id: 'p-1', payment_type: 'CASH', amount: '50.00' }],
    lines: [
      { id: 'l-1', product_name: 'Widget A', quantity: 2, quantity_decimals: 0, unit_price: '15.00', line_total: '30.00' },
      { id: 'l-2', product_name: 'Widget B', quantity: 1, quantity_decimals: 0, unit_price: '20.00', line_total: '20.00' },
    ],
  },
  {
    id: 'r-2',
    receipt_number: 'REC-002',
    receipt_type: 'sale',
    total: '75.00',
    subtotal: '63.03',
    tax_amount: '11.97',
    is_voided: false,
    posted_at: '2026-03-12T11:00:00Z',
    payments: [{ id: 'p-2', payment_type: 'CARD', amount: '75.00' }],
    lines: [
      { id: 'l-3', product_name: 'Gadget C', quantity: 3, quantity_decimals: 0, unit_price: '25.00', line_total: '75.00' },
    ],
  },
  {
    id: 'r-3',
    receipt_number: 'REC-003',
    receipt_type: 'return',
    total: '20.00',
    subtotal: '16.81',
    tax_amount: '3.19',
    is_voided: false,
    posted_at: '2026-03-12T11:30:00Z',
    payments: [{ id: 'p-3', payment_type: 'CASH', amount: '20.00' }],
    lines: [
      { id: 'l-4', product_name: 'Widget A', quantity: 1, quantity_decimals: 0, unit_price: '20.00', line_total: '20.00' },
    ],
  },
];

// ── Helpers ───────────────────────────────────────────────────────────────────

function withShift() {
  mockUseTerminalStore.mockImplementation(
    (selector: (s: Record<string, unknown>) => unknown) =>
      selector({ shift: { id: 'shift-1' } }),
  );
}

function withoutShift() {
  mockUseTerminalStore.mockImplementation(
    (selector: (s: Record<string, unknown>) => unknown) =>
      selector({ shift: null }),
  );
}

function renderPage() {
  return render(
    <MemoryRouter>
      <TodaySalesPage />
    </MemoryRouter>,
  );
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('TodaySalesPage', () => {
  beforeEach(() => {
    withShift();
    mockFetchShiftReceipts.mockResolvedValue(sampleReceipts);
    // Default for the pre-existing cases: a manager reading the panel, so the
    // money assertions below are about the arithmetic, not the B-13 mask.
    mockOperator = {
      id: 'op-1',
      name: 'Manager',
      roles: ['manager'],
      permissions: ['pos.view_reports'],
    };
    mockUseCashDisclosure.mockReset();
    mockUseCashDisclosure.mockReturnValue('conceal');
  });

  it('renders the "no shift" empty state when shift is null', () => {
    withoutShift();
    renderPage();
    expect(screen.getByText("Open a shift to see today's sales.")).toBeInTheDocument();
  });

  it('renders the empty "no receipts" state when shift exists but no receipts', async () => {
    mockFetchShiftReceipts.mockResolvedValue([]);
    renderPage();
    expect(await screen.findByText('No receipts yet this shift')).toBeInTheDocument();
  });

  it('surfaces a toast when fetchShiftReceipts throws', async () => {
    mockFetchShiftReceipts.mockRejectedValue(new Error('network'));
    renderPage();
    await waitFor(() => {
      expect(toast.error).toHaveBeenCalled();
    });
  });

  it('renders the page title and back button', () => {
    const { getByText } = renderPage();
    expect(getByText("Today's Sales")).toBeInTheDocument();
  });

  it('renders receipt table when shift has receipts', async () => {
    const { findByText } = renderPage();

    expect(await findByText('REC-001')).toBeInTheDocument();
    expect(await findByText('REC-002')).toBeInTheDocument();
    expect(await findByText('REC-003')).toBeInTheDocument();
  });

  it('shows returns count and return type badge', async () => {
    const { findByText, findAllByText } = renderPage();

    expect(await findByText('Returns')).toBeInTheDocument();
    const returnBadges = await findAllByText('Return');
    expect(returnBadges.length).toBeGreaterThanOrEqual(1);
  });

  it('shows receipt line items in the items column', async () => {
    const { findByText } = renderPage();

    expect(await findByText('2× Widget A, 1× Widget B')).toBeInTheDocument();
    expect(await findByText('3× Gadget C')).toBeInTheDocument();
  });

  it('formats current and missing-product receipt lines with their accepted precision', async () => {
    mockFetchShiftReceipts.mockResolvedValue([{
      ...sampleReceipts[0],
      lines: [
        {
          id: 'current-product-line',
          product_name: 'Measured Item',
          quantity: 1.2,
          quantity_decimals: 2,
          unit_price: '15.00',
          line_total: '18.00',
        },
        {
          id: 'missing-product-line',
          product_name: 'Archived Item',
          quantity: 1.2,
          unit_price: '10.00',
          line_total: '12.00',
        },
      ],
    }]);

    renderPage();

    expect(await screen.findByText('1.20× Measured Item, 1.2000× Archived Item')).toBeInTheDocument();
  });

  // ── O-28: the headline is NET of refunds ────────────────────────────────────
  //
  // Owner ruling 2026-08-21 (LEDGER O-28): every Today's-Sales headline figure is
  // NET, EXCLUDING REFUNDS, and must be labelled accordingly. Before the fix the
  // tile summed SALE receipts only (125.00 here) and showed the day's returns as a
  // separate, never-subtracted figure — a cashier reading "Total Sales" saw a
  // number that ignored the 20.00 return.
  it('renders the headline as sales minus returns under the net label', async () => {
    renderPage();

    // 50.00 + 75.00 sales − 20.00 return = 105.00 at the currency scale.
    expect(await screen.findByText('Net sales (excl. refunds)')).toBeInTheDocument();
    expect(await screen.findByText('105.00')).toBeInTheDocument();
    expect(screen.queryByText('125.00')).not.toBeInTheDocument();
  });

  // Legacy returns stored a NEGATIVE total; v4 refund authoring stores a POSITIVE
  // one (v3-refund-chain spec §7.7). A raw `sales - sum(returnTotals)` ADDS a
  // legacy return back into the headline. Per-row magnitude is the only safe form
  // — same reasoning as the backend `-ABS` CASE (ticket
  // 2026-08-01-positive-refund-total-consumers).
  it('subtracts a legacy negative-total return by magnitude, not by sign', async () => {
    mockFetchShiftReceipts.mockResolvedValue([
      sampleReceipts[0],
      sampleReceipts[1],
      { ...sampleReceipts[2], total: '-20.00' },
    ]);

    renderPage();

    expect(await screen.findByText('105.00')).toBeInTheDocument();
    // The un-normalised form would report 145.00 (125 − −20).
    expect(screen.queryByText('145.00')).not.toBeInTheDocument();
  });

  // Mixed-era shift: ONE legacy return (negative stored total) and ONE v4 refund
  // (positive stored total), both 20.00 in magnitude. Any form that sums the raw
  // totals lets the two eras cancel (−20 + 20 = 0) and deducts nothing at all.
  it('deducts both refund eras in one shift instead of letting them cancel', async () => {
    mockFetchShiftReceipts.mockResolvedValue([
      sampleReceipts[0],
      sampleReceipts[1],
      { ...sampleReceipts[2], id: 'r-3a', receipt_number: 'REC-003', total: '-20.00' },
      { ...sampleReceipts[2], id: 'r-3b', receipt_number: 'REC-004', total: '20.00' },
    ]);

    renderPage();

    // 125 − (20 + 20) = 85.00. The cancelling form reports 125.00.
    expect(await screen.findByText('85.00')).toBeInTheDocument();
    expect(screen.queryByText('125.00')).not.toBeInTheDocument();
  });

  // P2-2: a VOIDED refund never moved money, so it must not deduct. The sale arm
  // already filtered `!is_voided`; the return arm did not, so voiding a refund
  // *reduced* the headline a second time.
  it('ignores a voided refund', async () => {
    mockFetchShiftReceipts.mockResolvedValue([
      sampleReceipts[0],
      sampleReceipts[1],
      { ...sampleReceipts[2], is_voided: true },
    ]);

    renderPage();

    expect(await screen.findByText('125.00')).toBeInTheDocument();
    expect(screen.queryByText('105.00')).not.toBeInTheDocument();
  });

  // P2-3: training receipts are excluded from every fiscal aggregate server-side
  // (`training_flag = false` in OwnerSalesSummaryService / SalesReportService), but
  // `ShiftController::receipts` applies no such filter, so the device received them
  // and folded them into the headline.
  it('excludes a training sale from the headline', async () => {
    mockFetchShiftReceipts.mockResolvedValue([
      sampleReceipts[0],
      sampleReceipts[1],
      { ...sampleReceipts[0], id: 'r-train', receipt_number: 'REC-900', total: '999.00', is_training: true },
      sampleReceipts[2],
    ]);

    renderPage();

    expect(await screen.findByText('105.00')).toBeInTheDocument();
    expect(screen.queryByText('1104.00')).not.toBeInTheDocument();
  });

  it('excludes a training refund from the deduction', async () => {
    mockFetchShiftReceipts.mockResolvedValue([
      sampleReceipts[0],
      sampleReceipts[1],
      { ...sampleReceipts[2], id: 'r-train-ret', receipt_number: 'REC-901', is_training: true },
    ]);

    renderPage();

    expect(await screen.findByText('125.00')).toBeInTheDocument();
    expect(screen.queryByText('105.00')).not.toBeInTheDocument();
  });

  it('shows reprint button for each receipt', async () => {
    const { findAllByText } = renderPage();

    const reprintButtons = await findAllByText('Reprint');
    expect(reprintButtons).toHaveLength(3);
  });

  /**
   * B-13 gate r1 (F-2) — `/sales` was the most direct blind-count bypass on the
   * device: per-receipt total next to the tender label, for the shift being
   * counted. Summing the CASH-labelled rows reproduced exactly what `/shift`,
   * `/reports` and the X report conceal.
   */
  describe('blind-count concealment (B-13 F-2)', () => {
    const CASHIER = {
      id: 'op-2',
      name: 'Cashier',
      roles: ['cashier'],
      permissions: ['pos.operate_terminal'],
    };

    it('conceals per-receipt totals and tender labels from a cashier', async () => {
      mockOperator = CASHIER;
      renderPage();
      await screen.findByText('REC-001');

      // The row is still there — receipt number, time and items stay visible.
      expect(screen.getByText('REC-001')).toBeInTheDocument();
      expect(screen.getByText('2× Widget A, 1× Widget B')).toBeInTheDocument();
      // The money and the tender are not.
      expect(screen.queryByText('50.00')).toBeNull();
      expect(screen.queryByText('75.00')).toBeNull();
      expect(screen.queryByText('CASH')).toBeNull();
      expect(screen.queryByText('CARD')).toBeNull();
    });

    it('conceals the aggregate money tiles from a cashier but keeps the receipt COUNT', async () => {
      mockOperator = CASHIER;
      renderPage();
      await screen.findByText('REC-001');

      // net = 50 + 75 − 20 = 105.00 — must not be on screen.
      expect(screen.queryByText('105.00')).toBeNull();
      // Receipt count is not money.
      expect(screen.getByText('Receipts')).toBeInTheDocument();
      expect(screen.getByText('3')).toBeInTheDocument();
    });

    it('explains the concealment rather than silently showing dashes', async () => {
      mockOperator = CASHIER;
      renderPage();
      await screen.findByText('REC-001');
      expect(screen.getByText('reports.dashboard.cashConcealed')).toBeInTheDocument();
    });

    it('withdraws the per-receipt detail drill-down, which is a pure money surface', async () => {
      mockOperator = CASHIER;
      renderPage();
      await screen.findByText('REC-001');
      expect(screen.queryByText('reports.view')).toBeNull();
      // Reprint stays — a customer duplicate is a core till function.
      expect(screen.getAllByText('Reprint').length).toBeGreaterThan(0);
    });

    it('shows everything to a MANAGER on the same blind-count shift', async () => {
      renderPage();
      await screen.findByText('REC-001');
      expect(screen.getByText('105.00')).toBeInTheDocument();
      expect(screen.getAllByText('CASH').length).toBe(2);
      expect(screen.queryByText('reports.dashboard.cashConcealed')).toBeNull();
    });

    it('shows everything to a cashier when blind counting is OFF', async () => {
      mockOperator = CASHIER;
      mockUseCashDisclosure.mockReturnValue('disclose');
      renderPage();
      await screen.findByText('REC-001');
      expect(screen.getByText('105.00')).toBeInTheDocument();
      expect(screen.getAllByText('CASH').length).toBe(2);
    });

    it('fails CLOSED for a cashier with NO operator resolved', async () => {
      mockOperator = null;
      renderPage();
      await screen.findByText('REC-001');
      expect(screen.queryByText('105.00')).toBeNull();
      expect(screen.queryByText('CASH')).toBeNull();
    });

    it('closes for an operator whose cached authority went stale', async () => {
      mockOperator = { ...CASHIER, roles: ['manager'], permissions: ['pos.view_reports'] };
      Object.assign(mockOperator, { authority_stale: true });
      renderPage();
      await screen.findByText('REC-001');
      expect(screen.queryByText('105.00')).toBeNull();
    });
  });
});
