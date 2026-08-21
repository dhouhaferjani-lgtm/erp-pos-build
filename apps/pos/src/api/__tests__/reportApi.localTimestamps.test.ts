import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Regression tests for the 2026-06-12 reports audit.
 *
 * `offline_receipts.created_at` is written by the column default
 * `datetime('now')` → `YYYY-MM-DD HH:MM:SS` (UTC, SPACE separator).
 * `shift.opened_at` is ISO 8601 (`T` separator). SQLite compares TEXT
 * lexicographically and `' ' < 'T'`, so binding the raw ISO value as the
 * window boundary excluded every same-day receipt — the local X report and
 * the offline Today Sales list both showed zero sales right after a sale.
 */

vi.mock('@/lib/api', () => ({
  apiGet: vi.fn(),
  // `fetchShiftReceipts` reads the PAGINATED envelope via apiGetRaw (O-28 P1-1).
  apiGetRaw: vi.fn(),
  apiPost: vi.fn(),
}));

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn().mockResolvedValue({}),
  queryAll: vi.fn().mockResolvedValue([]),
}));

vi.mock('@/lib/db/repositories/paymentRepository', () => ({
  getAllPaymentMethods: vi.fn().mockResolvedValue([]),
}));

vi.mock('@/lib/fiscal/instance', () => ({
  getFiscalEventEngine: vi.fn(),
}));

vi.mock('@/lib/fiscal/zSessionAuthoring', () => ({
  appendXReport: vi.fn(),
}));

vi.mock('@/stores/authStore', () => ({
  useAuthStore: {
    getState: vi.fn(() => ({
      companyId: 'company-1',
      companies: [{ id: 'company-1', currency: 'TND' }],
    })),
  },
}));

vi.mock('@/stores/terminalStore', () => ({
  useTerminalStore: {
    getState: vi.fn(() => ({
      terminal: { id: 'term-1', code: 'POS01', fiscal_schema_version: 3 },
      shift: {
        id: 'shift-1',
        terminal_id: 'term-1',
        opened_at: '2026-06-12T08:54:51+00:00',
      },
    })),
  },
}));

let mockIsOnline = false;
vi.mock('@/stores/connectivityStore', () => ({
  useConnectivityStore: {
    getState: vi.fn(() => ({ isOnline: mockIsOnline })),
  },
}));

import { generateXReport, fetchShiftReceipts } from '../reportApi';
import { apiGet, apiGetRaw } from '@/lib/api';
import { queryAll } from '@/lib/db';
import type { OfflineReceipt } from '@/lib/db/repositories/offlineReceiptRepository';

function offlineReceiptsCall() {
  return vi.mocked(queryAll).mock.calls.find(
    ([, sql]) => (sql as string).includes('FROM offline_receipts'),
  );
}

beforeEach(() => {
  vi.mocked(queryAll).mockClear();
  vi.mocked(queryAll).mockResolvedValue([]);
  vi.mocked(apiGet).mockReset();
  vi.mocked(apiGetRaw).mockReset();
  mockIsOnline = false;
});

describe('generateXReport (local path)', () => {
  it('normalizes the ISO shift opened_at to SQLite UTC format in the receipts window', async () => {
    // fiscalSessionId defined → local generation, no server round-trip
    await generateXReport('term-1', { fiscalSessionId: 'fs-1' });

    const call = offlineReceiptsCall();
    expect(call).toBeDefined();
    expect((call![2] as unknown[])[1]).toBe('2026-06-12 08:54:51');
  });
});

describe('fetchShiftReceipts (offline fallback)', () => {
  it('normalizes the ISO shift opened_at to SQLite UTC format in the receipts window', async () => {
    vi.mocked(apiGetRaw).mockRejectedValue(new Error('network down'));

    await fetchShiftReceipts('shift-1');

    const call = offlineReceiptsCall();
    expect(call).toBeDefined();
    expect((call![2] as unknown[])[1]).toBe('2026-06-12 08:54:51');
  });

  it('falls back to local receipts on a 404 even while online (device shift not yet projected)', async () => {
    // Device-authoritative offline-first shifts: the device mints the shift id and
    // authors SESSION_OPEN locally; the server only gets a pos_shifts row after the
    // projection syncs. Until then GET /pos/shifts/{id}/receipts 404s. The device's
    // own receipts live in local SQLite, so the panel must read them, not error.
    mockIsOnline = true;
    vi.mocked(apiGetRaw).mockRejectedValue(
      Object.assign(new Error('Request failed (404)'), { status: 404 }),
    );

    const result = await fetchShiftReceipts('shift-1');

    expect(offlineReceiptsCall()).toBeDefined();
    expect(result).toEqual([]);
  });

  it('batch-enriches local historical lines from current product precision and leaves missing products on the fallback', async () => {
    const offlineReceipt: OfflineReceipt = {
      id: 'receipt-1',
      idempotency_key: 'receipt-1-key',
      receipt_number: 'POS01-0001',
      terminal_id: 'term-1',
      terminal_code: 'POS01',
      operator_id: 'operator-1',
      operator_name: 'Cashier',
      lines: JSON.stringify([
        {
          product_id: 'product-scale-3',
          name: 'Bulk oil',
          quantity: 1.234,
          unit_price: '10.00',
          line_total: '12.34',
        },
        {
          product_id: 'deleted-product',
          name: 'Archived item',
          quantity: 1.2,
          unit_price: '5.00',
          line_total: '6.00',
        },
      ]),
      subtotal: '18.34',
      tax_amount: '0.00',
      discount_amount: '0.00',
      total: '18.34',
      currency: 'USD',
      fiscal_hash: 'hash',
      previous_hash: 'previous-hash',
      hash_sequence: 1,
      transaction_discount_amount: null,
      transaction_discount_reason: null,
      tendered_amount: '20.00',
      change_due: '1.66',
      payment_method_id: 'cash-method',
      payment_repository_id: 'cash-repository',
      status: 'pending',
      payments_json: '[]',
      consumption_mode: null,
      table_id: null,
      cash_rounding_adjustment: null,
      cash_rounding_denomination: null,
      tolerance_shortfall: null,
      canonical_bytes: null,
      server_receipt_id: null,
      fiscal_schema_version: 3,
      is_training: 0,
      created_at: '2026-06-12 09:00:00',
      synced_at: null,
      sync_error: null,
      retry_count: 0,
    };

    vi.mocked(apiGetRaw).mockRejectedValue(new Error('network down'));
    vi.mocked(queryAll)
      .mockResolvedValueOnce([offlineReceipt])
      .mockResolvedValueOnce([{ id: 'product-scale-3', quantity_decimals: 3 }]);

    const result = await fetchShiftReceipts('shift-1');

    expect(result[0]?.lines[0]?.quantity_decimals).toBe(3);
    expect(result[0]?.lines[1]?.quantity_decimals).toBeUndefined();

    const precisionCall = vi.mocked(queryAll).mock.calls.find(
      ([, sql]) => (sql as string).includes('FROM products'),
    );
    expect(precisionCall?.[2]).toEqual(['product-scale-3', 'deleted-product']);
  });

  it('rethrows a non-404 server error while online so genuine failures surface', async () => {
    mockIsOnline = true;
    vi.mocked(apiGetRaw).mockRejectedValue(
      Object.assign(new Error('Request failed (500)'), { status: 500 }),
    );

    await expect(fetchShiftReceipts('shift-1')).rejects.toThrow('Request failed (500)');
    expect(offlineReceiptsCall()).toBeUndefined();
  });
});
