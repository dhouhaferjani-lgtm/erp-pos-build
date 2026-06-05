/**
 * Manager-role predicate — canonical definition shared across SettingsPage
 * and any future surface that needs to gate manager-only actions.
 *
 * Roles considered manager-level: manager, admin, owner.
 */
export function isManagerRole(roles: string[] | undefined): boolean {
  return roles?.some((r) => ['manager', 'admin', 'owner'].includes(r)) ?? false;
}
