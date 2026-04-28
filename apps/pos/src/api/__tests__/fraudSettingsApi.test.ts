import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fetchFraudSettings, refreshFraudSettingsCache } from '../fraudSettingsApi';

vi.mock('@/lib/api', () => ({ apiGet: vi.fn() }));
vi.mock('@/lib/db/repositories/companyFraudSettingsCacheRepository', () => ({
  upsertCompanyFraudSettings: vi.fn(),
}));

import { apiGet } from '@/lib/api';
import { upsertCompanyFraudSettings } from '@/lib/db/repositories/companyFraudSettingsCacheRepository';

describe('fraudSettingsApi', () => {
  beforeEach(() => {
    vi.mocked(apiGet).mockReset();
    vi.mocked(upsertCompanyFraudSettings).mockReset();
  });

  it('GETs /pos/fraud-settings and returns the unwrapped DTO', async () => {
    vi.mocked(apiGet).mockResolvedValueOnce({
      cashVarianceOverSoft: '1.0000',
      cashVarianceOverHard: '20.0000',
      cashVarianceUnderSoft: '1.0000',
      cashVarianceUnderHard: '20.0000',
      requireBlindCashCount: true,
      requireManagerPinAboveHard: true,
      cashVarianceEmailSeverity: 'none',
    });
    const result = await fetchFraudSettings();
    expect(apiGet).toHaveBeenCalledWith('/pos/fraud-settings');
    expect(result.requireBlindCashCount).toBe(true);
  });

  it('refreshes the local cache by calling fetch + upsert', async () => {
    vi.mocked(apiGet).mockResolvedValueOnce({
      cashVarianceOverSoft: '1.0000',
      cashVarianceOverHard: '20.0000',
      cashVarianceUnderSoft: '1.0000',
      cashVarianceUnderHard: '20.0000',
      requireBlindCashCount: false,
      requireManagerPinAboveHard: true,
      cashVarianceEmailSeverity: 'none',
    });
    await refreshFraudSettingsCache({} as never, 'co-1');
    expect(upsertCompanyFraudSettings).toHaveBeenCalledWith(
      expect.anything(),
      expect.objectContaining({
        company_id: 'co-1',
        cash_variance_over_hard: '20.0000',
        require_blind_cash_count: false,
      }),
    );
  });
});
