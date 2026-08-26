/**
 * B-13 gate r1 (R1-1) — an authorization refusal from the server must never be
 * laundered into a locally-authored fiscal event.
 *
 * `generateXReport` used a bare `catch` around `POST /pos/reports/x`, which
 * cannot tell `403 Forbidden` from a dropped connection. A cashier-provisioned
 * terminal refused by `ReportController::generateXReport`'s
 * `Gate::authorize('pos.view_reports')` therefore fell through to the local
 * builder and appended an immutable `X_REPORT` event (rule 8 — never
 * correctable, only superseded). An authz denial produced a fiscal WRITE.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';

const mocks = vi.hoisted(() => {
  class FakeApiRequestError extends Error {
    constructor(
      public readonly status: number,
      public readonly apiMessage: string,
      public readonly code: string,
    ) {
      super(apiMessage);
      this.name = 'ApiRequestError';
    }
  }
  return {
    FakeApiRequestError,
    apiPost: vi.fn(),
    apiGet: vi.fn(),
    apiGetRaw: vi.fn(),
    getAllPaymentMethods: vi.fn(),
    queryAll: vi.fn(),
    getDatabase: vi.fn(),
    appendXReport: vi.fn(),
    getFiscalEventEngine: vi.fn(),
  };
});

vi.mock('@/lib/api', () => ({
  apiPost: mocks.apiPost,
  apiGet: mocks.apiGet,
  apiGetRaw: mocks.apiGetRaw,
  ApiRequestError: mocks.FakeApiRequestError,
}));
vi.mock('@/lib/db', () => ({
  getDatabase: mocks.getDatabase,
  queryAll: mocks.queryAll,
}));
vi.mock('@/lib/db/repositories/paymentRepository', () => ({
  getAllPaymentMethods: mocks.getAllPaymentMethods,
}));
vi.mock('@/lib/fiscal/zSessionAuthoring', () => ({ appendXReport: mocks.appendXReport }));
vi.mock('@/lib/fiscal/instance', () => ({ getFiscalEventEngine: mocks.getFiscalEventEngine }));
vi.mock('@/lib/fiscal/sealedReceiptView', () => ({ readSealedReceiptView: () => null }));
vi.mock('@/lib/offline/zReportService', () => ({ generateZReport: vi.fn() }));
vi.mock('@/lib/currency', () => ({ getCurrencyDecimals: () => 3 }));
vi.mock('@/stores/authStore', () => ({
  useAuthStore: {
    getState: () => ({
      companyId: 'co-1',
      companies: [{ id: 'co-1', currency: 'TND' }],
    }),
  },
}));
vi.mock('@/stores/terminalStore', () => ({
  useTerminalStore: { getState: () => ({ shift: null, terminal: null }) },
}));

import { generateXReport } from '../reportApi';
import { ApiRequestError } from '@/lib/api';

describe('generateXReport — server refusal vs offline', () => {
  beforeEach(() => {
    mocks.apiPost.mockReset();
    mocks.queryAll.mockReset();
    mocks.queryAll.mockResolvedValue([]);
    mocks.getAllPaymentMethods.mockReset();
    mocks.getAllPaymentMethods.mockResolvedValue([]);
    mocks.getDatabase.mockReset();
    mocks.getDatabase.mockResolvedValue({});
    mocks.appendXReport.mockReset();
  });

  it('REFUSES on a 403 — no local build, no fiscal event, typed error surfaced', async () => {
    mocks.apiPost.mockRejectedValue(new ApiRequestError(403, 'Forbidden', 'FORBIDDEN'));

    await expect(generateXReport('term-1')).rejects.toBeInstanceOf(ApiRequestError);
    expect(mocks.appendXReport).not.toHaveBeenCalled();
    // The local builder reads receipts; it must not have run at all.
    expect(mocks.queryAll).not.toHaveBeenCalled();
  });

  it('REFUSES on a 401 the same way', async () => {
    mocks.apiPost.mockRejectedValue(new ApiRequestError(401, 'Unauthorized', 'UNAUTHORIZED'));

    await expect(generateXReport('term-1')).rejects.toBeInstanceOf(ApiRequestError);
    expect(mocks.queryAll).not.toHaveBeenCalled();
  });

  it('falls back to the local builder when the network is down (existing behaviour)', async () => {
    mocks.apiPost.mockRejectedValue(new TypeError('Failed to fetch'));

    const report = await generateXReport('term-1');
    expect(report.terminal_id).toBe('term-1');
    expect(mocks.queryAll).toHaveBeenCalled();
  });

  it('falls back on a server 500 — a broken server is an outage, not a refusal', async () => {
    mocks.apiPost.mockRejectedValue(new ApiRequestError(500, 'Server error', 'SERVER_ERROR'));

    const report = await generateXReport('term-1');
    expect(report.terminal_id).toBe('term-1');
    expect(mocks.queryAll).toHaveBeenCalled();
  });

  it('falls back on a 404 — an older API build without the route is an outage', async () => {
    mocks.apiPost.mockRejectedValue(new ApiRequestError(404, 'Not found', 'NOT_FOUND'));

    await expect(generateXReport('term-1')).resolves.toBeDefined();
    expect(mocks.queryAll).toHaveBeenCalled();
  });

  it('does not call the server at all on the v3 device-authoritative path', async () => {
    await generateXReport('term-1', {
      tenantId: 't-1',
      fiscalShiftId: 'fs-1',
      fiscalSessionId: 'fs-1',
      operatorId: 'op-1',
      operatorName: 'Op',
    });
    expect(mocks.apiPost).not.toHaveBeenCalled();
  });
});
