import { describe, expect, it } from 'vitest'
import { entityRoutes } from './entityRoutes'

describe('entityRoutes', () => {
  it('builds product and variant routes through product detail pages', () => {
    expect(entityRoutes.product('prod-1')).toBe('/inventory/products/prod-1')
    expect(entityRoutes.variant('variant-1', { productId: 'prod-1' })).toBe('/inventory/products/prod-1')
    expect(entityRoutes.product('prod-1', { tab: 'movements' })).toBe('/inventory/products/prod-1?tab=movements')
    expect(entityRoutes.variant('variant-1', { productId: 'prod-1', tab: 'financialOperations' })).toBe(
      '/inventory/products/prod-1?tab=financialOperations',
    )
  })

  it('builds partner routes with supplier/customer awareness', () => {
    expect(entityRoutes.customer('partner-1')).toBe('/sales/customers/partner-1')
    expect(entityRoutes.supplier('partner-2')).toBe('/purchases/suppliers/partner-2')
    expect(entityRoutes.partner('partner-3', { partnerType: 'supplier' })).toBe('/purchases/suppliers/partner-3')
    expect(entityRoutes.partner('partner-4', { partnerType: 'customer' })).toBe('/sales/customers/partner-4')
    expect(entityRoutes.partner('partner-5', { partnerType: null })).toBe('/sales/customers/partner-5')
    expect(entityRoutes.customer('partner-1', { tab: 'payments' })).toBe('/sales/customers/partner-1?tab=payments')
    expect(entityRoutes.supplier('partner-2', { tab: 'documents' })).toBe('/purchases/suppliers/partner-2?tab=documents')
  })

  it('builds document routes by document type', () => {
    expect(entityRoutes.document('doc-1', { documentType: 'quote' })).toBe('/sales/quotes/doc-1')
    expect(entityRoutes.document('doc-2', { documentType: 'sales_order' })).toBe('/sales/orders/doc-2')
    expect(entityRoutes.document('doc-3', { documentType: 'invoice' })).toBe('/sales/invoices/doc-3')
    expect(entityRoutes.document('doc-4', { documentType: 'credit_note' })).toBe('/sales/credit-notes/doc-4')
    expect(entityRoutes.document('doc-5', { documentType: 'return_note' })).toBe('/sales/return-notes/doc-5')
    expect(entityRoutes.document('doc-6', { documentType: 'purchase_order' })).toBe('/purchases/orders/doc-6')
    expect(entityRoutes.document('doc-7', { documentType: 'delivery_note' })).toBe('/inventory/delivery-notes/doc-7')
    expect(entityRoutes.document('doc-8', { documentType: 'supplier_invoice' })).toBe('/purchases/supplier-invoices/doc-8')
    expect(entityRoutes.document('doc-9', { documentType: 'invoice', tab: 'related' })).toBe('/sales/invoices/doc-9?tab=related')
  })

  it('builds treasury, expense, inventory, and finance entity routes', () => {
    expect(entityRoutes.payment('payment-1')).toBe('/treasury/payments/payment-1')
    expect(entityRoutes.expense('expense-1')).toBe('/expenses/expense-1/view')
    expect(entityRoutes.stockTransfer('transfer-1')).toBe('/inventory/stock-transfers/transfer-1')
    expect(entityRoutes.goodsReceipt('receipt-1', { purchaseOrderId: 'po-1' })).toBe('/purchases/orders/po-1')
    expect(entityRoutes.batch('batch-1')).toBe('/inventory/batches/batch-1')
    expect(entityRoutes.journalEntry('entry-1')).toBe('/finance/journal-entries/entry-1')
  })
})
