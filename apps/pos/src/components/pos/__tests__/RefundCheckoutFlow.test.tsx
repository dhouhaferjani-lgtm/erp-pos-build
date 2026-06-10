/**
 * Pure-UI tests for RefundCheckoutFlow (Task 2b).
 *
 * The REAL refundCheckoutStore drives the steps (state is injected with
 * setState); the settlement/approval service modules are mocked here ONLY
 * because this is a pure-UI test — the orchestration itself is tested against
 * the real service (with mocked HTTP) in refundCheckoutStore.test.ts.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import { RefundCheckoutFlow } from '../RefundCheckoutFlow';
import { useRefundCheckoutStore } from '@/stores/refundCheckoutStore';
import { useCartStore } from '@/stores/cartStore';
import type { PosOverrideContext } from '@/lib/operatorApproval/posOverrideAuthoring';
import type { CartItem } from '@/types/cart';

vi.mock('react-i18next', () => ({
  initReactI18next: { type: '3rdParty', init: () => {} },
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) => {
      if (typeof opts?.['message'] === 'string' && opts['message'] !== '') {
        return `${key}:${String(opts['message'])}`;
      }
      return key;
    },
    i18n: { language: 'en' },
  }),
}));

// Pure-UI test: the orchestration store is real, but its service dependencies
// are stubbed so a click cannot reach SQLite/HTTP from jsdom.
vi.mock('@/lib/refundFlow/refundSettlementService', async () => {
  const actual = await vi.importActual<
    typeof import('@/lib/refundFlow/refundSettlementService')
  >('@/lib/refundFlow/refundSettlementService');
  return {
    ...actual,
    prepareRefundSettlement: vi.fn(),
    submitRefundReturn: vi.fn(),
  };
});
vi.mock('@/lib/refundFlow/refundApproval', () => ({
  authorRefundReturnApproval: vi.fn(),
  syncRefundApprovalEvents: vi.fn(),
}));

import {
  submitRefundReturn,
} from '@/lib/refundFlow/refundSettlementService';
import {
  authorRefundReturnApproval,
  syncRefundApprovalEvents,
} from '@/lib/refundFlow/refundApproval';

const approvalContext: PosOverrideContext = {
  tenantId: 'tenant-1',
  companyId: 'company-1',
  terminalId: 'terminal-1',
  cashierUserId: 'cashier-1',
  businessDate: '2026-06-10',
  isTraining: false,
};

const prepared = {
  serverReceiptId: 'server-receipt-1',
  lines: [{ line_id: 'line-1', quantity: '2.0000' }],
  serverLines: [],
};

const approvalEvidence = {
  approval_id: 'approval-uuid',
  approval_fiscal_event_id: 'fe-approval-1',
  approval_scope: 'void_or_return_override' as const,
  approval_supervisor_user_id: 'manager-9',
  approval_override_event_id: 'fe-override-1',
  authorized_by_user_id: 'manager-9',
};

const settlementResponse = {
  id: 'return-receipt-1',
  receipt_number: 'L01-T01-00043',
  receipt_type: 'return',
  original_receipt_id: 'server-receipt-1',
  return_reason: 'other',
  subtotal: '-20.00',
  tax_amount: '-3.80',
  total: '-23.80',
  currency: 'EUR',
  posted_at: '2026-06-10T09:00:00Z',
  qr_token: null,
  issued_voucher: null,
  lines: [],
};

function returnItem(): CartItem {
  return {
    id: 'return-item-1',
    product: { id: 'prod-1', name: 'Widget A', sku: 'PROD-001', price: '10.00' },
    quantity: -2,
    unit_price: '10.00',
    line_total: '-20.00',
    tax_rate: '19.00',
    tax_amount: '-3.80',
    kind: 'return',
  };
}

function renderFlow(onRefundSettled = vi.fn()) {
  render(
    <RefundCheckoutFlow
      approvalContext={approvalContext}
      terminalId="terminal-1"
      cashierUserId="cashier-1"
      onRefundSettled={onRefundSettled}
    />,
  );
  return onRefundSettled;
}

beforeEach(() => {
  vi.clearAllMocks();
  useRefundCheckoutStore.getState().reset();
  useCartStore.setState({
    items: [returnItem()],
    transactionDiscount: undefined,
    cartSessionId: null,
    cartLinesRemovedThisSession: 0,
  });
  vi.mocked(submitRefundReturn).mockResolvedValue({ ok: true, response: settlementResponse });
  vi.mocked(authorRefundReturnApproval).mockResolvedValue(approvalEvidence);
  vi.mocked(syncRefundApprovalEvents).mockResolvedValue(undefined);
});

describe('RefundCheckoutFlow', () => {
  it('renders nothing while idle without error', () => {
    renderFlow();
    expect(screen.queryByTestId('refund-destination-picker')).toBeNull();
    expect(screen.queryByTestId('refund-confirm-modal')).toBeNull();
    expect(screen.queryByTestId('refund-approval-modal')).toBeNull();
    expect(screen.queryByTestId('refund-checkout-error-banner')).toBeNull();
  });

  it('shows the dismissible prepare-failure banner while idle with an error', () => {
    useRefundCheckoutStore.setState({
      step: 'idle',
      error: { key: 'refundFlow.checkout.errorNotSynced', serverMessage: null },
    });
    renderFlow();

    const banner = screen.getByTestId('refund-checkout-error-banner');
    expect(banner.textContent).toContain('refundFlow.checkout.errorNotSynced');

    fireEvent.click(screen.getByTestId('refund-checkout-error-dismiss'));
    expect(useRefundCheckoutStore.getState().error).toBeNull();
  });

  it('interpolates the server message into the validation error', () => {
    useRefundCheckoutStore.setState({
      step: 'idle',
      error: { key: 'refundFlow.checkout.errorValidation', serverMessage: 'Cap exceeded.' },
    });
    renderFlow();

    expect(screen.getByTestId('refund-checkout-error-banner').textContent).toContain(
      'Cap exceeded.',
    );
  });

  it('walks destination → confirm (with destination summary) → approval through the real store', () => {
    useRefundCheckoutStore.setState({
      step: 'destination',
      prepared,
      receiptNumber: 'L01-T01-00042',
    });
    renderFlow();

    // Destination step
    expect(screen.getByTestId('refund-destination-picker')).toBeTruthy();
    fireEvent.click(screen.getByTestId('refund-destination-radio-cash'));
    fireEvent.click(screen.getByTestId('refund-destination-confirm'));

    // Confirm step — shows the amount + the chosen destination
    expect(useRefundCheckoutStore.getState().step).toBe('confirm');
    expect(screen.getByTestId('refund-confirm-modal')).toBeTruthy();
    expect(screen.getByTestId('refund-destination-summary').textContent).toContain(
      'refundFlow.destination.cash',
    );
    fireEvent.click(screen.getByTestId('refund-confirm-submit'));

    // Approval step
    return waitFor(() => {
      expect(useRefundCheckoutStore.getState().step).toBe('approval');
      expect(screen.getByTestId('refund-approval-modal')).toBeTruthy();
    });
  });

  it('cancel on the approval step aborts back to the cart and clears the PIN state', async () => {
    useRefundCheckoutStore.setState({
      step: 'approval',
      prepared,
      receiptNumber: 'L01-T01-00042',
      destination: 'cash',
    });
    renderFlow();

    fireEvent.change(screen.getByLabelText(/voidReturn.managerPin/), {
      target: { value: '4321' },
    });
    fireEvent.click(screen.getByTestId('refund-approval-cancel'));

    expect(useRefundCheckoutStore.getState().step).toBe('idle');
    expect(useRefundCheckoutStore.getState().prepared).toBeNull();
    // Cart untouched.
    expect(useCartStore.getState().items).toHaveLength(1);
    // PIN input unmounted — re-opening the step starts blank.
    act(() => {
      useRefundCheckoutStore.setState({
        step: 'approval',
        prepared,
        receiptNumber: 'L01-T01-00042',
        destination: 'cash',
      });
    });
    await waitFor(() => {
      const pin = screen.getByLabelText(/voidReturn.managerPin/);
      expect((pin as HTMLInputElement).value).toBe('');
    });
  });

  it('authorize is disabled until a 4-digit PIN is entered, then submits and settles', async () => {
    const onSettled = vi.fn();
    useRefundCheckoutStore.setState({
      step: 'approval',
      prepared,
      receiptNumber: 'L01-T01-00042',
      destination: 'cash',
      refundItemsSnapshot: [returnItem()],
    });
    renderFlow(onSettled);

    const authorize = screen.getByTestId('refund-approval-authorize');
    expect((authorize as HTMLButtonElement).disabled).toBe(true);

    fireEvent.change(screen.getByLabelText(/voidReturn.managerPin/), {
      target: { value: '4321' },
    });
    expect((authorize as HTMLButtonElement).disabled).toBe(false);

    fireEvent.click(authorize);

    await waitFor(() => {
      expect(useRefundCheckoutStore.getState().step).toBe('settled');
    });
    expect(onSettled).toHaveBeenCalledWith(settlementResponse);
    // The approval modal closed.
    expect(screen.queryByTestId('refund-approval-modal')).toBeNull();
  });

  it('shows the approval error inside the modal when the submit fails', () => {
    useRefundCheckoutStore.setState({
      step: 'approval',
      prepared,
      receiptNumber: 'L01-T01-00042',
      destination: 'cash',
      error: { key: 'refundFlow.checkout.errorApproval', serverMessage: null },
    });
    renderFlow();

    expect(screen.getByTestId('refund-approval-error').textContent).toContain(
      'refundFlow.checkout.errorApproval',
    );
    // The idle banner is NOT shown for approval-step errors.
    expect(screen.queryByTestId('refund-checkout-error-banner')).toBeNull();
  });

  it('shows a blocking busy overlay while preparing (Fix 4)', () => {
    useRefundCheckoutStore.setState({ step: 'preparing' });
    renderFlow();

    const overlay = screen.getByTestId('refund-checkout-busy-overlay');
    expect(overlay.textContent).toContain('refundFlow.checkout.preparing');
  });

  it('renders the refund amount from the begin() snapshot, NOT the live cart (Fix 3)', () => {
    useRefundCheckoutStore.setState({
      step: 'confirm',
      prepared,
      receiptNumber: 'L01-T01-00042',
      destination: 'cash',
      // Snapshot says 20.00 was prepared …
      refundItemsSnapshot: [returnItem()],
    });
    // … but the live cart has since drifted to a 10.00 return line.
    useCartStore.setState({
      items: [{ ...returnItem(), quantity: -1, line_total: '-10.00' }],
    });
    renderFlow();

    const amount = screen.getByTestId('refund-amount-value').textContent ?? '';
    expect(amount).toContain('20,00');
    expect(amount).not.toContain('10,00');
  });

  it('freezes the reason and PIN inputs once approval evidence is cached (Fix 6)', () => {
    useRefundCheckoutStore.setState({
      step: 'approval',
      prepared,
      receiptNumber: 'L01-T01-00042',
      destination: 'cash',
      refundItemsSnapshot: [returnItem()],
      approval: approvalEvidence,
      error: { key: 'refundFlow.checkout.errorApprovalSync', serverMessage: null },
    });
    renderFlow();

    const reason = screen.getByLabelText(/voidReturn.reason/) as HTMLInputElement;
    const pin = screen.getByLabelText(/voidReturn.managerPin/) as HTMLInputElement;
    expect(reason.disabled).toBe(true);
    expect(pin.disabled).toBe(true);
    // Retry does NOT require a second PIN entry — authorize stays enabled
    // even with an empty PIN field.
    const authorize = screen.getByTestId('refund-approval-authorize') as HTMLButtonElement;
    expect(authorize.disabled).toBe(false);
  });

  it('visibly disables the modal close affordance while submitting (Fix 7d)', () => {
    useRefundCheckoutStore.setState({
      step: 'submitting',
      prepared,
      receiptNumber: 'L01-T01-00042',
      destination: 'cash',
      refundItemsSnapshot: [returnItem()],
    });
    renderFlow();

    const close = screen.getByTestId('modal-close-button') as HTMLButtonElement;
    expect(close.disabled).toBe(true);
  });
});
