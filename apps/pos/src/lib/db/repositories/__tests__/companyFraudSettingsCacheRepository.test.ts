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
});
