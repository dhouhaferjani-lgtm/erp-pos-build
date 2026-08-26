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
 * Manager-role predicate — canonical definition shared across SettingsPage
 * and any future surface that needs to gate manager-only actions.
 */
export function isManagerRole(roles: string[] | undefined): boolean {
  return getAccessLevel(roles) >= ROLE_LEVEL.manager;
}

/**
 * Manager gate for every POS surface — composed from the PIN OPERATOR'S ROLES
 * ONLY (B-13 (iv), owner-ruled 2026-08-21 "fix properly").
 *
 * The device login user is a PROVISIONING identity, not the acting one. An
 * IziPOS terminal is signed in once with a back-office account — in the
 * ordinary single-account deployment, the business owner's — and then handed
 * to whoever is on shift, who identifies with a PIN. This predicate used to
 * take `Math.max(operatorLevel, loginUserLevel)`, which made EVERY PIN
 * operator on such a terminal a manager: a cashier PIN cleared `/shift`,
 * `/reports`, `/reports/z`, the X report, the cash-drawer operations and the
 * device-unbind action. Taking the login user's roles as a second argument is
 * no longer possible — the leg is gone, not merely unused.
 *
 * Fails CLOSED with no active PIN operator. There is no "no PIN policy" mode
 * in the device app to fall back to a login user for: `App.tsx` renders
 * `<PinEntryPage>` (or `<PinSetupPage>` on a virgin tenant) instead of
 * `<AppShell>` whenever `operator` is null, so the shell never mounts without
 * one. On a genuinely single-account terminal the owner sets up their OWN PIN,
 * whose operator carries the owner role — so nothing that worked before this
 * change stops working for the person the login account belongs to.
 */
export function hasManagerAccess(operatorRoles: string[] | undefined): boolean {
  return isManagerRole(operatorRoles);
}
