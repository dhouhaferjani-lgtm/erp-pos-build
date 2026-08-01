/**
 * Round-2 fix (finding 11 residual) — `beginV4()`'s REUSE-vs-CAP ordering,
 * tested against the REAL `refundIntentRepository` on REAL SQLite.
 *
 * The main store suite mocks `getCumulativeRefundedQuantityByOriginalLine`
 * to an empty Map, which masks the interleaving entirely (fiscal minor
 * N-4). This file un-mocks it: the cap, the intent state machine, the
 * fingerprint and the linkage verification all run for real, so the
 * ordering is actually exercised.
 *
 * The defect: the cumulative cap counts every intent that has reached a
 * state proving an append — INCLUDING the one about to be reused. For a
 * FULL-quantity refund already appended, the cap therefore saw
 * `alreadyRefunded (full) + thisAttempt (full) > originalQuantity` and
 * refused with `refundQuantityExceeded` BEFORE reuse was discovered. The
 * operator was told they were over-refunding when the refund was in fact
 * already done and merely needed payout/print reconciliation — and
 * `useRefundReconciliationStore.refresh()` never ran.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { CartItem } from '@/types/cart';

const h = vi.hoisted(() => ({ db: null as unknown }));

// `@/lib/db` re-implemented over the live adapter so every repository in
// the graph runs for REAL.
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

// Only the ORIGINAL's signed-event resolution is stubbed (sealing a real
// original is `resolveOriginalFiscalEventLocally.realAuthoring.test.ts`'s
// job) — `getFiscalEventById`, used by the linkage check, stays REAL.
vi.mock('@/lib/db/repositories/fiscalEventRepository', async () => {
  const actual = await vi.importActual<typeof import('@/lib/db/repositories/fiscalEventRepository')>(
    '@/lib/db/repositories/fiscalEventRepository',
  );
  return { ...actual, resolveOriginalFiscalEventLocally: vi.fn() };
});

vi.mock('@/lib/refundFlow/refundApprovalV3', () => ({
  authorRefundReturnApprovalV3: vi.fn(),
  recoverRefundApprovalEvidenceLocally: vi.fn().mockResolvedValue(null),
}));
vi.mock('@/lib/offline/refundReceiptService', () => ({ createRefundReceipt: vi.fn() }));
vi.mock('@/lib/db/repositories/terminalStateRepository', () => ({
  getTerminalState: vi.fn().mockResolvedValue(null),
}));

import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import { applyAllMigrations } from '@/lib/db/__tests__/helpers/migrationTestHelpers';
import { useRefundCheckoutStore } from '../refundCheckoutStore';
import { useAuthStore } from '@/stores/authStore';
import { useTerminalStore } from '@/stores/terminalStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { useCartStore } from '@/stores/cartStore';
import { useRefundReconciliationStore } from '@/stores/refundReconciliationStore';
import { resolveOriginalFiscalEventLocally } from '@/lib/db/repositories/fiscalEventRepository';
import {
  findActiveRefundIntent,
  computeLineSnapshotFingerprint,
  markApprovalAuthored,
  markRefundEventAppended,
} from '@/lib/db/repositories/refundIntentRepository';

const ORIGINAL_ID = 'orig-receipt-uuid-1';
const ORIGINAL_RECEIPT_NUMBER = 'MAIN-T01-2026-00000042';
const TERMINAL_ID = 'terminal-1';
const FISCAL_EVENT_ID = 'fe-refund-1';
const CANONICAL_BYTES = JSON.stringify({ payload: { invoice_type_code: 'REFUND' } });

/** A FULL-quantity return of the original's only line (qty 2). */
function returnItem(): CartItem {
  return {
    id: `return-${ORIGINAL_ID}-0`,
    product: { id: 'prod-1', name: 'Widget A', sku: 'PROD-001', price: '10.000' },
    quantity: -2,
    unit_price: '10.000',
    line_total: '-20.000',
    tax_rate: '0.00',
    tax_amount: '-0.000',
    kind: 'return',
  };
}

function beginInput(items: CartItem[]) {
  return {
    db: (h.db as SqliteTestAdapter).asDatabase(),
    receiptToken: null,
    receiptNumber: ORIGINAL_RECEIPT_NUMBER,
    refundItems: items,
    v4CapabilityEnabled: true,
    originalLocalReceiptId: ORIGINAL_ID,
  };
}

describe('beginV4 — reuse is resolved BEFORE the cumulative cap (finding 11 residual)', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(async () => {
    vi.clearAllMocks();
    adapter = new SqliteTestAdapter();
    h.db = adapter;
    await applyAllMigrations(adapter);

    // The ORIGINAL's own local row — the legacy value bound's ceiling.
    await adapter.execute(
      `INSERT INTO offline_receipts (
         id, idempotency_key, receipt_number, terminal_id, terminal_code,
         operator_id, operator_name, lines, subtotal, tax_amount, discount_amount,
         total, currency, fiscal_hash, previous_hash, hash_sequence,
         payment_method_id, payment_repository_id, status, receipt_kind
       ) VALUES ($1, $1, $2, $3, 'T01', 'op-1', 'Cashier', '[]',
                 '20.000', '0.000', '0.000', '20.000', 'EUR', 'h', 'p', 1,
                 'pm-cash', 'pr-cash', 'synced', 'sale')`,
      [ORIGINAL_ID, ORIGINAL_RECEIPT_NUMBER, TERMINAL_ID],
    );

    useAuthStore.setState({
      companyId: 'company-1',
      user: { id: 'user-1', tenantId: 'tenant-1' } as never,
      companies: [{ id: 'company-1', currency: 'EUR', name: 'Test Co' } as never],
    } as never);
    useTerminalStore.setState({
      terminal: { id: TERMINAL_ID, location: null } as never,
      shift: { id: 'shift-1' } as never,
    } as never);
    useOperatorStore.setState({ operator: { id: 'op-1', name: 'Cashier' } as never } as never);
    useRefundReconciliationStore.setState({ epoch: 0 });
    useRefundCheckoutStore.getState().reset();

    vi.mocked(resolveOriginalFiscalEventLocally).mockResolvedValue({
      fiscalEventId: 'fe-original-1',
      businessDate: '2026-07-30',
      total: '20.000',
      cashRoundingAdjustment: '0.000',
      lineItems: [{ product_id: 'prod-1', quantity: '2.000' }] as never,
      payments: [{ method_code: 'CASH', amount: '20.000' }] as never,
      trainingFlag: false,
      transactionDiscountAmount: '0',
    });
  });

  afterEach(() => {
    adapter.close();
    useCartStore.setState({ items: [] } as never);
  });

  /** Drives a REAL intent to `refund_event_appended` with a REAL fingerprint
   *  by letting `begin()` create it, then transitioning it and seeding the
   *  linkage rows the resume path verifies. */
  async function appendFirstRefund(items: CartItem[]): Promise<string> {
    useCartStore.setState({ items } as never);
    await useRefundCheckoutStore.getState().begin(beginInput(items));
    expect(useRefundCheckoutStore.getState().step).toBe('confirm');
    const intent = useRefundCheckoutStore.getState().refundIntent!;

    const db = adapter.asDatabase();
    await markApprovalAuthored(db, intent.id);
    await markRefundEventAppended(db, intent.id, FISCAL_EVENT_ID);
    await adapter.execute(
      `INSERT INTO fiscal_events (
         id, tenant_id, company_id, terminal_id, operator_id,
         event_type, event_version, signature_version, sequence_number,
         event_time_device, business_date, canonical_bytes,
         previous_hash, current_hash, sync_status, created_at,
         source_event_class, source_event_id
       ) VALUES ($1,'tenant-1','company-1',$2,'op-1','SALE_RECEIPT',4,'v1',2,
                 '2026-08-01T10:00:00Z','2026-08-01',$3,
                 $4,$5,'pending','2026-08-01T10:00:00Z','refund_intents',$6)`,
      [FISCAL_EVENT_ID, TERMINAL_ID, CANONICAL_BYTES, '0'.repeat(64), '1'.repeat(64), intent.id],
    );
    await adapter.execute(
      `INSERT INTO offline_receipts (
         id, idempotency_key, receipt_number, terminal_id, terminal_code,
         operator_id, operator_name, lines, subtotal, tax_amount, discount_amount,
         total, currency, fiscal_hash, previous_hash, hash_sequence,
         payment_method_id, payment_repository_id, status, receipt_kind, canonical_bytes
       ) VALUES ($1, $2, 'AVOIR-1', $3, 'T01', 'op-1', 'Cashier', '[]',
                 '-20.000', '-0.000', '0.000', '-20.000', 'EUR', 'h2', 'p2', 2,
                 'pm-cash', 'pr-cash', 'pending', 'refund', $4)`,
      ['refund-receipt-1', intent.id, TERMINAL_ID, CANONICAL_BYTES],
    );

    useRefundCheckoutStore.getState().reset();
    return intent.id;
  }

  it('a FULL-quantity retry of an already-appended intent routes to reconciliation, NOT refundQuantityExceeded', async () => {
    const items = [returnItem()];
    const intentId = await appendFirstRefund(items);

    // Sanity: the REAL cap now genuinely counts this appended intent, so
    // the pre-fix ordering really would have refused here.
    const fingerprint = await computeLineSnapshotFingerprint(
      JSON.parse(
        (await adapter.select<Array<{ line_snapshot_json: string }>>(
          'SELECT line_snapshot_json FROM refund_intents WHERE id = $1',
          [intentId],
        ))[0]!.line_snapshot_json,
      ) as unknown,
    );
    expect(await findActiveRefundIntent(adapter.asDatabase(), ORIGINAL_ID, fingerprint)).not.toBeNull();

    useCartStore.setState({ items } as never);
    await useRefundCheckoutStore.getState().begin(beginInput(items));

    const state = useRefundCheckoutStore.getState();
    expect(state.step).toBe('idle');
    expect(state.error?.key).toBe('refundFlow.refundAlreadyAppended');
    // …and the payout/reprint prompt is actually surfaced.
    expect(useRefundReconciliationStore.getState().epoch).toBeGreaterThan(0);
  });

  it('the cap still refuses a genuine over-refund (a DIFFERENT selection past the original quantity)', async () => {
    // Append a full-quantity refund, then attempt a SECOND, different
    // selection (qty 1) — a different fingerprint, so no reuse; the cap
    // must fire on its own merits.
    await appendFirstRefund([returnItem()]);

    const partial: CartItem = {
      ...returnItem(),
      quantity: -1,
      line_total: '-10.000',
      tax_amount: '-0.000',
    };
    useCartStore.setState({ items: [partial] } as never);
    await useRefundCheckoutStore.getState().begin(beginInput([partial]));

    const state = useRefundCheckoutStore.getState();
    expect(state.step).toBe('idle');
    expect(state.error?.key).toBe('refundFlow.refundQuantityExceeded');
  });

  it('a corrupt appended intent (dangling fiscal-event reference) fails closed instead of routing to reconciliation', async () => {
    const items = [returnItem()];
    const intentId = await appendFirstRefund(items);
    // `fiscal_events` is append-only (a real trigger), so corruption is
    // modelled the way it could actually occur: the intent points at an
    // event id that is not there. A non-empty id column was all the
    // pre-fix check required.
    await adapter.execute(
      "UPDATE refund_intents SET refund_fiscal_event_id = 'ghost-event' WHERE id = $1",
      [intentId],
    );

    useCartStore.setState({ items } as never);
    await useRefundCheckoutStore.getState().begin(beginInput(items));

    const state = useRefundCheckoutStore.getState();
    expect(state.step).toBe('idle');
    expect(state.error?.key).toBe('refundFlow.checkout.errorInternal');
    expect(useRefundReconciliationStore.getState().epoch).toBe(0);
  });

  it('a corrupt appended intent (receipt bytes disagree with the signed event) fails closed', async () => {
    const items = [returnItem()];
    await appendFirstRefund(items);
    await adapter.execute(
      "UPDATE offline_receipts SET canonical_bytes = 'different-bytes' WHERE id = 'refund-receipt-1'",
    );

    useCartStore.setState({ items } as never);
    await useRefundCheckoutStore.getState().begin(beginInput(items));

    expect(useRefundCheckoutStore.getState().error?.key).toBe('refundFlow.checkout.errorInternal');
  });
});
