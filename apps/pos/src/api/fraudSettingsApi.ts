import type Database from '@tauri-apps/plugin-sql';
import { apiGet } from '@/lib/api';
import { upsertCompanyFraudSettings } from '@/lib/db/repositories/companyFraudSettingsCacheRepository';
import {
  DEFAULT_OFFLINE_REFUND_COUNT_CEILING,
  DEFAULT_OFFLINE_REFUND_VALUE_CEILING,
  DEFAULT_ONLINE_REQUIRED_REFUND_THRESHOLD,
} from '@/lib/refundFlow/refundExposureDefaults';

export interface FraudSettingsResponse {
  cashVarianceOverSoft: string;
  cashVarianceOverHard: string;
  cashVarianceUnderSoft: string;
  cashVarianceUnderHard: string;
  requireBlindCashCount: boolean;
  requireManagerPinAboveHard: boolean;
  cashVarianceEmailSeverity: 'none' | 'critical' | 'warning' | 'info';
  /** Lane C M2/M3 refund-exposure policies (see CompanyFraudSettings::DEFAULT_*). */
  offlineRefundCountCeiling: number;
  offlineRefundValueCeiling: string;
  onlineRequiredRefundThreshold: string;
}

export async function fetchFraudSettings(): Promise<FraudSettingsResponse> {
  return apiGet<FraudSettingsResponse>('/pos/fraud-settings');
}

export async function refreshFraudSettingsCache(
  db: Database,
  companyId: string,
): Promise<void> {
  const settings = await fetchFraudSettings();
  await upsertCompanyFraudSettings(db, {
    company_id: companyId,
    cash_variance_over_soft: settings.cashVarianceOverSoft,
    cash_variance_over_hard: settings.cashVarianceOverHard,
    cash_variance_under_soft: settings.cashVarianceUnderSoft,
    cash_variance_under_hard: settings.cashVarianceUnderHard,
    require_blind_cash_count: settings.requireBlindCashCount,
    require_manager_pin_above_hard: settings.requireManagerPinAboveHard,
    cash_variance_email_severity: settings.cashVarianceEmailSeverity,
    // Lane C M2/M3 — a server build that predates the refund-exposure
    // settings omits these keys entirely. Writing `undefined` into a NOT
    // NULL column would throw and lose the WHOLE refresh (including the
    // cash-variance thresholds the offline EOD close depends on), so the
    // device's own seeded-equal fallback fills the gap. Precedence:
    // server-sent tenant value > device fallback constant.
    offline_refund_count_ceiling:
      settings.offlineRefundCountCeiling ?? DEFAULT_OFFLINE_REFUND_COUNT_CEILING,
    offline_refund_value_ceiling:
      settings.offlineRefundValueCeiling ?? DEFAULT_OFFLINE_REFUND_VALUE_CEILING,
    online_required_refund_threshold:
      settings.onlineRequiredRefundThreshold ?? DEFAULT_ONLINE_REQUIRED_REFUND_THRESHOLD,
  });
}
