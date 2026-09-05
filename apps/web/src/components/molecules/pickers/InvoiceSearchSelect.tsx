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
  /**
   * OPT-IN source filter (F-STG-4). This picker is shared: the credit-note page
   * and the return-note page both mount it
   * (`CreateCreditNotePage.tsx`, `CreateReturnNotePage.tsx`).
   *
   * - `'payable'` (DEFAULT, unchanged behaviour) — `status=posted` +
   *   `has_balance=true`: invoices that still owe money.
   * - `'creditable'` — `creditable=1`: every SEALED invoice, Posted (still
   *   owing) OR Paid (settled → the credit becomes a customer credit). Only the
   *   credit-note page asks for this; gate r1 BLOCKER-2/MAJOR-4 flagged that
   *   making it unconditional silently changed the return-note source list too.
   */
  sourceFilter?: 'payable' | 'creditable' | undefined
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

/**
 * The server-side filter each source mode asks for. Kept as data so the DEFAULT
 * (`payable`) is provably byte-identical to the pre-F-STG-4 behaviour.
 */
const sourceFilterConfig = {
  payable: {
    statusFilter: 'posted',
    additionalFilters: { has_balance: 'true' },
    emptyKey: 'sales:invoices.noPostedInvoices',
    emptyFallback: 'No posted invoices available',
  },
  // `creditable=1` returns Posted AND Paid invoices — see
  // `InvoiceController::index()`, which validates the flag as a boolean.
  // Its empty state must NOT say "no posted invoices": the list deliberately
  // carries settled (Paid) invoices too (gate r2 NEW-6).
  creditable: {
    statusFilter: undefined,
    additionalFilters: { creditable: '1' },
    emptyKey: 'sales:invoices.noCreditableInvoices',
    emptyFallback: 'No invoices available to credit',
  },
} as const satisfies Record<
  'payable' | 'creditable',
  {
    statusFilter: string | undefined
    additionalFilters: Record<string, string>
    emptyKey: string
    emptyFallback: string
  }
>

export function InvoiceSearchSelect({ sourceFilter = 'payable', ...props }: InvoiceSearchSelectProps) {
  const { t } = useTranslation()
  const { statusFilter, additionalFilters, emptyKey, emptyFallback } = sourceFilterConfig[sourceFilter]

  return (
    <DocumentSearchSelect<Invoice>
      {...props}
      config={{
        endpoint: '/invoices',
        queryKey: 'invoices-search',
        ...(statusFilter === undefined ? {} : { statusFilter }),
        additionalFilters,
        icon: Receipt,
        searchPlaceholder: t('sales:invoices.searchPlaceholder', 'Search by invoice number or partner...'),
        noResultsMessage: t('sales:invoices.noInvoicesFound', 'No invoices found'),
        noDataMessage: t(emptyKey, emptyFallback),
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
