/**
 * AdvancedPaymentsModal — On Account integration (final-review bug, 2026-06-05)
 *
 * Regression lock for a CRITICAL seam bug found in final review: the modal
 * handed AccountChargeConfirmation a numeric `total` via `String(total)`, so a
 * whole-number EUR total (e.g. 119) reached the strict credit-decision parser
 * as `"119"`. That parser requires an exact `^\d+\.\d{2}$` match for a scale-2
 * currency, so it rejected with `money_scale_invalid` and the real "Charge to
 * account" Confirm button stayed disabled — EUR charges were un-confirmable.
 *
 * Unlike the sibling AdvancedPaymentsModal.test.tsx (which STUBS the
 * confirmation to focus on tile/mode wiring), this test renders the REAL
 * AccountChargeConfirmation so the modal -> confirmation -> credit-engine seam
 * is exercised end to end. Only the boundaries are mocked (managersApi,
 * scopedManagerPin, the two stores, useCurrency); bcformat, formatCurrency,
 * getCurrencyDecimals and the credit-rules engine all run for real.
 *
 * WITHOUT the modal-boundary fix (`total={bcformat(String(total), decimals)}`)
 * AND the defensive normalization inside the confirmation, this test fails: the
 * `money_scale_invalid` rejection renders and Confirm is disabled.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
// The modal does not forward a `now` clock to the real confirmation, so the
// credit engine uses real `new Date()`. Build a fresh snapshot timestamp so the
// balance never trips the hard-stale guard (240 min) regardless of run time.
const FRESH_BALANCE_AT = new Date(Date.now() - 60_000).toISOString();
import { render, screen, fireEvent } from '@testing-library/react';
import type { PaymentMethod, PaymentRepository } from '@/types/payment';
import type { AttachedCheckoutCustomer, VoucherTenderRow } from '@/stores/paymentStore';

vi.mock('react-i18next', () => ({
  initReactI18next: { type: '3rdParty', init: () => {} },
  useTranslation: () => ({
    t: (key: string, opts?: { defaultValue?: string }) => opts?.defaultValue ?? key,
  }),
}));

// Keep real currency math (formatCurrency / getCurrencyDecimals) so the real
// confirmation runs at the true EUR scale; only force useCurrency to EUR/2.
vi.mock('@/lib/currency', async () => {
  const actual = await vi.importActual<typeof import('@/lib/currency')>('@/lib/currency');
  return {
    ...actual,
    useCurrency: () => ({
      currency: 'EUR',
      decimals: 2,
      format: (amount: number) => `${amount.toFixed(2)} EUR`,
    }),
  };
});

// Boundary mocks: manager lookup / PIN verification are network seams. They are
// never reached on the approved happy path, but the real confirmation imports
// them at module load.
vi.mock('@/api/managersApi', () => ({
  fetchAuthorizedManagers: vi.fn().mockResolvedValue([{ id: 'm1', name: 'Mgr' }]),
}));
vi.mock('@/lib/operatorApproval/scopedManagerPin', () => ({
  verifyScopedManagerPin: vi.fn().mockResolvedValue({ id: 'm1', name: 'Mgr', roles: [] }),
}));

// VoucherTenderModal pulls heavier deps; the account-charge path never opens it.
vi.mock('@/components/pos/VoucherTenderModal', () => ({
  VoucherTenderModal: () => null,
}));

// NumPad is only used in the split-tender working area, which is replaced by the
// confirmation in account-charge mode.
vi.mock('@/components/molecules/NumPad', () => ({
  NumPad: () => null,
}));

let currentCustomer: AttachedCheckoutCustomer | null = null;
const mockVoucherTenders: VoucherTenderRow[] = [];
const mockRemoveVoucherPayment = vi.fn<(code: string) => void>();

vi.mock('@/stores/paymentStore', () => ({
  usePaymentStore: <T,>(
    selector: (s: {
      voucherTenders: VoucherTenderRow[];
      removeVoucherPayment: (code: string) => void;
      selectedCustomer: AttachedCheckoutCustomer | null;
    }) => T,
  ): T =>
    selector({
      voucherTenders: mockVoucherTenders,
      removeVoucherPayment: mockRemoveVoucherPayment,
      selectedCustomer: currentCustomer,
    }),
}));

vi.mock('@/stores/authStore', () => ({
  useAuthStore: {
    getState: () => ({ user: { id: 'cashier-1' } }),
  },
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

// An eligible, approving customer at total 119 under a scale-2 (EUR) currency:
// credit_limit 500.00, zero balances, charge enabled, active, fresh snapshot.
function approvingEurCustomer(): AttachedCheckoutCustomer {
  return {
    id: 'cust-1',
    tenant_id: 'tenant-1',
    company_id: 'company-1',
    name: 'Acme Garage',
    phone: null,
    email: null,
    tax_number: null,
    customer_category: null,
    receivable_balance: '0.00',
    credit_balance: '0.00',
    credit_limit: '500.00',
    payment_terms_days: 30,
    charge_account_enabled: 1,
    charge_policy_version: 'v1',
    account_status: 'active',
    account_status_changed_at: null,
    account_status_reason: null,
    account_status_version: 1,
    balance_updated_at: FRESH_BALANCE_AT,
    is_active: 1,
    customer_sync_status: 'synced',
  };
}

describe('AdvancedPaymentsModal — On Account integration (real confirmation + credit engine)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    currentCustomer = approvingEurCustomer();
  });

  it('a whole-number EUR total is confirmable: no money_scale_invalid, Confirm enabled', async () => {
    render(
      <AdvancedPaymentsModal
        isOpen
        onClose={vi.fn()}
        // Numeric whole-number total — String(119) === '119', which the strict
        // scale-2 parser rejects unless normalized at the seam.
        total={119}
        paymentMethods={[cashMethod]}
        paymentRepositories={[cashRepo]}
        onComplete={vi.fn().mockResolvedValue(undefined)}
        isProcessing={false}
        error={null}
        onChargeToAccount={vi.fn().mockResolvedValue(undefined)}
      />,
    );

    // Enter account-charge mode by tapping the synthetic "On Account" tile.
    fireEvent.click(screen.getByRole('button', { name: /On Account/i }));

    // The REAL confirmation is mounted (not a stub).
    expect(screen.getByTestId('account-charge-confirmation')).toBeInTheDocument();

    // The bug surface: a scale mismatch would render this rejection message.
    expect(screen.queryByTestId('rejection-message')).not.toBeInTheDocument();
    expect(screen.queryByText(/could not be validated/i)).not.toBeInTheDocument();

    // The seam works end to end: the engine APPROVED, so Confirm is enabled.
    const confirm = await screen.findByTestId('account-charge-confirm');
    expect(confirm).toBeEnabled();
  });
});
