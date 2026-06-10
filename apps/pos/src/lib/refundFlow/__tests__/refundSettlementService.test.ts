import { beforeEach, describe, expect, it, vi } from 'vitest';
import type Database from '@tauri-apps/plugin-sql';
import { ApiRequestError, apiGet, apiPost } from '@/lib/api';
import {
  findReceiptByNumber,
  findReceiptByQrToken,
} from '@/lib/offline/voucherRepository';
import { FetchTimeoutError } from '@/lib/fetchWithTimeout';
import type { CartItem } from '@/types/cart';
import {
  buildStoreReturnPayload,
  generateRefundRequestId,
  prepareRefundSettlement,
  submitRefundReturn,
  toServerRefundDestination,
  type RefundApprovalEvidence,
  type SubmitRefundInput,
} from '../refundSettlementService';

// ─── Mocks (HTTP mocked at the api-client boundary only) ────────────────────

vi.mock('@/lib/api', async () => {
  // Re-export the REAL ApiRequestError (mocking only the request functions)
  // so instanceof checks in the service exercise the production class — a
  // hand-cloned mock class drifts silently when the real one changes.
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api');
  return {
    ...actual,
    apiGet: vi.fn(),
    apiPost: vi.fn(),
  };
});

vi.mock('@/lib/offline/voucherRepository', () => ({
  findReceiptByQrToken: vi.fn(),
  findReceiptByNumber: vi.fn(),
}));

const mockGetState = vi.fn(() => ({ isOnline: true }));
vi.mock('@/stores/connectivityStore', () => ({
  useConnectivityStore: { getState: () => mockGetState() },
}));

function mockConnectivityOnline(isOnline: boolean): void {
  mockGetState.mockReturnValue({ isOnline });
}

const fakeDb = {} as Database;
const NO_BACKOFF = { backoffMs: 0 };

const SERVER_RECEIPT_ID = '7d4e2a10-1111-4222-8333-444455556666';

function qrIndexEntry() {
  return {
    receipt_uuid: SERVER_RECEIPT_ID,
    qr_token: 'v:kid:7d4e2a10-1111-4222-8333-444455556666:mac',
    receipt_number: 'L01-T01-00042',
    terminal_id: 'terminal-1',
    posted_at: '2026-06-09T10:00:00Z',
    total: '23.80',
    currency: 'EUR',
    partner_id: null,
    synced_at: '2026-06-09T10:01:00Z',
  };
}

function refundItem(overrides: Partial<CartItem> = {}): CartItem {
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

function serverReceipt(linesOverrides: Array<Record<string, unknown>> = []) {
  return {
    id: SERVER_RECEIPT_ID,
    receipt_number: 'L01-T01-00042',
    lines: linesOverrides.length > 0 ? linesOverrides : [
      {
        id: 'line-1',
        product_id: 'prod-1',
        composite_item_id: null,
        product_code: 'PROD-001',
        product_name: 'Widget A',
        quantity: '5.0000',
        unit_price: '10.0000',
        returned_quantity: '0.0000',
      },
    ],
  };
}

const approval: RefundApprovalEvidence = {
  approval_id: 'a0000000-0000-4000-8000-000000000001',
  approval_fiscal_event_id: 'a0000000-0000-4000-8000-000000000002',
  approval_scope: 'void_or_return_override',
  approval_supervisor_user_id: 'a0000000-0000-4000-8000-000000000003',
  approval_override_event_id: 'a0000000-0000-4000-8000-000000000004',
  authorized_by_user_id: 'a0000000-0000-4000-8000-000000000003',
};

function submitInput(overrides: Partial<SubmitRefundInput> = {}): SubmitRefundInput {
  return {
    serverReceiptId: SERVER_RECEIPT_ID,
    terminalId: 'terminal-1',
    returnReason: 'defective',
    lines: [{ line_id: 'line-1', quantity: '2.0000' }],
    destination: 'original',
    refundRequestId: 'b0000000-0000-4000-8000-000000000001',
    approval,
    retry: NO_BACKOFF,
    ...overrides,
  };
}

function returnResponse() {
  return {
    id: 'c0000000-0000-4000-8000-000000000001',
    receipt_number: 'L01-T01-00043',
    receipt_type: 'return',
    original_receipt_id: SERVER_RECEIPT_ID,
    return_reason: 'defective',
    subtotal: '-16.81',
    tax_amount: '-3.19',
    total: '-20.00',
    currency: 'EUR',
    posted_at: '2026-06-10T09:00:00Z',
    qr_token: 'v:kid:c0000000-0000-4000-8000-000000000001:mac',
    issued_voucher: null,
    lines: [
      { product_name: 'Widget A', quantity: '-2.0000', unit_price: '10.0000', line_total: '-20.0000' },
    ],
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  mockConnectivityOnline(true);
});

// ─── prepareRefundSettlement ─────────────────────────────────────────────────

describe('prepareRefundSettlement', () => {
  it('returns NOT_SYNCED when the local qr-index has no entry', async () => {
    vi.mocked(findReceiptByQrToken).mockResolvedValue(null);
    vi.mocked(findReceiptByNumber).mockResolvedValue(null);

    const result = await prepareRefundSettlement({
      db: fakeDb,
      receiptToken: 'v:kid:7d4e2a10-1111-4222-8333-444455556666:mac',
      receiptNumber: 'L01-T01-00042',
      refundItems: [refundItem()],
      retry: NO_BACKOFF,
    });

    expect(result).toEqual({ ok: false, error: { code: 'NOT_SYNCED' } });
    expect(apiGet).not.toHaveBeenCalled();
  });

  it('treats a number-lookup hit with a MISMATCHING qr_token as NOT_SYNCED', async () => {
    // The scanned token resolved nothing, and the receipt-number entry that
    // DID resolve carries a different token — the number collided with a
    // different receipt. Binding it would refund against the wrong receipt.
    vi.mocked(findReceiptByQrToken).mockResolvedValue(null);
    vi.mocked(findReceiptByNumber).mockResolvedValue(qrIndexEntry());

    const result = await prepareRefundSettlement({
      db: fakeDb,
      receiptToken: 'v:kid:99999999-9999-4999-8999-999999999999:othermac',
      receiptNumber: 'L01-T01-00042',
      refundItems: [refundItem()],
      retry: NO_BACKOFF,
    });

    expect(result).toEqual({ ok: false, error: { code: 'NOT_SYNCED' } });
    expect(apiGet).not.toHaveBeenCalled();
  });

  it('accepts a number-lookup hit whose stored qr_token is NULL (offline-issued original)', async () => {
    // No token recorded in the index — nothing to verify against; the
    // number match stands.
    vi.mocked(findReceiptByQrToken).mockResolvedValue(null);
    vi.mocked(findReceiptByNumber).mockResolvedValue({
      ...qrIndexEntry(),
      qr_token: null,
    });
    vi.mocked(apiGet).mockResolvedValue(serverReceipt());

    const result = await prepareRefundSettlement({
      db: fakeDb,
      receiptToken: 'v:kid:7d4e2a10-1111-4222-8333-444455556666:mac',
      receiptNumber: 'L01-T01-00042',
      refundItems: [refundItem()],
      retry: NO_BACKOFF,
    });

    expect(result.ok).toBe(true);
  });

  it('falls back to receipt-number lookup when the token is null', async () => {
    vi.mocked(findReceiptByNumber).mockResolvedValue(qrIndexEntry());
    vi.mocked(apiGet).mockResolvedValue(serverReceipt());

    const result = await prepareRefundSettlement({
      db: fakeDb,
      receiptToken: null,
      receiptNumber: 'L01-T01-00042',
      refundItems: [refundItem()],
      retry: NO_BACKOFF,
    });

    expect(findReceiptByQrToken).not.toHaveBeenCalled();
    expect(findReceiptByNumber).toHaveBeenCalledWith(fakeDb, 'L01-T01-00042');
    expect(result.ok).toBe(true);
  });

  it('resolves the server id, fetches the receipt and maps the lines', async () => {
    vi.mocked(findReceiptByQrToken).mockResolvedValue(qrIndexEntry());
    vi.mocked(apiGet).mockResolvedValue(serverReceipt());

    const result = await prepareRefundSettlement({
      db: fakeDb,
      receiptToken: 'v:kid:7d4e2a10-1111-4222-8333-444455556666:mac',
      receiptNumber: 'L01-T01-00042',
      refundItems: [refundItem()],
      retry: NO_BACKOFF,
    });

    expect(apiGet).toHaveBeenCalledWith(`/pos/receipts/${SERVER_RECEIPT_ID}`);
    expect(result).toEqual({
      ok: true,
      value: {
        serverReceiptId: SERVER_RECEIPT_ID,
        lines: [{ line_id: 'line-1', quantity: '2.0000' }],
        serverLines: serverReceipt().lines,
      },
    });
  });

  it('returns LINE_MAPPING_FAILED when returned_quantity exhausts the line', async () => {
    vi.mocked(findReceiptByQrToken).mockResolvedValue(qrIndexEntry());
    vi.mocked(apiGet).mockResolvedValue(
      serverReceipt([
        {
          id: 'line-1',
          product_id: 'prod-1',
          composite_item_id: null,
          product_code: 'PROD-001',
          product_name: 'Widget A',
          quantity: '5.0000',
          unit_price: '10.0000',
          returned_quantity: '4.0000',
        },
      ]),
    );

    const result = await prepareRefundSettlement({
      db: fakeDb,
      receiptToken: 'v:kid:7d4e2a10-1111-4222-8333-444455556666:mac',
      receiptNumber: 'L01-T01-00042',
      refundItems: [refundItem({ quantity: -2 })],
      retry: NO_BACKOFF,
    });

    expect(result).toEqual({
      ok: false,
      error: {
        code: 'LINE_MAPPING_FAILED',
        reason: 'INSUFFICIENT_RETURNABLE_QUANTITY',
        itemId: 'return-item-1',
      },
    });
  });

  it('fails closed (SERVER_ERROR) on a malformed receipt response', async () => {
    vi.mocked(findReceiptByQrToken).mockResolvedValue(qrIndexEntry());
    vi.mocked(apiGet).mockResolvedValue({ id: SERVER_RECEIPT_ID, lines: 'nope' });

    const result = await prepareRefundSettlement({
      db: fakeDb,
      receiptToken: 'v:kid:7d4e2a10-1111-4222-8333-444455556666:mac',
      receiptNumber: 'L01-T01-00042',
      refundItems: [refundItem()],
      retry: NO_BACKOFF,
    });

    expect(result).toEqual({ ok: false, error: { code: 'SERVER_ERROR' } });
  });
});

// ─── buildStoreReturnPayload / destination mapping ───────────────────────────

describe('toServerRefundDestination', () => {
  it("maps the picker's 'original' to the server enum 'original_payment'", () => {
    expect(toServerRefundDestination('original')).toBe('original_payment');
    expect(toServerRefundDestination('cash')).toBe('cash');
    expect(toServerRefundDestination('store_voucher')).toBe('store_voucher');
  });
});

describe('buildStoreReturnPayload', () => {
  it('builds the full StoreReturnRequest contract incl. approval evidence', () => {
    const payload = buildStoreReturnPayload(
      submitInput({ notes: 'damaged box', overrideReason: 'manager ok' }),
    );

    expect(payload).toEqual({
      terminal_id: 'terminal-1',
      return_reason: 'defective',
      lines: [{ line_id: 'line-1', quantity: '2.0000' }],
      notes: 'damaged box',
      refund_request_id: 'b0000000-0000-4000-8000-000000000001',
      refund_destination: 'original_payment',
      approval_id: approval.approval_id,
      approval_fiscal_event_id: approval.approval_fiscal_event_id,
      approval_scope: 'void_or_return_override',
      approval_supervisor_user_id: approval.approval_supervisor_user_id,
      approval_override_event_id: approval.approval_override_event_id,
      authorized_by_user_id: approval.authorized_by_user_id,
      override_reason: 'manager ok',
    });
  });

  it('omits optional keys instead of sending undefined', () => {
    const payload = buildStoreReturnPayload(submitInput());
    expect('notes' in payload).toBe(false);
    expect('override_reason' in payload).toBe(false);
  });
});

describe('generateRefundRequestId', () => {
  it('produces a uuid', () => {
    expect(generateRefundRequestId()).toMatch(
      /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i,
    );
  });
});

// ─── submitRefundReturn ──────────────────────────────────────────────────────

describe('submitRefundReturn', () => {
  it('POSTs the payload and returns the typed response on success', async () => {
    vi.mocked(apiPost).mockResolvedValue(returnResponse());

    const result = await submitRefundReturn(submitInput());

    expect(apiPost).toHaveBeenCalledWith(
      `/pos/receipts/${SERVER_RECEIPT_ID}/return`,
      buildStoreReturnPayload(submitInput()),
    );
    expect(result).toEqual({ ok: true, response: returnResponse() });
    if (result.ok) {
      expect(result.response.qr_token).toBe(
        'v:kid:c0000000-0000-4000-8000-000000000001:mac',
      );
      expect(result.response.issued_voucher).toBeNull();
    }
  });

  it('retries 5xx with the SAME refund_request_id then succeeds', async () => {
    vi.mocked(apiPost)
      .mockRejectedValueOnce(new ApiRequestError(503, 'Service Unavailable', 'SERVICE_UNAVAILABLE'))
      .mockRejectedValueOnce(new ApiRequestError(500, 'Server Error', 'SERVER_ERROR'))
      .mockResolvedValueOnce(returnResponse());

    const result = await submitRefundReturn(submitInput());

    expect(apiPost).toHaveBeenCalledTimes(3);
    const bodies = vi.mocked(apiPost).mock.calls.map((call) => call[1]);
    for (const body of bodies) {
      expect((body as { refund_request_id: string }).refund_request_id).toBe(
        'b0000000-0000-4000-8000-000000000001',
      );
    }
    expect(result.ok).toBe(true);
  });

  it('fails CLOSED (SERVER_ERROR) after exhausting retries on a persistent 5xx', async () => {
    vi.mocked(apiPost).mockRejectedValue(
      new ApiRequestError(500, 'Server Error', 'SERVER_ERROR'),
    );

    const result = await submitRefundReturn(submitInput());

    // 1 initial + 2 retries = 3 attempts, then fail closed — NOT offline.
    expect(apiPost).toHaveBeenCalledTimes(3);
    expect(result).toEqual({ ok: false, error: { code: 'SERVER_ERROR', status: 500 } });
  });

  it('treats 408/429 as retryable then fails closed', async () => {
    vi.mocked(apiPost).mockRejectedValue(
      new ApiRequestError(429, 'Too Many Requests', 'TOO_MANY_REQUESTS'),
    );

    const result = await submitRefundReturn(submitInput());

    expect(apiPost).toHaveBeenCalledTimes(3);
    expect(result).toEqual({ ok: false, error: { code: 'SERVER_ERROR', status: 429 } });
  });

  it('returns OFFLINE immediately on a typed fetch timeout (positive offline evidence)', async () => {
    vi.mocked(apiPost).mockRejectedValue(new FetchTimeoutError('url', 10_000, 'POST'));

    const result = await submitRefundReturn(submitInput());

    expect(apiPost).toHaveBeenCalledTimes(1);
    expect(result).toEqual({ ok: false, error: { code: 'OFFLINE' } });
  });

  it('returns OFFLINE when the connectivity layer reports offline', async () => {
    mockConnectivityOnline(false);
    vi.mocked(apiPost).mockRejectedValue(new Error('socket hang up'));

    const result = await submitRefundReturn(submitInput());

    expect(result).toEqual({ ok: false, error: { code: 'OFFLINE' } });
  });

  it('fails CLOSED on an unexpected error while online (no offline downgrade)', async () => {
    mockConnectivityOnline(true);
    vi.mocked(apiPost).mockRejectedValue(new Error('something exploded'));

    const result = await submitRefundReturn(submitInput());

    expect(apiPost).toHaveBeenCalledTimes(1);
    expect(result).toEqual({ ok: false, error: { code: 'SERVER_ERROR' } });
  });

  it('returns VALIDATION_FAILED immediately on 422 with the server message', async () => {
    vi.mocked(apiPost).mockRejectedValue(
      new ApiRequestError(422, 'Maximum returnable quantity exceeded', 'RETURN_FAILED'),
    );

    const result = await submitRefundReturn(submitInput());

    expect(apiPost).toHaveBeenCalledTimes(1);
    expect(result).toEqual({
      ok: false,
      error: {
        code: 'VALIDATION_FAILED',
        status: 422,
        apiCode: 'RETURN_FAILED',
        message: 'Maximum returnable quantity exceeded',
      },
    });
  });

  it('surfaces the issued voucher on a store_voucher settlement', async () => {
    const voucher = {
      id: 'v-1',
      code: 'VCH-123',
      initial_balance: '9.98000',
      currency: 'EUR',
      expires_at: '2027-06-10T00:00:00Z',
      redemption_mode: 'bearer',
      partner_id: null,
    };
    vi.mocked(apiPost).mockResolvedValue({ ...returnResponse(), issued_voucher: voucher });

    const result = await submitRefundReturn(submitInput({ destination: 'store_voucher' }));

    const body = vi.mocked(apiPost).mock.calls[0]![1] as { refund_destination: string };
    expect(body.refund_destination).toBe('store_voucher');
    expect(result.ok).toBe(true);
    if (result.ok) {
      expect(result.response.issued_voucher).toEqual(voucher);
    }
  });

  it('fails closed on a malformed /return response body', async () => {
    vi.mocked(apiPost).mockResolvedValue({ unexpected: true });

    const result = await submitRefundReturn(submitInput());

    expect(result).toEqual({ ok: false, error: { code: 'SERVER_ERROR' } });
  });
});
