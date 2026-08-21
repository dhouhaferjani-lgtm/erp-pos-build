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

describe('ShiftClosurePage', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    storeState = { shift, terminal };
    mockBuildPreview.mockResolvedValue(preview);
    mockResolveDisclosure.mockResolvedValue('disclose');
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

  it('renders real takings, transaction count and average basket', async () => {
    render(<ShiftClosurePage />);

    expect(await screen.findByText('3274.000 DT')).toBeInTheDocument();
    expect(screen.getByText('24')).toBeInTheDocument();
    // 3274.000 / 24 = 136.41666… → 136.417 at currency scale 3.
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
    expect(screen.queryByText('3274.000 DT')).not.toBeInTheDocument();
    // Non-money counts stay visible — they leak nothing about the drawer.
    expect(screen.getByText('24')).toBeInTheDocument();
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
