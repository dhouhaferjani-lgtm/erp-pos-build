/**
 * Whether a pre-close screen may show drawer-expectation figures.
 *
 * The blind cash count regime (SV-10) exists so the person counting the drawer
 * cannot see what the drawer is *supposed* to hold. `CashReconciliationSection`
 * enforces that inside the close flow, and `EndOfDayPreviewModal` extends the
 * concealment to the surrounding money cards. A separate screen that renders
 * `expected_cash` for the same open shift would defeat both.
 *
 * The route gate alone is not a sufficient blind-count boundary, and the reason
 * survives B-13 even though its original premise did not.
 *
 * ORIGINALLY (falsified, B-13 2026-08-26): `hasManagerAccess` took the MAX of
 * the PIN operator's roles and the logged-in device user's roles, so on a
 * terminal signed in with an owner account a cashier PIN cleared the
 * manager-only `/shift` route outright. That leg is now REMOVED — the gate
 * composes from the PIN operator alone (`lib/auth/roles.ts`).
 *
 * STILL TRUE, for two independent reasons:
 *  1. Blind counting is not about ROLE. It conceals the expectation from
 *     whoever is COUNTING, and on most shifts that is the manager who cleared
 *     the gate. `/shift` and `/reports` therefore conceal regardless of role
 *     while a shift is open; only the Header opening-float tooltip and
 *     `/sales` are role-conditioned, because there the manager is not the one
 *     at the drawer.
 *  2. A route gate is a UI control on a terminal holding the login account's
 *     bearer token (LEDGER B-13-S1). The policy is the invariant; the gate is
 *     one of several places that honour it.
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
