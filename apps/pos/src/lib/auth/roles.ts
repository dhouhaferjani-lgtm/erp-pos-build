/**
 * LEGACY role-name ladder, kept ONLY as the fallback for a cached operator row
 * that carries no `permissions` (see `hasManagerAccess`).
 *
 * Gate r1 (R1-4) recorded why this must not be the primary gate: the server
 * authorizes on PERMISSIONS, while this is a hardcoded four-name allowlist.
 * `owner` is not seeded at all (it exists only as a protected name in
 * `RoleController.php:228`); `viewer` / `technician` / `operator` /
 * `accountant` ARE seeded but are absent here; and a tenant may create any
 * role name it likes (`RoleController.php:187-198`). Every one of those scores
 * 0 — the gate fails closed, which is safe but wrong: a tenant that grants
 * `pos.view_reports` to a custom "supervisor" role gets a server that says yes
 * and a device that redirects to `/` with no explanation.
 */
const ROLE_LEVEL = {
  cashier: 0,
  manager: 1,
  admin: 1,
  owner: 2,
} as const;

/**
 * POS access hierarchy. Owner is explicitly above manager so a business owner
 * working the till (their own PIN) sees every manager-gated Caisse surface.
 */
export function getAccessLevel(roles: string[] | undefined): number {
  return roles?.reduce((level, role) => {
    const knownRole = role as keyof typeof ROLE_LEVEL;
    return Math.max(level, ROLE_LEVEL[knownRole] ?? 0);
  }, 0) ?? 0;
}

/**
 * Manager-role predicate over role NAMES — the legacy fallback only.
 */
export function isManagerRole(roles: string[] | undefined): boolean {
  return getAccessLevel(roles) >= ROLE_LEVEL.manager;
}

/**
 * The manager-gated POS surfaces, each mapped to the SEEDED PERMISSION that
 * distinguishes a manager from a cashier for that concern.
 *
 * - `reports` → `pos.view_reports`: exactly what the server authorizes for the
 *   X report, the Z history and POS analytics (`ReportController.php:59, 218,
 *   261, 327, 366, 424`; `AnalyticsController.php:29-145`). Manager holds it
 *   (`RolesAndPermissionsSeeder.php:619`), cashier does not (`:685`). Also
 *   gates `/reports`, `/shift`, `/reports/z` and the device-unbind action,
 *   which have no server permission of their own.
 * - `cash_drawer` → `pos.approve_cash_drawer_control`: the drawer-control
 *   authority in the seeder (`:379` definition, `:624` manager grant; absent
 *   from the cashier block). Deliberately NOT `pos.operate_terminal`, which is
 *   what `CashDrawerController::deposit/payout` authorizes (`:42`, `:111`) and
 *   which CASHIERS HOLD — that is the server-side gap recorded in the LEDGER,
 *   and mirroring it here would make the device gate a no-op.
 */
export const MANAGER_SURFACE_PERMISSION = {
  reports: 'pos.view_reports',
  cash_drawer: 'pos.approve_cash_drawer_control',
} as const;

export type ManagerSurface = keyof typeof MANAGER_SURFACE_PERMISSION;

/**
 * The authority a manager gate reads. Structurally satisfied by the
 * `Operator` in `operatorStore`, deliberately narrower than it: a gate has no
 * business seeing a name, a PIN hash or a discount cap.
 */
export interface ManagerGateAuthority {
  roles?: string[];
  permissions?: string[];
  /**
   * True when this operator's authority came from a device cache older than
   * the offline TTL (`operatorStore` sets it on the offline PIN-verify path
   * from `operator_pins.synced_at`). Absent means live — the online verify
   * path has no cache to age.
   */
  authority_stale?: boolean;
}

/**
 * Manager gate for every POS surface — composed from the PIN OPERATOR'S
 * AUTHORITY ONLY (B-13 (iv), owner-ruled 2026-08-21 "fix properly").
 *
 * The device login user is a PROVISIONING identity, not the acting one. An
 * IziPOS terminal is signed in once with a back-office account — in the
 * ordinary single-account deployment, the business owner's — and then handed
 * to whoever is on shift, who identifies with a PIN. This predicate used to
 * take `Math.max(operatorLevel, loginUserLevel)`, which made EVERY PIN
 * operator on such a terminal a manager: a cashier PIN cleared `/shift`,
 * `/reports`, `/reports/z`, the X report, the cash-drawer operations and the
 * device-unbind action. The login-user leg is GONE, not merely unused.
 *
 * Three fail-closed properties, in order of evaluation:
 *
 * 1. **No operator ⇒ closed.** There is no "no PIN policy" mode to fall back
 *    to a login user for: `App.tsx` renders `<PinEntryPage>` (or
 *    `<PinSetupPage>` on a virgin tenant) instead of `<AppShell>` whenever
 *    `operator` is null, so the shell never mounts without one. On a genuinely
 *    single-account terminal the owner sets up their OWN PIN, whose operator
 *    carries the owner's roles and permissions — nothing that worked before
 *    stops working for the person the login account belongs to.
 * 2. **Stale cached authority ⇒ closed** (gate r1 R1-3). After this lane the
 *    operator's cached roles/permissions are the SOLE basis for every manager
 *    gate, and offline PIN verification reads them from SQLite. Past the TTL
 *    the manager surfaces close; selling, refunds and the operator's own shift
 *    close are untouched, so a multi-day outage still trades.
 * 3. **Permission first, name only as a fallback** (gate r1 R1-4). The gate
 *    keys on the same permission the server authorizes. Role names are
 *    consulted only when the operator carries no permissions at all — a cache
 *    row written before pin-data shipped them — so a device upgrade cannot
 *    lock every manager out until the next roster pull.
 */
export function hasManagerAccess(
  operator: ManagerGateAuthority | null | undefined,
  surface: ManagerSurface = 'reports',
): boolean {
  if (!operator) return false;
  if (operator.authority_stale === true) return false;

  const permissions = operator.permissions;
  if (permissions !== undefined && permissions.length > 0) {
    return permissions.includes(MANAGER_SURFACE_PERMISSION[surface]);
  }

  return isManagerRole(operator.roles);
}
