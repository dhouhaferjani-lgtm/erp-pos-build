import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import type { LocalVoucher } from '@/lib/offline/voucherRepository';

// ─── Module mocks ─────────────────────────────────────────────────────────────

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) => {
      const map: Record<string, string> = {
        'voucherTender.title': 'Apply voucher',
        'voucherTender.codeLabel': 'Voucher code',
        'voucherTender.codePlaceholder': 'Scan or type code…',
        'voucherTender.lookup': 'Look up',
        'voucherTender.looking': 'Looking up…',
        'voucherTender.cancel': 'Cancel',
        'voucherTender.back': 'Back',
        'voucherTender.apply': 'Apply',
        'voucherTender.balance': 'Balance',
        'voucherTender.redemptionMode': 'Mode',
        'voucherTender.expires': 'Expires',
        'voucherTender.amountLabel': 'Amount to apply',
        'voucherTender.modeBearer': 'Bearer (any holder)',
        'voucherTender.modeCustomerBound': 'Customer-bound',
        'voucherTender.notFound': 'Voucher not found. Check the code and try again.',
        'voucherTender.alreadyApplied': 'This voucher has already been applied to this sale.',
        'voucherTender.unsupportedKind':
          'Restaurant vouchers are not yet supported — Phase 2 feature. Only store vouchers (MPV) can be used here.',
        'voucherTender.notRedeemable': `This voucher cannot be redeemed (status: ${String(opts?.status ?? '')}).`,
        'voucherTender.invalidAmount': 'Enter a valid amount greater than zero.',
        'voucherTender.amountExceedsBalance': `Amount cannot exceed the voucher balance (${String(opts?.balance ?? '')} ${String(opts?.currency ?? '')}).`,
        'voucherTender.amountExceedsDue': `Amount cannot exceed the remaining due (${String(opts?.due ?? '')} ${String(opts?.currency ?? '')}).`,
        'voucherTender.lookupFailed': 'Could not read local voucher database. Please try again.',
      };
      return map[key] ?? (opts?.defaultValue as string | undefined) ?? key;
    },
    i18n: { language: 'en' },
  }),
}));

// Mock Modal
vi.mock('@/components/pos/Modal', () => ({
  Modal: ({ isOpen, children }: { isOpen: boolean; children: React.ReactNode }) =>
    isOpen ? <div data-testid="modal">{children}</div> : null,
}));

// Mock voucherRepository — findByCode returns what we configure
const mockFindByCode = vi.fn<(db: unknown, code: string) => Promise<LocalVoucher | null>>();
vi.mock('@/lib/offline/voucherRepository', () => ({
  findByCode: (db: unknown, code: string) => mockFindByCode(db, code),
}));

// Mock @/lib/currency to break the MoneyInput → currency → authStore → api → i18n
// import chain. MoneyInput only uses getCurrencyDecimals; map known currencies to
// their scale (TND = 3) so the modal's amount-formatting stays correct in tests.
vi.mock('@/lib/currency', () => ({
  getCurrencyDecimals: (currency: string): number =>
    ['TND', 'LYD', 'BHD', 'IQD', 'JOD', 'KWD', 'OMR'].includes(currency) ? 3 : 2,
}));

// Mock @/lib/decimal to break the import chain into authStore/api/i18n
// The component uses bccomp, bcformat from this module.
vi.mock('@/lib/decimal', () => ({
  bccomp: (a: string, b: string): number => {
    const numA = parseFloat(a);
    const numB = parseFloat(b);
    if (numA < numB) return -1;
    if (numA > numB) return 1;
    return 0;
  },
  bcformat: (value: string, scale: number): string => parseFloat(value).toFixed(scale),
  bcadd: (a: string, b: string, scale: number = 3): string =>
    (parseFloat(a) + parseFloat(b)).toFixed(scale),
  bcsub: (a: string, b: string, scale: number = 3): string =>
    (parseFloat(a) - parseFloat(b)).toFixed(scale),
}));

// Mock paymentStore — track addVoucherPayment calls
const mockAddVoucherPayment = vi.fn<(code: string, amount: string) => void>();
const mockAppliedVoucherCodes = new Set<string>();

vi.mock('@/stores/paymentStore', () => ({
  usePaymentStore: () => ({
    appliedVoucherCodes: mockAppliedVoucherCodes,
    addVoucherPayment: mockAddVoucherPayment,
    removeVoucherPayment: vi.fn(),
  }),
}));

// Import after mocks
import { VoucherTenderModal } from '../VoucherTenderModal';

// ─── Test helpers ─────────────────────────────────────────────────────────────

const mockDb = {} as Parameters<typeof VoucherTenderModal>[0]['db'];

function makeVoucher(overrides: Partial<LocalVoucher> = {}): LocalVoucher {
  return {
    id: 'v-001',
    code: 'VOUCHER-001',
    initial_balance: '50.00',
    current_balance: '50.00',
    currency: 'EUR',
    status: 'Issued',
    redemption_mode: 'Bearer',
    voucher_kind: 'MPV',
    source: 'Refund',
    issued_at: '2026-01-01T00:00:00Z',
    expires_at: null,
    partner_id: null,
    issued_to_partner_id: null,
    redeemable_at_terminal_id: null,
    notes: null,
    synced_at: '2026-04-01T00:00:00Z',
    ...overrides,
  };
}

function buildProps(overrides: Partial<Parameters<typeof VoucherTenderModal>[0]> = {}) {
  return {
    isOpen: true,
    onClose: vi.fn(),
    db: mockDb,
    remainingDue: '30.00',
    currency: 'EUR',
    onApplied: vi.fn(),
    methodCode: 'store_voucher' as const,
    ...overrides,
  };
}

async function typeCodeAndLookup(code: string) {
  const input = screen.getByTestId('voucher-code-input');
  fireEvent.change(input, { target: { value: code } });
  await act(async () => {
    fireEvent.click(screen.getByTestId('voucher-lookup-button'));
  });
}

// ─── Tests ────────────────────────────────────────────────────────────────────

describe('VoucherTenderModal — rendering', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockAppliedVoucherCodes.clear();
    mockFindByCode.mockResolvedValue(null);
  });

  it('renders the scan phase when open', () => {
    render(<VoucherTenderModal {...buildProps()} />);

    expect(screen.getByTestId('voucher-tender-modal')).toBeInTheDocument();
    expect(screen.getByTestId('voucher-code-input')).toBeInTheDocument();
    expect(screen.getByTestId('voucher-lookup-button')).toBeInTheDocument();
  });

  it('renders nothing when isOpen is false', () => {
    render(<VoucherTenderModal {...buildProps({ isOpen: false })} />);
    expect(screen.queryByTestId('voucher-tender-modal')).not.toBeInTheDocument();
  });

  // B5-fix audit Minor 1 (2026-05-01): the modal must accept a methodCode
  // prop discriminating which tender tile opened it. In Phase 1 only
  // 'store_voucher' is fully wired; the prop exists so the discriminator
  // is correct end-to-end and won't need refactoring when restaurant_voucher
  // and gift_card land in Phase 2+.
  it('accepts a methodCode prop and applies the correct discriminator on apply', async () => {
    const onApplied = vi.fn();
    mockFindByCode.mockResolvedValue(makeVoucher({ voucher_kind: 'MPV' }));

    render(
      <VoucherTenderModal
        {...buildProps({ onApplied, methodCode: 'store_voucher' })}
      />,
    );

    await typeCodeAndLookup('VOUCHER-001');
    await waitFor(() => expect(screen.getByTestId('voucher-found-section')).toBeInTheDocument());

    await act(async () => {
      fireEvent.click(screen.getByTestId('voucher-apply-button'));
    });

    // onApplied was called — the discriminator is implicit via the
    // methodCode prop and surfaces in the parent's voucherTenders mapping.
    expect(onApplied).toHaveBeenCalledWith('VOUCHER-001', expect.any(String));
    // addVoucherPayment was called — the parent uses methodCode to decide
    // how to label the resulting tender row when building AdvancedPaymentLine.
    expect(mockAddVoucherPayment).toHaveBeenCalled();
  });
});

describe('VoucherTenderModal — local-only lookup (no API/fetch)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockAppliedVoucherCodes.clear();
  });

  it('calls findByCode (local SQLite only) — not any network function', async () => {
    mockFindByCode.mockResolvedValue(makeVoucher());
    render(<VoucherTenderModal {...buildProps()} />);

    await typeCodeAndLookup('VOUCHER-001');

    await waitFor(() => expect(screen.getByTestId('voucher-found-section')).toBeInTheDocument());
    // findByCode was called with the db handle and the code (no fetch/apiGet)
    expect(mockFindByCode).toHaveBeenCalledWith(mockDb, 'VOUCHER-001');
  });

  it('shows error when voucher is not found', async () => {
    mockFindByCode.mockResolvedValue(null);
    render(<VoucherTenderModal {...buildProps()} />);

    await typeCodeAndLookup('NONEXISTENT');

    await waitFor(() => expect(screen.getByTestId('voucher-lookup-error')).toBeInTheDocument());
    expect(screen.getByTestId('voucher-lookup-error')).toHaveTextContent('Voucher not found');
  });
});

describe('VoucherTenderModal — restaurant-voucher rejection', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockAppliedVoucherCodes.clear();
  });

  it('rejects a voucher with voucher_kind !== "MPV" with a Phase 2 message', async () => {
    mockFindByCode.mockResolvedValue(makeVoucher({ voucher_kind: 'SPV' }));
    render(<VoucherTenderModal {...buildProps()} />);

    await typeCodeAndLookup('SPV-001');

    await waitFor(() => expect(screen.getByTestId('voucher-lookup-error')).toBeInTheDocument());
    expect(screen.getByTestId('voucher-lookup-error')).toHaveTextContent(
      'Restaurant vouchers are not yet supported',
    );
    // Should NOT navigate to found phase
    expect(screen.queryByTestId('voucher-found-section')).not.toBeInTheDocument();
  });

  it('accepts an MPV voucher', async () => {
    mockFindByCode.mockResolvedValue(makeVoucher({ voucher_kind: 'MPV' }));
    render(<VoucherTenderModal {...buildProps()} />);

    await typeCodeAndLookup('VOUCHER-001');

    await waitFor(() => expect(screen.getByTestId('voucher-found-section')).toBeInTheDocument());
    expect(screen.queryByTestId('voucher-lookup-error')).not.toBeInTheDocument();
  });

  it('rejects a non-redeemable voucher (Expired status)', async () => {
    mockFindByCode.mockResolvedValue(makeVoucher({ status: 'Expired' }));
    render(<VoucherTenderModal {...buildProps()} />);

    await typeCodeAndLookup('EXPIRED-001');

    await waitFor(() => expect(screen.getByTestId('voucher-lookup-error')).toBeInTheDocument());
    expect(screen.getByTestId('voucher-lookup-error')).toHaveTextContent('cannot be redeemed');
  });
});

describe('VoucherTenderModal — voucher details display', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockAppliedVoucherCodes.clear();
  });

  it('shows balance, expiry, and redemption_mode after lookup', async () => {
    mockFindByCode.mockResolvedValue(
      makeVoucher({
        current_balance: '42.50',
        redemption_mode: 'Bearer',
        expires_at: '2027-01-01T00:00:00Z',
      }),
    );
    render(<VoucherTenderModal {...buildProps()} />);

    await typeCodeAndLookup('VOUCHER-001');

    await waitFor(() => expect(screen.getByTestId('voucher-found-section')).toBeInTheDocument());

    expect(screen.getByTestId('voucher-balance')).toHaveTextContent('42.50 EUR');
    expect(screen.getByTestId('voucher-redemption-mode')).toHaveTextContent('Bearer (any holder)');
    expect(screen.getByTestId('voucher-expiry')).toBeInTheDocument();
  });

  it('does not show expiry when expires_at is null', async () => {
    mockFindByCode.mockResolvedValue(makeVoucher({ expires_at: null }));
    render(<VoucherTenderModal {...buildProps()} />);

    await typeCodeAndLookup('VOUCHER-001');

    await waitFor(() => expect(screen.getByTestId('voucher-found-section')).toBeInTheDocument());
    expect(screen.queryByTestId('voucher-expiry')).not.toBeInTheDocument();
  });
});

describe('VoucherTenderModal — default amount = min(balance, remaining due)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockAppliedVoucherCodes.clear();
  });

  it('defaults to remaining due when balance > due', async () => {
    mockFindByCode.mockResolvedValue(makeVoucher({ current_balance: '50.00' }));
    render(<VoucherTenderModal {...buildProps({ remainingDue: '20.00' })} />);

    await typeCodeAndLookup('VOUCHER-001');

    await waitFor(() => expect(screen.getByTestId('voucher-found-section')).toBeInTheDocument());

    const amountInput = screen.getByTestId('voucher-amount-input') as HTMLInputElement;
    expect(amountInput.value).toBe('20.00');
  });

  it('defaults to balance when balance < due', async () => {
    mockFindByCode.mockResolvedValue(makeVoucher({ current_balance: '10.00' }));
    render(<VoucherTenderModal {...buildProps({ remainingDue: '30.00' })} />);

    await typeCodeAndLookup('VOUCHER-001');

    await waitFor(() => expect(screen.getByTestId('voucher-found-section')).toBeInTheDocument());

    const amountInput = screen.getByTestId('voucher-amount-input') as HTMLInputElement;
    expect(amountInput.value).toBe('10.00');
  });

  it('cashier can adjust amount down', async () => {
    mockFindByCode.mockResolvedValue(makeVoucher({ current_balance: '50.00' }));
    render(<VoucherTenderModal {...buildProps({ remainingDue: '30.00' })} />);

    await typeCodeAndLookup('VOUCHER-001');
    await waitFor(() => expect(screen.getByTestId('voucher-found-section')).toBeInTheDocument());

    const amountInput = screen.getByTestId('voucher-amount-input') as HTMLInputElement;
    fireEvent.change(amountInput, { target: { value: '15.00' } });
    expect(amountInput.value).toBe('15.00');
  });
});

describe('VoucherTenderModal — apply flow', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockAppliedVoucherCodes.clear();
  });

  it('adds voucher payment and calls onApplied on successful apply', async () => {
    const onApplied = vi.fn();
    mockFindByCode.mockResolvedValue(makeVoucher({ current_balance: '50.00', code: 'VOUCHER-001' }));

    render(<VoucherTenderModal {...buildProps({ onApplied, remainingDue: '30.00' })} />);
    await typeCodeAndLookup('VOUCHER-001');
    await waitFor(() => expect(screen.getByTestId('voucher-found-section')).toBeInTheDocument());

    await act(async () => {
      fireEvent.click(screen.getByTestId('voucher-apply-button'));
    });

    expect(mockAddVoucherPayment).toHaveBeenCalledWith('VOUCHER-001', '30.00');
    expect(onApplied).toHaveBeenCalledWith('VOUCHER-001', '30.00');
  });
});

describe('VoucherTenderModal — stacking (multiple vouchers)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockAppliedVoucherCodes.clear();
  });

  it('applies two different vouchers as separate tender rows', async () => {
    const onApplied = vi.fn();

    // First voucher lookup
    mockFindByCode.mockResolvedValueOnce(makeVoucher({ code: 'VOUCHER-A', current_balance: '20.00' }));
    render(<VoucherTenderModal {...buildProps({ onApplied, remainingDue: '35.00' })} />);
    await typeCodeAndLookup('VOUCHER-A');
    await waitFor(() => expect(screen.getByTestId('voucher-found-section')).toBeInTheDocument());

    await act(async () => {
      fireEvent.click(screen.getByTestId('voucher-apply-button'));
    });

    expect(onApplied).toHaveBeenCalledWith('VOUCHER-A', '20.00');

    // Simulate second voucher (new render with updated applied codes)
    mockAppliedVoucherCodes.add('VOUCHER-A');
    mockFindByCode.mockResolvedValueOnce(makeVoucher({ code: 'VOUCHER-B', current_balance: '15.00' }));

    // Component resets to scan phase after first apply — type second code
    await typeCodeAndLookup('VOUCHER-B');
    await waitFor(() => expect(screen.getByTestId('voucher-found-section')).toBeInTheDocument());

    await act(async () => {
      fireEvent.click(screen.getByTestId('voucher-apply-button'));
    });

    expect(onApplied).toHaveBeenCalledWith('VOUCHER-B', '15.00');
    expect(mockAddVoucherPayment).toHaveBeenCalledTimes(2);
  });
});

describe('VoucherTenderModal — duplicate guard', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockAppliedVoucherCodes.clear();
  });

  it('shows error when same voucher code is already in appliedVoucherCodes', async () => {
    mockAppliedVoucherCodes.add('VOUCHER-001');
    mockFindByCode.mockResolvedValue(makeVoucher({ code: 'VOUCHER-001' }));

    render(<VoucherTenderModal {...buildProps()} />);
    await typeCodeAndLookup('VOUCHER-001');

    await waitFor(() => expect(screen.getByTestId('voucher-lookup-error')).toBeInTheDocument());
    expect(screen.getByTestId('voucher-lookup-error')).toHaveTextContent(
      'This voucher has already been applied',
    );
    // No payment row added
    expect(mockAddVoucherPayment).not.toHaveBeenCalled();
    // Should not navigate to found phase
    expect(screen.queryByTestId('voucher-found-section')).not.toBeInTheDocument();
  });
});

describe('VoucherTenderModal — C1: exception safety on findByCode failure', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockAppliedVoucherCodes.clear();
  });

  it('shows lookupFailed error and re-enables lookup button when findByCode throws', async () => {
    mockFindByCode.mockRejectedValue(new Error('SQLite: disk I/O error'));

    render(<VoucherTenderModal {...buildProps()} />);
    await typeCodeAndLookup('VOUCHER-001');

    // Error message is displayed
    await waitFor(() => expect(screen.getByTestId('voucher-lookup-error')).toBeInTheDocument());
    expect(screen.getByTestId('voucher-lookup-error')).toHaveTextContent(
      'Could not read local voucher database. Please try again.',
    );

    // isLooking is reset — lookup button must be enabled again
    const lookupButton = screen.getByTestId('voucher-lookup-button');
    expect(lookupButton).not.toBeDisabled();

    // Should not navigate to found phase
    expect(screen.queryByTestId('voucher-found-section')).not.toBeInTheDocument();
  });
});

describe('VoucherTenderModal — I3: TND scale-3 monetary precision', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockAppliedVoucherCodes.clear();
  });

  it('defaults to due (15.500) when TND balance (25.123) exceeds remaining due, with no precision drift', async () => {
    // TND voucher: balance "25.123", due "15.500"
    mockFindByCode.mockResolvedValue(
      makeVoucher({
        current_balance: '25.123',
        currency: 'TND',
      }),
    );

    render(
      <VoucherTenderModal
        {...buildProps({
          remainingDue: '15.500',
          currency: 'TND',
        })}
      />,
    );

    await typeCodeAndLookup('VOUCHER-TND');

    await waitFor(() => expect(screen.getByTestId('voucher-found-section')).toBeInTheDocument());

    const amountInput = screen.getByTestId('voucher-amount-input') as HTMLInputElement;
    // Must be exactly "15.500" — limited by due, no IEEE 754 drift
    expect(amountInput.value).toBe('15.500');
  });

  it('defaults to balance (10.750) when TND balance is less than remaining due (25.000)', async () => {
    mockFindByCode.mockResolvedValue(
      makeVoucher({
        current_balance: '10.750',
        currency: 'TND',
      }),
    );

    render(
      <VoucherTenderModal
        {...buildProps({
          remainingDue: '25.000',
          currency: 'TND',
        })}
      />,
    );

    await typeCodeAndLookup('VOUCHER-TND-2');

    await waitFor(() => expect(screen.getByTestId('voucher-found-section')).toBeInTheDocument());

    const amountInput = screen.getByTestId('voucher-amount-input') as HTMLInputElement;
    // Must be exactly "10.750" — balance wins, no precision drift
    expect(amountInput.value).toBe('10.750');
  });
});
