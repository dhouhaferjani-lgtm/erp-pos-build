import { useAuthStore } from '../stores/authStore'

// Permission keys mapped to modules
export const PERMISSIONS = {
  // Sales
  'sales.view': ['admin', 'sales', 'manager'],
  'sales.create': ['admin', 'sales', 'manager'],
  'sales.edit': ['admin', 'sales', 'manager'],

  // Purchases
  'purchases.view': ['admin', 'purchases', 'manager'],
  'purchases.create': ['admin', 'purchases', 'manager'],
  'purchases.edit': ['admin', 'purchases', 'manager'],

  // Inventory
  'inventory.view': ['admin', 'inventory', 'manager'],
  'inventory.create': ['admin', 'inventory', 'manager'],
  'inventory.edit': ['admin', 'inventory', 'manager'],

  // Treasury
  'treasury.view': ['admin', 'treasury', 'accountant', 'manager'],
  'treasury.create': ['admin', 'treasury', 'accountant', 'manager'],
  'treasury.edit': ['admin', 'treasury', 'accountant', 'manager'],

  // Treasury - Repositories
  'repositories.view': ['admin', 'treasury', 'accountant', 'manager'],
  'repositories.manage': ['admin', 'accountant'],

  // Withholding Certificates
  'withholding.view': ['admin', 'accountant', 'manager'],
  'withholding.create': ['admin', 'accountant'],
  'withholding.edit': ['admin', 'accountant'],

  // Reports
  'reports.view': ['admin', 'manager', 'accountant'],

  // Accounting/Finance
  'accounts.view': ['admin', 'accountant', 'manager'],
  'accounts.manage': ['admin', 'accountant'],
  'journal.view': ['admin', 'accountant', 'manager'],
  'journal.create': ['admin', 'accountant'],
  'journal.post': ['admin', 'accountant'],

  // Settings
  'settings.view': ['admin', 'manager'],
  'settings.edit': ['admin'],
  'settings.manage': ['admin', 'manager'],

  // Dashboard (everyone can view)
  'dashboard.view': ['admin', 'sales', 'purchases', 'inventory', 'treasury', 'accountant', 'manager', 'user'],

  // Vehicles
  'vehicles.view': ['admin', 'sales', 'manager'],
  'vehicles.create': ['admin', 'sales', 'manager'],
  'vehicles.edit': ['admin', 'sales', 'manager'],

  // Pricing
  'pricing.view': ['admin', 'sales', 'manager'],
  'pricing.manage': ['admin', 'manager'],

  // Services
  'services.view': ['admin', 'sales', 'manager'],
  'services.create': ['admin', 'sales', 'manager'],
  'services.edit': ['admin', 'sales', 'manager'],

  // Units of Measure (UOM)
  'uom.view': ['admin', 'manager', 'inventory'],
  'uom.create': ['admin', 'manager'],
  'uom.edit': ['admin', 'manager'],
  'uom.delete': ['admin', 'manager'],

  // POS
  'pos.manage_terminals': ['admin', 'manager'],
  'pos.operate_terminal': ['admin', 'manager', 'cashier'],
  'pos.manage_shifts': ['admin', 'manager'],
  'pos.view_reports': ['admin', 'manager'],
  'pos.void_receipts': ['admin', 'manager'],
  'pos.view_receipts': ['admin', 'manager', 'cashier'],
  'pos.manage_tables': ['admin', 'manager'],

  // Catalog (Composite Items & Modifiers)
  'composite-items.view': ['admin', 'manager'],
  'composite-items.create': ['admin', 'manager'],
  'composite-items.update': ['admin', 'manager'],
  'composite-items.delete': ['admin'],
  'composite-items.manage-recipes': ['admin', 'manager'],
  'modifier-groups.view': ['admin', 'manager'],
  'modifier-groups.manage': ['admin', 'manager'],

  // Promotions
  'promotions.view': ['admin', 'manager'],
  'promotions.manage': ['admin', 'manager'],

  // Coupons
  'coupons.view': ['admin', 'manager'],
  'coupons.manage': ['admin', 'manager'],

  // Loyalty
  'loyalty.view': ['admin', 'manager'],
  'loyalty.manage': ['admin', 'manager'],

  // Contacts / CRM
  'contacts.view': ['admin', 'manager', 'sales', 'cashier'],
  'contacts.create': ['admin', 'manager', 'sales', 'cashier'],
  'contacts.update': ['admin', 'manager', 'sales'],
  'contacts.delete': ['admin', 'manager'],

  // Enrichment
  'enrichment.view': ['admin', 'manager'],
  'enrichment.review': ['admin', 'manager'],

  // Workshop — Technicians (HRM-lite; Spec C)
  'workshop.technicians.view': ['admin', 'manager', 'technician', 'sales'],
  'workshop.technicians.manage': ['admin', 'manager'],
  'workshop.technicians.view_pay': ['admin', 'manager'],
  'workshop.technicians.view_pii': ['admin', 'manager'],
  'workshop.technicians.approve_time_off': ['admin', 'manager'],
  'workshop.technicians.adjust_time_entries': ['admin', 'manager'],
} as const

export type Permission = keyof typeof PERMISSIONS

// Module-level permission mapping for navigation
export const MODULE_PERMISSIONS: Partial<Record<string, Permission[]>> = {
  dashboard: ['dashboard.view'],
  sales: ['sales.view'],
  purchases: ['purchases.view'],
  inventory: ['inventory.view'],
  treasury: ['treasury.view'],
  vehicles: ['vehicles.view'],
  services: ['services.view'],
  reports: ['reports.view'],
  finance: ['accounts.view', 'journal.view'],
  pricing: ['pricing.view'],
  accounts: ['accounts.view'],
  settings: ['settings.view'],
  uom: ['uom.view'],
  pos: ['pos.operate_terminal', 'pos.manage_terminals', 'pos.view_receipts'],
  withholding: ['withholding.view'],
  'composite-items': ['composite-items.view'],
  'modifier-groups': ['modifier-groups.view'],
  promotions: ['promotions.view'],
  coupons: ['coupons.view'],
  loyalty: ['loyalty.view'],
  contacts: ['contacts.view'],
  enrichment: ['enrichment.view'],
  'workshop-technicians': ['workshop.technicians.view'],
}

/**
 * Hook to check user permissions
 */
export function usePermissions() {
  const user = useAuthStore((state) => state.user)
  const roles = user?.roles ?? []

  /**
   * Check if user has a specific permission
   */
  const hasPermission = (permission: Permission): boolean => {
    const allowedRoles = PERMISSIONS[permission] as readonly string[] | undefined
    if (!allowedRoles) return false
    return roles.some((role) => allowedRoles.includes(role))
  }

  /**
   * Check if user has any of the given permissions
   */
  const hasAnyPermission = (permissions: Permission[]): boolean => {
    return permissions.some((p) => hasPermission(p))
  }

  /**
   * Check if user has all of the given permissions
   */
  const hasAllPermissions = (permissions: Permission[]): boolean => {
    return permissions.every((p) => hasPermission(p))
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
