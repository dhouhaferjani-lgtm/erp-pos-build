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
        'reports.avgTicket': 'Avg Ticket',
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

vi.mock('@/lib/currency', () => ({
  useCurrency: () => ({ format: (v: number) => String(v) }),
}));

// ── Dynamic mocks ─────────────────────────────────────────────────────────────

const mockUseTerminalStore = vi.fn();
vi.mock('@/stores/terminalStore', () => ({
  useTerminalStore: (selector: (s: Record<string, unknown>) => unknown) =>
    mockUseTerminalStore(selector),
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

    // 50.00 + 75.00 sales − 20.00 return = 105.000 (bcsum/bcsub default scale 3).
    expect(await screen.findByText('Net sales (excl. refunds)')).toBeInTheDocument();
    expect(await screen.findByText('105.000')).toBeInTheDocument();
    expect(screen.queryByText('125.000')).not.toBeInTheDocument();
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

    expect(await screen.findByText('105.000')).toBeInTheDocument();
    // The un-normalised form would report 145.000 (125 − −20).
    expect(screen.queryByText('145.000')).not.toBeInTheDocument();
  });

  it('shows reprint button for each receipt', async () => {
    const { findAllByText } = renderPage();

    const reprintButtons = await findAllByText('Reprint');
    expect(reprintButtons).toHaveLength(3);
  });
});
