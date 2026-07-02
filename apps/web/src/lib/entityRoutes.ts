export type PartnerRouteType = 'customer' | 'supplier' | 'both' | null | undefined

export interface EntityRouteTabOption {
  tab?: string | null | undefined
}

export type DocumentRouteType =
  | 'quote'
  | 'sales_order'
  | 'order'
  | 'invoice'
  | 'credit_note'
  | 'return_note'
  | 'purchase_order'
  | 'delivery_note'
  | 'supplier_invoice'

export interface VariantRouteOptions {
  productId: string | null | undefined
  tab?: string | null
}

export interface PartnerRouteOptions extends EntityRouteTabOption {
  partnerType?: PartnerRouteType
}

export interface DocumentRouteOptions extends EntityRouteTabOption {
  documentType: DocumentRouteType
}

export interface GoodsReceiptRouteOptions {
  purchaseOrderId: string | null | undefined
}

function withTab(path: string, tab?: string | null): string {
  if (!tab) return path
  const params = new URLSearchParams({ tab })
  return `${path}?${params.toString()}`
}

function customerRoute(id: string, options: EntityRouteTabOption = {}): string {
  return withTab(`/sales/customers/${id}`, options.tab)
}

function supplierRoute(id: string, options: EntityRouteTabOption = {}): string {
  return withTab(`/purchases/suppliers/${id}`, options.tab)
}

export const entityRoutes = {
  product: (id: string, options: EntityRouteTabOption = {}): string =>
    withTab(`/inventory/products/${id}`, options.tab),

  variant: (_id: string, options: VariantRouteOptions): string | null =>
    options.productId ? entityRoutes.product(options.productId, { tab: options.tab }) : null,

  customer: customerRoute,

  supplier: supplierRoute,

  partner: (id: string, options: PartnerRouteOptions = {}): string =>
    options.partnerType === 'supplier' ? supplierRoute(id, options) : customerRoute(id, options),

  document: (id: string, options: DocumentRouteOptions): string => {
    let path: string
    switch (options.documentType) {
      case 'quote':
        path = `/sales/quotes/${id}`
        break
      case 'sales_order':
      case 'order':
        path = `/sales/orders/${id}`
        break
      case 'invoice':
        path = `/sales/invoices/${id}`
        break
      case 'credit_note':
        path = `/sales/credit-notes/${id}`
        break
      case 'return_note':
        path = `/sales/return-notes/${id}`
        break
      case 'purchase_order':
        path = `/purchases/orders/${id}`
        break
      case 'delivery_note':
        path = `/inventory/delivery-notes/${id}`
        break
      case 'supplier_invoice':
        path = `/purchases/supplier-invoices/${id}`
        break
    }
    return withTab(path, options.tab)
  },

  payment: (id: string): string => `/treasury/payments/${id}`,

  expense: (id: string): string => `/expenses/${id}/view`,

  stockTransfer: (id: string): string => `/inventory/stock-transfers/${id}`,

  goodsReceipt: (_id: string, options: GoodsReceiptRouteOptions): string | null =>
    options.purchaseOrderId ? `/purchases/orders/${options.purchaseOrderId}` : null,

  batch: (id: string): string => `/inventory/batches/${id}`,

  journalEntry: (id: string): string => `/finance/journal-entries/${id}`,
} as const

export function documentRouteTypeFromSource(sourceType: string | null | undefined): DocumentRouteType | null {
  switch (sourceType) {
    case 'quote':
    case 'sales_order':
    case 'order':
    case 'invoice':
    case 'credit_note':
    case 'return_note':
    case 'purchase_order':
    case 'delivery_note':
    case 'supplier_invoice':
      return sourceType
    default:
      return null
  }
}
