import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import type { AttachedCheckoutCustomer } from '@/stores/paymentStore';
import type { AccountChargeOverrideApprovalInput } from '@/lib/accountCharge/accountChargeService';

vi.mock('react-i18next', () => ({
  initReactI18next: { type: '3rdParty', init: () => {} },
  useTranslation: () => ({
    t: (key: string, opts?: { defaultValue?: string }) =>
      opts?.defaultValue ?? key,
  }),
}));

vi.mock('@/api/managersApi', () => ({
  fetchAuthorizedManagers: vi.fn().mockResolvedValue([{ id: 'm1', name: 'Mgr' }]),
}));

vi.mock('@/lib/operatorApproval/scopedManagerPin', () => ({
  verifyScopedManagerPin: vi.fn().mockResolvedValue({ id: 'm1', name: 'Mgr', roles: [] }),
}));

const APPROVAL_CONTEXT = {
  tenantId: 'tenant-1',
  companyId: 'company-1',
  terminalId: 'terminal-1',
  cashierUserId: 'c1',
  businessDate: '2026-06-04',
  isTraining: false,
};

let currentCustomer: AttachedCheckoutCustomer | null = null;

vi.mock('@/stores/paymentStore', () => ({
  usePaymentStore: <T,>(selector: (state: { selectedCustomer: AttachedCheckoutCustomer | null }) => T): T =>
    selector({ selectedCustomer: currentCustomer }),
}));

import { AccountChargeConfirmation } from '../AccountChargeConfirmation';
import { verifyScopedManagerPin } from '@/lib/operatorApproval/scopedManagerPin';

const FIXED_NOW = new Date('2026-06-04T12:00:00.000Z');

function makeCustomer(overrides: Partial<AttachedCheckoutCustomer> = {}): AttachedCheckoutCustomer {
  return {
    id: 'cust-1',
    tenant_id: 'tenant-1',
    company_id: 'company-1',
    name: 'Acme Garage',
    phone: null,
    email: null,
    tax_number: null,
    customer_category: null,
    receivable_balance: '10.000',
    credit_balance: '0.000',
    credit_limit: '1000.000',
    payment_terms_days: 30,
    charge_account_enabled: 1,
    charge_policy_version: 'v1',
    account_status: 'active',
    account_status_changed_at: null,
    account_status_reason: null,
    account_status_version: 1,
    balance_updated_at: '2026-06-04T11:59:00.000Z',
    is_active: 1,
    customer_sync_status: 'synced',
    ...overrides,
  } as AttachedCheckoutCustomer;
}

describe('AccountChargeConfirmation', () => {
  beforeEach(() => {
    currentCustomer = makeCustomer();
  });

  it('shows balance impact and enables confirm for an approved decision', async () => {
    const onConfirm = vi.fn().mockResolvedValue(undefined);
    render(
      <AccountChargeConfirmation
        total="119.000"
        currency="TND"
        cashierUserId="c1"
        onConfirm={onConfirm}
        onCancel={vi.fn()}
        isProcessing={false}
        now={() => FIXED_NOW}
      />,
    );

    expect(screen.getByText('Acme Garage')).toBeInTheDocument();

    const confirm = await screen.findByRole('button', { name: /charge to account/i });
    expect(confirm).toBeEnabled();

    fireEvent.click(confirm);
    await waitFor(() => expect(onConfirm).toHaveBeenCalledWith(null));
  });

  it('shows manager PIN on credit-limit rejection and confirms with override after PIN', async () => {
    currentCustomer = makeCustomer({ credit_limit: '100.000', receivable_balance: '90.000' });
    const onConfirm = vi.fn().mockResolvedValue(undefined);

    render(
      <AccountChargeConfirmation
        total="119.000"
        currency="TND"
        cashierUserId="c1"
        approvalContext={APPROVAL_CONTEXT}
        onConfirm={onConfirm}
        onCancel={vi.fn()}
        isProcessing={false}
        now={() => FIXED_NOW}
      />,
    );

    const rejectionMessage = await screen.findByTestId('rejection-message');
    expect(rejectionMessage).toHaveTextContent(/credit limit/i);
    expect(await screen.findByTestId('manager-pin-panel')).toBeInTheDocument();

    // Confirm should be disabled before override is captured.
    const confirm = screen.getByRole('button', { name: /charge to account/i });
    expect(confirm).toBeDisabled();

    // Drive the ManagerPinPanel to success: select manager, enter PIN, verify.
    const select = screen.getByTestId('manager-pin-select');
    fireEvent.change(select, { target: { value: 'm1' } });

    fireEvent.click(screen.getByTestId('numpad-digit-1'));
    fireEvent.click(screen.getByTestId('numpad-digit-2'));
    fireEvent.click(screen.getByTestId('numpad-digit-3'));
    fireEvent.click(screen.getByTestId('numpad-digit-4'));

    fireEvent.click(screen.getByTestId('manager-pin-verify'));

    await waitFor(() => expect(confirm).toBeEnabled());

    fireEvent.click(confirm);

    await waitFor(() => expect(onConfirm).toHaveBeenCalledTimes(1));
    const override = onConfirm.mock.calls[0]?.[0] as AccountChargeOverrideApprovalInput | null;
    expect(override).not.toBeNull();
    if (override === null) throw new Error('override should not be null');
    expect(override.approvalScope).toBe('credit_limit_override');
    expect(override.cashierUserId).toBe('c1');
    expect(override.reasonCode).toBe('credit_limit_exceeded');
    expect(override.supervisorUserId).toBe('m1');
    expect(override.supervisorUserSnapshot).toEqual({ name: 'Mgr' });

    // The override must go through the canonical scoped (audited, online-confirm
    // + offline-fallback) path — not the divergent {user_id,pin}-only endpoint.
    expect(vi.mocked(verifyScopedManagerPin)).toHaveBeenCalledWith(
      expect.objectContaining({
        pin: '1234',
        context: APPROVAL_CONTEXT,
        approvalScope: 'credit_limit_override',
        targetOperatorId: 'm1',
      }),
    );
  });

  it('invalidates a captured override when the charge amount changes', async () => {
    currentCustomer = makeCustomer({ credit_limit: '100.000', receivable_balance: '90.000' });
    const onConfirm = vi.fn().mockResolvedValue(undefined);

    const { rerender } = render(
      <AccountChargeConfirmation
        total="119.000"
        currency="TND"
        cashierUserId="c1"
        approvalContext={APPROVAL_CONTEXT}
        onConfirm={onConfirm}
        onCancel={vi.fn()}
        isProcessing={false}
        now={() => FIXED_NOW}
      />,
    );

    // Capture an override via the credit-limit rejection -> PIN success path.
    expect(await screen.findByTestId('rejection-message')).toHaveTextContent(/credit limit/i);
    expect(await screen.findByTestId('manager-pin-panel')).toBeInTheDocument();

    fireEvent.change(screen.getByTestId('manager-pin-select'), { target: { value: 'm1' } });
    fireEvent.click(screen.getByTestId('numpad-digit-1'));
    fireEvent.click(screen.getByTestId('numpad-digit-2'));
    fireEvent.click(screen.getByTestId('numpad-digit-3'));
    fireEvent.click(screen.getByTestId('numpad-digit-4'));
    fireEvent.click(screen.getByTestId('manager-pin-verify'));

    const confirm = screen.getByRole('button', { name: /charge to account/i });
    await waitFor(() => expect(confirm).toBeEnabled());

    // Re-render with a different (larger) charge amount. The stale override must
    // be cleared, so confirm is no longer enabled via the override.
    rerender(
      <AccountChargeConfirmation
        total="500.000"
        currency="TND"
        cashierUserId="c1"
        approvalContext={APPROVAL_CONTEXT}
        onConfirm={onConfirm}
        onCancel={vi.fn()}
        isProcessing={false}
        now={() => FIXED_NOW}
      />,
    );

    await waitFor(() => expect(screen.queryByTestId('override-captured')).not.toBeInTheDocument());
    expect(screen.getByRole('button', { name: /charge to account/i })).toBeDisabled();

    // The stale override is never passed to onConfirm.
    expect(onConfirm).not.toHaveBeenCalled();
  });

  // Defensive normalization (final-review bug): the modal could hand a
  // non-canonical `total` like `'119'` (whole number, no decimals) for a
  // scale-2 currency. The strict credit-decision parser requires an exact
  // `^\d+\.\d{2}$` match, so without normalization this rejects with
  // `money_scale_invalid` and the Confirm button stays disabled. The
  // confirmation normalizes `props.total` to the currency scale before
  // feeding the engine, so a whole-number total must still be confirmable.
  it('normalizes a non-canonical whole-number total to the currency scale (no money_scale_invalid)', async () => {
    currentCustomer = makeCustomer({
      // Scale-2 (EUR) balances so the engine runs at scale 2.
      receivable_balance: '0.00',
      credit_balance: '0.00',
      credit_limit: '500.00',
    });
    const onConfirm = vi.fn().mockResolvedValue(undefined);

    render(
      <AccountChargeConfirmation
        total="119"
        currency="EUR"
        cashierUserId="c1"
        onConfirm={onConfirm}
        onCancel={vi.fn()}
        isProcessing={false}
        now={() => FIXED_NOW}
      />,
    );

    // The strict-parser rejection must NOT appear...
    expect(screen.queryByTestId('rejection-message')).not.toBeInTheDocument();
    // ...and the Confirm button is enabled (approved decision).
    const confirm = await screen.findByRole('button', { name: /charge to account/i });
    expect(confirm).toBeEnabled();

    fireEvent.click(confirm);
    await waitFor(() => expect(onConfirm).toHaveBeenCalledWith(null));
  });

  it('blocks with a message and no PIN on a hard rejection (account closed)', async () => {
    currentCustomer = makeCustomer({ account_status: 'closed' });
    render(
      <AccountChargeConfirmation
        total="119.000"
        currency="TND"
        cashierUserId="c1"
        onConfirm={vi.fn()}
        onCancel={vi.fn()}
        isProcessing={false}
        now={() => FIXED_NOW}
      />,
    );

    expect(await screen.findByText(/closed/i)).toBeInTheDocument();
    expect(screen.queryByTestId('manager-pin-panel')).not.toBeInTheDocument();

    const confirm = screen.getByRole('button', { name: /charge to account/i });
    expect(confirm).toBeDisabled();
  });
});
