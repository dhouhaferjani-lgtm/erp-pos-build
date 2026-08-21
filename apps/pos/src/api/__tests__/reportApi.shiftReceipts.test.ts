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

vi.mock('@/lib/db/sqliteTime', () => ({ toSqliteUtc: (v: string) => v }));

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
import { queryAll } from '@/lib/db';
import { fetchShiftReceipts } from '../reportApi';

const apiGetMock = vi.mocked(apiGet);
const apiGetRawMock = vi.mocked(apiGetRaw);
const queryAllMock = vi.mocked(queryAll);

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
    expect(apiGetRawMock.mock.calls[0]?.[1]).toMatchObject({ page: 1, per_page: 200 });
    expect(apiGetRawMock.mock.calls[1]?.[1]).toMatchObject({ page: 2, per_page: 200 });
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

  // P3-C — a SHORT page without `meta` is legitimately single-page (some endpoints in
  // this module answer a divergent envelope). A FULL page without `meta` is the
  // dangerous case: it looks complete and is not, which is the exact silent-truncation
  // this lane exists to remove. Fail loudly instead of guessing.
  it('refuses a full page that carries no pagination meta', async () => {
    // Exactly SHIFT_RECEIPTS_PAGE_SIZE rows — indistinguishable from a truncated page.
    apiGetRawMock.mockResolvedValue({
      data: Array.from({ length: 200 }, (_, i) => receipt(i + 1, 'sale')),
    });

    await expect(fetchShiftReceipts('shift-1')).rejects.toThrow(/pagination meta/i);
  });

  it('accepts a short page with no pagination meta as the only page', async () => {
    apiGetRawMock.mockResolvedValue({ data: page2 });

    const receipts = await fetchShiftReceipts('shift-1');

    expect(receipts).toHaveLength(2);
    expect(apiGetRawMock).toHaveBeenCalledTimes(1);
  });

  // P3-B — the hard stop must fail LOUDLY. Thrown from inside the try it was caught by
  // the offline fallback, so an offline shift past the cap silently returned local rows
  // instead of erroring — contradicting the docblock's "fail loudly rather than spin
  // forever or silently truncate".
  it('throws past the page cap even when offline, rather than falling back', async () => {
    mockIsOnline = false;
    apiGetRawMock.mockResolvedValue({
      data: page1,
      meta: { current_page: 1, last_page: 9999, per_page: 20, total: 999999 },
    });

    await expect(fetchShiftReceipts('shift-1')).rejects.toThrow(/exceeded 40 pages/);
    expect(queryAllMock).not.toHaveBeenCalled();
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

/**
 * O-28 — the OFFLINE mapper is the last path by which a training or voided receipt
 * can inflate the audited figure.
 *
 * `fetchLocalShiftReceipts` hardcoded `is_voided: false` and emitted no
 * `is_training` at all, even though `offline_receipts` carries BOTH columns
 * (`is_training` on the `OfflineReceipt` interface; `voided` added by migration 15
 * `add_voided_to_offline_receipts`) and the row query is `SELECT *`. So the panel's
 * `isCounted` filter — correct on the online path — had nothing to act on offline:
 * every training and voided receipt was silently counted as a real sale.
 *
 * The repository already uses exactly this predicate pair for the same reason
 * (`getUnsyncedReceiptLineBlobs`: "`voided = 0`: a sealed-then-voided receipt's sale
 * was reversed. `is_training = 0`: training receipts never move real stock.").
 */
function offlineRow(over: Record<string, unknown>) {
  return {
    id: 'local-1',
    receipt_number: 'POS01-0001',
    terminal_id: 'term-1',
    payment_method_id: 'pm-1',
    lines: '[]',
    subtotal: '84.00',
    tax_amount: '16.00',
    total: '100.00',
    receipt_kind: 'sale',
    is_training: 0,
    voided: 0,
    created_at: '2026-08-21 09:00:00',
    ...over,
  };
}

describe('fetchLocalShiftReceipts training/void projection', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockIsOnline = false;
    apiGetRawMock.mockRejectedValue(new Error('offline'));
  });

  function withRows(rows: unknown[]) {
    queryAllMock.mockImplementation((_db: unknown, sql: string) =>
      Promise.resolve(sql.includes('FROM offline_receipts') ? rows : []),
    );
  }

  it('marks a locally voided receipt as voided instead of hardcoding false', async () => {
    withRows([offlineRow({ id: 'local-void', voided: 1 })]);

    const receipts = await fetchShiftReceipts('shift-1');

    expect(receipts).toHaveLength(1);
    expect(receipts[0]?.is_voided).toBe(true);
  });

  it('surfaces the training flag so the panel can exclude it', async () => {
    withRows([offlineRow({ id: 'local-training', is_training: 1 })]);

    const receipts = await fetchShiftReceipts('shift-1');

    expect(receipts).toHaveLength(1);
    expect(receipts[0]?.is_training).toBe(true);
  });

  it('leaves an ordinary offline sale counted', async () => {
    withRows([offlineRow({})]);

    const receipts = await fetchShiftReceipts('shift-1');

    expect(receipts[0]?.is_voided).toBe(false);
    expect(receipts[0]?.is_training).toBe(false);
  });

  // The tile's population, expressed exactly as `TodaySalesPanel.isCounted` reads it.
  // Before the mapper fix all three rows survived this filter offline, so a training
  // receipt and a voided receipt both moved the headline.
  it('leaves only the real sale in the counted population', async () => {
    withRows([
      offlineRow({ id: 'real' }),
      offlineRow({ id: 'trained', is_training: 1 }),
      offlineRow({ id: 'voided', voided: 1 }),
    ]);

    const receipts = await fetchShiftReceipts('shift-1');
    const counted = receipts.filter((r) => !r.is_voided && r.is_training !== true);

    expect(counted.map((r) => r.id)).toEqual(['real']);
  });
});
