import { PERMISSIONS, type Permission as GeneratedPermission } from './permissionsMap.generated'
import { UI_ALIAS_PERMISSIONS, type UiAliasPermission } from './uiAliasPermissions'
import { useAuthStore } from '../stores/authStore'

export { PERMISSIONS } from './permissionsMap.generated'
export { UI_ALIAS_PERMISSIONS } from './uiAliasPermissions'

export type Permission = GeneratedPermission | UiAliasPermission

const SERVER_AUTHORITATIVE_PERMISSIONS = new Set<Permission>([
  'pricing.view_cost_prices',
  'bank-statements.view',
  'bank-statements.import',
  'bank-statements.reconcile',
  'bank-statements.reopen',
])

// Module-level permission mapping for navigation
export const MODULE_PERMISSIONS: Partial<Record<string, Permission[]>> = {
  dashboard: ['dashboard.view'],
  sales: ['sales.view'],
  purchases: ['purchases.view'],
  'document-ingestions': ['document-ingestions.view'],
  inventory: ['inventory.view'],
  'inventory.transfers.view': ['inventory.transfers.view'],
  'replenishment.view': ['replenishment.view'],
  expenses: ['expenses.view'],
  'expense-categories': ['expense-categories.view'],
  'expense-recurrences': ['expense-recurrences.view'],
  treasury: ['treasury.view'],
  'bank-statements': ['bank-statements.view'],
  remittances: ['instruments.remit'],
  vehicles: ['vehicles.view'],
  services: ['services.view'],
  // W-6 D5 owner ruling (2026-08-05, "Option B split"): reports.view is
  // deprecated and no longer gates any API route; VAT period reads now
  // check reports.financial. Gate finding I-3 (2026-08-06 review): this
  // 'reports' key's ONLY consumers are the vat-periods/vat-report nav item
  // (Sidebar.tsx) and route (routes/index.tsx moduleKey="reports") — the
  // treasuryOverview/cashMovements Sidebar entries were WRONGLY sharing it
  // (they need reports.operational, not reports.financial) and have their
  // own 'reports.operational' key below.
  reports: ['reports.financial'],
  'reports.financial': ['reports.financial'],
  'reports.operational': ['reports.operational'],
  'ledger.view': ['ledger.view'],
  ownerReports: ['dashboard.owner'],
  finance: ['accounts.view', 'journal.view'],
  pricing: ['pricing.view'],
  accounts: ['accounts.view'],
  settings: ['settings.view'],
  uom: ['uom.view'],
  pos: ['pos.operate_terminal', 'pos.manage_terminals', 'pos.view_receipts'],
  withholding: ['withholding.view'],
  'composite-items': ['composite-items.view'],
  'modifier-groups': ['modifier-groups.view'],
  'workshop-bundles': ['workshop-bundles.view'],
  promotions: ['promotions.view'],
  coupons: ['coupons.view'],
  loyalty: ['loyalty.view'],
  contacts: ['contacts.view'],
  enrichment: ['enrichment.view'],
  'workshop-technicians': ['workshop.technicians.view'],
  'workshop-payroll': ['workshop.payroll.view'],
  'workshop-work-orders': ['work-orders.view'],
  scheduling: ['scheduling.appointments.view'],
  'batches.write-off': ['batches.write-off'],
}

function isGeneratedPermission(permission: Permission): permission is GeneratedPermission {
  return permission in PERMISSIONS
}

function isUiAliasPermission(permission: Permission): permission is UiAliasPermission {
  return permission in UI_ALIAS_PERMISSIONS
}

/**
 * Hook to check user permissions
 */
export function usePermissions() {
  const user = useAuthStore((state) => state.user)
  const roles = user?.roles ?? []
  const serverPermissions = user?.permissions

  /**
   * Check if user has a specific permission
   */
  const hasPermission = (permission: Permission): boolean => {
    if (serverPermissions?.includes(permission) === true) {
      return true
    }
    if (SERVER_AUTHORITATIVE_PERMISSIONS.has(permission)) {
      return false
    }

    let allowedRoles: readonly string[]
    if (isGeneratedPermission(permission)) {
      allowedRoles = PERMISSIONS[permission]
    } else if (isUiAliasPermission(permission)) {
      allowedRoles = UI_ALIAS_PERMISSIONS[permission]
    } else {
      return false
    }

    return roles.some((role) => allowedRoles.includes(role))
  }

  /**
   * Check if user has any of the given permissions
   */
  const hasAnyPermission = (permissions: Permission[]): boolean => {
    return permissions.some((permission) => hasPermission(permission))
  }

  /**
   * Check if user has all of the given permissions
   */
  const hasAllPermissions = (permissions: Permission[]): boolean => {
    return permissions.every((permission) => hasPermission(permission))
  }

  /**
   * Check if user can access a specific module
   */
  const canAccessModule = (moduleKey: string): boolean => {
    const requiredPermissions = MODULE_PERMISSIONS[moduleKey]
    if (!requiredPermissions) return true // No restrictions
    return hasAnyPermission(requiredPermissions)
  }

  /**
   * Check if user has a specific role
   */
  const hasRole = (role: string): boolean => {
    return roles.includes(role)
  }

  /**
   * Check if user is admin
   */
  const isAdmin = (): boolean => {
    return roles.includes('admin')
  }

  return {
    hasPermission,
    hasAnyPermission,
    hasAllPermissions,
    canAccessModule,
    hasRole,
    isAdmin,
    roles,
  }
}
