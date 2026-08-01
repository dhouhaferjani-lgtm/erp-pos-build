/**
 * Orchestration tests for the refund checkout state machine (Task 2b).
 *
 * The REAL refundSettlementService (and the real line mapper) runs underneath —
 * HTTP is mocked at the api-client boundary and the local qr-index at the
 * repository boundary, mirroring refundSettlementService.test.ts. Only the
 * approval layer (manager-PIN handshake) is mocked: it is a separate module
 * with its own unit tests and hits SQLite/bcrypt/the fiscal engine.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type Database from '@tauri-apps/plugin-sql';
import { ApiRequestError, apiGet, apiPost } from '@/lib/api';
import {
  findReceiptByNumber,
  findReceiptByQrToken,
} from '@/lib/offline/voucherRepository';
import { FetchTimeoutError } from '@/lib/fetchWithTimeout';
import {
  authorRefundReturnApproval,
  syncRefundApprovalEvents,
} from '@/lib/refundFlow/refundApproval';
import type { CartItem } from '@/types/cart';
import { useCartStore } from '@/stores/cartStore';
import { useAuthStore } from '@/stores/authStore';
import { useTerminalStore } from '@/stores/terminalStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { usePaymentStore } from '@/stores/paymentStore';
import { useRefundReconciliationStore } from '@/stores/refundReconciliationStore';
import { useRefundCheckoutStore } from '../refundCheckoutStore';
import type { PosOverrideContext } from '@/lib/operatorApproval/posOverrideAuthoring';

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api');
  return { ...actual, apiGet: vi.fn(), apiPost: vi.fn() };
});

vi.mock('@/lib/offline/voucherRepository', () => ({
  findReceiptByQrToken: vi.fn(),
  findReceiptByNumber: vi.fn(),
}));

const mockConnectivity = vi.fn(() => ({ isOnline: true }));
vi.mock('@/stores/connectivityStore', () => ({
  useConnectivityStore: { getState: () => mockConnectivity() },
}));

vi.mock('@/lib/refundFlow/refundApproval', () => ({
  authorRefundReturnApproval: vi.fn(),
  syncRefundApprovalEvents: vi.fn(),
}));

vi.mock('@/lib/refundFlow/refundZAccounting', () => ({
  recordRefundSettlementForZ: vi.fn(),
}));

// v3-refund-chain-integration spec §9.2/§9.3 — the v4 branch's own
// dependencies, mocked so this file stays a pure orchestration test (the
// individual pieces have their own unit tests: refundApprovalV3.test.ts,
// refundReceiptService.test.ts, refundIntentRepository.test.ts).
vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn().mockResolvedValue({}),
}));
vi.mock('@/lib/db/repositories/fiscalEventRepository', () => ({
  resolveOriginalFiscalEventLocally: vi.fn(),
}));
vi.mock('@/lib/db/repositories/refundIntentRepository', () => ({
  createOrReuseActiveRefundIntent: vi.fn(),
  markApprovalAuthored: vi.fn().mockResolvedValue(undefined),
  getCumulativeRefundedQuantityByOriginalLine: vi.fn().mockResolvedValue(new Map()),
}));
vi.mock('@/lib/db/repositories/terminalStateRepository', () => ({
  getTerminalState: vi.fn().mockResolvedValue(null),
}));
vi.mock('@/lib/offline/refundReceiptService', () => ({
  createRefundReceipt: vi.fn(),
}));
vi.mock('@/lib/refundFlow/refundApprovalV3', () => ({
  authorRefundReturnApprovalV3: vi.fn(),
  // Wave-2 fix-wave finding 11 — resume, don't re-author.
  recoverRefundApprovalEvidenceLocally: vi.fn().mockResolvedValue(null),
}));
vi.mock('@/lib/db/repositories/offlineReceiptRepository', () => ({
  getOfflineReceiptByIdempotencyKey: vi.fn().mockResolvedValue(null),
}));

import { recordRefundSettlementForZ } from '@/lib/refundFlow/refundZAccounting';
import { resolveOriginalFiscalEventLocally } from '@/lib/db/repositories/fiscalEventRepository';
import {
  createOrReuseActiveRefundIntent,
  markApprovalAuthored,
  getCumulativeRefundedQuantityByOriginalLine,
  type RefundIntentRow,
} from '@/lib/db/repositories/refundIntentRepository';
import { createRefundReceipt } from '@/lib/offline/refundReceiptService';
import {
  authorRefundReturnApprovalV3,
  recoverRefundApprovalEvidenceLocally,
} from '@/lib/refundFlow/refundApprovalV3';
import { getOfflineReceiptByIdempotencyKey } from '@/lib/db/repositories/offlineReceiptRepository';

const fakeDb = {} as Database;
const NO_RETRY = { maxRetries: 0, backoffMs: 0 };

const SERVER_RECEIPT_ID = '7d4e2a10-1111-4222-8333-444455556666';
const RECEIPT_NUMBER = 'L01-T01-00042';
const QR_TOKEN = `v:kid:${SERVER_RECEIPT_ID}:mac`;

const approvalContext: PosOverrideContext = {
  tenantId: 'tenant-1',
  companyId: 'company-1',
  terminalId: 'terminal-1',
  cashierUserId: 'cashier-1',
  businessDate: '2026-06-10',
  isTraining: false,
};

const approvalEvidence = {
  approval_id: 'approval-uuid',
  approval_fiscal_event_id: 'fe-approval-1',
  approval_scope: 'void_or_return_override' as const,
  approval_supervisor_user_id: 'manager-9',
  approval_override_event_id: 'fe-override-1',
  authorized_by_user_id: 'manager-9',
};

function qrIndexEntry() {
  return {
    receipt_uuid: SERVER_RECEIPT_ID,
    qr_token: QR_TOKEN,
    receipt_number: RECEIPT_NUMBER,
    terminal_id: 'terminal-1',
    posted_at: '2026-06-09T10:00:00Z',
    total: '23.80',
    currency: 'EUR',
    partner_id: null,
    synced_at: '2026-06-09T10:01:00Z',
  };
}

function returnItem(overrides: Partial<CartItem> = {}): CartItem {
  return {
    id: 'return-item-1',
    product: { id: 'prod-1', name: 'Widget A', sku: 'PROD-001', price: '10.0000' },
    quantity: -2,
    unit_price: '10.0000',
    line_total: '-20.0000',
    tax_rate: '19.00',
    tax_amount: '-3.8000',
    kind: 'return',
    ...overrides,
  };
}

function serverReceipt() {
  return {
    id: SERVER_RECEIPT_ID,
    receipt_number: RECEIPT_NUMBER,
    lines: [
      {
        id: 'line-1',
        product_id: 'prod-1',
        variant_id: null,
        product_code: 'PROD-001',
        product_name: 'Widget A',
        quantity: '3.0000',
        unit_price: '10.0000',
        returned_quantity: '0.0000',
      },
    ],
  };
}

function settlementResponse() {
  return {
    id: 'return-receipt-1',
    receipt_number: 'L01-T01-00043',
    receipt_type: 'return',
    original_receipt_id: SERVER_RECEIPT_ID,
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
}

function beginInput(items: CartItem[] = [returnItem()]) {
  return {
    db: fakeDb,
    receiptToken: QR_TOKEN,
    receiptNumber: RECEIPT_NUMBER,
    refundItems: items,
    retry: NO_RETRY,
    // v3-refund-chain-integration spec §9.2/§9.3 — this ENTIRE file tests
    // the LEGACY path, which must stay byte-intact. false routes begin()
    // to the unchanged legacy branch; the v4 branch has its own test
    // block further down.
    v4CapabilityEnabled: false,
  };
}

function submitInput(overrides: Record<string, unknown> = {}) {
  return {
    approvalContext,
    managerPin: '4321',
    reason: 'changed mind',
    terminalId: 'terminal-1',
    retry: NO_RETRY,
    ...overrides,
  };
}

/**
 * Walk the machine to the approval step (prepare → destination → confirm).
 * The cart is loaded with the SAME items — the stale-cart fingerprint guard
 * compares the live return lines against the begin() snapshot.
 */
async function walkToApproval(items: CartItem[] = [returnItem()]) {
  useCartStore.setState({ items });
  const store = useRefundCheckoutStore.getState();
  await store.begin(beginInput(items));
  useRefundCheckoutStore.getState().selectDestination('cash');
  useRefundCheckoutStore.getState().confirmAccepted();
  expect(useRefundCheckoutStore.getState().step).toBe('approval');
}

beforeEach(() => {
  vi.clearAllMocks();
  mockConnectivity.mockReturnValue({ isOnline: true });
  useRefundCheckoutStore.getState().reset();
  useCartStore.setState({ items: [], transactionDiscount: undefined, cartSessionId: null, cartLinesRemovedThisSession: 0 });
  vi.mocked(findReceiptByQrToken).mockResolvedValue(qrIndexEntry());
  vi.mocked(findReceiptByNumber).mockResolvedValue(qrIndexEntry());
  vi.mocked(apiGet).mockResolvedValue(serverReceipt());
  vi.mocked(apiPost).mockResolvedValue(settlementResponse());
  vi.mocked(authorRefundReturnApproval).mockResolvedValue(approvalEvidence);
  vi.mocked(syncRefundApprovalEvents).mockResolvedValue(undefined);
  vi.mocked(recordRefundSettlementForZ).mockResolvedValue(true);
});

describe('refundCheckoutStore — prepare failures', () => {
  it('NOT_SYNCED → idle with the not-synced message key', async () => {
    vi.mocked(findReceiptByQrToken).mockResolvedValue(null);
    vi.mocked(findReceiptByNumber).mockResolvedValue(null);

    await useRefundCheckoutStore.getState().begin(beginInput());

    const state = useRefundCheckoutStore.getState();
    expect(state.step).toBe('idle');
    expect(state.error?.key).toBe('refundFlow.checkout.errorNotSynced');
  });

  it('LINE_MAPPING_FAILED → idle with the line-mapping message key', async () => {
    await useRefundCheckoutStore
      .getState()
      .begin(beginInput([returnItem({ product: { id: 'prod-x', name: 'Other', sku: 'OTHER-SKU', price: '10.0000' } })]));

    const state = useRefundCheckoutStore.getState();
    expect(state.step).toBe('idle');
    expect(state.error?.key).toBe('refundFlow.checkout.errorLineMapping');
  });

  it('OFFLINE (positive offline evidence) → idle with the offline message key', async () => {
    vi.mocked(apiGet).mockRejectedValue(new FetchTimeoutError('url', 10_000, 'GET'));

    await useRefundCheckoutStore.getState().begin(beginInput());

    const state = useRefundCheckoutStore.getState();
    expect(state.step).toBe('idle');
    expect(state.error?.key).toBe('refundFlow.checkout.errorOffline');
  });

  it('SERVER_ERROR (reachable-but-erroring, retries exhausted) → idle with the server-error key', async () => {
    vi.mocked(apiGet).mockRejectedValue(new ApiRequestError(503, 'unavailable', 'SERVICE_UNAVAILABLE'));

    await useRefundCheckoutStore.getState().begin(beginInput());

    const state = useRefundCheckoutStore.getState();
    expect(state.step).toBe('idle');
    expect(state.error?.key).toBe('refundFlow.checkout.errorServer');
  });

  it('VALIDATION_FAILED → idle with the validation key carrying the server message', async () => {
    vi.mocked(apiGet).mockRejectedValue(
      new ApiRequestError(404, 'Receipt not found.', 'NOT_FOUND'),
    );

    await useRefundCheckoutStore.getState().begin(beginInput());

    const state = useRefundCheckoutStore.getState();
    expect(state.step).toBe('idle');
    expect(state.error?.key).toBe('refundFlow.checkout.errorValidation');
    expect(state.error?.serverMessage).toBe('Receipt not found.');
  });
});

describe('refundCheckoutStore — happy path', () => {
  it('walks prepare → destination → confirm → approval → submit and settles', async () => {
    useCartStore.setState({ items: [returnItem()] });
    const onSettled = vi.fn();

    await useRefundCheckoutStore.getState().begin(beginInput());
    expect(useRefundCheckoutStore.getState().step).toBe('destination');
    expect(useRefundCheckoutStore.getState().prepared?.serverReceiptId).toBe(SERVER_RECEIPT_ID);

    useRefundCheckoutStore.getState().selectDestination('store_voucher');
    expect(useRefundCheckoutStore.getState().step).toBe('confirm');

    useRefundCheckoutStore.getState().confirmAccepted();
    expect(useRefundCheckoutStore.getState().step).toBe('approval');

    await useRefundCheckoutStore.getState().approveAndSubmit(submitInput({ onSettled }));

    const state = useRefundCheckoutStore.getState();
    expect(state.step).toBe('settled');
    expect(state.error).toBeNull();
    expect(state.settledResponse?.receipt_number).toBe('L01-T01-00043');

    // Success clears the refund lines from the cart …
    expect(useCartStore.getState().items).toEqual([]);
    // … and hands the FULL response to the Phase-3 print seam.
    expect(onSettled).toHaveBeenCalledTimes(1);
    expect(onSettled).toHaveBeenCalledWith(settlementResponse());

    // The POST went to the /return endpoint with the mapped lines + destination.
    expect(apiPost).toHaveBeenCalledTimes(1);
    const [url, payload] = vi.mocked(apiPost).mock.calls[0] as [string, Record<string, unknown>];
    expect(url).toBe(`/pos/receipts/${SERVER_RECEIPT_ID}/return`);
    expect(payload['refund_destination']).toBe('store_voucher');
    expect(payload['lines']).toEqual([{ line_id: 'line-1', quantity: '2.0000' }]);
    expect(payload['approval_id']).toBe('approval-uuid');
    expect(payload['authorized_by_user_id']).toBe('manager-9');
    expect(typeof payload['refund_request_id']).toBe('string');
  });

  it('passes an edited-down partial quantity through the REAL mapper to the submit payload', async () => {
    // Receipt line sold 3; cashier edited the refund down to 1.
    const partial = returnItem({ quantity: -1, line_total: '-10.0000' });

    await walkToApproval([partial]);
    await useRefundCheckoutStore.getState().approveAndSubmit(submitInput());

    const [, payload] = vi.mocked(apiPost).mock.calls[0] as [string, Record<string, unknown>];
    expect(payload['lines']).toEqual([{ line_id: 'line-1', quantity: '1.0000' }]);
  });

  it('binds the approval to the server receipt id/number and the mapped line_ids, then syncs the evidence', async () => {
    await walkToApproval();
    await useRefundCheckoutStore.getState().approveAndSubmit(submitInput());

    expect(authorRefundReturnApproval).toHaveBeenCalledWith({
      context: approvalContext,
      managerPin: '4321',
      reason: 'changed mind',
      serverReceiptId: SERVER_RECEIPT_ID,
      receiptNumber: RECEIPT_NUMBER,
      lineIds: ['line-1'],
    });
    expect(syncRefundApprovalEvents).toHaveBeenCalledWith('company-1', approvalEvidence);
  });

  it('begin snapshots the return lines (display totals freeze at begin)', async () => {
    const items = [returnItem()];
    await useRefundCheckoutStore.getState().begin(beginInput(items));

    expect(useRefundCheckoutStore.getState().refundItemsSnapshot).toEqual(items);
  });
});

describe('refundCheckoutStore — serialization', () => {
  it('a second begin (second Pay press) while a checkout is active is a no-op', async () => {
    await useRefundCheckoutStore.getState().begin(beginInput());
    expect(useRefundCheckoutStore.getState().step).toBe('destination');

    await useRefundCheckoutStore.getState().begin(beginInput());

    expect(useRefundCheckoutStore.getState().step).toBe('destination');
    expect(apiGet).toHaveBeenCalledTimes(1);
  });

  it('a second approveAndSubmit while a submit is in flight is a no-op', async () => {
    await walkToApproval();

    let resolvePost: (value: unknown) => void = () => {};
    vi.mocked(apiPost).mockImplementation(
      () => new Promise((resolve) => { resolvePost = resolve; }),
    );

    const first = useRefundCheckoutStore.getState().approveAndSubmit(submitInput());
    const second = useRefundCheckoutStore.getState().approveAndSubmit(submitInput());
    // Wait for the first submit to actually reach the HTTP boundary (the
    // approval await runs first), then release it.
    await vi.waitFor(() => { expect(apiPost).toHaveBeenCalled(); });
    resolvePost(settlementResponse());
    await Promise.all([first, second]);

    expect(apiPost).toHaveBeenCalledTimes(1);
  });
});

describe('refundCheckoutStore — epoch guard (reset mid-flight)', () => {
  it('a reset during begin() kills the continuation — no destination step appears', async () => {
    let resolveGet: (value: unknown) => void = () => {};
    vi.mocked(apiGet).mockImplementation(
      () => new Promise((resolve) => { resolveGet = resolve; }),
    );

    const inFlight = useRefundCheckoutStore.getState().begin(beginInput());
    await vi.waitFor(() => { expect(apiGet).toHaveBeenCalled(); });

    useRefundCheckoutStore.getState().reset();
    resolveGet(serverReceipt());
    await inFlight;

    const state = useRefundCheckoutStore.getState();
    expect(state.step).toBe('idle');
    expect(state.prepared).toBeNull();
    expect(state.error).toBeNull();
  });

  it('a reset during submit kills the continuation — no clearReturnItems, no onSettled', async () => {
    const onSettled = vi.fn();
    await walkToApproval();

    let resolvePost: (value: unknown) => void = () => {};
    vi.mocked(apiPost).mockImplementation(
      () => new Promise((resolve) => { resolvePost = resolve; }),
    );

    const inFlight = useRefundCheckoutStore.getState().approveAndSubmit(submitInput({ onSettled }));
    await vi.waitFor(() => { expect(apiPost).toHaveBeenCalled(); });

    useRefundCheckoutStore.getState().reset();
    resolvePost(settlementResponse());
    await inFlight;

    const state = useRefundCheckoutStore.getState();
    expect(state.step).toBe('idle');
    expect(state.settledResponse).toBeNull();
    expect(onSettled).not.toHaveBeenCalled();
    // The cart's return lines were NOT cleared by the dead continuation.
    expect(useCartStore.getState().items).toHaveLength(1);
  });
});

describe('refundCheckoutStore — stale-cart fingerprint guard', () => {
  it('aborts to idle (fail closed) when the return lines changed after begin — no author, no POST', async () => {
    await walkToApproval();
    // A scan/edit mutates the return-line set while the modal sequence is up.
    useCartStore.setState({ items: [returnItem({ quantity: -1, line_total: '-10.0000' })] });

    await useRefundCheckoutStore.getState().approveAndSubmit(submitInput());

    const state = useRefundCheckoutStore.getState();
    expect(state.step).toBe('idle');
    expect(state.error?.key).toBe('refundFlow.checkout.errorCartChanged');
    expect(authorRefundReturnApproval).not.toHaveBeenCalled();
    expect(apiPost).not.toHaveBeenCalled();
  });

  it('M2 post-settle drift guard: cart mutated after approval began but before the POST resolved → settle recorded, NEW lines NOT cleared, drift error surfaced', async () => {
    // Codex r1 M2 defense-in-depth: the pre-submit fingerprint check runs
    // ONCE before the async approval/sync/submit chain. If a scan-hydration
    // replaces the return lines while the POST is in flight, the server has
    // settled the OLD prepared lines — clearing the cart now would eat the
    // NEW, unrelated return lines.
    const onSettled = vi.fn();
    await walkToApproval();

    let resolvePost: (value: unknown) => void = () => {};
    vi.mocked(apiPost).mockImplementation(
      () => new Promise((resolve) => { resolvePost = resolve; }),
    );

    const inFlight = useRefundCheckoutStore.getState().approveAndSubmit(submitInput({ onSettled }));
    await vi.waitFor(() => { expect(apiPost).toHaveBeenCalled(); });

    // Scan-hydration swaps the return lines mid-submit (after the one-time
    // pre-submit fingerprint check already passed).
    const newLines = [returnItem({ id: 'return-item-OTHER', quantity: -1, line_total: '-10.0000' })];
    useCartStore.setState({ items: newLines });

    resolvePost(settlementResponse());
    await inFlight;

    const state = useRefundCheckoutStore.getState();
    // The server settle DID happen — the settled state is recorded …
    expect(state.step).toBe('settled');
    expect(state.settledResponse).toEqual(settlementResponse());
    expect(recordRefundSettlementForZ).toHaveBeenCalledTimes(1);
    expect(onSettled).toHaveBeenCalledTimes(1);
    // … but the NEW (unrelated) return lines were NOT cleared …
    expect(useCartStore.getState().items).toEqual(newLines);
    // … and the drift is surfaced so the cashier re-checks the cart.
    expect(state.error?.key).toBe('refundFlow.checkout.errorCartChangedAfterSettle');

    // The error must survive the parent's settled-acknowledge (which the
    // onSettled seam triggers) so the idle banner actually shows it.
    useRefundCheckoutStore.getState().acknowledgeSettled();
    expect(useRefundCheckoutStore.getState().step).toBe('idle');
    expect(useRefundCheckoutStore.getState().error?.key).toBe('refundFlow.checkout.errorCartChangedAfterSettle');
  });

  it('M2 post-settle drift guard: an UNCHANGED cart still clears the return lines on settle (no false positive)', async () => {
    await walkToApproval();

    await useRefundCheckoutStore.getState().approveAndSubmit(submitInput());

    const state = useRefundCheckoutStore.getState();
    expect(state.step).toBe('settled');
    expect(state.error).toBeNull();
    expect(useCartStore.getState().items).toEqual([]);
  });
});

describe('refundCheckoutStore — abort & failure paths', () => {
  it('cancel from the approval step aborts cleanly back to the cart (no zombie state)', async () => {
    await walkToApproval();

    useRefundCheckoutStore.getState().cancel();

    const state = useRefundCheckoutStore.getState();
    expect(state.step).toBe('idle');
    expect(state.prepared).toBeNull();
    expect(state.destination).toBeNull();
    expect(state.refundRequestId).toBeNull();
    expect(state.approval).toBeNull();
    expect(state.error).toBeNull();
    expect(state.refundItemsSnapshot).toBeNull();
    // The cart is untouched — the cashier is back where they started.
    expect(useCartStore.getState().items).toHaveLength(1);
    expect(apiPost).not.toHaveBeenCalled();
  });

  it('a manager-PIN failure returns to the approval step with the PIN error key — nothing authored, nothing synced, nothing submitted', async () => {
    vi.mocked(authorRefundReturnApproval).mockRejectedValue(
      new Error('manager_pin_scope_mismatch'),
    );
    await walkToApproval();

    await useRefundCheckoutStore.getState().approveAndSubmit(submitInput());

    const state = useRefundCheckoutStore.getState();
    expect(state.step).toBe('approval');
    expect(state.error?.key).toBe('refundFlow.checkout.errorApproval');
    expect(state.approval).toBeNull();
    expect(syncRefundApprovalEvents).not.toHaveBeenCalled();
    expect(apiPost).not.toHaveBeenCalled();
  });

  it('a sync failure CACHES the authored evidence; the retry re-runs sync only (no second author / PIN entry)', async () => {
    vi.mocked(syncRefundApprovalEvents).mockRejectedValueOnce(
      new Error('Approval fiscal event is not synced yet.'),
    );
    await walkToApproval();

    await useRefundCheckoutStore.getState().approveAndSubmit(submitInput());

    let state = useRefundCheckoutStore.getState();
    expect(state.step).toBe('approval');
    expect(state.error?.key).toBe('refundFlow.checkout.errorApprovalSync');
    // The authored evidence survives the sync failure — fiscal events are
    // appended exactly once for this settlement target.
    expect(state.approval).toEqual(approvalEvidence);
    expect(apiPost).not.toHaveBeenCalled();

    await useRefundCheckoutStore.getState().approveAndSubmit(submitInput());

    state = useRefundCheckoutStore.getState();
    expect(state.step).toBe('settled');
    expect(authorRefundReturnApproval).toHaveBeenCalledTimes(1);
    expect(syncRefundApprovalEvents).toHaveBeenCalledTimes(2);
    expect(apiPost).toHaveBeenCalledTimes(1);
  });

  it('a submit SERVER_ERROR returns to approval; the retry reuses the SAME refund_request_id and does NOT re-author or re-sync the approval', async () => {
    await walkToApproval();
    vi.mocked(apiPost).mockRejectedValueOnce(new ApiRequestError(503, 'unavailable', 'SERVICE_UNAVAILABLE'));

    await useRefundCheckoutStore.getState().approveAndSubmit(submitInput());
    expect(useRefundCheckoutStore.getState().step).toBe('approval');
    expect(useRefundCheckoutStore.getState().error?.key).toBe('refundFlow.checkout.errorServer');

    await useRefundCheckoutStore.getState().approveAndSubmit(submitInput());
    expect(useRefundCheckoutStore.getState().step).toBe('settled');

    expect(authorRefundReturnApproval).toHaveBeenCalledTimes(1);
    expect(syncRefundApprovalEvents).toHaveBeenCalledTimes(1);
    const posts = vi.mocked(apiPost).mock.calls;
    expect(posts).toHaveLength(2);
    const firstPayload = posts[0]![1] as Record<string, unknown>;
    const secondPayload = posts[1]![1] as Record<string, unknown>;
    expect(firstPayload['refund_request_id']).toBe(secondPayload['refund_request_id']);
  });

  it('a submit OFFLINE failure surfaces the offline error key (no auto-retry in the store)', async () => {
    await walkToApproval();
    vi.mocked(apiPost).mockRejectedValue(new FetchTimeoutError('url', 10_000, 'GET'));

    await useRefundCheckoutStore.getState().approveAndSubmit(submitInput());

    const state = useRefundCheckoutStore.getState();
    expect(state.step).toBe('approval');
    expect(state.error?.key).toBe('refundFlow.checkout.errorOffline');
    expect(apiPost).toHaveBeenCalledTimes(1);
  });

  it('a submit VALIDATION_FAILED surfaces the server message under the validation key', async () => {
    await walkToApproval();
    vi.mocked(apiPost).mockRejectedValue(
      new ApiRequestError(422, 'Daily refund cap exceeded.', 'DAILY_REFUND_CAP_EXCEEDED'),
    );

    await useRefundCheckoutStore.getState().approveAndSubmit(submitInput());

    const state = useRefundCheckoutStore.getState();
    expect(state.step).toBe('approval');
    expect(state.error?.key).toBe('refundFlow.checkout.errorValidation');
    expect(state.error?.serverMessage).toBe('Daily refund cap exceeded.');
  });

  it('a fresh begin after a cancel generates a NEW refund_request_id (new settlement attempt)', async () => {
    await walkToApproval();
    vi.mocked(apiPost).mockRejectedValueOnce(new ApiRequestError(503, 'unavailable', 'SERVICE_UNAVAILABLE'));
    await useRefundCheckoutStore.getState().approveAndSubmit(submitInput());
    const firstId = (vi.mocked(apiPost).mock.calls[0]![1] as Record<string, unknown>)['refund_request_id'];

    useRefundCheckoutStore.getState().cancel();
    await walkToApproval();
    await useRefundCheckoutStore.getState().approveAndSubmit(submitInput());

    const secondId = (vi.mocked(apiPost).mock.calls[1]![1] as Record<string, unknown>)['refund_request_id'];
    expect(secondId).not.toBe(firstId);
  });

  it('Z accounting (B2): a settled refund records the settlement for the device Z', async () => {
    await walkToApproval(); // destination = 'cash'

    await useRefundCheckoutStore.getState().approveAndSubmit(submitInput());

    expect(useRefundCheckoutStore.getState().step).toBe('settled');
    expect(recordRefundSettlementForZ).toHaveBeenCalledTimes(1);
    expect(recordRefundSettlementForZ).toHaveBeenCalledWith({
      companyId: 'company-1',
      terminalId: 'terminal-1',
      destination: 'cash',
      originalReceiptNumber: RECEIPT_NUMBER,
      response: settlementResponse(),
    });
    expect(useRefundCheckoutStore.getState().settledZAccountingRecorded).toBe(true);
  });

  it('Z accounting (B2): a failed local record does NOT un-settle — the flow settles with the warning flag', async () => {
    vi.mocked(recordRefundSettlementForZ).mockResolvedValue(false);
    const onSettled = vi.fn();
    await walkToApproval();

    await useRefundCheckoutStore.getState().approveAndSubmit(submitInput({ onSettled }));

    const state = useRefundCheckoutStore.getState();
    expect(state.step).toBe('settled');
    expect(state.settledZAccountingRecorded).toBe(false);
    expect(onSettled).toHaveBeenCalledTimes(1);
  });

  it('Z accounting (B2): a teardown mid-submit still records the settlement (the server settle DID happen)', async () => {
    await walkToApproval();

    let resolvePost: (value: unknown) => void = () => {};
    vi.mocked(apiPost).mockImplementation(
      () => new Promise((resolve) => { resolvePost = resolve; }),
    );

    const inFlight = useRefundCheckoutStore.getState().approveAndSubmit(submitInput());
    await vi.waitFor(() => { expect(apiPost).toHaveBeenCalled(); });

    useRefundCheckoutStore.getState().reset();
    resolvePost(settlementResponse());
    await inFlight;

    // The dead continuation mutates no UI state …
    expect(useRefundCheckoutStore.getState().step).toBe('idle');
    // … but the Z accounting record was still written.
    expect(recordRefundSettlementForZ).toHaveBeenCalledTimes(1);
  });

  it('Z accounting (B2): a failed submit records nothing', async () => {
    await walkToApproval();
    vi.mocked(apiPost).mockRejectedValue(new ApiRequestError(503, 'unavailable', 'SERVICE_UNAVAILABLE'));

    await useRefundCheckoutStore.getState().approveAndSubmit(submitInput());

    expect(useRefundCheckoutStore.getState().step).toBe('approval');
    expect(recordRefundSettlementForZ).not.toHaveBeenCalled();
  });

  it('a corrupted approval state aborts to idle with a translated internal error and logs the diagnosis', async () => {
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});
    useRefundCheckoutStore.setState({
      step: 'approval',
      prepared: null,
      receiptNumber: RECEIPT_NUMBER,
      destination: 'cash',
    });

    await useRefundCheckoutStore.getState().approveAndSubmit(submitInput());

    const state = useRefundCheckoutStore.getState();
    expect(state.step).toBe('idle');
    expect(state.error?.key).toBe('refundFlow.checkout.errorInternal');
    expect(consoleError).toHaveBeenCalled();
    expect(apiPost).not.toHaveBeenCalled();
    consoleError.mockRestore();
  });
});

// ─── v4 flow (v3-refund-chain-integration §9.2/§9.3) ────────────────────────
//
// The legacy suite above stays byte-intact (v4CapabilityEnabled: false in
// every fixture). These tests exercise the NEW branch, discriminated by
// v4CapabilityEnabled: true — purely local original resolution (no server
// round trip), the §3.5/§3.7 lookup-level refusals, refund_intents
// drafting, and settling via createRefundReceipt's atomic append-first
// write instead of an HTTP POST.
describe('refundCheckoutStore — v4 flow', () => {
  const V4_ORIGINAL_LOCAL_RECEIPT_ID = 'orig-receipt-uuid-1';
  const V4_ORIGINAL_FISCAL_EVENT_ID = 'fe-original-1';
  /** Deliberately NOT `approvalContext.businessDate` ('2026-06-10') — the
   *  whole point of finding 7 is that the two must not be conflated. */
  const V4_ORIGINAL_BUSINESS_DATE = '2026-05-02';
  const V4_TERMINAL_ID = 'terminal-1';
  const V4_OPERATOR_ID = 'operator-1';

  function v4ReturnItem(overrides: Partial<CartItem> = {}): CartItem {
    return {
      id: `return-${V4_ORIGINAL_LOCAL_RECEIPT_ID}-0`,
      product: { id: 'prod-1', name: 'Widget A', sku: 'PROD-001', price: '10.0000' },
      quantity: -2,
      unit_price: '10.0000',
      line_total: '-20.0000',
      tax_rate: '19.00',
      tax_amount: '-3.8000',
      kind: 'return',
      ...overrides,
    };
  }

  function v4OriginalView(overrides: Record<string, unknown> = {}) {
    return {
      fiscalEventId: V4_ORIGINAL_FISCAL_EVENT_ID,
      // Wave-2 fix-wave finding 7 — the ORIGINAL's OWN signed business
      // date, deliberately DIFFERENT from the refund day so a test can
      // tell the two apart.
      businessDate: V4_ORIGINAL_BUSINESS_DATE,
      lineItems: [{ product_id: 'prod-1', quantity: '2.000', line_total: '20.000' }],
      payments: [{ method_code: 'CASH', amount: '20.000' }],
      trainingFlag: false,
      transactionDiscountAmount: '0',
      ...overrides,
    };
  }

  function v4RefundIntent(overrides: Partial<RefundIntentRow> = {}): RefundIntentRow {
    return {
      id: 'refund-intent-1',
      terminal_id: V4_TERMINAL_ID,
      operator_id: V4_OPERATOR_ID,
      original_local_receipt_id: V4_ORIGINAL_LOCAL_RECEIPT_ID,
      original_fiscal_event_id: V4_ORIGINAL_FISCAL_EVENT_ID,
      line_snapshot_json: JSON.stringify([{ originalLineIndex: 0 }]),
      line_snapshot_fingerprint: 'fp-1',
      approval_source_event_id: 'approval-src-1',
      override_source_event_id: 'override-src-1',
      refund_fiscal_event_id: null,
      state: 'drafted',
      payout_confirmed_at: null,
      payout_disputed_at: null,
      printed_at: null,
      created_at: '2026-07-31T10:00:00Z',
      updated_at: '2026-07-31T10:00:00Z',
      ...overrides,
    };
  }

  const v4ApprovalEvidence = {
    approval_id: 'v4-approval-uuid',
    approval_event_id: 'fe-v4-approval-1',
    approval_scope: 'void_or_return_override' as const,
    override_event_id: 'fe-v4-override-1',
    policy_version: 'pos-refund-v4-void-return-policy-v1',
    supervisor_user_id: 'manager-9',
    target_reference_id: V4_ORIGINAL_LOCAL_RECEIPT_ID,
  };

  const v4CreateReceiptResult = {
    fiscalEvent: { id: 'fe-v4-refund-1' } as never,
    offlineReceiptId: 'offline-receipt-1',
    receiptNumber: 'MAIN-T01-2026-00000007',
  };

  function v4BeginInput(items: CartItem[] = [v4ReturnItem()]) {
    return {
      db: fakeDb,
      receiptToken: null,
      receiptNumber: RECEIPT_NUMBER,
      refundItems: items,
      v4CapabilityEnabled: true,
      originalLocalReceiptId: V4_ORIGINAL_LOCAL_RECEIPT_ID,
    };
  }

  async function v4WalkToApproval(items: CartItem[] = [v4ReturnItem()]) {
    useCartStore.setState({ items });
    await useRefundCheckoutStore.getState().begin(v4BeginInput(items));
    useRefundCheckoutStore.getState().confirmAccepted();
    expect(useRefundCheckoutStore.getState().step).toBe('approval');
  }

  beforeEach(() => {
    useAuthStore.setState({
      companyId: 'company-1',
      user: {
        id: 'user-1',
        tenantId: 'tenant-1',
        name: 'Cashier',
        email: 'cashier@test.com',
        roles: [],
        permissions: [],
      } as never,
      companies: [{ id: 'company-1', currency: 'EUR', name: 'Test Co' } as never],
    } as never);
    useTerminalStore.setState({
      terminal: { id: V4_TERMINAL_ID, location: null } as never,
      shift: { id: '11111111-1111-4111-8111-111111111111' } as never,
    } as never);
    useOperatorStore.setState({
      operator: { id: V4_OPERATOR_ID, name: 'Cashier One' } as never,
    } as never);
    usePaymentStore.setState({
      paymentMethods: [{ id: 'pm-cash', code: 'CASH', is_cash_tender: true, is_active: true } as never],
      paymentRepositories: [{ id: 'pr-cash', type: 'cash_register', is_active: true } as never],
    } as never);
    useRefundReconciliationStore.setState({ epoch: 0 });

    vi.mocked(resolveOriginalFiscalEventLocally).mockResolvedValue(v4OriginalView() as never);
    vi.mocked(createOrReuseActiveRefundIntent).mockResolvedValue({
      intent: v4RefundIntent(),
      reused: false,
    });
    vi.mocked(authorRefundReturnApprovalV3).mockResolvedValue(v4ApprovalEvidence);
    vi.mocked(createRefundReceipt).mockResolvedValue(v4CreateReceiptResult);
  });

  describe('begin()', () => {
    it('resolves the original PURELY LOCALLY (no server round trip) and skips the destination step (cash-only, §3.4)', async () => {
      useCartStore.setState({ items: [v4ReturnItem()] });

      await useRefundCheckoutStore.getState().begin(v4BeginInput());

      const state = useRefundCheckoutStore.getState();
      expect(state.step).toBe('confirm'); // never 'destination'
      expect(state.destination).toBe('cash');
      expect(state.isV4).toBe(true);
      expect(apiGet).not.toHaveBeenCalled();
      expect(resolveOriginalFiscalEventLocally).toHaveBeenCalledWith(fakeDb, V4_ORIGINAL_LOCAL_RECEIPT_ID);
    });

    it('§3.7 — refuses a training original BEFORE any approval authoring', async () => {
      vi.mocked(resolveOriginalFiscalEventLocally).mockResolvedValue(
        v4OriginalView({ trainingFlag: true }) as never,
      );

      await useRefundCheckoutStore.getState().begin(v4BeginInput());

      const state = useRefundCheckoutStore.getState();
      expect(state.step).toBe('idle');
      expect(state.error?.key).toBe('refundFlow.trainingOriginalRefused');
      expect(createOrReuseActiveRefundIntent).not.toHaveBeenCalled();
    });

    it('§3.5 — refuses a whole-discount original BEFORE any approval authoring', async () => {
      vi.mocked(resolveOriginalFiscalEventLocally).mockResolvedValue(
        v4OriginalView({ transactionDiscountAmount: '5.000' }) as never,
      );

      await useRefundCheckoutStore.getState().begin(v4BeginInput());

      const state = useRefundCheckoutStore.getState();
      expect(state.step).toBe('idle');
      expect(state.error?.key).toBe('refundFlow.wholeDiscountReceiptRefused');
      expect(createOrReuseActiveRefundIntent).not.toHaveBeenCalled();
    });

    it('§9.6 (finding 8) — refuses a CARD-tendered original BEFORE any approval authoring', async () => {
      vi.mocked(resolveOriginalFiscalEventLocally).mockResolvedValue(
        v4OriginalView({ payments: [{ method_code: 'CARD', amount: '20.000' }] }) as never,
      );

      await useRefundCheckoutStore.getState().begin(v4BeginInput());

      const state = useRefundCheckoutStore.getState();
      expect(state.step).toBe('idle');
      expect(state.error?.key).toBe('refundFlow.nonCashOriginalRefused');
      // The manager PIN is never even requested, and no intent is drafted.
      expect(createOrReuseActiveRefundIntent).not.toHaveBeenCalled();
    });

    it('§9.6 (finding 8) — refuses a MIXED-tender original (a cash leg does not make it cash-only)', async () => {
      vi.mocked(resolveOriginalFiscalEventLocally).mockResolvedValue(
        v4OriginalView({
          payments: [
            { method_code: 'CASH', amount: '5.000' },
            { method_code: 'CARD', amount: '15.000' },
          ],
        }) as never,
      );

      await useRefundCheckoutStore.getState().begin(v4BeginInput());

      const state = useRefundCheckoutStore.getState();
      expect(state.step).toBe('idle');
      expect(state.error?.key).toBe('refundFlow.nonCashOriginalRefused');
      expect(createOrReuseActiveRefundIntent).not.toHaveBeenCalled();
    });

    /**
     * Wave-2 fix-wave finding 10 (fiscal I-1) — a partial refund of a
     * per-line-discounted line used to throw
     * `LineArithmeticInvariantError` from the payload builder AFTER the
     * manager PIN was spent and the approval+override events were on the
     * chain. It is now refused at the LOOKUP level.
     */
    it('finding 10 — refuses a PARTIAL refund of a per-line-discounted line BEFORE the PIN', async () => {
      // Original line qty 2; the cashier edited the return down to 1.
      const edited = v4ReturnItem({
        quantity: -1,
        line_total: '-10.0000',
        tax_amount: '-1.9000',
        discount_amount: '3.0000',
      });

      await useRefundCheckoutStore.getState().begin(v4BeginInput([edited]));

      const state = useRefundCheckoutStore.getState();
      expect(state.step).toBe('idle');
      expect(state.error?.key).toBe('refundFlow.discountedPartialRefundRefused');
      // No intent drafted, no PIN requested, nothing signed.
      expect(createOrReuseActiveRefundIntent).not.toHaveBeenCalled();
      expect(authorRefundReturnApprovalV3).not.toHaveBeenCalled();
    });

    it('finding 10 — a FULL-line refund of a discounted line is still allowed (line_total keeps the discount)', async () => {
      const full = v4ReturnItem({ discount_amount: '3.0000' });

      await useRefundCheckoutStore.getState().begin(v4BeginInput([full]));

      expect(useRefundCheckoutStore.getState().step).toBe('confirm');
      expect(createOrReuseActiveRefundIntent).toHaveBeenCalled();
    });

    it('finding 10 — an UNDISCOUNTED partial refund is unaffected', async () => {
      const partial = v4ReturnItem({ quantity: -1, line_total: '-10.0000', tax_amount: '-1.9000' });

      await useRefundCheckoutStore.getState().begin(v4BeginInput([partial]));

      expect(useRefundCheckoutStore.getState().step).toBe('confirm');
    });

    /**
     * Wave-2 fix-wave finding 11 (fiscal I-2 + codex M-3) — a REUSED
     * intent is resumed from the state it is actually in.
     */
    describe('finding 11 — reused-intent resume', () => {
      it('an already-APPENDED intent routes to reconciliation instead of replaying the flow', async () => {
        vi.mocked(createOrReuseActiveRefundIntent).mockResolvedValue({
          intent: v4RefundIntent({
            state: 'refund_event_appended',
            refund_fiscal_event_id: 'fe-v4-refund-1',
          }),
          reused: true,
        });
        vi.mocked(getOfflineReceiptByIdempotencyKey).mockResolvedValue({ id: 'offline-receipt-1' } as never);

        await useRefundCheckoutStore.getState().begin(v4BeginInput());

        const state = useRefundCheckoutStore.getState();
        expect(state.step).toBe('idle');
        expect(state.error?.key).toBe('refundFlow.refundAlreadyAppended');
        // The reconciliation prompt is surfaced — the refund IS done.
        expect(useRefundReconciliationStore.getState().epoch).toBeGreaterThan(0);
        // …and the flow never reaches the confirm step, so
        // createRefundReceipt can never re-attempt a transition that would
        // roll back the whole write-gate transaction.
        expect(createRefundReceipt).not.toHaveBeenCalled();
      });

      it('an APPENDED intent with NO linked offline_receipts row fails closed (corrupt linkage)', async () => {
        vi.mocked(createOrReuseActiveRefundIntent).mockResolvedValue({
          intent: v4RefundIntent({
            state: 'refund_event_appended',
            refund_fiscal_event_id: 'fe-v4-refund-1',
          }),
          reused: true,
        });
        vi.mocked(getOfflineReceiptByIdempotencyKey).mockResolvedValue(null);

        await useRefundCheckoutStore.getState().begin(v4BeginInput());

        const state = useRefundCheckoutStore.getState();
        expect(state.step).toBe('idle');
        expect(state.error?.key).toBe('refundFlow.checkout.errorInternal');
      });

      it('an APPENDED intent with no fiscal event id fails closed (never retries the append)', async () => {
        vi.mocked(createOrReuseActiveRefundIntent).mockResolvedValue({
          intent: v4RefundIntent({ state: 'refund_event_appended', refund_fiscal_event_id: null }),
          reused: true,
        });

        await useRefundCheckoutStore.getState().begin(v4BeginInput());

        expect(useRefundCheckoutStore.getState().error?.key).toBe('refundFlow.checkout.errorInternal');
        expect(getOfflineReceiptByIdempotencyKey).not.toHaveBeenCalled();
      });

      it('an APPROVAL_AUTHORED intent resumes with the RECOVERED approval — no second manager PIN', async () => {
        vi.mocked(createOrReuseActiveRefundIntent).mockResolvedValue({
          intent: v4RefundIntent({ state: 'approval_authored' }),
          reused: true,
        });
        vi.mocked(recoverRefundApprovalEvidenceLocally).mockResolvedValue(v4ApprovalEvidence);
        const items = [v4ReturnItem()];
        useCartStore.setState({ items });

        await useRefundCheckoutStore.getState().begin(v4BeginInput(items));

        const state = useRefundCheckoutStore.getState();
        expect(state.step).toBe('confirm');
        // The already-signed evidence is pre-seeded, so approveAndSubmit
        // resumes the APPEND only.
        expect(state.approval).toEqual(v4ApprovalEvidence);

        useRefundCheckoutStore.getState().confirmAccepted();
        await useRefundCheckoutStore.getState().approveAndSubmit(submitInput());

        expect(authorRefundReturnApprovalV3).not.toHaveBeenCalled();
        expect(createRefundReceipt).toHaveBeenCalled();
      });

      it('an APPROVAL_AUTHORED intent whose approval is NOT locally recoverable fails closed', async () => {
        vi.mocked(createOrReuseActiveRefundIntent).mockResolvedValue({
          intent: v4RefundIntent({ state: 'approval_authored' }),
          reused: true,
        });
        vi.mocked(recoverRefundApprovalEvidenceLocally).mockResolvedValue(null);

        await useRefundCheckoutStore.getState().begin(v4BeginInput());

        const state = useRefundCheckoutStore.getState();
        expect(state.step).toBe('idle');
        expect(state.error?.key).toBe('refundFlow.checkout.errorApproval');
        expect(authorRefundReturnApprovalV3).not.toHaveBeenCalled();
      });

      it('a reused DRAFTED intent takes the ordinary path, unchanged', async () => {
        vi.mocked(createOrReuseActiveRefundIntent).mockResolvedValue({
          intent: v4RefundIntent({ state: 'drafted' }),
          reused: true,
        });

        await useRefundCheckoutStore.getState().begin(v4BeginInput());

        const state = useRefundCheckoutStore.getState();
        expect(state.step).toBe('confirm');
        expect(state.approval).toBeNull();
      });
    });

    it('errorInternal when the original cannot be resolved locally (defensive — should not happen)', async () => {
      vi.mocked(resolveOriginalFiscalEventLocally).mockResolvedValue(null);

      await useRefundCheckoutStore.getState().begin(v4BeginInput());

      expect(useRefundCheckoutStore.getState().error?.key).toBe('refundFlow.checkout.errorInternal');
    });

    it('drafts/reuses the refund_intents row with the pre-generated sourceEventIds', async () => {
      await useRefundCheckoutStore.getState().begin(v4BeginInput());

      expect(createOrReuseActiveRefundIntent).toHaveBeenCalledWith(
        fakeDb,
        expect.objectContaining({
          terminalId: V4_TERMINAL_ID,
          operatorId: V4_OPERATOR_ID,
          originalLocalReceiptId: V4_ORIGINAL_LOCAL_RECEIPT_ID,
          originalFiscalEventId: V4_ORIGINAL_FISCAL_EVENT_ID,
          approvalSourceEventId: expect.any(String),
          overrideSourceEventId: expect.any(String),
        }),
      );
    });

    // Wave-2 review fix, ORCHESTRATOR-RULED (required) — device-local
    // cumulative-quantity backstop. The original line's own quantity is
    // 2.000 (v4OriginalView()'s fixture).
    describe('cumulative-quantity backstop (§12 device-side belt)', () => {
      it('refund 1 of qty-2, synced, then refund the remaining 1 → OK (cumulative 2 == original 2)', async () => {
        vi.mocked(getCumulativeRefundedQuantityByOriginalLine).mockResolvedValue(
          new Map([[0, '1.0000']]),
        );
        useCartStore.setState({ items: [v4ReturnItem({ quantity: -1, line_total: '-10.0000' })] });

        await useRefundCheckoutStore.getState().begin(
          v4BeginInput([v4ReturnItem({ quantity: -1, line_total: '-10.0000' })]),
        );

        const state = useRefundCheckoutStore.getState();
        expect(state.step).toBe('confirm');
        expect(state.error).toBeNull();
        expect(createOrReuseActiveRefundIntent).toHaveBeenCalledOnce();
      });

      it('refund 2 (full quantity), synced, then attempt to refund 1 more → refused (cumulative 3 > original 2)', async () => {
        vi.mocked(getCumulativeRefundedQuantityByOriginalLine).mockResolvedValue(
          new Map([[0, '2.0000']]),
        );
        useCartStore.setState({ items: [v4ReturnItem({ quantity: -1, line_total: '-10.0000' })] });

        await useRefundCheckoutStore.getState().begin(
          v4BeginInput([v4ReturnItem({ quantity: -1, line_total: '-10.0000' })]),
        );

        const state = useRefundCheckoutStore.getState();
        expect(state.step).toBe('idle');
        expect(state.error?.key).toBe('refundFlow.refundQuantityExceeded');
        expect(createOrReuseActiveRefundIntent).not.toHaveBeenCalled();
      });

      it('a fresh original with NOTHING refunded yet (empty cumulative map) allows refunding the full quantity', async () => {
        vi.mocked(getCumulativeRefundedQuantityByOriginalLine).mockResolvedValue(new Map());

        await useRefundCheckoutStore.getState().begin(v4BeginInput());

        const state = useRefundCheckoutStore.getState();
        expect(state.step).toBe('confirm');
        expect(state.error).toBeNull();
      });

      it('reads the cumulative map keyed by the SAME originalLocalReceiptId this attempt targets', async () => {
        await useRefundCheckoutStore.getState().begin(v4BeginInput());

        expect(getCumulativeRefundedQuantityByOriginalLine).toHaveBeenCalledWith(
          fakeDb,
          V4_ORIGINAL_LOCAL_RECEIPT_ID,
        );
      });
    });
  });

  describe('approveAndSubmit()', () => {
    it('walks confirm → approval → settles via createRefundReceipt, calling onV4Settled (never onSettled)', async () => {
      const onSettled = vi.fn();
      const onV4Settled = vi.fn();
      await v4WalkToApproval();

      await useRefundCheckoutStore
        .getState()
        .approveAndSubmit(submitInput({ onSettled, onV4Settled }));

      const state = useRefundCheckoutStore.getState();
      expect(state.step).toBe('settled');
      expect(state.error).toBeNull();
      expect(state.v4SettledResult).toEqual({
        fiscalEventId: 'fe-v4-refund-1',
        offlineReceiptId: 'offline-receipt-1',
        receiptNumber: 'MAIN-T01-2026-00000007',
        refundIntentId: 'refund-intent-1',
      });
      // Discriminated from the legacy path — always null on a v4 settle.
      expect(state.settledResponse).toBeNull();
      // Atomic write => no separate mirror that can fail independently.
      expect(state.settledZAccountingRecorded).toBe(true);
      expect(onV4Settled).toHaveBeenCalledTimes(1);
      expect(onV4Settled).toHaveBeenCalledWith(state.v4SettledResult);
      expect(onSettled).not.toHaveBeenCalled();
      expect(useCartStore.getState().items).toEqual([]);
      // §4.5 — the reconciliation modal's refresh signal bumped.
      expect(useRefundReconciliationStore.getState().epoch).toBe(1);
    });

    it('authors via authorRefundReturnApprovalV3 with the intent-supplied sourceEventIds, then settles createRefundReceipt with the same approval as approvalReferences', async () => {
      await v4WalkToApproval();

      await useRefundCheckoutStore.getState().approveAndSubmit(submitInput());

      expect(authorRefundReturnApprovalV3).toHaveBeenCalledWith(
        expect.objectContaining({
          originalLocalReceiptId: V4_ORIGINAL_LOCAL_RECEIPT_ID,
          originalFiscalEventId: V4_ORIGINAL_FISCAL_EVENT_ID,
          approvalSourceEventId: 'approval-src-1',
          overrideSourceEventId: 'override-src-1',
        }),
      );
      expect(createRefundReceipt).toHaveBeenCalledWith(
        expect.objectContaining({
          refundIntentId: 'refund-intent-1',
          paymentMethodId: 'pm-cash',
          paymentRepositoryId: 'pr-cash',
          approvalReferences: [v4ApprovalEvidence],
        }),
      );
      expect(markApprovalAuthored).toHaveBeenCalledWith(expect.anything(), 'refund-intent-1');
    });

    it('finding 7 — seals the ORIGINAL\'s own business date, never the refund day\'s', async () => {
      await v4WalkToApproval();

      await useRefundCheckoutStore.getState().approveAndSubmit(submitInput());

      expect(createRefundReceipt).toHaveBeenCalledWith(
        expect.objectContaining({
          // The NEW refund event is dated today…
          businessDate: approvalContext.businessDate,
          // …while the provenance reference carries the ORIGINAL's own
          // signed date. Conflating them signed a fabricated fact into an
          // immutable chain (rule 8).
          originalBusinessDate: V4_ORIGINAL_BUSINESS_DATE,
        }),
      );
      expect(approvalContext.businessDate).not.toBe(V4_ORIGINAL_BUSINESS_DATE);
    });

    it('caches the authored evidence immediately — a settle failure does NOT re-author on retry (no second PIN, no double fiscal append)', async () => {
      vi.mocked(createRefundReceipt).mockRejectedValueOnce(new Error('write-gate failure'));
      await v4WalkToApproval();

      await useRefundCheckoutStore.getState().approveAndSubmit(submitInput());
      let state = useRefundCheckoutStore.getState();
      expect(state.step).toBe('approval');
      expect(state.error?.key).toBe('refundFlow.checkout.errorInternal');
      expect(state.approval).toEqual(v4ApprovalEvidence);

      await useRefundCheckoutStore.getState().approveAndSubmit(submitInput());
      state = useRefundCheckoutStore.getState();
      expect(state.step).toBe('settled');
      expect(authorRefundReturnApprovalV3).toHaveBeenCalledTimes(1);
      expect(createRefundReceipt).toHaveBeenCalledTimes(2);
    });

    it('a markApprovalAuthored failure is non-fatal — the settle still proceeds with the already-cached approval', async () => {
      vi.mocked(markApprovalAuthored).mockRejectedValueOnce(new Error('transition guard rejected'));
      const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});
      await v4WalkToApproval();

      await useRefundCheckoutStore.getState().approveAndSubmit(submitInput());

      expect(useRefundCheckoutStore.getState().step).toBe('settled');
      expect(createRefundReceipt).toHaveBeenCalledTimes(1);
      expect(consoleError).toHaveBeenCalled();
      consoleError.mockRestore();
    });

    it('a PIN/authoring failure returns to approval with errorApproval — no settle attempted', async () => {
      vi.mocked(authorRefundReturnApprovalV3).mockRejectedValue(new Error('manager_pin_scope_mismatch'));
      await v4WalkToApproval();

      await useRefundCheckoutStore.getState().approveAndSubmit(submitInput());

      const state = useRefundCheckoutStore.getState();
      expect(state.step).toBe('approval');
      expect(state.error?.key).toBe('refundFlow.checkout.errorApproval');
      expect(state.approval).toBeNull();
      expect(createRefundReceipt).not.toHaveBeenCalled();
    });

    it('the stale-cart fingerprint guard applies identically to the v4 path', async () => {
      await v4WalkToApproval();
      useCartStore.setState({ items: [v4ReturnItem({ quantity: -1, line_total: '-10.0000' })] });

      await useRefundCheckoutStore.getState().approveAndSubmit(submitInput());

      const state = useRefundCheckoutStore.getState();
      expect(state.step).toBe('idle');
      expect(state.error?.key).toBe('refundFlow.checkout.errorCartChanged');
      expect(authorRefundReturnApprovalV3).not.toHaveBeenCalled();
      expect(createRefundReceipt).not.toHaveBeenCalled();
    });
  });
});
