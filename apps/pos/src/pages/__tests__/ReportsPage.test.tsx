import { describe, expect, it, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { ReportsPage } from '../ReportsPage';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) =>
      opts && Object.keys(opts).length > 0
        ? `${key} ${Object.values(opts).join(' ')}`
        : key,
  }),
}));

vi.mock('@/lib/currency', () => ({
  useCurrency: () => ({ currency: 'TND', decimals: 3, format: (v: string) => `${v} DT` }),
}));

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn().mockResolvedValue({}),
}));

const mockBuildPreview = vi.fn();
vi.mock('@/lib/offline/endOfDayPreview', () => ({
  buildEndOfDayPreview: (...args: unknown[]) => mockBuildPreview(...args),
}));

const mockLoadTickets = vi.fn();
vi.mock('@/lib/offline/salesHistory', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/offline/salesHistory')>();
  return { ...actual, loadSalesHistoryTickets: (...a: unknown[]) => mockLoadTickets(...a) };
});

const shift = {
  id: 'shift-1',
  terminal_id: 'term-1',
  shift_number: 42,
  status: 'OPEN' as const,
  opening_cash: '200.000',
  opened_at: '2026-08-21T07:00:00Z',
  user: { id: 'u-1', name: 'Yasmine B.' },
};
const terminal = { id: 'term-1', code: 'CAISSE-1', name: 'Register 1' };

let storeState: { shift: typeof shift | null; terminal: typeof terminal | null } = {
  shift,
  terminal,
};

vi.mock('@/stores/terminalStore', () => ({
  useTerminalStore: (selector: (s: typeof storeState) => unknown) => selector(storeState),
}));

vi.mock('@/stores/authStore', () => ({
  useAuthStore: (selector: (s: { companyId: string | null }) => unknown) =>
    selector({ companyId: 'company-1' }),
}));

/** Sale-only aggregates, exactly as the canonical preview returns them. */
const preview = {
  sales_count: 37,
  gross_sales: '4820.500',
  net_sales: '4050.840',
  tax_amount: '769.660',
  opening_cash: '0',
  cash_sales_net: '3100.000',
  drawer_movements_net: '0',
  expected_cash: '3100.000',
  variance: null,
  vat_breakdown: [],
  payment_methods: [
    {
      payment_method_id: 'pm-cash',
      payment_method_code: 'CASH',
      payment_method_name: 'Espèces',
      is_physical: true,
      total_amount: '3100.000',
      transaction_count: 20,
    },
    {
      payment_method_id: 'pm-card',
      payment_method_code: 'CARD',
      payment_method_name: 'Carte',
      is_physical: false,
      total_amount: '1200.500',
      transaction_count: 12,
    },
    {
      payment_method_id: 'pm-voucher',
      payment_method_code: 'VOUCHER',
      payment_method_name: 'Bon',
      is_physical: false,
      total_amount: '520.000',
      transaction_count: 5,
    },
  ],
  tolerance_summary: null,
  cash_rounding_summary: null,
  tolerance_auto_accept_count: null,
};

const tickets = [
  {
    id: 'r3',
    receiptNumber: 'T-1044',
    createdAt: '2026-08-21 10:05:00',
    operatorName: 'Ines Trabelsi',
    itemCount: 5,
    methodCodes: ['CARD', 'VOUCHER'],
    methodLabel: null,
    isRefund: false,
    total: '214.000',
  },
  {
    id: 'r2',
    receiptNumber: 'T-1043',
    createdAt: '2026-08-21 09:32:00',
    operatorName: 'Yasmine B.',
    itemCount: 1,
    methodCodes: ['CARD'],
    methodLabel: 'Carte',
    isRefund: false,
    total: '48.900',
  },
  {
    id: 'r1',
    receiptNumber: 'T-1042R',
    createdAt: '2026-08-21 09:18:00',
    operatorName: 'Yasmine B.',
    itemCount: 2,
    methodCodes: ['CASH'],
    methodLabel: 'Espèces',
    isRefund: true,
    total: '-12.500',
  },
];

describe('ReportsPage', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    storeState = { shift, terminal };
    mockBuildPreview.mockResolvedValue(preview);
    mockLoadTickets.mockResolvedValue(tickets);
  });

  it('renders the reports dashboard surface', async () => {
    render(<ReportsPage />);

    expect(screen.getByTestId('reports-screen')).toBeInTheDocument();
    expect(await screen.findByText('reports.dashboard.paymentBreakdown')).toBeInTheDocument();
    expect(screen.getByText('reports.dashboard.ticket')).toBeInTheDocument();
  });

  it('labels the headline as excluding refunds and reports it that way (O-28)', async () => {
    render(<ReportsPage />);

    expect(await screen.findByText('reports.dashboard.salesExclRefunds')).toBeInTheDocument();
    expect(screen.getByText('4820.500 DT')).toBeInTheDocument();
    expect(screen.getByText('37')).toBeInTheDocument();
    // 4820.500 / 37 = 130.2837… → 130.284 at currency scale 3.
    expect(screen.getByText('130.284 DT')).toBeInTheDocument();
  });

  it('surfaces refunds as their own counter-figure rather than netting them in', async () => {
    render(<ReportsPage />);

    const refundLine = (await screen.findByText('reports.dashboard.refunds')).parentElement;
    // One refund ticket of 12.500 — reported as a positive magnitude, and only
    // in the counter-figure: it is NOT netted into the 4820.500 headline.
    expect(refundLine?.textContent).toContain('1');
    expect(refundLine?.textContent).toContain('−12.500 DT');
    expect(screen.getByText('4820.500 DT')).toBeInTheDocument();
  });

  it('renders the real payment breakdown with real shares', async () => {
    render(<ReportsPage />);

    expect((await screen.findAllByText('Espèces')).length).toBeGreaterThan(0);
    expect(screen.getByText('3100.000 DT')).toBeInTheDocument();
    expect(screen.getByText('1200.500 DT')).toBeInTheDocument();
    expect(screen.getByText('520.000 DT')).toBeInTheDocument();
    // 3100.000 / 4820.500 = 64% ; 1200.500 → 25% ; 520.000 → 11%
    expect(screen.getByText('64%')).toBeInTheDocument();
    expect(screen.getByText('25%')).toBeInTheDocument();
    expect(screen.getByText('11%')).toBeInTheDocument();
  });

  it('lists real tickets with time, cashier, item count, tender and signed total', async () => {
    render(<ReportsPage />);

    // created_at is SQLite UTC TEXT — it must be read as UTC, not device-local.
    const expectedTime = new Date('2026-08-21T10:05:00Z').toLocaleTimeString([], {
      hour: '2-digit',
      minute: '2-digit',
    });

    const row = (await screen.findByText('T-1044')).parentElement;
    expect(row?.textContent).toContain(expectedTime);
    expect(row?.textContent).toContain('Ines Trabelsi');
    expect(row?.textContent).toContain('5');
    expect(row?.textContent).toContain('214.000 DT');
    // Split tender collapses to the MIXED label; a single tender shows its name.
    expect(row?.textContent).toContain('reports.payment.mixed');

    const singleTenderRow = screen.getByText('T-1043').parentElement;
    expect(singleTenderRow?.textContent).toContain('Carte');
    expect(singleTenderRow?.textContent).toContain('48.900 DT');
  });

  it('shows a refund ticket with its already-negative total and a return badge', async () => {
    render(<ReportsPage />);

    expect(await screen.findByText('T-1042R')).toBeInTheDocument();
    expect(screen.getAllByText('reports.typeReturn').length).toBeGreaterThan(0);
    // The row total must not be double-negated into "−-12.500".
    expect(screen.getByText('-12.500 DT')).toBeInTheDocument();
    expect(screen.queryByText('−-12.500 DT')).not.toBeInTheDocument();
  });

  it('filters the ticket list by tender', async () => {
    render(<ReportsPage />);

    await screen.findByText('T-1044');
    fireEvent.click(screen.getByRole('button', { name: 'Carte' }));

    await waitFor(() => expect(screen.queryByText('T-1044')).not.toBeInTheDocument());
    expect(screen.getByText('T-1043')).toBeInTheDocument();
    expect(screen.queryByText('T-1042R')).not.toBeInTheDocument();
  });

  it('filters the ticket list by search over receipt number and cashier', async () => {
    render(<ReportsPage />);

    await screen.findByText('T-1044');
    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'ines' } });

    await waitFor(() => expect(screen.queryByText('T-1043')).not.toBeInTheDocument());
    expect(screen.getByText('T-1044')).toBeInTheDocument();
  });

  it('re-reads against the shift opening when the shift period is selected', async () => {
    render(<ReportsPage />);

    await waitFor(() => expect(mockLoadTickets).toHaveBeenCalled());
    mockLoadTickets.mockClear();
    mockBuildPreview.mockClear();

    fireEvent.click(screen.getByRole('button', { name: 'reports.dashboard.currentShift' }));

    await waitFor(() =>
      expect(mockLoadTickets).toHaveBeenCalledWith(
        expect.anything(),
        'term-1',
        '2026-08-21T07:00:00Z',
      ),
    );
    expect(mockBuildPreview).toHaveBeenCalledWith(
      expect.anything(),
      'term-1',
      '2026-08-21T07:00:00Z',
      '0',
      'TND',
    );
  });

  it('shows an honest empty state when the period has no tickets', async () => {
    mockLoadTickets.mockResolvedValue([]);
    mockBuildPreview.mockResolvedValue({
      ...preview,
      sales_count: 0,
      gross_sales: '0.000',
      payment_methods: [],
    });

    render(<ReportsPage />);

    expect(await screen.findByText('reports.dashboard.empty')).toBeInTheDocument();
    // Headline and average basket both read a real, scale-formatted zero —
    // never a leftover mock figure.
    expect(screen.getAllByText('0.000 DT')).toHaveLength(2);
    expect(screen.getByText('0')).toBeInTheDocument();
  });

  it('surfaces a read failure instead of showing plausible numbers', async () => {
    mockLoadTickets.mockRejectedValue(new Error('db gone'));

    render(<ReportsPage />);

    expect(await screen.findByText('reports.dashboard.error')).toBeInTheDocument();
  });
});
