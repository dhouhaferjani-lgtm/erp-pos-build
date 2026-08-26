import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('@/api/fraudSettingsApi', () => ({
  fetchFraudSettings: vi.fn(),
}));

vi.mock('@/lib/db/repositories/companyFraudSettingsCacheRepository', () => ({
  getCompanyFraudSettings: vi.fn(),
}));

import { resolveCashDisclosure, shouldConcealTakings } from '../cashDisclosurePolicy';
import { fetchFraudSettings } from '@/api/fraudSettingsApi';
import { getCompanyFraudSettings } from '@/lib/db/repositories/companyFraudSettingsCacheRepository';

import type Database from '@tauri-apps/plugin-sql';

const mockDb = {} as Database;

function settings(requireBlindCashCount: boolean) {
  return {
    cashVarianceOverSoft: '1',
    cashVarianceOverHard: '5',
    cashVarianceUnderSoft: '1',
    cashVarianceUnderHard: '5',
    requireBlindCashCount,
    requireManagerPinAboveHard: false,
    cashVarianceEmailSeverity: 'none' as const,
    offlineRefundCountCeiling: 0,
    offlineRefundValueCeiling: '0',
    onlineRequiredRefundThreshold: '0',
  };
}

describe('resolveCashDisclosure', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('discloses when the live policy says blind counting is OFF', async () => {
    vi.mocked(fetchFraudSettings).mockResolvedValue(settings(false));

    await expect(resolveCashDisclosure(mockDb, 'company-1')).resolves.toBe('disclose');
    expect(getCompanyFraudSettings).not.toHaveBeenCalled();
  });

  it('conceals when the live policy says blind counting is ON', async () => {
    vi.mocked(fetchFraudSettings).mockResolvedValue(settings(true));

    await expect(resolveCashDisclosure(mockDb, 'company-1')).resolves.toBe('conceal');
  });

  it('falls back to the durable cache when the device is offline', async () => {
    vi.mocked(fetchFraudSettings).mockRejectedValue(new Error('offline'));
    vi.mocked(getCompanyFraudSettings).mockResolvedValue({
      company_id: 'company-1',
      cash_variance_over_soft: '1',
      cash_variance_over_hard: '5',
      cash_variance_under_soft: '1',
      cash_variance_under_hard: '5',
      require_blind_cash_count: false,
      require_manager_pin_above_hard: false,
      cash_variance_email_severity: 'none',
      offline_refund_count_ceiling: 0,
      offline_refund_value_ceiling: '0',
      online_required_refund_threshold: '0',
    });

    await expect(resolveCashDisclosure(mockDb, 'company-1')).resolves.toBe('disclose');
  });

  it('FAILS CLOSED to conceal when the policy is unknown offline', async () => {
    vi.mocked(fetchFraudSettings).mockRejectedValue(new Error('offline'));
    vi.mocked(getCompanyFraudSettings).mockResolvedValue(null);

    await expect(resolveCashDisclosure(mockDb, 'company-1')).resolves.toBe('conceal');
  });

  it('FAILS CLOSED when the live response omits the flag entirely', async () => {
    // A server build without the field, or a truncated/garbled payload, must
    // never read as "blind counting is off". Only an explicit false discloses.
    vi.mocked(fetchFraudSettings).mockResolvedValue(
      { ...settings(false), requireBlindCashCount: undefined } as never,
    );

    await expect(resolveCashDisclosure(mockDb, 'company-1')).resolves.toBe('conceal');
  });

  it('FAILS CLOSED when the cached row omits the flag entirely', async () => {
    vi.mocked(fetchFraudSettings).mockRejectedValue(new Error('offline'));
    vi.mocked(getCompanyFraudSettings).mockResolvedValue(
      { company_id: 'company-1' } as never,
    );

    await expect(resolveCashDisclosure(mockDb, 'company-1')).resolves.toBe('conceal');
  });

  it('FAILS CLOSED to conceal when even the cache read throws', async () => {
    vi.mocked(fetchFraudSettings).mockRejectedValue(new Error('offline'));
    vi.mocked(getCompanyFraudSettings).mockRejectedValue(new Error('db gone'));

    await expect(resolveCashDisclosure(mockDb, 'company-1')).resolves.toBe('conceal');
  });
});

/**
 * Gate r2 (fiscal r2-3) — the two-term predicate, all four combinations.
 * `hasOpenShift === false` is the branch F-6 reported as untested; it is
 * unreachable through the Header's UI, so this is where it gets pinned.
 */
describe('shouldConcealTakings', () => {
  it('conceals only when the policy conceals AND a shift is open', () => {
    expect(shouldConcealTakings('conceal', true)).toBe(true);
  });

  it('conceals NOTHING with no open shift — nothing is being counted', () => {
    expect(shouldConcealTakings('conceal', false)).toBe(false);
  });

  it('conceals nothing when the policy positively discloses', () => {
    expect(shouldConcealTakings('disclose', true)).toBe(false);
    expect(shouldConcealTakings('disclose', false)).toBe(false);
  });
});
