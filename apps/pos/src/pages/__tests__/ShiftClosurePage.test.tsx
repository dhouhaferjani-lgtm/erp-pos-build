import { describe, expect, it, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { ShiftClosurePage } from '../ShiftClosurePage';

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

const mockNavigate = vi.fn();
vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
}));

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn().mockResolvedValue({}),
}));

const mockBuildPreview = vi.fn();
vi.mock('@/lib/offline/endOfDayPreview', () => ({
  buildEndOfDayPreview: (...args: unknown[]) => mockBuildPreview(...args),
}));

const mockLoadTickets = vi.fn();
const mockLoadLegacyForShift = vi.fn();
vi.mock('@/lib/offline/salesHistory', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/offline/salesHistory')>();
  return {
    ...actual,
    loadSalesHistoryTickets: (...a: unknown[]) => mockLoadTickets(...a),
    loadLegacyRefundTotalsForShift: (...a: unknown[]) => mockLoadLegacyForShift(...a),
  };
});

const mockResolveDisclosure = vi.fn();
vi.mock('@/lib/offline/cashDisclosurePolicy', () => ({
  resolveCashDisclosure: (...args: unknown[]) => mockResolveDisclosure(...args),
}));

const mockRequestEndOfDay = vi.fn();
vi.mock('@/stores/shiftActionsStore', () => ({
  useShiftActionsStore: (selector: (s: { requestEndOfDay: () => void }) => unknown) =>
    selector({ requestEndOfDay: mockRequestEndOfDay }),
}));

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

/**
 * Worked example — the exact figures the page used to HARDCODE, now arriving
 * from the canonical `buildEndOfDayPreview` derivation instead.
 */
const preview = {
  sales_count: 24,
  gross_sales: '3274.000',
  net_sales: '2751.261',
  tax_amount: '522.739',
  opening_cash: '200.000',
  cash_sales_net: '1840.000',
  drawer_movements_net: '0.000',
  expected_cash: '2040.000',
  variance: null,
  vat_breakdown: [],
  payment_methods: [
    {
      payment_method_id: 'pm-cash',
      payment_method_code: 'CASH',
      payment_method_name: 'Espèces',
      is_physical: true,
      total_amount: '1840.000',
      transaction_count: 14,
    },
    {
      payment_method_id: 'pm-card',
      payment_method_code: 'CARD',
      payment_method_name: 'Carte',
      is_physical: false,
      total_amount: '1105.500',
      transaction_count: 8,
    },
    {
      payment_method_id: 'pm-voucher',
      payment_method_code: 'VOUCHER',
      payment_method_name: 'Bon',
      is_physical: false,
      total_amount: '328.500',
      transaction_count: 2,
    },
  ],
  tolerance_summary: null,
  cash_rounding_summary: null,
  tolerance_auto_accept_count: null,
};

/** One v4 refund in the shift — so gross (3274.000) and net (3261.500) differ. */
const refundTickets = [
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

describe('ShiftClosurePage', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    storeState = { shift, terminal };
    mockBuildPreview.mockResolvedValue(preview);
    mockResolveDisclosure.mockResolvedValue('disclose');
    mockLoadTickets.mockResolvedValue(refundTickets);
    mockLoadLegacyForShift.mockResolvedValue({ count: 0, amount: '0.000' });
  });

  it('reads the current shift figures from the canonical end-of-day derivation', async () => {
    render(<ShiftClosurePage />);

    await waitFor(() => expect(mockBuildPreview).toHaveBeenCalled());
    // terminal id, shift opening, opening float, currency, shift id — the same
    // arguments the real close flow passes, so the two screens cannot diverge.
    expect(mockBuildPreview).toHaveBeenCalledWith(
      expect.anything(),
      'term-1',
      '2026-08-21T07:00:00Z',
      '200.000',
      'TND',
      'shift-1',
    );
  });

  it('renders the real shift identity, not a hardcoded service number', async () => {
    render(<ShiftClosurePage />);

    expect(await screen.findByText('shiftClosure.serviceNumber 42')).toBeInTheDocument();
    expect(screen.getByText(/Yasmine B\./)).toBeInTheDocument();
    expect(screen.getByText(/Register 1/)).toBeInTheDocument();
  });

  it('headlines NET of refunds, on the same basis as /reports (O-28)', async () => {
    render(<ShiftClosurePage />);

    expect(await screen.findByText('reports.netSalesExclRefunds')).toBeInTheDocument();
    // 3274.000 gross − 12.500 refunded = 3261.500.
    expect(screen.getByText('3261.500 DT')).toBeInTheDocument();
    expect(screen.queryByText('3274.000 DT')).not.toBeInTheDocument();
  });

  it('shows the refunds counter-figure alongside the headline', async () => {
    render(<ShiftClosurePage />);

    const refundLine = (await screen.findByText('reports.dashboard.refunds')).parentElement;
    expect(refundLine?.textContent).toContain('1');
    expect(refundLine?.textContent).toContain('−12.500 DT');
  });

  it('folds LEGACY shift refunds in through the shift-keyed repository', async () => {
    mockLoadLegacyForShift.mockResolvedValue({ count: 1, amount: '10.000' });

    render(<ShiftClosurePage />);

    await waitFor(() => expect(mockLoadLegacyForShift).toHaveBeenCalledWith(
      expect.anything(), 'shift-1', 3,
    ));
    // 3274.000 − (12.500 + 10.000)
    expect(await screen.findByText('3251.500 DT')).toBeInTheDocument();
  });

  it('renders real transaction count and average basket', async () => {
    render(<ShiftClosurePage />);

    await screen.findByText('3261.500 DT');
    const txTile = screen.getByText('reports.dashboard.transactions').parentElement;
    expect(txTile?.textContent).toContain('24');
    // 3274.000 / 24 = 136.41666… → 136.417 (gross numerator).
    expect(screen.getByText('136.417 DT')).toBeInTheDocument();
  });

  it('renders one bar per real tender with its real share of takings', async () => {
    render(<ShiftClosurePage />);

    expect(await screen.findByText('Espèces')).toBeInTheDocument();
    expect(screen.getByText('1105.500 DT')).toBeInTheDocument();
    expect(screen.getByText('328.500 DT')).toBeInTheDocument();
    // 1840.000 / 3274.000 = 56.2% ; 1105.500 → 34% ; 328.500 → 10%
    expect(screen.getByText('56%')).toBeInTheDocument();
    expect(screen.getByText('34%')).toBeInTheDocument();
    expect(screen.getByText('10%')).toBeInTheDocument();
  });

  it('renders the real cash-drawer expectation when blind counting is OFF', async () => {
    render(<ShiftClosurePage />);

    expect(await screen.findByText('200.000 DT')).toBeInTheDocument();
    // Cash appears twice by design: the tender bar and the drawer line.
    expect(screen.getAllByText('1840.000 DT')).toHaveLength(2);
    expect(screen.getByText('2040.000 DT')).toBeInTheDocument();
  });

  it('conceals every drawer expectation when blind counting is ON', async () => {
    mockResolveDisclosure.mockResolvedValue('conceal');

    render(<ShiftClosurePage />);

    expect(await screen.findByText('shiftClosure.blindConcealedHint')).toBeInTheDocument();
    expect(screen.queryByText('2040.000 DT')).not.toBeInTheDocument();
    expect(screen.queryByText('1840.000 DT')).not.toBeInTheDocument();
    // The takings breakdown is part of the same disclosure surface.
    expect(screen.queryByText('3261.500 DT')).not.toBeInTheDocument();
    // Non-money counts stay visible — they leak nothing about the drawer.
    const txTile = screen.getByText('reports.dashboard.transactions').parentElement;
    expect(txTile?.textContent).toContain('24');
  });

  it('hands closure to the real end-of-day flow instead of closing here', async () => {
    render(<ShiftClosurePage />);

    fireEvent.click(await screen.findByRole('button', { name: 'shiftClosure.closeShift' }));

    expect(mockRequestEndOfDay).toHaveBeenCalledTimes(1);
  });

  it('shows an honest empty state when no shift is open', async () => {
    storeState = { shift: null, terminal };

    render(<ShiftClosurePage />);

    expect(await screen.findByText('shiftClosure.noOpenShift')).toBeInTheDocument();
    expect(mockBuildPreview).not.toHaveBeenCalled();
    expect(screen.queryByRole('button', { name: 'shiftClosure.closeShift' })).not.toBeInTheDocument();
  });

  it('surfaces a read failure instead of showing plausible numbers', async () => {
    mockBuildPreview.mockRejectedValue(new Error('db gone'));

    render(<ShiftClosurePage />);

    expect(await screen.findByText('shiftClosure.loadError')).toBeInTheDocument();
  });
});
