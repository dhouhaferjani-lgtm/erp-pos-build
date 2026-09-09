import { PERMISSIONS, type Permission as GeneratedPermission } from './permissionsMap.generated'
import { UI_ALIAS_PERMISSIONS, type UiAliasPermission } from './uiAliasPermissions'
import { useAuthStore } from '../stores/authStore'

export { PERMISSIONS } from './permissionsMap.generated'
export { UI_ALIAS_PERMISSIONS } from './uiAliasPermissions'

export type Permission = GeneratedPermission | UiAliasPermission

/**
 * Permissions whose ONLY authority is the server's answer: when the server does
 * not list one for this user, `hasPermission` returns false instead of falling
 * back to the static role map.
 *
 * Two reasons a permission belongs here, both live for the F-W2-14 entries:
 *
 *  1. FAIL CLOSED ON A STALE TENANT (gate r1 finding 2). These are NEW
 *     permissions. Under database-per-tenant they do not exist in an
 *     already-provisioned tenant DB until `tenants:seed
 *     --class=RolesAndPermissionsSeeder` has run there. Without this list a
 *     manager would match the role map, render the Post / New / Pay controls,
 *     and collect a 403 from the API.
 *  2. GRANTABLE PER USER OR PER CUSTOM ROLE (owner ruling 2026-09-07). The
 *     secure default stands — `operator` does NOT get
 *     `supplier-invoices.manage` — but an administrator may grant it to a
 *     specific user or a custom role through the permissions surface
 *     (`GET /api/v1/permissions` lists the whole catalogue,
 *     `PATCH /api/v1/roles/{id}` syncs a role's permissions). The role map
 *     cannot express that, so the server list must be the only authority in
 *     BOTH directions. `AuthUserData` builds it from
 *     `User::getAllPermissions()`, which already unions role-derived and
 *     directly-assigned permissions.
 */
const SERVER_AUTHORITATIVE_PERMISSIONS = new Set<Permission>([
  'batches.view',
  'batches.create',
  'batches.update',
  'batches.delete',
  'batches.recall',
  'batches.recall.request',
  'batches.write-off',
  'batches.traceability',

  'pricing.view_cost_prices',
  'bank-statements.view',
  'bank-statements.import',
  'bank-statements.reconcile',
  'bank-statements.reopen',
  'support-access.view',
  'support-access.manage',
  'supplier-invoices.manage',
  'payments.pay-supplier',
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
  'batches.view': ['batches.view'],
  dashboard: ['dashboard.view'],
  sales: ['sales.view'],
  // Gate r3 finding N1: this key is SHARED — it is also the sole guard on
  // seven unrelated purchase-order/quote-request routes
  // (routes/index.tsx:896,906,916,926,938,958,982, all `moduleKey="purchases"`
  // with no `permission`). Gate r2 finding 1 widened it to admit
  // `supplier-invoices.manage` holders so the Sidebar Purchases GROUP would
  // open for the accountant, but that also let an accountant deep-link those
  // seven routes and reach a page whose API 403s. Restored to its merge-base
  // value; the Sidebar group now gates on its own `nav.purchasesGroup` key
  // below instead of this one.
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
  'pos.operate_terminal': ['pos.operate_terminal'],
  'pos.manage_terminals': ['pos.manage_terminals'],
  'pos.manage_shifts': ['pos.manage_shifts'],
  'pos.manage_tables': ['pos.manage_tables'],
  'pos.view_reports': ['pos.view_reports'],
  'pos.view_receipts': ['pos.view_receipts'],
  compliance: ['compliance.export_jet', 'compliance.verify_chains', 'compliance.view_reprint_log'],
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
  // Self-mapped (merge reconciliation 2026-08-20): the DN-consolidation lane's
  // /sales/to-bill nav entry gates on the deliveries.view PERMISSION through the
  // nav `permission` field, which feeds canAccessModule as a module key. Built
  // pre-T4 it "worked" fail-open; under the typed fail-closed contract the raw
  // string is a compile error — and would hide the entry from every role.
  'deliveries.view': ['deliveries.view'],
  // UI-01 row 2: same shape, for the "stockByLocation" nav item.
  'inventory.view': ['inventory.view'],
  // Gate r2 finding 1 — NAV-ONLY keys for the Purchases group's children.
  //
  // Each is a UNION of the REAL backend permission that child's own API checks
  // (`can:purchase-orders.view` Document routes.php:275,
  //  `can:purchase-quote-requests.view` Procurement routes.php:45,
  //  `can:supplier-invoices.manage` Procurement routes.php:104)
  // with the legacy `purchases.view` alias, so that widening the GROUP gate
  // above for `supplier-invoices.manage` holders offers them only the entries
  // their permissions actually work on, while every role that could already see
  // these entries — including a role that holds nothing but the alias — keeps
  // all of them. Purely additive.
  //
  // `nav.supplierInvoices` carries no alias arm on purpose: the alias is exactly
  // what used to hide this entry from the accountant this PR grants.
  'nav.purchaseOrders': ['purchase-orders.view', 'purchases.view'],
  'nav.purchaseQuoteRequests': ['purchase-quote-requests.view', 'purchases.view'],
  'nav.supplierInvoices': ['supplier-invoices.manage'],
  // Gate r3 finding N1 — NAV-ONLY key for the Sidebar Purchases GROUP itself.
  //
  // The group's own gate used to be the SHARED `purchases` module key above,
  // which is also the sole guard on seven unrelated purchase-order/quote-
  // request ROUTES. Widening that shared key (gate r2 finding 1) opened the
  // group for `supplier-invoices.manage` holders but also let them deep-link
  // those seven routes into a page whose API 403s. This key carries the exact
  // same union `purchases` briefly did, but ONLY the group nav item reads it
  // (Sidebar.tsx) — the routes stay gated on the narrow `purchases` key, so an
  // accountant sees the group (and, inside it, only the children their own
  // real permission opens per the nav-child keys above) without gaining
  // reachability to a page the server refuses.
  'nav.purchasesGroup': ['purchases.view', 'supplier-invoices.manage'],
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
