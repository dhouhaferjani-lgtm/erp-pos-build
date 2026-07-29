import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

vi.mock('@/lib/db', () => ({
  queryAll: vi.fn(),
  execute: vi.fn().mockResolvedValue(undefined),
}));

import {
  upsertPaymentPolicy,
  getPaymentPolicy,
  type PaymentPolicyCacheRow,
} from '../paymentPolicyCacheRepository';
import { queryAll, execute } from '@/lib/db';

describe('paymentPolicyCacheRepository', () => {
  const db = {} as import('@tauri-apps/plugin-sql').default;

  beforeEach(() => {
    vi.clearAllMocks();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('returns null when no row cached and the table is empty', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
    vi.mocked(queryAll).mockResolvedValue([]);

    const out = await getPaymentPolicy(db, 'nonexistent-company');

    expect(out).toBeNull();
    const [, sql, params] = vi.mocked(queryAll).mock.calls[0]!;
    expect(sql).toMatch(/FROM payment_policy_cache/);
    expect(params).toContain('nonexistent-company');
    expect(warn).not.toHaveBeenCalled();
  });

  it('warns when the cache holds a DIFFERENT company (silent-degrade trap)', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
    vi.mocked(queryAll)
      .mockResolvedValueOnce([])
      .mockResolvedValueOnce([{ company_id: 'other-company' }]);

    const out = await getPaymentPolicy(db, 'company-tnd');

    // Still fail-closed — the warn only makes the mismatch observable.
    expect(out).toBeNull();
    expect(warn).toHaveBeenCalledOnce();
    expect(String(warn.mock.calls[0]![0])).toMatch(/company_id/);
  });

  it('always writes currency_scale explicitly so the scale-2 column default is unreachable', async () => {
    const row: PaymentPolicyCacheRow = {
      company_id: 'co-tnd',
      cash_rounding_enabled: true,
      cash_rounding_denomination: '0.050',
      tender_tolerance_enabled: false,
      tender_tolerance_percentage: '0.0050',
      tender_tolerance_max_amount: '0.100',
      currency_code: 'TND',
      currency_scale: 3,
    };

    await upsertPaymentPolicy(db, row);

    expect(execute).toHaveBeenCalledOnce();
    const [, sql, params] = vi.mocked(execute).mock.calls[0]!;
    expect(sql).toMatch(/INSERT INTO payment_policy_cache/);
    expect(sql).toMatch(/ON CONFLICT\(company_id\) DO UPDATE/);
    expect(params).toEqual([
      'co-tnd',
      1, // cash_rounding_enabled = true → 1
      '0.050',
      0, // tender_tolerance_enabled = false → 0
      '0.0050',
      '0.100',
      'TND',
      3,
    ]);
  });

  it('maps INTEGER 0/1 to boolean and preserves money strings verbatim', async () => {
    vi.mocked(queryAll).mockResolvedValue([
      {
        company_id: 'co-tnd',
        cash_rounding_enabled: 1,
        cash_rounding_denomination: '0.050',
        tender_tolerance_enabled: 0,
        tender_tolerance_percentage: '0.0050',
        tender_tolerance_max_amount: '0.100',
        currency_code: 'TND',
        currency_scale: 3,
        refreshed_at: '2026-07-27 08:00:00',
      },
    ]);

    const out = await getPaymentPolicy(db, 'co-tnd');

    expect(out?.cash_rounding_enabled).toBe(true);
    expect(out?.tender_tolerance_enabled).toBe(false);
    expect(out?.cash_rounding_denomination).toBe('0.050');
    expect(out?.tender_tolerance_max_amount).toBe('0.100');
    expect(out?.currency_scale).toBe(3);
    expect(out?.refreshed_at).toBe('2026-07-27 08:00:00');
  });
});
