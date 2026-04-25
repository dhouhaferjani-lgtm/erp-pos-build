import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('@/lib/db', () => ({
  queryAll: vi.fn(),
  execute: vi.fn().mockResolvedValue({ rowsAffected: 1 }),
}));

import { queryAll, execute } from '@/lib/db';
import {
  insertZReportCounts,
  getZReportCountsByZReportId,
  type ZReportCountRow,
} from '../zReportCountRepository';

describe('zReportCountRepository', () => {
  const db = {} as import('@tauri-apps/plugin-sql').default;

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('inserts and reads rows back', async () => {
    const zReportId = '11111111-1111-1111-1111-111111111111';
    const rows: ZReportCountRow[] = [
      {
        id: '22222222-2222-2222-2222-222222222222',
        z_report_id: zReportId,
        payment_method_id: 'pm-1',
        currency_code: 'EUR',
        expected_amount: '100.0000',
        actual_amount: '100.0000',
        variance_amount: '0.0000',
        variance_direction: 'balanced',
        transaction_count: 3,
      },
    ];

    vi.mocked(queryAll).mockResolvedValueOnce(rows);

    await insertZReportCounts(db, rows);
    const out = await getZReportCountsByZReportId(db, zReportId);

    expect(execute).toHaveBeenCalledTimes(1);
    const [, sql, params] = vi.mocked(execute).mock.calls[0]!;
    expect(sql).toMatch(/INSERT INTO z_report_counts/);
    expect(params).toEqual([
      rows[0]!.id,
      rows[0]!.z_report_id,
      rows[0]!.payment_method_id,
      rows[0]!.currency_code,
      rows[0]!.expected_amount,
      rows[0]!.actual_amount,
      rows[0]!.variance_amount,
      rows[0]!.variance_direction,
      rows[0]!.transaction_count,
    ]);

    expect(out).toHaveLength(1);
    expect(out[0]!.variance_direction).toBe('balanced');
    expect(out[0]!.actual_amount).toBe('100.0000');
  });

  it('round-trips an under variance with negative variance_amount', async () => {
    const zReportId = '33333333-3333-3333-3333-333333333333';
    const underRow: ZReportCountRow = {
      id: 'a-row-id',
      z_report_id: zReportId,
      payment_method_id: 'pm-1',
      currency_code: 'TND',
      expected_amount: '100.000',
      actual_amount: '95.000',
      variance_amount: '-5.000',
      variance_direction: 'under',
      transaction_count: 1,
    };

    vi.mocked(queryAll).mockResolvedValueOnce([underRow]);

    await insertZReportCounts(db, [underRow]);
    const out = await getZReportCountsByZReportId(db, zReportId);

    expect(out[0]!.variance_direction).toBe('under');
    expect(out[0]!.variance_amount).toBe('-5.000');
  });

  it('no-op on empty rows array', async () => {
    await insertZReportCounts(db, []);
    expect(execute).not.toHaveBeenCalled();
  });

  it('getZReportCountsByZReportId passes correct WHERE param', async () => {
    const zReportId = '44444444-4444-4444-4444-444444444444';
    vi.mocked(queryAll).mockResolvedValueOnce([]);

    await getZReportCountsByZReportId(db, zReportId);

    expect(queryAll).toHaveBeenCalledOnce();
    const [, sql, params] = vi.mocked(queryAll).mock.calls[0]!;
    expect(sql).toMatch(/WHERE z_report_id = /);
    expect(params).toEqual([zReportId]);
  });

  it('inserts multiple rows sequentially', async () => {
    const zReportId = '55555555-5555-5555-5555-555555555555';
    const multiRows: ZReportCountRow[] = [
      {
        id: 'row-1',
        z_report_id: zReportId,
        payment_method_id: 'pm-cash',
        currency_code: 'EUR',
        expected_amount: '200.0000',
        actual_amount: '198.0000',
        variance_amount: '-2.0000',
        variance_direction: 'under',
        transaction_count: 5,
      },
      {
        id: 'row-2',
        z_report_id: zReportId,
        payment_method_id: 'pm-card',
        currency_code: 'EUR',
        expected_amount: '150.0000',
        actual_amount: '152.0000',
        variance_amount: '2.0000',
        variance_direction: 'over',
        transaction_count: 3,
      },
    ];

    await insertZReportCounts(db, multiRows);

    expect(execute).toHaveBeenCalledTimes(2);
  });
});
