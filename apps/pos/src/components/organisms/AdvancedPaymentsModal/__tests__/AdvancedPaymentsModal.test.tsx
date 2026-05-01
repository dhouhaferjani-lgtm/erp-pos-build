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

function renderModal(overrides: {
  total?: number;
  paymentMethods?: PaymentMethod[];
  paymentRepositories?: PaymentRepository[];
  onComplete?: (payments: AdvancedPaymentLine[]) => Promise<void>;
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
      />,
    ),
  };
}

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
});
