// TODO(auth): this hook reads from a hardcoded ROLE_PERMISSIONS map. The proper fix is to consume
// the user's actual permission list from the auth payload (passport/sanctum response). Until that
// ships, custom roles with granted permissions will be silently denied. See follow-up ticket.
// Tracked in: memory/feedback_usePermissions_hardcoded_map.md

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
  'goods-receipt.edit-price': ['admin', 'manager'],

  // Inventory
  'inventory.view': ['admin', 'inventory', 'manager'],
  'inventory.create': ['admin', 'inventory', 'manager'],
  'inventory.edit': ['admin', 'inventory', 'manager'],

  'inventory.adjust': ['admin', 'inventory', 'manager'],

  // Inventory - Stock Transfers (document-based, lifecycle-tracked)
  'inventory.transfers.view': ['admin', 'inventory', 'manager'],
  'inventory.transfers.create': ['admin', 'inventory', 'manager'],
  'inventory.transfers.complete': ['admin', 'inventory', 'manager'],
  'inventory.transfers.cancel': ['admin', 'inventory', 'manager'],

  // Expenses
  'expenses.view': ['admin', 'manager', 'cashier', 'viewer', 'operator', 'accountant'],
  'expenses.create': ['admin', 'manager', 'cashier', 'operator', 'accountant'],
  'expenses.update': ['admin', 'manager', 'operator', 'accountant'],
  'expenses.delete': ['admin', 'accountant'],
  'expenses.post': ['admin', 'manager', 'accountant'],
  'income.view': ['admin', 'manager', 'cashier', 'viewer', 'operator', 'accountant'],
  'income.create': ['admin', 'manager', 'cashier', 'operator', 'accountant'],
  'income.update': ['admin', 'manager', 'operator', 'accountant'],
  'income.delete': ['admin', 'accountant'],
  'income.post': ['admin', 'manager', 'accountant'],

  // Expense Categories
  'expense-categories.view': ['admin', 'manager', 'cashier', 'viewer', 'operator', 'accountant'],
  'expense-categories.create': ['admin', 'manager', 'accountant'],
  'expense-categories.update': ['admin', 'manager', 'accountant'],
  'expense-categories.delete': ['admin', 'manager', 'accountant'],

  // Documents (unified view/update — e.g. attachment uploads)
  'documents.view': ['admin', 'manager', 'cashier', 'viewer', 'operator', 'accountant'],
  'documents.update': ['admin', 'manager', 'cashier', 'operator', 'accountant'],

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
  'dashboard.owner': ['admin', 'manager'],

  // Vehicles
  'vehicles.view': ['admin', 'sales', 'manager'],
  'vehicles.create': ['admin', 'sales', 'manager'],
  'vehicles.edit': ['admin', 'sales', 'manager'],
  'vehicles.manage_ownership': ['admin', 'manager', 'operator'],
  'vehicles.log_mileage': ['admin', 'manager', 'operator', 'technician'],

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
  'pos.configure_cash_count': ['admin', 'manager'],
  'pos.void_voucher': ['admin', 'manager'],
  'pos.extend_voucher_expiry': ['admin', 'manager'],
  'pos.transfer_voucher': ['admin'],
  'pos.issue_goodwill_voucher': ['admin', 'manager'],
  'pos.search_customer_full_history': ['admin', 'manager'],

  // Fiscal quarantine resolution
  'fiscal.events.resolve_quarantine': ['admin', 'manager'],

  // Catalog (Composite Items & Modifiers)
  'composite-items.view': ['admin', 'manager'],
  'composite-items.create': ['admin', 'manager'],
  'composite-items.update': ['admin', 'manager'],
  'composite-items.delete': ['admin'],
  'composite-items.manage-recipes': ['admin', 'manager'],
  'modifier-groups.view': ['admin', 'manager'],
  'modifier-groups.manage': ['admin', 'manager'],

  // Catalog (Product Attributes & Variants — T2)
  'catalog.attributes.view': ['admin', 'manager'],
  'catalog.attributes.create': ['admin', 'manager'],
  'catalog.attributes.update': ['admin', 'manager'],
  'catalog.attributes.delete': ['admin'],
  'catalog.variants.view': ['admin', 'manager'],
  'catalog.variants.create': ['admin', 'manager'],
  'catalog.variants.update': ['admin', 'manager'],
  'catalog.variants.delete': ['admin'],
  'catalog.labels.print': ['admin', 'manager'],

  // Workshop Service Bundles
  'workshop-bundles.view': ['admin', 'manager', 'technician'],
  'workshop-bundles.manage': ['admin', 'manager'],

  // Promotions
  'promotions.view': ['admin', 'manager'],
  'promotions.manage': ['admin', 'manager'],

  // Coupons
  'coupons.view': ['admin', 'manager'],
  'coupons.manage': ['admin', 'manager'],

  // Loyalty
  'loyalty.view': ['admin', 'manager'],
  'loyalty.manage': ['admin', 'manager'],
  'loyalty.enroll': ['admin', 'manager', 'cashier'],

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
  'workshop.technicians.manage_certifications': ['admin', 'manager'],
  'workshop.technicians.manage_time_off': ['admin', 'manager'],
  'workshop.technicians.manage_time_entries': ['admin', 'manager', 'technician'],

  // Workshop — Payroll exports (Phase A.4)
  'workshop.payroll.view': ['admin', 'manager'],
  'workshop.payroll.generate': ['admin', 'manager'],

  // Workshop — Work Orders (Spec B)
  'work-orders.view': ['admin', 'manager', 'operator', 'technician'],
  'work-orders.create': ['admin', 'manager', 'operator'],
  'work-orders.update': ['admin', 'manager', 'operator', 'technician'],
  'work-orders.approve': ['admin', 'manager'],
  'work-orders.assign': ['admin', 'manager'],
  'work-orders.transition': ['admin', 'manager', 'operator'],
  'work-orders.cancel': ['admin', 'manager'],
  'work-orders.complete': ['admin', 'manager', 'operator', 'technician'],
  'work-orders.view_financials': ['admin', 'manager', 'accountant'],

  // Batch Expiry — write-off and reversal
  'batches.write-off': ['admin', 'manager'],

  // Scheduling (Spec D)
  'scheduling.bays.view': ['admin', 'manager', 'operator'],
  'scheduling.bays.manage': ['admin', 'manager'],
  'scheduling.appointments.view': ['admin', 'manager', 'operator', 'technician'],
  'scheduling.appointments.create': ['admin', 'manager', 'operator'],
  'scheduling.appointments.update': ['admin', 'manager', 'operator'],
  'scheduling.appointments.cancel': ['admin', 'manager'],
  'scheduling.appointments.convert': ['admin', 'manager', 'operator'],
} as const

export type Permission = keyof typeof PERMISSIONS

// Module-level permission mapping for navigation
export const MODULE_PERMISSIONS: Partial<Record<string, Permission[]>> = {
  dashboard: ['dashboard.view'],
  sales: ['sales.view'],
  purchases: ['purchases.view'],
  inventory: ['inventory.view'],
  expenses: ['expenses.view'],
  'expense-categories': ['expense-categories.view'],
  treasury: ['treasury.view'],
  vehicles: ['vehicles.view'],
  services: ['services.view'],
  reports: ['reports.view'],
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
