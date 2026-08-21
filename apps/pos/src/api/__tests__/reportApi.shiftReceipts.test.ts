import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * O-28 / P1-1 — `GET /pos/shifts/{id}/receipts` is PAGINATED.
 *
 * `ShiftController::receipts` ends in `->paginate($request->input('per_page', 20))`
 * ordered `posted_at DESC`, and answers the `{ data, meta }` envelope. `apiGet`
 * unwraps `json.data` and DROPS `meta.pagination` (documented on `apiGetRaw` in
 * `lib/api.ts`), so the old `apiGet<ShiftReceipt[]>(...)` returned PAGE ONE ONLY —
 * the 20 most recent receipts.
 *
 * Consequences, both real on any shift busier than 20 transactions:
 *  - the Today's-Sales headline was computed over a truncated population, and
 *  - because the order is `posted_at DESC`, the receipts silently dropped are the
 *    EARLIEST ones — so a refund taken at the start of the shift stopped being
 *    deducted from the net figure as soon as 20 later receipts pushed it off page 1;
 *  - the offline fallback has no limit at all, so the same shift showed two
 *    different "net sales" figures depending on connectivity.
 */

vi.mock('@/lib/api', () => ({
  apiGet: vi.fn(),
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

vi.mock('@/lib/fiscal/instance', () => ({ getFiscalEventEngine: vi.fn() }));
vi.mock('@/lib/fiscal/zSessionAuthoring', () => ({ appendXReport: vi.fn() }));
vi.mock('@/lib/offline/zReportService', () => ({ generateZReport: vi.fn() }));

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
      shift: { id: 'shift-1', opened_at: '2026-08-21T06:00:00.000Z' },
    })),
  },
}));

let mockIsOnline = true;
vi.mock('@/stores/connectivityStore', () => ({
  useConnectivityStore: { getState: () => ({ isOnline: mockIsOnline }) },
}));

import { apiGet, apiGetRaw } from '@/lib/api';
import { fetchShiftReceipts } from '../reportApi';

const apiGetMock = vi.mocked(apiGet);
const apiGetRawMock = vi.mocked(apiGetRaw);

function receipt(n: number, type: 'sale' | 'return') {
  return {
    id: `r-${String(n)}`,
    receipt_number: `REC-${String(n).padStart(3, '0')}`,
    receipt_type: type,
    total: type === 'return' ? '20.00' : '10.00',
    subtotal: '8.40',
    tax_amount: '1.60',
    is_voided: false,
    posted_at: `2026-08-21T${String(6 + n).padStart(2, '0')}:00:00Z`,
    payments: [],
    lines: [],
  };
}

// posted_at DESC: page 1 = the 20 most recent SALES, page 2 = the shift's
// earliest receipts, which is where the opening refund lives.
const page1 = Array.from({ length: 20 }, (_, i) => receipt(30 - i, 'sale'));
const page2 = [receipt(2, 'sale'), receipt(1, 'return')];

describe('fetchShiftReceipts pagination', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockIsOnline = true;
    // Faithful `apiGet` behaviour: it resolves the unwrapped page-1 array.
    apiGetMock.mockResolvedValue(page1);
    apiGetRawMock.mockImplementation((_url: string, params?: Record<string, unknown>) => {
      const page = Number(params?.page ?? 1);
      return Promise.resolve(
        page === 1
          ? { data: page1, meta: { current_page: 1, last_page: 2, per_page: 20, total: 22 } }
          : { data: page2, meta: { current_page: 2, last_page: 2, per_page: 20, total: 22 } },
      );
    });
  });

  it('returns every page, not just the first', async () => {
    const receipts = await fetchShiftReceipts('shift-1');

    expect(receipts).toHaveLength(22);
  });

  it('keeps an early refund that ordering pushed off page 1', async () => {
    const receipts = await fetchShiftReceipts('shift-1');

    expect(receipts.filter((r) => r.receipt_type === 'return')).toHaveLength(1);
    expect(receipts.map((r) => r.id)).toContain('r-1');
  });

  it('reads the paginated envelope rather than the meta-dropping unwrap', async () => {
    await fetchShiftReceipts('shift-1');

    expect(apiGetMock).not.toHaveBeenCalled();
    expect(apiGetRawMock).toHaveBeenCalledTimes(2);
    expect(apiGetRawMock.mock.calls[0]?.[1]).toMatchObject({ page: 1 });
    expect(apiGetRawMock.mock.calls[1]?.[1]).toMatchObject({ page: 2 });
  });

  it('stops after a single request when the shift fits on one page', async () => {
    apiGetRawMock.mockResolvedValue({
      data: page2,
      meta: { current_page: 1, last_page: 1, per_page: 200, total: 2 },
    });

    const receipts = await fetchShiftReceipts('shift-1');

    expect(receipts).toHaveLength(2);
    expect(apiGetRawMock).toHaveBeenCalledTimes(1);
  });

  it('falls back to the local shift receipts when the server errors offline', async () => {
    mockIsOnline = false;
    apiGetRawMock.mockRejectedValue(new Error('network'));

    const receipts = await fetchShiftReceipts('shift-1');

    // The local SQLite query is mocked to return no rows; the point is that the
    // rejection is routed to the offline path rather than thrown.
    expect(receipts).toEqual([]);
  });
});
