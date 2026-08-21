/**
 * Whether a pre-close screen may show drawer-expectation figures.
 *
 * The blind cash count regime (SV-10) exists so the person counting the drawer
 * cannot see what the drawer is *supposed* to hold. `CashReconciliationSection`
 * enforces that inside the close flow, and `EndOfDayPreviewModal` extends the
 * concealment to the surrounding money cards. A separate screen that renders
 * `expected_cash` for the same open shift would defeat both.
 *
 * The `/shift` route is nominally manager-only, but `hasManagerAccess` takes the
 * MAX of the PIN operator's roles and the logged-in device user's roles — on a
 * terminal signed in with an owner account (the ordinary single-account POS
 * deployment) a cashier PIN operator clears that gate. So the route gate alone
 * is not a sufficient blind-count boundary; the policy has to be honoured.
 *
 * Fails CLOSED: only a positively-read `require_blind_cash_count === false`
 * discloses. An unknown policy conceals, mirroring `handleOpenEndOfDay`'s
 * fail-closed reset and the modal's `cashCountPolicyUnavailable` block.
 */

import type Database from '@tauri-apps/plugin-sql';
import { fetchFraudSettings } from '@/api/fraudSettingsApi';
import { getCompanyFraudSettings } from '@/lib/db/repositories/companyFraudSettingsCacheRepository';

export type CashDisclosure = 'disclose' | 'conceal';

export async function resolveCashDisclosure(
  db: Database,
  companyId: string,
): Promise<CashDisclosure> {
  // Only an EXPLICIT `false` discloses. Testing truthiness instead would read a
  // missing/undefined flag — a server build without the field, a truncated
  // payload, a partially-written cache row — as "blind counting is off", which
  // is the one wrong answer this function must never give.
  try {
    const settings = await fetchFraudSettings();
    return settings.requireBlindCashCount === false ? 'disclose' : 'conceal';
  } catch {
    // Offline: read the durable cache populated at activation / last online close.
    try {
      const cached = await getCompanyFraudSettings(db, companyId);
      if (!cached) return 'conceal';
      return cached.require_blind_cash_count === false ? 'disclose' : 'conceal';
    } catch {
      return 'conceal';
    }
  }
}
