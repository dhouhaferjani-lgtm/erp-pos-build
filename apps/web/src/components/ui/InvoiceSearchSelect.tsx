/**
 * Invoice Search Select Component
 * Thin wrapper around DocumentSearchSelect configured for invoices
 */

import { useTranslation } from 'react-i18next'
import { Receipt } from 'lucide-react'
import { DocumentSearchSelect } from './DocumentSearchSelect'

export interface Invoice {
  id: string
  number: string
  document_date: string
  total: string
  balance: string
  currency: string
  status: string
  partner_id: string
  partner?: {
    id: string
    name: string
  } | null
  lines?: Array<{
    id: string
    product_id: string | null
    product_code: string | null
    product_name: string
    description: string | null
    quantity: number
    unit_price: string
    tax_rate: string
    total: string
    quantity_decimals?: number | null
  }>
}

interface InvoiceSearchSelectProps {
  value?: Invoice | null | undefined
  onChange: (invoice: Invoice | null) => void
  partnerId?: string | undefined
  required?: boolean | undefined
  disabled?: boolean | undefined
  className?: string | undefined
  label?: string | undefined
  error?: string | undefined
}

export function InvoiceSearchSelect(props: InvoiceSearchSelectProps) {
  const { t } = useTranslation()

  const formatCurrency = (amount: number | string | undefined, currency: string = 'EUR') => {
    if (amount === undefined) return ''
    const num = typeof amount === 'string' ? parseFloat(amount) : amount
    return new Intl.NumberFormat('fr-FR', {
      style: 'currency',
      currency: currency,
    }).format(num)
  }

  return (
    <DocumentSearchSelect<Invoice>
      {...props}
      config={{
        endpoint: '/invoices',
        queryKey: 'invoices-search',
        statusFilter: 'posted',
        additionalFilters: { has_balance: 'true' },
        icon: Receipt,
        searchPlaceholder: t('sales:invoices.searchPlaceholder', 'Search by invoice number or partner...'),
        noResultsMessage: t('sales:invoices.noInvoicesFound', 'No invoices found'),
        noDataMessage: t('sales:invoices.noPostedInvoices', 'No posted invoices available'),
        getDisplayText: (invoice) => {
          const partner = invoice.partner?.name || t('common:unknown')
          const total = formatCurrency(invoice.total, invoice.currency)
          const balance = formatCurrency(invoice.balance, invoice.currency)

          return `${invoice.number} - ${partner} - ${total} (${t('sales:invoices.balance', 'Balance')}: ${balance})`
        },
        renderItem: (invoice) => (
          <>
            <div className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-gray-100">
              <Receipt className="h-4 w-4 text-gray-500" />
            </div>
            <div className="min-w-0 flex-1">
              <div className="flex items-center gap-2">
                <span className="text-sm font-medium text-gray-900">{invoice.number}</span>
                <span className="text-xs text-gray-500">
                  {new Date(invoice.document_date).toLocaleDateString()}
                </span>
              </div>
              <div className="text-xs text-gray-500 truncate">
                {invoice.partner?.name || t('common:unknown')}
              </div>
              <div className="mt-1 flex items-center gap-2 text-xs">
                <span className="text-gray-700">
                  {t('sales:invoices.total', 'Total')}: {formatCurrency(invoice.total, invoice.currency)}
                </span>
                <span className="text-blue-600">
                  {t('sales:invoices.balance', 'Balance')}: {formatCurrency(invoice.balance, invoice.currency)}
                </span>
              </div>
            </div>
          </>
        ),
      }}
    />
  )
}
