import type Database from '@tauri-apps/plugin-sql';
import { apiGet } from '@/lib/api';
import { upsertCompanyFraudSettings } from '@/lib/db/repositories/companyFraudSettingsCacheRepository';

export interface FraudSettingsResponse {
  cashVarianceOverSoft: string;
  cashVarianceOverHard: string;
  cashVarianceUnderSoft: string;
  cashVarianceUnderHard: string;
  requireBlindCashCount: boolean;
  requireManagerPinAboveHard: boolean;
  cashVarianceEmailSeverity: 'none' | 'critical' | 'warning' | 'info';
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
  });
}
