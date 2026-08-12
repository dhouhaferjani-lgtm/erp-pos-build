import type { AdminRole } from '../stores/adminAuthStore'

export interface AdminRoutePolicy {
  href: string
  path: string
  roles: readonly AdminRole[]
  testHref: string
}

export const fullAdminRoles = ['super_admin'] as const satisfies readonly AdminRole[]
export const countryDefaultsRoles = ['super_admin', 'defaults_editor'] as const satisfies readonly AdminRole[]
export const supportAccessRoles = ['super_admin', 'support_approver'] as const satisfies readonly AdminRole[]

function route(path: string, roles: readonly AdminRole[], testPath = path): AdminRoutePolicy {
  return {
    href: `/admin/${path}`,
    path,
    roles,
    testHref: `/admin/${testPath}`,
  }
}

/**
 * The single authorization inventory for protected admin pages.
 *
 * The production route tree and sidebar both consume these entries. Tests iterate
 * the same manifest, so a newly added admin route cannot silently skip the
 * three-role authorization matrix.
 */
export const adminRoutePolicies = {
  dashboard: route('dashboard', fullAdminRoles),
  supportAccess: route('support-access', supportAccessRoles),
  tenants: route('tenants', fullAdminRoles),
  companyOwners: route('company-owners', fullAdminRoles),
  verticals: route('verticals', fullAdminRoles),
  auditLogs: route('audit-logs', fullAdminRoles),
  billing: route('billing', fullAdminRoles),
  subscriptions: route('billing/subscriptions', fullAdminRoles),
  invoices: route('billing/invoices', fullAdminRoles),
  payments: route('billing/payments', fullAdminRoles),
  monitoring: route('monitoring', fullAdminRoles),
  countryDefaults: route('country-defaults', countryDefaultsRoles),
  countryDefaultsTemplate: route(
    'country-defaults/templates/:templateId',
    countryDefaultsRoles,
    'country-defaults/templates/template-1',
  ),
  countryDefaultAssignments: route('country-defaults/assignments', countryDefaultsRoles),
} as const satisfies Record<string, AdminRoutePolicy>

export function canAccessAdminRoute(policy: AdminRoutePolicy, role: AdminRole): boolean {
  return policy.roles.includes(role)
}

const roleHomes: Record<AdminRole, string> = {
  super_admin: adminRoutePolicies.dashboard.href,
  defaults_editor: adminRoutePolicies.countryDefaults.href,
  support_approver: adminRoutePolicies.supportAccess.href,
}

export function homeForAdminRole(role: AdminRole): string {
  return roleHomes[role]
}
