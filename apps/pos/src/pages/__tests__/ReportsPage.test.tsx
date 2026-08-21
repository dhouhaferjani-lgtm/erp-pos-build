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
  getCurrencyDecimals: () => 3,
}));

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn().mockResolvedValue({}),
  queryAll: vi.fn(),
}));

const mockResolveDisclosure = vi.fn();
vi.mock('@/lib/offline/cashDisclosurePolicy', () => ({
  resolveCashDisclosure: (...args: unknown[]) => mockResolveDisclosure(...args),
}));

import { queryAll } from '@/lib/db';

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
 * ONE set of `offline_receipts` rows. Both the ticket list and the money
 * aggregates are DERIVED from these by the real `loadSalesHistoryTickets` and
 * the real `buildEndOfDayPreview` — no hand-written preview fixture, so the
 * headline-vs-breakdown basis asymmetry is genuinely exercised.
 *
 * Worked example:
 *   sale A  214.000  CARD 100.000 + VOUCHER 114.000   (split tender, 5 lines)
 *   sale B   48.900  CASH 50.000 tendered, 1.100 change  (1 line)
 *   refund C −12.500 CASH 12.500 (positive magnitude per §7.2a, 2 lines)
 */
const receiptRows = [
  {
    id: 'r3',
    receipt_number: 'T-1044',
    created_at: '2026-08-21 10:05:00',
    operator_name: 'Ines Trabelsi',
    lines: JSON.stringify([
      { tax_rate: '19', tax_amount: '34.168', line_total: '179.832' },
      { tax_rate: '19', tax_amount: '0', line_total: '0' },
      { tax_rate: '19', tax_amount: '0', line_total: '0' },
      { tax_rate: '19', tax_amount: '0', line_total: '0' },
      { tax_rate: '19', tax_amount: '0', line_total: '0' },
    ]),
    payments_json: JSON.stringify([
      { payment_method_id: 'pm-card', method_code: 'CARD', amount: '100.000' },
      { payment_method_id: 'pm-voucher', method_code: 'VOUCHER', amount: '114.000' },
    ]),
    payment_method_id: 'pm-card',
    total: '214.000',
    subtotal: '179.832',
    tax_amount: '34.168',
    change_due: '0',
    receipt_kind: 'sale',
    cash_rounding_adjustment: null,
    tolerance_shortfall: null,
  },
  {
    id: 'r2',
    receipt_number: 'T-1043',
    created_at: '2026-08-21 09:32:00',
    operator_name: 'Yasmine B.',
    lines: JSON.stringify([{ tax_rate: '19', tax_amount: '7.808', line_total: '41.092' }]),
    payments_json: JSON.stringify([
      { payment_method_id: 'pm-cash', method_code: 'CASH', amount: '50.000' },
    ]),
    payment_method_id: 'pm-cash',
    total: '48.900',
    subtotal: '41.092',
    tax_amount: '7.808',
    change_due: '1.100',
    receipt_kind: 'sale',
    cash_rounding_adjustment: null,
    tolerance_shortfall: null,
  },
  {
    id: 'r1',
    receipt_number: 'T-1042R',
    created_at: '2026-08-21 09:18:00',
    operator_name: 'Yasmine B.',
    lines: JSON.stringify([
      { tax_rate: '19', tax_amount: '-1.996', line_total: '-12.500' },
      { tax_rate: '19', tax_amount: '0', line_total: '0' },
    ]),
    payments_json: JSON.stringify([
      { payment_method_id: 'pm-cash', method_code: 'CASH', amount: '12.500' },
    ]),
    payment_method_id: 'pm-cash',
    total: '-12.500',
    subtotal: '-10.504',
    tax_amount: '-1.996',
    change_due: null,
    receipt_kind: 'refund',
    cash_rounding_adjustment: null,
    tolerance_shortfall: null,
  },
];

const paymentMethodRows = [
  { id: 'pm-cash', code: 'CASH', name: 'Espèces', is_physical: 1 },
  { id: 'pm-card', code: 'CARD', name: 'Carte', is_physical: 0 },
  { id: 'pm-voucher', code: 'VOUCHER', name: 'Bon', is_physical: 0 },
];

let legacyRefundRows: { total: string }[] = [];

function seedDb(rows = receiptRows) {
  vi.mocked(queryAll).mockImplementation(async (_db, sql) => {
    const text = sql as string;
    if (text.includes('local_refund_records')) return legacyRefundRows as never;
    if (text.includes('FROM offline_receipts')) return rows as never;
    if (text.includes('FROM payment_methods')) return paymentMethodRows as never;
    return [] as never;
  });
}

describe('ReportsPage', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    storeState = { shift, terminal };
    legacyRefundRows = [];
    mockResolveDisclosure.mockResolvedValue('disclose');
    seedDb();
  });

  it('renders the reports dashboard surface', async () => {
    render(<ReportsPage />);

    expect(screen.getByTestId('reports-screen')).toBeInTheDocument();
    expect(await screen.findByText('reports.dashboard.paymentBreakdown')).toBeInTheDocument();
    expect(screen.getByText('reports.dashboard.ticket')).toBeInTheDocument();
  });

  it('headlines NET of refunds under the O-28 label (gross 262.900 − 12.500)', async () => {
    render(<ReportsPage />);

    expect(await screen.findByText('reports.netSalesExclRefunds')).toBeInTheDocument();
    // 214.000 + 48.900 = 262.900 gross (sale-only) ; minus the 12.500 refund.
    expect(screen.getByText('250.400 DT')).toBeInTheDocument();
    // The gross figure must NOT be what is shown.
    expect(screen.queryByText('262.900 DT')).not.toBeInTheDocument();
  });

  it('keeps transactions and average basket on the sale-only population', async () => {
    render(<ReportsPage />);

    await screen.findByText('250.400 DT');
    // 2 sales — the refund is not a transaction in the averaged population.
    const txTile = screen.getByText('reports.dashboard.transactions').parentElement;
    expect(txTile?.textContent).toContain('2');
    // 262.900 / 2 = 131.450 (gross numerator, matching the /sales lane).
    expect(screen.getByText('131.450 DT')).toBeInTheDocument();
  });

  it('surfaces refunds as their own counter-figure rather than netting them in', async () => {
    render(<ReportsPage />);

    const refundLine = (await screen.findByText('reports.dashboard.refunds')).parentElement;
    expect(refundLine?.textContent).toContain('1');
    expect(refundLine?.textContent).toContain('−12.500 DT');
  });

  it('folds LEGACY refunds into the same counter and headline', async () => {
    // Pre-v4 refunds never write an offline_receipts row — they live only in
    // local_refund_records, and are stored NEGATIVE.
    legacyRefundRows = [{ total: '-10.000' }];
    seedDb();

    render(<ReportsPage />);

    const refundLine = (await screen.findByText('reports.dashboard.refunds')).parentElement;
    expect(refundLine?.textContent).toContain('2');
    expect(refundLine?.textContent).toContain('−22.500 DT');
    // 262.900 − (12.500 + 10.000)
    expect(screen.getByText('240.400 DT')).toBeInTheDocument();
  });

  it('renders the real tender breakdown, change-netted and refund-signed', async () => {
    render(<ReportsPage />);

    expect((await screen.findAllByText('Espèces')).length).toBeGreaterThan(0);
    // CASH: 50.000 tendered − 1.100 change − 12.500 refund = 36.400
    expect(screen.getByText('36.400 DT')).toBeInTheDocument();
    expect(screen.getByText('100.000 DT')).toBeInTheDocument();
    expect(screen.getByText('114.000 DT')).toBeInTheDocument();
  });

  it('reconciles: the tender bars sum to the net headline', async () => {
    render(<ReportsPage />);

    await screen.findByText('250.400 DT');
    // 36.400 + 100.000 + 114.000 = 250.400 — the breakdown is refund-signed and
    // the headline is net-of-refunds, so post-O-28 the two bases agree exactly.
    // (Pre-fix the headline was the 262.900 gross and this did NOT hold.)
    const bars = ['36.400 DT', '100.000 DT', '114.000 DT'];
    for (const bar of bars) expect(screen.getByText(bar)).toBeInTheDocument();
    // 36.400/250.400 = 15% ; 100.000 → 40% ; 114.000 → 46%
    expect(screen.getByText('15%')).toBeInTheDocument();
    expect(screen.getByText('40%')).toBeInTheDocument();
    expect(screen.getByText('46%')).toBeInTheDocument();
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
    expect(row?.textContent).toContain('reports.payment.mixed');

    // Single tender shows the method's display NAME, not its raw code.
    const singleTenderRow = screen.getByText('T-1043').parentElement;
    expect(singleTenderRow?.textContent).toContain('Espèces');
    expect(singleTenderRow?.textContent).toContain('48.900 DT');
  });

  it('shows a refund ticket with its already-negative total and a return badge', async () => {
    render(<ReportsPage />);

    expect(await screen.findByText('T-1042R')).toBeInTheDocument();
    expect(screen.getAllByText('reports.typeReturn').length).toBeGreaterThan(0);
    expect(screen.getByText('-12.500 DT')).toBeInTheDocument();
    expect(screen.queryByText('−-12.500 DT')).not.toBeInTheDocument();
  });

  it('conceals physical-cash takings while a shift is open under blind counting', async () => {
    mockResolveDisclosure.mockResolvedValue('conceal');

    render(<ReportsPage />);

    expect(await screen.findByText('reports.dashboard.cashConcealed')).toBeInTheDocument();
    // The CASH bar and its amount are the blind-count secret's raw material.
    expect(screen.queryByText('36.400 DT')).not.toBeInTheDocument();
    expect(screen.queryByText('15%')).not.toBeInTheDocument();
    // Non-physical tenders stay visible — they are not in the drawer.
    expect(screen.getByText('100.000 DT')).toBeInTheDocument();
    expect(screen.getByText('114.000 DT')).toBeInTheDocument();
  });

  it('discloses cash takings when NO shift is open, even under blind counting', async () => {
    // Nothing is being counted right now, so there is no secret to protect.
    mockResolveDisclosure.mockResolvedValue('conceal');
    storeState = { shift: null, terminal };

    render(<ReportsPage />);

    expect(await screen.findByText('36.400 DT')).toBeInTheDocument();
    expect(screen.queryByText('reports.dashboard.cashConcealed')).not.toBeInTheDocument();
  });

  it('filters the ticket list by tender', async () => {
    render(<ReportsPage />);

    await screen.findByText('T-1044');
    // Only CASH is a single-tender option here; the split-tender T-1044 is
    // reachable only through MIXED, so the options partition the list.
    fireEvent.click(screen.getByRole('button', { name: 'Espèces' }));

    await waitFor(() => expect(screen.queryByText('T-1044')).not.toBeInTheDocument());
    expect(screen.getByText('T-1043')).toBeInTheDocument();
    expect(screen.getByText('T-1042R')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'reports.payment.mixed' }));
    await waitFor(() => expect(screen.getByText('T-1044')).toBeInTheDocument());
    expect(screen.queryByText('T-1043')).not.toBeInTheDocument();
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

    await screen.findByText('T-1044');
    vi.mocked(queryAll).mockClear();

    fireEvent.click(screen.getByRole('button', { name: 'reports.dashboard.currentShift' }));

    await waitFor(() => {
      const receiptCall = vi
        .mocked(queryAll)
        .mock.calls.find((c) => (c[1] as string).includes('FROM offline_receipts'));
      // Rule 20: the shift opening is bound as SQLite UTC TEXT, never ISO 8601.
      expect(receiptCall?.[2]).toEqual(['term-1', '2026-08-21 07:00:00']);
    });
  });

  it('shows an honest empty state when the period has no tickets', async () => {
    seedDb([]);

    render(<ReportsPage />);

    expect(await screen.findByText('reports.dashboard.empty')).toBeInTheDocument();
    expect(screen.getAllByText('0.000 DT')).toHaveLength(2);
    expect(screen.getByText('0')).toBeInTheDocument();
  });

  it('shows no confident zeros before the first read resolves', () => {
    vi.mocked(queryAll).mockImplementation(() => new Promise(() => {}));

    render(<ReportsPage />);

    // A KPI reading "0.000 DT" while the read is still in flight is a lie.
    expect(screen.queryByText('0.000 DT')).not.toBeInTheDocument();
    expect(screen.getByText('reports.dashboard.loading')).toBeInTheDocument();
  });

  it('surfaces a read failure instead of showing plausible numbers', async () => {
    vi.mocked(queryAll).mockRejectedValue(new Error('db gone'));

    render(<ReportsPage />);

    expect(await screen.findByText('reports.dashboard.error')).toBeInTheDocument();
    expect(screen.queryByText('0.000 DT')).not.toBeInTheDocument();
  });
});
