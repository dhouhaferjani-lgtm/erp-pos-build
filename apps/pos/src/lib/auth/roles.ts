const ROLE_LEVEL = {
  cashier: 0,
  manager: 1,
  admin: 1,
  owner: 2,
} as const;

/**
 * POS access hierarchy. Owner is explicitly above manager so the authenticated
 * business owner sees every manager-gated Caisse surface even when the active
 * PIN operator is a cashier.
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

export function hasManagerAccess(
  operatorRoles: string[] | undefined,
  userRoles: string[] | undefined,
): boolean {
  return Math.max(getAccessLevel(operatorRoles), getAccessLevel(userRoles)) >= ROLE_LEVEL.manager;
}
