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
  'support-access.view',
  'support-access.manage',
])

/**
 * Module-level permission mapping for navigation.
 *
 * UI-01: declared `as const satisfies` so `keyof typeof` is a LITERAL union
 * (see {@link ModuleKey}) instead of `string`. The previous
 * `Partial<Record<string, Permission[]>>` erased the key space, which let any
 * typo — or a key that never existed, like `parts_catalog` — reach
 * {@link usePermissions.canAccessModule} unchecked.
 */
export const MODULE_PERMISSIONS = {
  dashboard: ['dashboard.view'],
  sales: ['sales.view'],
  purchases: ['purchases.view'],
  'document-ingestions': ['document-ingestions.view'],
  inventory: ['inventory.view'],
  'inventory.transfers.view': ['inventory.transfers.view'],
  'inventory.adjustments.view': ['inventory.adjustments.view'],
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
  'support-access': ['support-access.view'],
  // UI-01 row 1: a real backend permission (admin/manager) that the
  // "newGoodsReceipt" nav item already referenced as a module key. Self-mapped
  // like 'inventory.transfers.view' / 'replenishment.view' above.
  'goods-receipt.create-standalone': ['goods-receipt.create-standalone'],
  // UI-01 row 2: same shape, for the "stockByLocation" nav item.
  'inventory.view': ['inventory.view'],
} as const satisfies Record<string, readonly Permission[]>

/**
 * The closed set of module keys accepted by `canAccessModule` and by every
 * `moduleKey` / `permissionModule` / nav `permission` prop that feeds it.
 * An unlisted key is a compile error at the call site and, if one is forced
 * through at runtime, a denial (fail-closed).
 */
export type ModuleKey = keyof typeof MODULE_PERMISSIONS

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
  const hasAnyPermission = (permissions: readonly Permission[]): boolean => {
    return permissions.some((permission) => hasPermission(permission))
  }

  /**
   * Check if user has all of the given permissions
   */
  const hasAllPermissions = (permissions: readonly Permission[]): boolean => {
    return permissions.every((permission) => hasPermission(permission))
  }

  /**
   * Check if user can access a specific module
   */
  const canAccessModule = (moduleKey: ModuleKey): boolean => {
    // UI-01 fail-closed contract: a key outside MODULE_PERMISSIONS is a bug,
    // not an "unrestricted" module. The union makes it a compile error at the
    // call site; if one is still forced through (a cast, or JS callers), the
    // gate DENIES rather than opening for every role.
    const requiredPermissions: readonly Permission[] | undefined = MODULE_PERMISSIONS[moduleKey]
    if (!requiredPermissions) return false
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
