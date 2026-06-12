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

vi.mock('@/stores/connectivityStore', () => ({
  useConnectivityStore: {
    getState: vi.fn(() => ({ isOnline: false })),
  },
}));

import { generateXReport, fetchShiftReceipts } from '../reportApi';
import { apiGet } from '@/lib/api';
import { queryAll } from '@/lib/db';

function offlineReceiptsCall() {
  return vi.mocked(queryAll).mock.calls.find(
    ([, sql]) => (sql as string).includes('FROM offline_receipts'),
  );
}

beforeEach(() => {
  vi.mocked(queryAll).mockClear();
  vi.mocked(queryAll).mockResolvedValue([]);
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
    vi.mocked(apiGet).mockRejectedValue(new Error('network down'));

    await fetchShiftReceipts('shift-1');

    const call = offlineReceiptsCall();
    expect(call).toBeDefined();
    expect((call![2] as unknown[])[1]).toBe('2026-06-12 08:54:51');
  });
});
