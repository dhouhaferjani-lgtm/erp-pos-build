/**
 * v3-refund-chain-integration spec §4.5 — RefundPayoutReconciliationModal.
 *
 * Pure-UI-plus-orchestration test: the real `useRefundReconciliationStore`
 * drives the refresh trigger; the repository/print/authoring layers are
 * mocked (each has its own unit tests elsewhere). `isTauriEnvironment()`
 * naturally returns false in jsdom (no `window.__TAURI_INTERNALS__`), so
 * `attemptPrint()`'s own internal short-circuit is exercised for free —
 * these tests assert the "print did not happen, printed_at stays null"
 * branch, which is the actual, honest behavior in this test environment.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, fireEvent, act } from '@testing-library/react';
import { RefundPayoutReconciliationModal } from '../RefundPayoutReconciliationModal';
import { useAuthStore } from '@/stores/authStore';
import { useTerminalStore } from '@/stores/terminalStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { useRefundReconciliationStore } from '@/stores/refundReconciliationStore';
import type { RefundIntentRow } from '@/lib/db/repositories/refundIntentRepository';

vi.mock('react-i18next', () => ({
  initReactI18next: { type: '3rdParty', init: () => {} },
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) =>
      typeof opts?.['amount'] === 'string' ? `${key}:${opts['amount']}` : key,
    i18n: { language: 'en' },
  }),
}));

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn().mockResolvedValue({}),
}));

vi.mock('@/lib/db/repositories/refundIntentRepository', () => ({
  getRefundIntentsPendingPayoutConfirmation: vi.fn(),
  getRefundIntentsPendingReprint: vi.fn(),
  confirmRefundIntentPayout: vi.fn().mockResolvedValue(undefined),
  disputeRefundIntentPayout: vi.fn().mockResolvedValue(undefined),
  confirmRefundIntentPrinted: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/refundFlow/refundApprovalV3', () => ({
  authorPayoutDisputeEvidence: vi.fn().mockResolvedValue({ approval_id: 'a1', approval_event_id: 'fe1' }),
}));

vi.mock('@/lib/offline/getOfflineReceiptForPrint', () => ({
  getOfflineReceiptForPrint: vi.fn(),
}));

import {
  getRefundIntentsPendingPayoutConfirmation,
  getRefundIntentsPendingReprint,
  confirmRefundIntentPayout,
  disputeRefundIntentPayout,
  confirmRefundIntentPrinted,
} from '@/lib/db/repositories/refundIntentRepository';
import { authorPayoutDisputeEvidence } from '@/lib/refundFlow/refundApprovalV3';
import { getOfflineReceiptForPrint } from '@/lib/offline/getOfflineReceiptForPrint';

function intent(overrides: Partial<RefundIntentRow> = {}): RefundIntentRow {
  return {
    id: 'refund-intent-1',
    terminal_id: 'terminal-1',
    operator_id: 'operator-1',
    original_local_receipt_id: 'orig-1',
    original_fiscal_event_id: 'fe-orig-1',
    line_snapshot_json: '[]',
    line_snapshot_fingerprint: 'fp-1',
    approval_source_event_id: 'approval-src-1',
    override_source_event_id: 'override-src-1',
    refund_fiscal_event_id: 'fe-refund-1',
    state: 'refund_event_appended',
    payout_confirmed_at: null,
    payout_disputed_at: null,
    printed_at: null,
    created_at: '2026-07-31T10:00:00Z',
    updated_at: '2026-07-31T10:00:00Z',
    ...overrides,
  };
}

function fullReceipt(total = '-20.000') {
  return {
    id: 'offline-receipt-1',
    receipt_number: 'MAIN-T01-2026-00000007',
    receipt_type: 'sale',
    posted_at: '2026-07-31T10:05:00Z',
    cashier_name: 'Cashier One',
    subtotal: total,
    tax_amount: '0.000',
    discount_amount: '0.000',
    total,
    tolerance_writeoff: null,
    cash_rounding_adjustment: null,
    currency: 'EUR',
    fiscal_hash: 'hash',
    customer_name: null,
    notes: null,
    company: { name: 'Test Co', address_street: null, address_street_2: null, address_city: null, address_postal_code: null, country_code: 'FR', tax_id: null, phone: null },
    terminal: { id: 'terminal-1', name: 'T01', code: 'T01' },
    lines: [],
    vat_details: [],
    payments: [],
  } as never;
}

beforeEach(() => {
  vi.clearAllMocks();
  useRefundReconciliationStore.setState({ epoch: 0 });
  useAuthStore.setState({
    companyId: 'company-1',
    user: { id: 'user-1', tenantId: 'tenant-1', name: 'Cashier', email: 'c@test.com', roles: [], permissions: [] } as never,
  } as never);
  useTerminalStore.setState({
    terminal: { id: 'terminal-1', is_training_mode: false, location: null } as never,
  } as never);
  useOperatorStore.setState({
    operator: { id: 'operator-1', name: 'Cashier One', roles: ['cashier'] } as never,
  } as never);

  vi.mocked(getRefundIntentsPendingPayoutConfirmation).mockResolvedValue([]);
  vi.mocked(getRefundIntentsPendingReprint).mockResolvedValue([]);
  vi.mocked(getOfflineReceiptForPrint).mockResolvedValue(fullReceipt());
});

describe('RefundPayoutReconciliationModal', () => {
  it('renders nothing when there is nothing pending', async () => {
    render(<RefundPayoutReconciliationModal />);

    await waitFor(() => {
      expect(getRefundIntentsPendingPayoutConfirmation).toHaveBeenCalled();
    });
    expect(screen.queryByTestId('refund-reconciliation-modal')).toBeNull();
  });

  it('shows the payout-confirmation prompt with the refund amount for the oldest pending row', async () => {
    vi.mocked(getRefundIntentsPendingPayoutConfirmation).mockResolvedValue([intent()]);
    vi.mocked(getOfflineReceiptForPrint).mockResolvedValue(fullReceipt('-20.000'));

    render(<RefundPayoutReconciliationModal />);

    await waitFor(() => {
      expect(screen.getByTestId('refund-reconciliation-confirm-body')).toBeInTheDocument();
    });
    expect(screen.getByTestId('refund-reconciliation-confirm-body').textContent).toContain(
      'refundFlow.reconciliation.confirmBody',
    );
  });

  it('Yes: confirms the payout and re-queries (no dispute evidence authored)', async () => {
    vi.mocked(getRefundIntentsPendingPayoutConfirmation).mockResolvedValue([intent()]);

    render(<RefundPayoutReconciliationModal />);
    await waitFor(() => {
      expect(screen.getByTestId('refund-reconciliation-confirm')).toBeInTheDocument();
    });

    await act(async () => {
      fireEvent.click(screen.getByTestId('refund-reconciliation-confirm'));
    });

    await waitFor(() => {
      expect(confirmRefundIntentPayout).toHaveBeenCalledWith(expect.anything(), 'refund-intent-1');
    });
    expect(disputeRefundIntentPayout).not.toHaveBeenCalled();
    expect(authorPayoutDisputeEvidence).not.toHaveBeenCalled();
    // isTauriEnvironment() is false in jsdom -- print never happens, so
    // printed_at is never stamped from this path in this environment.
    expect(confirmRefundIntentPrinted).not.toHaveBeenCalled();
    // Re-queried after the action.
    expect(getRefundIntentsPendingPayoutConfirmation).toHaveBeenCalledTimes(2);
  });

  it('No/Not sure: disputes the payout AND authors the §4.5 dispute-evidence event', async () => {
    vi.mocked(getRefundIntentsPendingPayoutConfirmation).mockResolvedValue([intent()]);

    render(<RefundPayoutReconciliationModal />);
    await waitFor(() => {
      expect(screen.getByTestId('refund-reconciliation-dispute')).toBeInTheDocument();
    });

    await act(async () => {
      fireEvent.click(screen.getByTestId('refund-reconciliation-dispute'));
    });

    await waitFor(() => {
      expect(disputeRefundIntentPayout).toHaveBeenCalledWith(expect.anything(), 'refund-intent-1');
    });
    expect(authorPayoutDisputeEvidence).toHaveBeenCalledWith(
      expect.objectContaining({
        refundFiscalEventId: 'fe-refund-1',
        refundIntentId: 'refund-intent-1',
        operator: expect.objectContaining({ id: 'operator-1' }),
      }),
    );
    expect(confirmRefundIntentPayout).not.toHaveBeenCalled();
  });

  it('a dispute-evidence authoring failure does not block the reconciliation flow (non-fatal)', async () => {
    vi.mocked(getRefundIntentsPendingPayoutConfirmation).mockResolvedValue([intent()]);
    vi.mocked(authorPayoutDisputeEvidence).mockRejectedValueOnce(new Error('network error'));
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});

    render(<RefundPayoutReconciliationModal />);
    await waitFor(() => {
      expect(screen.getByTestId('refund-reconciliation-dispute')).toBeInTheDocument();
    });

    await act(async () => {
      fireEvent.click(screen.getByTestId('refund-reconciliation-dispute'));
    });

    await waitFor(() => {
      expect(disputeRefundIntentPayout).toHaveBeenCalledTimes(1);
    });
    expect(consoleError).toHaveBeenCalled();
    consoleError.mockRestore();
  });

  it('shows the reprint prompt when there is no pending confirmation but a pending reprint exists', async () => {
    vi.mocked(getRefundIntentsPendingPayoutConfirmation).mockResolvedValue([]);
    vi.mocked(getRefundIntentsPendingReprint).mockResolvedValue([
      intent({ payout_confirmed_at: '2026-07-31T10:10:00Z' }),
    ]);

    render(<RefundPayoutReconciliationModal />);

    await waitFor(() => {
      expect(screen.getByTestId('refund-reconciliation-reprint-body')).toBeInTheDocument();
    });
  });

  it('Skip on the reprint prompt closes without confirming print (row is untouched, re-prompts next time)', async () => {
    vi.mocked(getRefundIntentsPendingReprint).mockResolvedValue([
      intent({ payout_confirmed_at: '2026-07-31T10:10:00Z' }),
    ]);

    render(<RefundPayoutReconciliationModal />);
    await waitFor(() => {
      expect(screen.getByTestId('refund-reconciliation-skip')).toBeInTheDocument();
    });

    fireEvent.click(screen.getByTestId('refund-reconciliation-skip'));

    await waitFor(() => {
      expect(screen.queryByTestId('refund-reconciliation-modal')).toBeNull();
    });
    expect(confirmRefundIntentPrinted).not.toHaveBeenCalled();
  });

  it('re-queries when useRefundReconciliationStore.refresh() bumps the epoch (the post-v4-settle trigger)', async () => {
    render(<RefundPayoutReconciliationModal />);
    await waitFor(() => {
      expect(getRefundIntentsPendingPayoutConfirmation).toHaveBeenCalledTimes(1);
    });

    act(() => {
      useRefundReconciliationStore.getState().refresh();
    });

    await waitFor(() => {
      expect(getRefundIntentsPendingPayoutConfirmation).toHaveBeenCalledTimes(2);
    });
  });
});
