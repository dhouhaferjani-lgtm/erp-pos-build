/**
 * AdvancedPaymentsModal — B3-followup audit (Finding 1, 2026-05-01)
 *
 * Locks the production-path proof for B3: a voucher tender added by
 * VoucherTenderModal flows through `paymentStore.voucherTenders`, surfaces
 * in this modal's payment list with its instrument fields visible, AND
 * merges into the AdvancedPaymentLine[] passed to `onComplete` with
 * `instrument_type: 'store_voucher'` and `instrument_serial: <code>`.
 *
 * Without this wiring B3's writer plumbing has no live UI consumer and a
 * voucher-bearing sale would seal a v3 receipt with a null serial in the
 * fiscal hash — exactly the original B3 production bug at the entry point.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import type { PaymentMethod, PaymentRepository } from '@/types/payment';
import type { AdvancedPaymentLine, VoucherTenderRow } from '@/stores/paymentStore';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@/lib/currency', () => ({
  useCurrency: () => ({
    currency: 'EUR',
    decimals: 2,
    format: (amount: number) => `${amount.toFixed(2)} EUR`,
  }),
}));

/**
 * Codex review B5 (2026-05-01): VoucherTenderModal is mounted as a child of
 * AdvancedPaymentsModal. Mock it as a thin probe so the test can assert it
 * opens AND can simulate the apply-callback that pushes a voucher tender
 * row into paymentStore. The real-component scan/lookup path is exercised
 * by VoucherTenderModal's own tests; here we only care about the mount +
 * onApplied wiring.
 */
const mockVoucherTenderModalProps = vi.fn<(props: unknown) => void>();
vi.mock('@/components/pos/VoucherTenderModal', () => ({
  VoucherTenderModal: (props: {
    isOpen: boolean;
    onClose: () => void;
    onApplied: (code: string, amount: string) => void;
    db: unknown;
    remainingDue: string;
    currency: string;
  }) => {
    mockVoucherTenderModalProps(props);
    if (!props.isOpen) return null;
    return (
      <div data-testid="voucher-tender-modal-mock">
        <span data-testid="voucher-tender-modal-remaining-due">{props.remainingDue}</span>
        <span data-testid="voucher-tender-modal-currency">{props.currency}</span>
        <button
          data-testid="voucher-tender-modal-mock-apply"
          onClick={() => props.onApplied('SV-MOCK-001', '20.00')}
        >
          Mock Apply
        </button>
        <button
          data-testid="voucher-tender-modal-mock-close"
          onClick={() => props.onClose()}
        >
          Mock Close
        </button>
      </div>
    );
  },
}));

vi.mock('@/components/molecules/NumPad', () => ({
  NumPad: ({ value, onChange }: { value: string; onChange: (v: string) => void }) => (
    <div>
      <span data-testid="numpad-value">{value}</span>
      <button
        data-testid="numpad-set-25"
        onClick={() => onChange('25')}
      >
        Set 25
      </button>
    </div>
  ),
}));

// Voucher tender state — the test mutates this between cases.
let mockVoucherTenders: VoucherTenderRow[] = [];
const mockRemoveVoucherPayment = vi.fn<(code: string) => void>();

vi.mock('@/stores/paymentStore', () => ({
  usePaymentStore: <T,>(selector: (s: {
    voucherTenders: VoucherTenderRow[];
    removeVoucherPayment: (code: string) => void;
  }) => T): T => selector({
    voucherTenders: mockVoucherTenders,
    removeVoucherPayment: mockRemoveVoucherPayment,
  }),
}));

import { AdvancedPaymentsModal } from '../AdvancedPaymentsModal';

const cashMethod: PaymentMethod = {
  id: 'pm-cash',
  code: 'CASH',
  name: 'Cash',
  is_physical: true,
  has_maturity: false,
  requires_third_party: false,
  is_push: false,
  has_deducted_fees: false,
  is_restricted: false,
  fee_type: null,
  fee_fixed: '0.00',
  fee_percent: '0.00',
  restriction_type: null,
  is_active: true,
  position: 1,
};

const storeVoucherMethod: PaymentMethod = {
  ...cashMethod,
  id: 'pm-store-voucher',
  code: 'store_voucher',
  name: 'Store Voucher',
  is_physical: false,
  position: 2,
};

const cashRepo: PaymentRepository = {
  id: 'repo-cash',
  code: 'CASH-DRAWER',
  name: 'Cash Drawer',
  type: 'cash_register',
  bank_name: null,
  account_number: null,
  iban: null,
  bic: null,
  balance: '0.00',
  is_active: true,
};

const virtualRepo: PaymentRepository = {
  ...cashRepo,
  id: 'repo-virtual',
  code: 'VIRTUAL',
  name: 'Virtual',
  type: 'virtual',
};

const bankAccountRepo: PaymentRepository = {
  ...cashRepo,
  id: 'repo-bank',
  code: 'MAIN-BANK',
  name: 'Main Bank Account',
  type: 'bank_account',
};

function renderModal(overrides: {
  total?: number;
  paymentMethods?: PaymentMethod[];
  paymentRepositories?: PaymentRepository[];
  onComplete?: (payments: AdvancedPaymentLine[]) => Promise<void>;
  /**
   * Codex review B5 (2026-05-01): when supplied, the modal opens
   * VoucherTenderModal on instrument-bearing tile taps. When omitted
   * (default) the modal falls back to the B4 dead-end message — that's
   * the documented contract for callers that don't yet pass a db handle.
   */
  voucherDb?: unknown | null;
} = {}) {
  const onComplete = overrides.onComplete ?? vi.fn().mockResolvedValue(undefined);
  return {
    onComplete,
    ...render(
      <AdvancedPaymentsModal
        isOpen
        onClose={vi.fn()}
        total={overrides.total ?? 50}
        paymentMethods={overrides.paymentMethods ?? [cashMethod, storeVoucherMethod]}
        paymentRepositories={overrides.paymentRepositories ?? [cashRepo, virtualRepo]}
        onComplete={onComplete}
        isProcessing={false}
        error={null}
        voucherDb={overrides.voucherDb as never}
      />,
    ),
  };
}

const mockDb = {} as unknown;

describe('AdvancedPaymentsModal — B4: route instrument-bearing taps through voucher flow', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockVoucherTenders = [];
  });

  /**
   * Codex review B4 (2026-04-30) UI half: tapping a `store_voucher` /
   * `restaurant_voucher` / `gift_card` payment-method tile must NOT create a
   * free-form `PaymentLineItem` (which would carry no instrument fields and
   * the server would 422 on at the validator). Instead the tap MUST route
   * through the voucher tender flow (the dedicated `VoucherTenderModal`
   * scan/lookup path that writes a `VoucherTenderRow` with the voucher code
   * as `instrument_serial`). For B4 scope, the tap can no-op or open a stub —
   * what matters is that no normal payment line is added. B5 wires the actual
   * mount of `VoucherTenderModal`.
   */
  it('tapping store_voucher method tile does NOT add a free-form payment line', () => {
    renderModal({
      total: 50,
      paymentMethods: [cashMethod, storeVoucherMethod],
    });

    // Tap the Store Voucher tile.
    fireEvent.click(screen.getByText('Store Voucher'));

    // The "Add Payment" button must NOT appear (the modal must not have
    // entered the per-line config flow for a voucher tap). If it did, the
    // cashier could populate amount + repository + click Add Payment, which
    // is exactly the free-form path B4 forbids.
    expect(screen.queryByText('advancedPayments.addPayment')).not.toBeInTheDocument();
  });

  it('tapping store_voucher method tile WITHOUT a voucherDb falls back to the dead-end cashier message (B4 fallback)', () => {
    // Codex review B5 (2026-05-01): when the parent doesn't supply a
    // voucherDb handle (e.g. pre-shift terminals where the SQLite handle
    // isn't open yet, or a misconfigured mount), the modal cannot open
    // VoucherTenderModal — but it MUST NOT silently no-op either, or the
    // cashier will think the tile is broken. The fallback is the original
    // B4 dead-end message. This guarantees ANY user feedback is shown,
    // which is the contract B4 nailed down.
    renderModal({
      total: 50,
      paymentMethods: [cashMethod, storeVoucherMethod],
      // voucherDb omitted on purpose
    });

    fireEvent.click(screen.getByText('Store Voucher'));

    expect(
      screen.getByText('advancedPayments.voucherTenderFlowRequired'),
    ).toBeInTheDocument();

    // No voucher modal in the tree (would fail because the mock returns
    // null when isOpen is false; queryByTestId is the safe assertion).
    expect(screen.queryByTestId('voucher-tender-modal-mock')).not.toBeInTheDocument();
  });

  it('tapping cash method tile still adds a normal PaymentLineItem (regression guard)', async () => {
    const { onComplete } = renderModal({
      total: 50,
      paymentMethods: [cashMethod, storeVoucherMethod],
    });

    fireEvent.click(screen.getByText('Cash'));
    fireEvent.click(screen.getByText(/advancedPayments.payRemaining/i));
    fireEvent.click(screen.getByText('advancedPayments.addPayment'));

    const completeBtn = screen.getByText('advancedPayments.completeTransaction').closest('button');
    expect(completeBtn).not.toBeDisabled();
    fireEvent.click(completeBtn!);
    await Promise.resolve();

    expect(onComplete).toHaveBeenCalledOnce();
    const payments = vi.mocked(onComplete).mock.calls[0]![0];
    expect(payments).toHaveLength(1);
    expect(payments[0]).toEqual(expect.objectContaining({
      payment_method_id: 'pm-cash',
      amount: 50,
      repository_id: 'repo-cash',
    }));
    // Cash rows must continue to land WITHOUT instrument metadata.
    expect(payments[0].instrument_type).toBeUndefined();
    expect(payments[0].instrument_serial).toBeUndefined();
  });
});

/**
 * Codex review B5 (2026-05-01): voucher tender flow now opens
 * VoucherTenderModal as a child overlay when an instrument-bearing tile is
 * tapped AND the parent supplied a voucherDb handle. Apply path: the modal
 * pushes the tender into paymentStore.voucherTenders and calls onApplied,
 * which closes the overlay so the cashier sees the tender row appear in
 * AdvancedPaymentsModal's payments list.
 *
 * Tests fail on parent commit 5b65cd06 (where the modal is NOT mounted) and
 * pass after this change.
 */
describe('AdvancedPaymentsModal — B5: VoucherTenderModal mount + apply', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockVoucherTenders = [];
  });

  it('tapping store_voucher tile WITH a voucherDb opens VoucherTenderModal (no dead-end message)', () => {
    renderModal({
      total: 50,
      paymentMethods: [cashMethod, storeVoucherMethod],
      voucherDb: mockDb,
    });

    fireEvent.click(screen.getByText('Store Voucher'));

    expect(screen.getByTestId('voucher-tender-modal-mock')).toBeInTheDocument();
    // The B4 dead-end message must NOT appear when the modal opens — that
    // message is reserved for the no-db fallback path.
    expect(
      screen.queryByText('advancedPayments.voucherTenderFlowRequired'),
    ).not.toBeInTheDocument();
    // The free-form Add Payment button must remain absent — taps on
    // instrument-bearing tiles never enter the cash/card config flow.
    expect(screen.queryByText('advancedPayments.addPayment')).not.toBeInTheDocument();
  });

  it('VoucherTenderModal receives the remaining due (currency-formatted) and currency code', () => {
    renderModal({
      total: 50,
      paymentMethods: [cashMethod, storeVoucherMethod],
      voucherDb: mockDb,
    });

    fireEvent.click(screen.getByText('Store Voucher'));

    // Currency mock fixes EUR with 2 decimals; full 50.00 still due.
    expect(
      screen.getByTestId('voucher-tender-modal-remaining-due').textContent,
    ).toBe('50.00');
    expect(
      screen.getByTestId('voucher-tender-modal-currency').textContent,
    ).toBe('EUR');
  });

  it('VoucherTenderModal closes after onApplied (cashier sees the tender row appear in this modal)', () => {
    renderModal({
      total: 50,
      paymentMethods: [cashMethod, storeVoucherMethod],
      voucherDb: mockDb,
    });

    fireEvent.click(screen.getByText('Store Voucher'));
    expect(screen.getByTestId('voucher-tender-modal-mock')).toBeInTheDocument();

    // Simulate the modal applying a voucher.
    fireEvent.click(screen.getByTestId('voucher-tender-modal-mock-apply'));

    // The modal must close (onApplied → setIsVoucherTenderModalOpen(false)).
    expect(screen.queryByTestId('voucher-tender-modal-mock')).not.toBeInTheDocument();
  });

  it('tapping store_voucher again (after a voucher was applied) reopens VoucherTenderModal for stacking', () => {
    // Pre-applied voucher tender already in the store (mocked).
    mockVoucherTenders = [{ code: 'SV-EXISTING-001', amount: '20.00' }];

    renderModal({
      total: 50,
      paymentMethods: [cashMethod, storeVoucherMethod],
      voucherDb: mockDb,
    });

    // Existing voucher tender row visible.
    expect(screen.getByTestId('voucher-tender-row-SV-EXISTING-001')).toBeInTheDocument();

    fireEvent.click(screen.getByText('Store Voucher'));

    // New modal opened for the next voucher.
    expect(screen.getByTestId('voucher-tender-modal-mock')).toBeInTheDocument();
  });

  it('VoucherTenderModal mock-close reverts the modal-open flag without breaking the modal', () => {
    renderModal({
      total: 50,
      paymentMethods: [cashMethod, storeVoucherMethod],
      voucherDb: mockDb,
    });

    fireEvent.click(screen.getByText('Store Voucher'));
    expect(screen.getByTestId('voucher-tender-modal-mock')).toBeInTheDocument();

    fireEvent.click(screen.getByTestId('voucher-tender-modal-mock-close'));

    expect(screen.queryByTestId('voucher-tender-modal-mock')).not.toBeInTheDocument();
    // No dead-end message appears either — the cashier just dismissed.
    expect(
      screen.queryByText('advancedPayments.voucherTenderFlowRequired'),
    ).not.toBeInTheDocument();
  });

  it('tapping cash tile with a voucherDb still adds a free-form payment line (regression guard)', () => {
    renderModal({
      total: 50,
      paymentMethods: [cashMethod, storeVoucherMethod],
      voucherDb: mockDb,
    });

    fireEvent.click(screen.getByText('Cash'));

    // Voucher modal must NOT appear — cash is not instrument-bearing.
    expect(screen.queryByTestId('voucher-tender-modal-mock')).not.toBeInTheDocument();
    // Free-form Add Payment button must appear.
    expect(screen.getByText('advancedPayments.addPayment')).toBeInTheDocument();
  });

  it('voucherDb prop matches the value forwarded to VoucherTenderModal', () => {
    renderModal({
      total: 50,
      paymentMethods: [cashMethod, storeVoucherMethod],
      voucherDb: mockDb,
    });

    fireEvent.click(screen.getByText('Store Voucher'));

    // The mock captures props on every render — the latest call's `db`
    // must be the same object passed via voucherDb.
    expect(mockVoucherTenderModalProps).toHaveBeenCalled();
    const calls = mockVoucherTenderModalProps.mock.calls;
    const lastProps = calls[calls.length - 1]![0] as {
      db: unknown;
    };
    expect(lastProps.db).toBe(mockDb);
  });
});

describe('AdvancedPaymentsModal — B3-followup Finding 1: voucher tender wiring', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockVoucherTenders = [];
  });

  it('renders voucher tender rows from paymentStore alongside cash/card lines', () => {
    mockVoucherTenders = [
      { code: 'SV-2026-0099', amount: '20.00' },
    ];

    renderModal({ total: 20 });

    expect(screen.getByTestId('voucher-tender-row-SV-2026-0099')).toBeInTheDocument();
    expect(screen.getByText('SV-2026-0099')).toBeInTheDocument();
  });

  it('voucher tender amount counts toward totalPaid and unlocks Complete', () => {
    mockVoucherTenders = [
      { code: 'SV-2026-0099', amount: '50.00' },
    ];

    renderModal({ total: 50 });

    // Complete button must be enabled because voucher tenders cover the full total.
    const completeBtn = screen.getByText('advancedPayments.completeTransaction').closest('button');
    expect(completeBtn).not.toBeDisabled();
  });

  it('voucher tender with insufficient amount keeps Complete disabled and shows remaining', () => {
    mockVoucherTenders = [
      { code: 'SV-2026-0099', amount: '20.00' },
    ];

    renderModal({ total: 50 });

    const completeBtn = screen.getByText('advancedPayments.completeTransaction').closest('button');
    expect(completeBtn).toBeDisabled();

    // Remaining label appears (30 EUR still due).
    expect(screen.getByText('advancedPayments.remaining')).toBeInTheDocument();
  });

  it('Complete merges voucher tenders into AdvancedPaymentLine[] with instrument_type + instrument_serial', async () => {
    mockVoucherTenders = [
      { code: 'SV-2026-0099', amount: '50.00' },
    ];

    const { onComplete } = renderModal({ total: 50 });

    const completeBtn = screen.getByText('advancedPayments.completeTransaction').closest('button');
    fireEvent.click(completeBtn!);

    // Wait for the async onComplete handler to settle.
    await Promise.resolve();

    expect(onComplete).toHaveBeenCalledOnce();
    const payments = vi.mocked(onComplete).mock.calls[0]![0];
    expect(payments).toHaveLength(1);
    expect(payments[0]).toEqual({
      payment_method_id: 'pm-store-voucher',
      amount: 50,
      repository_id: 'repo-virtual',
      instrument_type: 'store_voucher',
      instrument_serial: 'SV-2026-0099',
    });
  });

  it('Complete merges BOTH voucher tenders AND local cash/card payment lines', async () => {
    // Voucher partial (20) + cash partial (30) = 50.
    mockVoucherTenders = [
      { code: 'SV-2026-0099', amount: '20.00' },
    ];

    const { onComplete } = renderModal({ total: 50 });

    // Add a cash payment line for the remaining 30. Mirrors the real cashier
    // flow: select Cash, type 30, click "Add Payment".
    fireEvent.click(screen.getByText('Cash'));
    fireEvent.click(screen.getByTestId('numpad-set-25')); // sets to 25 — bump.
    // Re-set to 30 by clicking Set 25 once doesn't get us there; instead use
    // the "Pay Remaining" pill which fills the input with the outstanding
    // balance, exactly the cashier UX path.
    fireEvent.click(screen.getByText(/advancedPayments.payRemaining/i));
    fireEvent.click(screen.getByText('advancedPayments.addPayment'));

    const completeBtn = screen.getByText('advancedPayments.completeTransaction').closest('button');
    expect(completeBtn).not.toBeDisabled();
    fireEvent.click(completeBtn!);
    await Promise.resolve();

    expect(onComplete).toHaveBeenCalledOnce();
    const payments = vi.mocked(onComplete).mock.calls[0]![0];
    expect(payments).toHaveLength(2);
    // Cash line first (kept in modal-local list order), voucher last.
    expect(payments[0]).toEqual(expect.objectContaining({
      payment_method_id: 'pm-cash',
      repository_id: 'repo-cash',
    }));
    expect(payments[0].instrument_type).toBeUndefined();
    expect(payments[0].instrument_serial).toBeUndefined();
    expect(payments[1]).toEqual({
      payment_method_id: 'pm-store-voucher',
      amount: 20,
      repository_id: 'repo-virtual',
      instrument_type: 'store_voucher',
      instrument_serial: 'SV-2026-0099',
    });
  });

  it('clicking remove on a voucher tender row calls removeVoucherPayment(code) on the store', () => {
    mockVoucherTenders = [
      { code: 'SV-2026-0099', amount: '20.00' },
    ];

    renderModal({ total: 50 });

    const removeBtn = screen
      .getByTestId('voucher-tender-row-SV-2026-0099')
      .querySelector('button[aria-label="advancedPayments.delete"]');
    expect(removeBtn).not.toBeNull();
    fireEvent.click(removeBtn!);

    expect(mockRemoveVoucherPayment).toHaveBeenCalledWith('SV-2026-0099');
  });

  it('Complete with voucher tenders fails loudly when store_voucher PaymentMethod is not configured', async () => {
    mockVoucherTenders = [
      { code: 'SV-2026-0099', amount: '50.00' },
    ];

    const { onComplete } = renderModal({
      total: 50,
      // No store_voucher method configured.
      paymentMethods: [cashMethod],
    });

    const completeBtn = screen.getByText('advancedPayments.completeTransaction').closest('button');
    fireEvent.click(completeBtn!);
    await Promise.resolve();

    // onComplete must NOT have been called — the modal surfaces an error.
    expect(onComplete).not.toHaveBeenCalled();
    expect(screen.getByText('advancedPayments.voucherMethodMissing')).toBeInTheDocument();
  });

  /**
   * B3-followup audit (Minor 2, 2026-05-01): the bank_account fallback for
   * voucher repository must be REMOVED. A tenant with no `virtual` repo but
   * a configured `bank_account` repo was silently routing voucher tenders to
   * a bank account ID — correct v3 hash but wrong GL journal (voucher
   * liability posted against bank account = reconciliation drift).
   *
   * This test MUST fail on parent commit 55dced44 (which has the fallback)
   * and MUST pass on the new HEAD (where the fallback is dropped).
   */
  it('Complete with voucher tenders fails with voucherRepositoryMissing when only bank_account repo is configured (no virtual repo)', async () => {
    mockVoucherTenders = [
      { code: 'SV-2026-9999', amount: '50.00' },
    ];

    const { onComplete } = renderModal({
      total: 50,
      // Tenant has cash_register + bank_account repos — but NO virtual repo.
      paymentRepositories: [cashRepo, bankAccountRepo],
    });

    const completeBtn = screen.getByText('advancedPayments.completeTransaction').closest('button');
    fireEvent.click(completeBtn!);
    await Promise.resolve();

    // onComplete must NOT be called — the modal must surface a clear error.
    expect(onComplete).not.toHaveBeenCalled();

    // The error message must be visible (not silently routed to bank_account).
    expect(
      screen.getByText('advancedPayments.voucherRepositoryMissing'),
    ).toBeInTheDocument();

    // Defensive: confirm the bank_account repo ID was NOT passed to onComplete.
    // (This assertion is redundant given onComplete wasn't called, but makes
    // the intent explicit for future readers.)
    expect(onComplete).not.toHaveBeenCalledWith(
      expect.arrayContaining([
        expect.objectContaining({ repository_id: 'repo-bank' }),
      ]),
    );
  });
});
