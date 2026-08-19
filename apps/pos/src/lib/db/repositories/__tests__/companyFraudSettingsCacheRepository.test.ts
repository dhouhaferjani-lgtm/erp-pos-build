import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('@/lib/db', () => ({
  queryAll: vi.fn(),
  execute: vi.fn().mockResolvedValue(undefined),
}));

import {
  upsertCompanyFraudSettings,
  getCompanyFraudSettings,
  type CompanyFraudSettingsCacheRow,
} from '../companyFraudSettingsCacheRepository';
import { queryAll, execute } from '@/lib/db';

describe('companyFraudSettingsCacheRepository', () => {
  const db = {} as import('@tauri-apps/plugin-sql').default;

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('returns null when no row cached', async () => {
    vi.mocked(queryAll).mockResolvedValue([]);

    const out = await getCompanyFraudSettings(db, 'nonexistent-company');

    expect(out).toBeNull();
    const [, sql, params] = vi.mocked(queryAll).mock.calls[0]!;
    expect(sql).toMatch(/FROM company_fraud_settings_cache/);
    expect(params).toContain('nonexistent-company');
  });

  it('upserts then reads back fields preserved at scale 4', async () => {
    const row: CompanyFraudSettingsCacheRow = {
      company_id: 'co-1',
      cash_variance_over_soft: '1.0000',
      cash_variance_over_hard: '20.0000',
      cash_variance_under_soft: '1.0000',
      cash_variance_under_hard: '20.0000',
      require_blind_cash_count: false,
      require_manager_pin_above_hard: true,
      cash_variance_email_severity: 'none',
      // Lane C M2/M3 refund-exposure policies (seeded defaults).
      offline_refund_count_ceiling: 5,
      offline_refund_value_ceiling: '300.0000',
      online_required_refund_threshold: '100.0000',
    };

    vi.mocked(queryAll).mockResolvedValue([
      {
        company_id: 'co-1',
        cash_variance_over_soft: '1.0000',
        cash_variance_over_hard: '20.0000',
        cash_variance_under_soft: '1.0000',
        cash_variance_under_hard: '20.0000',
        require_blind_cash_count: 0,
        require_manager_pin_above_hard: 1,
        cash_variance_email_severity: 'none',
      },
    ]);

    await upsertCompanyFraudSettings(db, row);
    const out = await getCompanyFraudSettings(db, 'co-1');

    expect(execute).toHaveBeenCalledOnce();
    const [, upsertSql, upsertParams] = vi.mocked(execute).mock.calls[0]!;
    expect(upsertSql).toMatch(/INSERT INTO company_fraud_settings_cache/);
    expect(upsertSql).toMatch(/ON CONFLICT\(company_id\) DO UPDATE/);
    expect(upsertParams).toContain('co-1');
    expect(upsertParams).toContain(0); // require_blind_cash_count = false → 0
    expect(upsertParams).toContain(1); // require_manager_pin_above_hard = true → 1

    expect(out).not.toBeNull();
    expect(out?.cash_variance_over_hard).toBe('20.0000');
    expect(out?.require_blind_cash_count).toBe(false);
    expect(out?.require_manager_pin_above_hard).toBe(true);
  });

  /**
   * Lane C M2/M3 — the refund-exposure policies ride this SAME cached row.
   * A silent drop here would make every device fall back to the seeded
   * constants and hide a tenant's own tightened ceiling.
   */
  it('carries the M2/M3 refund-exposure policies through the upsert and the read', async () => {
    const row: CompanyFraudSettingsCacheRow = {
      company_id: 'co-4',
      cash_variance_over_soft: '1.0000',
      cash_variance_over_hard: '20.0000',
      cash_variance_under_soft: '1.0000',
      cash_variance_under_hard: '20.0000',
      require_blind_cash_count: false,
      require_manager_pin_above_hard: true,
      cash_variance_email_severity: 'none',
      offline_refund_count_ceiling: 3,
      offline_refund_value_ceiling: '150.0000',
      online_required_refund_threshold: '80.0000',
    };

    vi.mocked(queryAll).mockResolvedValue([
      {
        ...row,
        require_blind_cash_count: 0,
        require_manager_pin_above_hard: 1,
      },
    ]);

    await upsertCompanyFraudSettings(db, row);
    const out = await getCompanyFraudSettings(db, 'co-4');

    const [, upsertSql, upsertParams] = vi.mocked(execute).mock.calls[0]!;
    expect(upsertSql).toMatch(/offline_refund_count_ceiling/);
    expect(upsertSql).toMatch(/offline_refund_value_ceiling/);
    expect(upsertSql).toMatch(/online_required_refund_threshold/);
    expect(upsertParams).toContain(3);
    expect(upsertParams).toContain('150.0000');
    expect(upsertParams).toContain('80.0000');

    const [, selectSql] = vi.mocked(queryAll).mock.calls[0]!;
    expect(selectSql).toMatch(/online_required_refund_threshold/);
    expect(out?.offline_refund_count_ceiling).toBe(3);
    expect(out?.offline_refund_value_ceiling).toBe('150.0000');
    expect(out?.online_required_refund_threshold).toBe('80.0000');
  });

  it('upsert overwrites existing row — second call carries new values', async () => {
    const updated: CompanyFraudSettingsCacheRow = {
      company_id: 'co-2',
      cash_variance_over_soft: '2.0000',
      cash_variance_over_hard: '30.0000',
      cash_variance_under_soft: '2.0000',
      cash_variance_under_hard: '30.0000',
      require_blind_cash_count: true,
      require_manager_pin_above_hard: true,
      cash_variance_email_severity: 'critical',
      offline_refund_count_ceiling: 5,
      offline_refund_value_ceiling: '300.0000',
      online_required_refund_threshold: '100.0000',
    };

    vi.mocked(queryAll).mockResolvedValue([
      {
        company_id: 'co-2',
        cash_variance_over_soft: '2.0000',
        cash_variance_over_hard: '30.0000',
        cash_variance_under_soft: '2.0000',
        cash_variance_under_hard: '30.0000',
        require_blind_cash_count: 1,
        require_manager_pin_above_hard: 1,
        cash_variance_email_severity: 'critical',
      },
    ]);

    await upsertCompanyFraudSettings(db, updated);
    const out = await getCompanyFraudSettings(db, 'co-2');

    const [, , params] = vi.mocked(execute).mock.calls[0]!;
    expect(params).toContain('30.0000');
    expect(params).toContain('critical');
    expect(params).toContain(1); // require_blind_cash_count = true → 1

    expect(out?.cash_variance_over_hard).toBe('30.0000');
    expect(out?.require_blind_cash_count).toBe(true);
    expect(out?.cash_variance_email_severity).toBe('critical');
  });

  it('maps INTEGER 0/1 from SQLite to boolean false/true', async () => {
    vi.mocked(queryAll).mockResolvedValue([
      {
        company_id: 'co-3',
        cash_variance_over_soft: '5.0000',
        cash_variance_over_hard: '50.0000',
        cash_variance_under_soft: '5.0000',
        cash_variance_under_hard: '50.0000',
        require_blind_cash_count: 0,
        require_manager_pin_above_hard: 0,
        cash_variance_email_severity: 'warning',
      },
    ]);

    const out = await getCompanyFraudSettings(db, 'co-3');

    expect(out?.require_blind_cash_count).toBe(false);
    expect(out?.require_manager_pin_above_hard).toBe(false);
    expect(out?.cash_variance_email_severity).toBe('warning');
  });

  it.each([1, '1', true] as const)(
    'maps the driver true shape %j to a blind-count requirement',
    async (requireBlindCashCount) => {
      vi.mocked(queryAll).mockResolvedValue([
        {
          company_id: 'co-driver-shape',
          cash_variance_over_soft: '5.0000',
          cash_variance_over_hard: '50.0000',
          cash_variance_under_soft: '5.0000',
          cash_variance_under_hard: '50.0000',
          require_blind_cash_count: requireBlindCashCount,
          require_manager_pin_above_hard: 0,
          cash_variance_email_severity: 'warning',
        },
      ]);

      const out = await getCompanyFraudSettings(db, 'co-driver-shape');

      expect(out?.require_blind_cash_count).toBe(true);
    },
  );
});
