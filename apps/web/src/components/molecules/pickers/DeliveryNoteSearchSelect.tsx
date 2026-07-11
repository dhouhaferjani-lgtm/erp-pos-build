/**
 * Delivery Note Search Select Component
 * Thin wrapper around DocumentSearchSelect configured for delivery notes
 */

import { useTranslation } from 'react-i18next'
import { Truck } from 'lucide-react'
import { DocumentSearchSelect } from './DocumentSearchSelect'
import { useCurrency } from '@/hooks/useCurrency'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface DeliveryNote {
  id: string
  document_number: string
  document_date: string
  partner?: {
    id: string
    name: string
  } | null
  total: string
  status: string
  lines?: Array<{
    id: string
    product_code: string
    quantity: number
  }>
}

interface DeliveryNoteSearchSelectProps {
  value?: DeliveryNote | null | undefined
  onChange: (deliveryNote: DeliveryNote | null) => void
  partnerId?: string | undefined
  required?: boolean | undefined
  disabled?: boolean | undefined
  className?: string | undefined
  label?: string | undefined
  error?: string | undefined
}

function itemCount(deliveryNote: DeliveryNote): number {
  const lines = deliveryNote.lines || []
  return lines.reduce((sum: number, line) => sum + line.quantity, 0)
}

export function DeliveryNoteSearchSelect(props: DeliveryNoteSearchSelectProps) {
  const { t } = useTranslation()
  const { decimals } = useCurrency()

  return (
    <DocumentSearchSelect<DeliveryNote>
      {...props}
      config={{
        endpoint: '/delivery-notes',
        queryKey: 'delivery-notes-search',
        statusFilter: 'confirmed',
        icon: Truck,
        searchPlaceholder: t('sales:deliveryNotes.searchPlaceholder', 'Search by delivery note number or partner...'),
        noResultsMessage: t('sales:deliveryNotes.noResults', 'No delivery notes found'),
        noDataMessage: t('sales:deliveryNotes.noConfirmed', 'No confirmed delivery notes available'),
        additionalLocalFilter: (dn, query) => {
          if (!query) return true
          const q = query.toLowerCase()
          const docNumberMatches = dn.document_number.toLowerCase().includes(q)
          const partnerNameMatches = dn.partner?.name.toLowerCase().includes(q) ?? false
          return docNumberMatches || partnerNameMatches
        },
        getDisplayText: (deliveryNote) => {
          const partner = deliveryNote.partner?.name || t('common:unknown')
          const date = deliveryNote.document_date
          const lines = deliveryNote.lines || []
          const lineCount = lines.length
          const totalItems = itemCount(deliveryNote)

          return `${deliveryNote.document_number} - ${partner} - ${date} (${lineCount} ${t('sales:lineItems.title', 'Line Items')} • ${totalItems} ${t('sales:lineItems.quantity', 'Items')})`
        },
        renderItem: (deliveryNote) => (
          <>
            <div className={`flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full ${colorTokens.surface.muted}`}>
              <Truck className={`h-4 w-4 ${colorTokens.text.subtle}`} />
            </div>
            <div className="min-w-0 flex-1">
              <div className="flex items-center gap-2">
                <span className={`text-sm font-medium ${colorTokens.text.primary}`}>{deliveryNote.document_number}</span>
                <span className={`text-xs ${colorTokens.text.subtle}`}>
                  {new Date(deliveryNote.document_date).toLocaleDateString()}
                </span>
              </div>
              <div className={`text-xs ${colorTokens.text.subtle} truncate`}>
                {deliveryNote.partner?.name || t('common:unknown')}
              </div>
              {deliveryNote.lines && deliveryNote.lines.length > 0 && (
                <div className={`mt-1 text-xs ${colorTokens.text.disabled}`}>
                  {deliveryNote.lines.length} {t('sales:lineItems.title', 'Line Items')} • {itemCount(deliveryNote)} {t('sales:lineItems.quantity', 'Items')}
                </div>
              )}
            </div>
            <div className={`flex-shrink-0 text-sm font-medium ${colorTokens.text.primary}`}>
              {parseFloat(deliveryNote.total).toFixed(decimals)}
            </div>
          </>
        ),
      }}
    />
  )
}

export type { DeliveryNote }
