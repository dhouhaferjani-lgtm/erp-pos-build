// UI grouping gates — NOT backend authorization. Never add keys here; migrate call sites to real backend permissions instead.
export const UI_ALIAS_PERMISSIONS = {
  'dashboard.view': ['admin', 'sales', 'purchases', 'inventory', 'treasury', 'accountant', 'manager', 'user'],
  'sales.view': ['admin', 'sales', 'manager'],
  'sales.create': ['admin', 'sales', 'manager'],
  'purchases.view': ['admin', 'purchases', 'manager'],
  'purchases.create': ['admin', 'purchases', 'manager'],
  'services.view': ['admin', 'sales', 'manager'],
  'services.create': ['admin', 'sales', 'manager'],
  'services.edit': ['admin', 'sales', 'manager'],
  'inventory.create': ['admin', 'inventory', 'manager'],
  'treasury.create': ['admin', 'treasury', 'accountant', 'manager'],
} as const

export type UiAliasPermission = keyof typeof UI_ALIAS_PERMISSIONS
