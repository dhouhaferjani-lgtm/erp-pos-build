import type { AdminRole } from '../stores/adminAuthStore'

export const fullAdminRoles = ['super_admin'] as const satisfies readonly AdminRole[]
export const countryDefaultsRoles = ['super_admin', 'defaults_editor'] as const satisfies readonly AdminRole[]
export const supportAccessRoles = ['super_admin', 'support_approver'] as const satisfies readonly AdminRole[]

const roleHomes: Record<AdminRole, string> = {
  super_admin: '/admin/dashboard',
  defaults_editor: '/admin/country-defaults',
  support_approver: '/admin/support-access',
}

export function homeForAdminRole(role: AdminRole): string {
  return roleHomes[role]
}
