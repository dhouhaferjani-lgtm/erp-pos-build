import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fetchFraudSettings, refreshFraudSettingsCache } from '../fraudSettingsApi';

vi.mock('@/lib/api', () => ({ apiGet: vi.fn() }));
vi.mock('@/lib/db/repositories/companyFraudSettingsCacheRepository', () => ({
  upsertCompanyFraudSettings: vi.fn(),
}));

import { apiGet } from '@/lib/api';
import { upsertCompanyFraudSettings } from '@/lib/db/repositories/companyFraudSettingsCacheRepository';
import {
  DEFAULT_OFFLINE_REFUND_COUNT_CEILING,
  DEFAULT_OFFLINE_REFUND_VALUE_CEILING,
  DEFAULT_ONLINE_REQUIRED_REFUND_THRESHOLD,
} from '@/lib/refundFlow/refundExposureDefaults';

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

  /**
   * Lane C M2/M3 — the refund-exposure policies ride this same payload.
   * A tenant value must reach the cache verbatim…
   */
  it('carries the M2/M3 refund-exposure policies into the cache row', async () => {
    vi.mocked(apiGet).mockResolvedValueOnce({
      cashVarianceOverSoft: '1.0000',
      cashVarianceOverHard: '20.0000',
      cashVarianceUnderSoft: '1.0000',
      cashVarianceUnderHard: '20.0000',
      requireBlindCashCount: false,
      requireManagerPinAboveHard: true,
      cashVarianceEmailSeverity: 'none',
      offlineRefundCountCeiling: 2,
      offlineRefundValueCeiling: '120.0000',
      onlineRequiredRefundThreshold: '60.0000',
    });

    await refreshFraudSettingsCache({} as never, 'co-1');

    expect(upsertCompanyFraudSettings).toHaveBeenCalledWith(
      expect.anything(),
      expect.objectContaining({
        offline_refund_count_ceiling: 2,
        offline_refund_value_ceiling: '120.0000',
        online_required_refund_threshold: '60.0000',
      }),
    );
  });

  /**
   * …and a server build that predates the settings must fall back to the
   * seeded-equal device constants rather than writing NULL into a NOT NULL
   * column and losing the WHOLE refresh (including the cash-variance
   * thresholds the offline EOD close depends on).
   */
  it('falls back to the seeded device defaults when the server omits the M2/M3 fields', async () => {
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
        offline_refund_count_ceiling: DEFAULT_OFFLINE_REFUND_COUNT_CEILING,
        offline_refund_value_ceiling: DEFAULT_OFFLINE_REFUND_VALUE_CEILING,
        online_required_refund_threshold: DEFAULT_ONLINE_REQUIRED_REFUND_THRESHOLD,
      }),
    );
  });
});
