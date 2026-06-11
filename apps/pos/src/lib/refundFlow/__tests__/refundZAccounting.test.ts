import { beforeEach, describe, expect, it, vi } from 'vitest';

// ─── Mocks (repository / store boundary only) ────────────────────────────────
// vi.mock factories are hoisted to the top of the file by Vitest; no top-level
// variables from this module may be referenced inside them.

vi.mock('@/lib/db/repositories/localRefundRecordRepository', () => ({
  insertLocalRefundRecord: vi.fn(),
}));

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn().mockResolvedValue({}),
}));

vi.mock('@/stores/terminalStore', () => ({
  useTerminalStore: { getState: vi.fn() },
}));

// Import AFTER mocks are registered.
import { recordRefundSettlementForZ } from '../refundZAccounting';
import type { RecordRefundSettlementInput } from '../refundZAccounting';
import { insertLocalRefundRecord } from '@/lib/db/repositories/localRefundRecordRepository';
import { getDatabase } from '@/lib/db';
import { useTerminalStore } from '@/stores/terminalStore';

// ─── Helpers ─────────────────────────────────────────────────────────────────

const SHIFT = {
  id: 'shift-abc',
  terminal_id: 'term-1',
  shift_number: 3,
  status: 'OPEN' as const,
  opening_cash: '100.00',
  opened_at: '2026-06-10T08:00:00Z',
  user: { id: 'user-1', name: 'Alice' },
};

function baseInput(
  destination: RecordRefundSettlementInput['destination'],
  total = '-23.80',
): RecordRefundSettlementInput {
  return {
    companyId: 'company-1',
    terminalId: 'term-1',
    destination,
    originalReceiptNumber: 'L01-T01-00042',
    response: {
      id: 'c0000000-0000-4000-8000-000000000001',
      receipt_number: 'L01-T01-00043',
      receipt_type: 'return',
      original_receipt_id: 'server-receipt-1',
      return_reason: 'defective',
      subtotal: '-20.00',
      tax_amount: '-3.80',
      total,
      currency: 'EUR',
      posted_at: '2026-06-10T09:00:00Z',
      qr_token: 'v:kid:c0000000:mac',
      issued_voucher: null,
      lines: [],
    },
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(useTerminalStore.getState).mockReturnValue({ shift: SHIFT } as ReturnType<typeof useTerminalStore.getState>);
  vi.mocked(insertLocalRefundRecord).mockResolvedValue(undefined);
  vi.mocked(getDatabase).mockResolvedValue({} as Awaited<ReturnType<typeof getDatabase>>);
});

// ─── cash destination ─────────────────────────────────────────────────────────

describe('recordRefundSettlementForZ — destination cash', () => {
  it('inserts with cash_impact = abs(total) and returns true', async () => {
    const result = await recordRefundSettlementForZ(baseInput('cash'));

    expect(result).toBe(true);
    expect(insertLocalRefundRecord).toHaveBeenCalledOnce();
    const inserted = vi.mocked(insertLocalRefundRecord).mock.calls[0]![1];
    // cash_impact must be the positive magnitude of total
    expect(inserted.cash_impact).toBe('23.800');
    expect(inserted.destination).toBe('cash');
    expect(inserted.total).toBe('-23.80');
    expect(inserted.shift_id).toBe(SHIFT.id);
  });
});

// ─── store_voucher destination ────────────────────────────────────────────────

describe('recordRefundSettlementForZ — destination store_voucher', () => {
  it("inserts with cash_impact = '0' and returns true", async () => {
    const result = await recordRefundSettlementForZ(baseInput('store_voucher'));

    expect(result).toBe(true);
    const inserted = vi.mocked(insertLocalRefundRecord).mock.calls[0]![1];
    expect(inserted.cash_impact).toBe('0');
    expect(inserted.destination).toBe('store_voucher');
  });
});

// ─── original_payment destination ─────────────────────────────────────────────

describe('recordRefundSettlementForZ — destination original', () => {
  it("inserts with cash_impact = '0' and returns true (server Treasury proration, no till cash)", async () => {
    const result = await recordRefundSettlementForZ(baseInput('original'));

    expect(result).toBe(true);
    const inserted = vi.mocked(insertLocalRefundRecord).mock.calls[0]![1];
    expect(inserted.cash_impact).toBe('0');
    expect(inserted.destination).toBe('original_payment');
  });
});

// ─── null shift guard ─────────────────────────────────────────────────────────

describe('recordRefundSettlementForZ — null shift', () => {
  it('returns false and logs an error when terminalStore shift is null, and never calls insert', async () => {
    vi.mocked(useTerminalStore.getState).mockReturnValue({ shift: null } as ReturnType<typeof useTerminalStore.getState>);
    const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => undefined);

    const result = await recordRefundSettlementForZ(baseInput('cash'));

    expect(result).toBe(false);
    expect(insertLocalRefundRecord).not.toHaveBeenCalled();
    expect(consoleSpy).toHaveBeenCalledOnce();
    consoleSpy.mockRestore();
  });
});

// ─── repository failure ───────────────────────────────────────────────────────

describe('recordRefundSettlementForZ — repository insert failure', () => {
  it('returns false and never throws when insertLocalRefundRecord rejects', async () => {
    vi.mocked(insertLocalRefundRecord).mockRejectedValueOnce(new Error('SQLite constraint'));
    const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => undefined);

    await expect(recordRefundSettlementForZ(baseInput('cash'))).resolves.toBe(false);

    expect(consoleSpy).toHaveBeenCalledOnce();
    consoleSpy.mockRestore();
  });

  it('returns false and never throws when getDatabase rejects', async () => {
    vi.mocked(getDatabase).mockRejectedValueOnce(new Error('DB init failed'));
    const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => undefined);

    await expect(recordRefundSettlementForZ(baseInput('cash'))).resolves.toBe(false);

    expect(consoleSpy).toHaveBeenCalledOnce();
    consoleSpy.mockRestore();
  });
});
