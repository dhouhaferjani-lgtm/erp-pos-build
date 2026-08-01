/**
 * v3-refund-chain-integration spec §4.5 — RefundPayoutReconciliationModal.
 *
 * **Wave-2 fix-wave finding 17 (fiscal I-5) — the repository is NO LONGER
 * MOCKED.** This suite used to `vi.fn()`-mock
 * `getRefundIntentsPendingPayoutConfirmation` with a fixed array, which is
 * precisely the query carrying finding 5's defect (no
 * `payout_disputed_at IS NULL` term). With a fixed mock the post-dispute
 * `refresh()` returned the same row and the test simply never asserted
 * what the operator sees next, so the infinite-re-prompt loop passed
 * green while the real device trapped the cashier behind a
 * non-dismissible modal whose only escape was a false "Yes" attestation.
 *
 * The whole `refundIntentRepository` now runs FOR REAL against an
 * in-memory SQLite database (the `SqliteTestAdapter` + real `migrations`
 * pattern used by the repository's own suite), so the queries, the
 * guarded transitions and the affected-row assertions are exercised
 * end-to-end from the UI. Only the fiscal-authoring and print layers stay
 * mocked (they need an engine/printer of their own).
 *
 * `isTauriEnvironment()` naturally returns false in jsdom (no
 * `window.__TAURI_INTERNALS__`), so `attemptPrint()`'s own internal
 * short-circuit is exercised for free — these tests assert the "print did
 * not happen, printed_at stays null" branch, which is the actual, honest
 * behavior in this test environment.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

/** Live adapter handle, shared with the hoisted `@/lib/db` factory. */
const h = vi.hoisted(() => ({ db: null as unknown }));
import { render, screen, waitFor, fireEvent, act } from '@testing-library/react';
import { RefundPayoutReconciliationModal } from '../RefundPayoutReconciliationModal';
import { useAuthStore } from '@/stores/authStore';
import { useTerminalStore } from '@/stores/terminalStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { useRefundReconciliationStore } from '@/stores/refundReconciliationStore';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import { applyAllMigrations } from '@/lib/db/__tests__/helpers/migrationTestHelpers';

vi.mock('react-i18next', () => ({
  initReactI18next: { type: '3rdParty', init: () => {} },
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) =>
      typeof opts?.['amount'] === 'string' ? `${key}:${opts['amount']}` : key,
    i18n: { language: 'en' },
  }),
}));

// `@/lib/db` is a set of thin wrappers over `Database.select/execute` —
// re-implemented verbatim over the live adapter so the REAL repository
// runs against REAL SQLite (finding 17).
vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn(async () => h.db),
  queryAll: vi.fn(async (db: never, sql: string, params: unknown[] = []) =>
    (db as unknown as SqliteTestAdapter).select(sql, params)),
  queryOne: vi.fn(async (db: never, sql: string, params: unknown[] = []) => {
    const rows = await (db as unknown as SqliteTestAdapter).select<unknown[]>(sql, params);
    return rows[0] ?? null;
  }),
  execute: vi.fn(async (db: never, sql: string, params: unknown[] = []) =>
    (db as unknown as SqliteTestAdapter).execute(sql, params)),
}));

vi.mock('@/lib/refundFlow/refundApprovalV3', () => ({
  authorPayoutDisputeEvidence: vi.fn().mockResolvedValue({ approval_id: 'a1', approval_event_id: 'fe1' }),
}));

vi.mock('@/lib/offline/getOfflineReceiptForPrint', () => ({
  getOfflineReceiptForPrint: vi.fn(),
}));

import {
  createOrReuseActiveRefundIntent,
  confirmRefundIntentPayout,
  markApprovalAuthored,
  markRefundEventAppended,
  getRefundIntentById,
} from '@/lib/db/repositories/refundIntentRepository';
import { authorPayoutDisputeEvidence } from '@/lib/refundFlow/refundApprovalV3';
import { getOfflineReceiptForPrint } from '@/lib/offline/getOfflineReceiptForPrint';

const INTENT_ID = 'refund-intent-1';
const ORIGINAL_LOCAL_RECEIPT_ID = 'orig-1';

/** Seeds a REAL `refund_intents` row that has reached
 *  `refund_event_appended` — the state §4.5's payout prompt keys on. */
async function seedAppendedIntent(id = INTENT_ID): Promise<void> {
  const db = (h.db as SqliteTestAdapter).asDatabase();
  const { intent } = await createOrReuseActiveRefundIntent(db, {
    id,
    terminalId: 'terminal-1',
    operatorId: 'operator-1',
    originalLocalReceiptId: ORIGINAL_LOCAL_RECEIPT_ID,
    originalFiscalEventId: 'fe-orig-1',
    lineSnapshot: [{ originalLineIndex: 0, quantity: '1.000' }],
    approvalSourceEventId: `approval-src-${id}`,
    overrideSourceEventId: `override-src-${id}`,
  });
  await markApprovalAuthored(db, intent.id);
  await markRefundEventAppended(db, intent.id, 'fe-refund-1');
}

async function readIntent(id = INTENT_ID) {
  return getRefundIntentById((h.db as SqliteTestAdapter).asDatabase(), id);
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

beforeEach(async () => {
  vi.clearAllMocks();
  h.db = new SqliteTestAdapter();
  await applyAllMigrations(h.db as SqliteTestAdapter);
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

  vi.mocked(getOfflineReceiptForPrint).mockResolvedValue(fullReceipt());
});

afterEach(() => {
  (h.db as SqliteTestAdapter).close();
});

describe('RefundPayoutReconciliationModal (real refundIntentRepository — finding 17)', () => {
  it('renders nothing when there is nothing pending', async () => {
    render(<RefundPayoutReconciliationModal />);

    await waitFor(() => {
      expect(vi.mocked(getOfflineReceiptForPrint)).not.toHaveBeenCalled();
    });
    expect(screen.queryByTestId('refund-reconciliation-modal')).toBeNull();
  });

  it('shows the payout-confirmation prompt with the refund amount for the oldest pending row', async () => {
    await seedAppendedIntent();

    render(<RefundPayoutReconciliationModal />);

    await waitFor(() => {
      expect(screen.getByTestId('refund-reconciliation-confirm-body')).toBeInTheDocument();
    });
    expect(screen.getByTestId('refund-reconciliation-confirm-body').textContent).toContain(
      'refundFlow.reconciliation.confirmBody',
    );
  });

  it('Yes: confirms the payout in the REAL row and the prompt disappears', async () => {
    await seedAppendedIntent();

    render(<RefundPayoutReconciliationModal />);
    await waitFor(() => {
      expect(screen.getByTestId('refund-reconciliation-confirm')).toBeInTheDocument();
    });

    await act(async () => {
      fireEvent.click(screen.getByTestId('refund-reconciliation-confirm'));
    });

    await waitFor(async () => {
      expect((await readIntent())?.payout_confirmed_at).not.toBeNull();
    });
    expect((await readIntent())?.payout_disputed_at).toBeNull();
    expect(authorPayoutDisputeEvidence).not.toHaveBeenCalled();
    // isTauriEnvironment() is false in jsdom -- print never happens, so
    // printed_at is never stamped from this path in this environment.
    expect((await readIntent())?.printed_at).toBeNull();
  });

  it('finding 5 — "No / Not sure" resolves the payout ONCE and the prompt does NOT re-appear', async () => {
    await seedAppendedIntent();

    render(<RefundPayoutReconciliationModal />);
    await waitFor(() => {
      expect(screen.getByTestId('refund-reconciliation-dispute')).toBeInTheDocument();
    });

    await act(async () => {
      fireEvent.click(screen.getByTestId('refund-reconciliation-dispute'));
    });

    // The REAL row is disputed…
    await waitFor(async () => {
      expect((await readIntent())?.payout_disputed_at).not.toBeNull();
    });
    expect((await readIntent())?.payout_confirmed_at).toBeNull();

    // …and the §4.5 evidence event was authored BEFORE the marker
    // (finding 13 — order is the durability mechanism).
    expect(authorPayoutDisputeEvidence).toHaveBeenCalledWith(
      expect.objectContaining({
        refundFiscalEventId: 'fe-refund-1',
        refundIntentId: INTENT_ID,
        operator: expect.objectContaining({ id: 'operator-1' }),
      }),
    );

    // THE REGRESSION THIS FILE EXISTS FOR: the confirm prompt must be
    // GONE. With the old mocked query (and the old missing
    // `payout_disputed_at IS NULL` term) the very same row came back and
    // the cashier was re-prompted forever, with a false "Yes" as the only
    // escape.
    await waitFor(() => {
      expect(screen.queryByTestId('refund-reconciliation-confirm')).toBeNull();
    });
    expect(screen.queryByTestId('refund-reconciliation-confirm-body')).toBeNull();
  });

  it('finding 5 — a DISPUTED-but-unprinted intent enters REPRINT recovery (not limbo)', async () => {
    await seedAppendedIntent();

    render(<RefundPayoutReconciliationModal />);
    await waitFor(() => {
      expect(screen.getByTestId('refund-reconciliation-dispute')).toBeInTheDocument();
    });
    await act(async () => {
      fireEvent.click(screen.getByTestId('refund-reconciliation-dispute'));
    });

    // Print is a no-op in jsdom, so printed_at stays null → the same row
    // must now surface as a REPRINT prompt rather than vanishing.
    await waitFor(() => {
      expect(screen.getByTestId('refund-reconciliation-reprint-body')).toBeInTheDocument();
    });
  });

  it('finding 13 — a dispute-evidence authoring failure leaves the row UNRESOLVED and the prompt up for retry', async () => {
    await seedAppendedIntent();
    vi.mocked(authorPayoutDisputeEvidence).mockRejectedValueOnce(new Error('network error'));
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});

    render(<RefundPayoutReconciliationModal />);
    await waitFor(() => {
      expect(screen.getByTestId('refund-reconciliation-dispute')).toBeInTheDocument();
    });

    await act(async () => {
      fireEvent.click(screen.getByTestId('refund-reconciliation-dispute'));
    });

    // The marker was NOT set — "dispute recorded" must imply "evidence
    // exists", so a failed authoring cannot leave a marker behind with no
    // signed record and no retry path.
    expect((await readIntent())?.payout_disputed_at).toBeNull();
    expect((await readIntent())?.payout_confirmed_at).toBeNull();
    // …and the cashier can try again: the prompt is still up.
    expect(screen.getByTestId('refund-reconciliation-dispute')).toBeInTheDocument();
    expect(consoleError).toHaveBeenCalled();
    consoleError.mockRestore();
  });

  it('shows the reprint prompt when there is no pending confirmation but a pending reprint exists', async () => {
    await seedAppendedIntent();
    const db = (h.db as SqliteTestAdapter).asDatabase();
    await confirmRefundIntentPayout(db, INTENT_ID);

    render(<RefundPayoutReconciliationModal />);

    await waitFor(() => {
      expect(screen.getByTestId('refund-reconciliation-reprint-body')).toBeInTheDocument();
    });
  });

  it('Skip on the reprint prompt closes without confirming print (row is untouched, re-prompts next time)', async () => {
    await seedAppendedIntent();
    const db = (h.db as SqliteTestAdapter).asDatabase();
    await confirmRefundIntentPayout(db, INTENT_ID);

    render(<RefundPayoutReconciliationModal />);
    await waitFor(() => {
      expect(screen.getByTestId('refund-reconciliation-skip')).toBeInTheDocument();
    });

    fireEvent.click(screen.getByTestId('refund-reconciliation-skip'));

    await waitFor(() => {
      expect(screen.queryByTestId('refund-reconciliation-modal')).toBeNull();
    });
    expect((await readIntent())?.printed_at).toBeNull();
  });

  it('re-queries when useRefundReconciliationStore.refresh() bumps the epoch (the post-v4-settle trigger)', async () => {
    render(<RefundPayoutReconciliationModal />);
    await waitFor(() => {
      expect(screen.queryByTestId('refund-reconciliation-modal')).toBeNull();
    });

    // A refund settles AFTER mount — the epoch bump must surface it.
    await seedAppendedIntent();
    act(() => {
      useRefundReconciliationStore.getState().refresh();
    });

    await waitFor(() => {
      expect(screen.getByTestId('refund-reconciliation-confirm-body')).toBeInTheDocument();
    });
  });
});
