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
    const allowedRoles = PERMISSIONS[permission] as readonly string[]
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
