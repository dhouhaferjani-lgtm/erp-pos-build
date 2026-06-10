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
import { authorizeRefundReturnApproval } from '@/lib/refundFlow/refundApproval';
import type { CartItem } from '@/types/cart';
import { useCartStore } from '@/stores/cartStore';
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
  authorizeRefundReturnApproval: vi.fn(),
}));

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

/** Walk the machine to the approval step (prepare → destination → confirm). */
async function walkToApproval(items: CartItem[] = [returnItem()]) {
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
  vi.mocked(authorizeRefundReturnApproval).mockResolvedValue(approvalEvidence);
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
    useCartStore.setState({ items: [partial] });

    await walkToApproval([partial]);
    await useRefundCheckoutStore.getState().approveAndSubmit(submitInput());

    const [, payload] = vi.mocked(apiPost).mock.calls[0] as [string, Record<string, unknown>];
    expect(payload['lines']).toEqual([{ line_id: 'line-1', quantity: '1.0000' }]);
  });

  it('binds the approval to the server receipt id/number and the mapped line_ids', async () => {
    await walkToApproval();
    await useRefundCheckoutStore.getState().approveAndSubmit(submitInput());

    expect(authorizeRefundReturnApproval).toHaveBeenCalledWith({
      context: approvalContext,
      managerPin: '4321',
      reason: 'changed mind',
      serverReceiptId: SERVER_RECEIPT_ID,
      receiptNumber: RECEIPT_NUMBER,
      lineIds: ['line-1'],
    });
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

describe('refundCheckoutStore — abort & failure paths', () => {
  it('cancel from the approval step aborts cleanly back to the cart (no zombie state)', async () => {
    useCartStore.setState({ items: [returnItem()] });
    await walkToApproval();

    useRefundCheckoutStore.getState().cancel();

    const state = useRefundCheckoutStore.getState();
    expect(state.step).toBe('idle');
    expect(state.prepared).toBeNull();
    expect(state.destination).toBeNull();
    expect(state.refundRequestId).toBeNull();
    expect(state.approval).toBeNull();
    expect(state.error).toBeNull();
    // The cart is untouched — the cashier is back where they started.
    expect(useCartStore.getState().items).toHaveLength(1);
    expect(apiPost).not.toHaveBeenCalled();
  });

  it('a manager-PIN failure returns to the approval step with the approval error key and submits nothing', async () => {
    vi.mocked(authorizeRefundReturnApproval).mockRejectedValue(
      new Error('manager_pin_scope_mismatch'),
    );
    await walkToApproval();

    await useRefundCheckoutStore.getState().approveAndSubmit(submitInput());

    const state = useRefundCheckoutStore.getState();
    expect(state.step).toBe('approval');
    expect(state.error?.key).toBe('refundFlow.checkout.errorApproval');
    expect(apiPost).not.toHaveBeenCalled();
  });

  it('a submit SERVER_ERROR returns to approval; the retry reuses the SAME refund_request_id and does NOT re-author the approval', async () => {
    await walkToApproval();
    vi.mocked(apiPost).mockRejectedValueOnce(new ApiRequestError(503, 'unavailable', 'SERVICE_UNAVAILABLE'));

    await useRefundCheckoutStore.getState().approveAndSubmit(submitInput());
    expect(useRefundCheckoutStore.getState().step).toBe('approval');
    expect(useRefundCheckoutStore.getState().error?.key).toBe('refundFlow.checkout.errorServer');

    await useRefundCheckoutStore.getState().approveAndSubmit(submitInput());
    expect(useRefundCheckoutStore.getState().step).toBe('settled');

    expect(authorizeRefundReturnApproval).toHaveBeenCalledTimes(1);
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
});
