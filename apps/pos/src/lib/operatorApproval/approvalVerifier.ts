import type { CachedOperator } from '@/lib/db/repositories/operatorPinRepository';

/**
 * FU-1b — offline approval-scope cache TTL.
 *
 * The offline manager-approval fallback (`scopedManagerPin` Case 5) bypasses the
 * server's revocation/caps checks and trusts the device-cached operator row.
 * FU-1's prune revokes a SUSPENDED operator on the next successful `/pin-data`
 * pull, but a device that goes offline and NEVER re-syncs would retain that
 * authority indefinitely. This TTL bounds that window: an offline approval is
 * refused once the cached approval-scope metadata is older than the TTL, forcing
 * the terminal back online to re-establish trust.
 *
 * 7 days balances offline-first resilience (a shop must keep operating through a
 * multi-day outage) against stale-authority exposure. Cf. SSSD
 * `offline_credentials_expiration` (days since last online login) and the
 * bounded-offline-window principle in EMV velocity / cached-credential policy.
 */
export const APPROVAL_CACHE_MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000;

/**
 * True when the cached approval-scope metadata was fetched within `maxAgeMs` of
 * `now`. Fails closed (returns false) on a missing or unparseable fetch time.
 *
 * Clock-trust caveat: compares the device's `now` (`Date.now()` ms) against the
 * server-stamped `fetchedAt`. A backward-skewed device clock weakens the bound
 * (keeps the cache "fresh"); a forward skew only fails safe. A future
 * `fetchedAt` (server clock ahead of the device) is treated as fresh. This is
 * the same system-clock model used by SSSD / Windows cached credentials; a
 * monotonic/signed-time source is out of scope.
 */
export function isApprovalCacheFresh(
  fetchedAt: string | null | undefined,
  now: number,
  maxAgeMs: number = APPROVAL_CACHE_MAX_AGE_MS,
): boolean {
  if (!fetchedAt) {
    return false;
  }
  const fetchedMs = Date.parse(fetchedAt);
  if (Number.isNaN(fetchedMs)) {
    return false;
  }
  return now - fetchedMs <= maxAgeMs;
}

export type ApprovalScope =
  | 'close_shift_variance'
  | 'credit_limit_override'
  | 'account_status_override'
  | 'discount_limit_override'
  | 'tender_tolerance_override'
  | 'void_or_return_override'
  | 'cash_drawer_control';

export type OfflineApprovalPinResult =
  | { ok: true; operatorId: string }
  | { ok: false; code: 'scope_mismatch' | 'invalid_pin' | 'server_quarantined' };

export async function verifyOfflineApprovalPin(input: {
  operator: CachedOperator;
  pin: string;
  tenantId: string;
  companyId: string;
  terminalId: string;
  approvalScope: ApprovalScope;
  bcryptCheck: (pin: string, hash: string) => boolean | Promise<boolean>;
}): Promise<OfflineApprovalPinResult> {
  if (input.operator.approval_mirror_status === 'server_quarantined') {
    return { ok: false, code: 'server_quarantined' };
  }

  if (
    input.operator.tenant_id !== input.tenantId ||
    !input.operator.company_ids?.includes(input.companyId) ||
    !input.operator.terminal_ids?.includes(input.terminalId) ||
    !input.operator.approval_scopes?.includes(input.approvalScope)
  ) {
    return { ok: false, code: 'scope_mismatch' };
  }

  const valid = await input.bcryptCheck(input.pin, input.operator.pin_hash);
  if (!valid) {
    return { ok: false, code: 'invalid_pin' };
  }

  return { ok: true, operatorId: input.operator.id };
}
