import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import { StatusBadge, type StatusTone } from '@/components/atoms/StatusBadge'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { entityRoutes } from '@/lib/entityRoutes'
import type { DeliveryNote } from '../api/deliveryNotes'

const laneTones: Record<string, StatusTone> = {
  consolidation: 'info',
  order_conversion: 'success',
  pre_post_delivery: 'info',
  legacy_unknown: 'neutral',
}

function billingLaneKey(lane: string | null): string {
  switch (lane) {
    case 'consolidation':
      return 'consolidation'
    case 'order_conversion':
      return 'orderConversion'
    case 'pre_post_delivery':
      return 'prePostDelivery'
    case 'legacy_unknown':
      return 'legacyUnknown'
    default:
      return 'unknown'
  }
}

interface DeliveryNoteBillingAttributionProps {
  invoiceId: string | null
  invoiceNumber: string | null
  lane: string | null
}

export function DeliveryNoteBillingAttribution({
  invoiceId,
  invoiceNumber,
  lane,
}: DeliveryNoteBillingAttributionProps) {
  const { t } = useTranslation('sales')
  const badge = (
    <StatusBadge tone={lane === null ? 'neutral' : (laneTones[lane] ?? 'neutral')}>
      {t(`deliveryNotes.partnerTab.invoicedVia.${billingLaneKey(lane)}`)}
    </StatusBadge>
  )

  if (invoiceId === null || invoiceNumber === null) return badge

  return (
    <div className="flex flex-wrap items-center gap-2">
      {badge}
      <Link
        to={entityRoutes.document(invoiceId, { documentType: 'invoice' })}
        className={`font-medium ${colorTokens.intent.primary.text} ${colorTokens.intent.primary.textHoverStrongest}`}
      >
        {invoiceNumber}
      </Link>
    </div>
  )
}

export function DeliveryNoteBillingStatus({
  deliveryNote,
}: {
  deliveryNote: Pick<DeliveryNote,
    | 'invoiced_at'
    | 'invoiced_by_document_id'
    | 'invoiced_by_document_number'
    | 'invoiced_via'
  >
}) {
  const { t } = useTranslation('sales')

  if (deliveryNote.invoiced_at === null) {
    return <StatusBadge tone="pending">{t('deliveryNotes.partnerTab.billingState.uninvoiced')}</StatusBadge>
  }

  return (
    <DeliveryNoteBillingAttribution
      invoiceId={deliveryNote.invoiced_by_document_id}
      invoiceNumber={deliveryNote.invoiced_by_document_number}
      lane={deliveryNote.invoiced_via}
    />
  )
}
