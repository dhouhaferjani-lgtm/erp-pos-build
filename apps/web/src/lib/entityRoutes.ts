export type PartnerRouteType = 'customer' | 'supplier' | 'both' | null | undefined

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
}

export interface PartnerRouteOptions {
  partnerType?: PartnerRouteType
}

export interface DocumentRouteOptions {
  documentType: DocumentRouteType
}

export interface GoodsReceiptRouteOptions {
  purchaseOrderId: string | null | undefined
}

function customerRoute(id: string): string {
  return `/sales/customers/${id}`
}

function supplierRoute(id: string): string {
  return `/purchases/suppliers/${id}`
}

export const entityRoutes = {
  product: (id: string): string => `/inventory/products/${id}`,

  variant: (_id: string, options: VariantRouteOptions): string | null =>
    options.productId ? `/inventory/products/${options.productId}` : null,

  customer: customerRoute,

  supplier: supplierRoute,

  partner: (id: string, options: PartnerRouteOptions = {}): string =>
    options.partnerType === 'supplier' ? supplierRoute(id) : customerRoute(id),

  document: (id: string, options: DocumentRouteOptions): string => {
    switch (options.documentType) {
      case 'quote':
        return `/sales/quotes/${id}`
      case 'sales_order':
      case 'order':
        return `/sales/orders/${id}`
      case 'invoice':
        return `/sales/invoices/${id}`
      case 'credit_note':
        return `/sales/credit-notes/${id}`
      case 'return_note':
        return `/sales/return-notes/${id}`
      case 'purchase_order':
        return `/purchases/orders/${id}`
      case 'delivery_note':
        return `/inventory/delivery-notes/${id}`
      case 'supplier_invoice':
        return `/purchases/supplier-invoices/${id}`
    }
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
