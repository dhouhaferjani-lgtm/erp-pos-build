/**
 * Invoice Search Select Component
 * Thin wrapper around DocumentSearchSelect configured for invoices
 */

import { useTranslation } from 'react-i18next'
import { Receipt } from 'lucide-react'
import { DocumentSearchSelect } from './DocumentSearchSelect'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

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

const invoiceCurrencyFormatters = new Map<string, Intl.NumberFormat>()

function formatInvoiceCurrency(amount: number | string | undefined, currency: string = 'EUR') {
  if (amount === undefined) return ''
  const existing = invoiceCurrencyFormatters.get(currency)
  const formatter = existing ?? Intl.NumberFormat('fr-FR', {
    style: 'currency',
    currency,
  })
  if (existing === undefined) {
    invoiceCurrencyFormatters.set(currency, formatter)
  }
  const num = typeof amount === 'string' ? parseFloat(amount) : amount
  return formatter.format(num)
}

export function InvoiceSearchSelect(props: InvoiceSearchSelectProps) {
  const { t } = useTranslation()

  return (
    <DocumentSearchSelect<Invoice>
      {...props}
      config={{
        endpoint: '/invoices',
        queryKey: 'invoices-search',
        // F-STG-4: a credit note may be raised against any sealed invoice —
        // Posted (still owing) OR Paid (settled → the credit becomes a customer
        // refund). The backend `creditable=true` filter returns exactly that set
        // (see InvoiceController::index), replacing the old status=posted +
        // has_balance filter that hid fully-paid invoices.
        additionalFilters: { creditable: 'true' },
        icon: Receipt,
        searchPlaceholder: t('sales:invoices.searchPlaceholder', 'Search by invoice number or partner...'),
        noResultsMessage: t('sales:invoices.noInvoicesFound', 'No invoices found'),
        noDataMessage: t('sales:invoices.noPostedInvoices', 'No posted invoices available'),
        getDisplayText: (invoice) => {
          const partner = invoice.partner?.name || t('common:unknown')
          const total = formatInvoiceCurrency(invoice.total, invoice.currency)
          const balance = formatInvoiceCurrency(invoice.balance, invoice.currency)

          return `${invoice.number} - ${partner} - ${total} (${t('sales:invoices.balance', 'Balance')}: ${balance})`
        },
        renderItem: (invoice) => (
          <>
            <div className={`flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full ${colorTokens.surface.muted}`}>
              <Receipt className={`h-4 w-4 ${colorTokens.text.subtle}`} />
            </div>
            <div className="min-w-0 flex-1">
              <div className="flex items-center gap-2">
                <span className={`text-sm font-medium ${colorTokens.text.primary}`}>{invoice.number}</span>
                <span className={`text-xs ${colorTokens.text.subtle}`}>
                  {new Date(invoice.document_date).toLocaleDateString()}
                </span>
              </div>
              <div className={`text-xs ${colorTokens.text.subtle} truncate`}>
                {invoice.partner?.name || t('common:unknown')}
              </div>
              <div className="mt-1 flex items-center gap-2 text-xs">
                <span className={`${colorTokens.text.secondary}`}>
                  {t('sales:invoices.total', 'Total')}: {formatInvoiceCurrency(invoice.total, invoice.currency)}
                </span>
                <span className={`${colorTokens.intent.primary.text}`}>
                  {t('sales:invoices.balance', 'Balance')}: {formatInvoiceCurrency(invoice.balance, invoice.currency)}
                </span>
              </div>
            </div>
          </>
        ),
      }}
    />
  )
}
