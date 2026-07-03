import { CircleX, Clock, CheckCircle, AlertCircle } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { StatusBadge, type StatusTone } from '../../../components/atoms'

export type PaymentStatus = 'unpaid' | 'partially_paid' | 'in_payment' | 'paid' | 'overpaid'

export interface PaymentStatusBadgeProps {
  status: PaymentStatus
  className?: string
}

const statusConfig: Record<
  PaymentStatus,
  {
    label: string
    tone: StatusTone
    icon: typeof CircleX
  }
> = {
  unpaid: { label: 'Unpaid', tone: 'danger', icon: CircleX },
  partially_paid: { label: 'Partially Paid', tone: 'warning', icon: Clock },
  in_payment: { label: 'In Payment', tone: 'info', icon: Clock },
  paid: { label: 'Paid', tone: 'success', icon: CheckCircle },
  overpaid: { label: 'Overpaid', tone: 'warning', icon: AlertCircle },
}

/**
 * Badge component to display payment status for invoices.
 *
 * Payment status is computed from outstanding amount:
 * - unpaid: No payments received yet
 * - partially_paid: Some payments, but not full amount
 * - in_payment: Payment registered but pending reconciliation
 * - paid: Fully paid (outstanding = 0)
 * - overpaid: Customer paid more than invoice total
 */
export function PaymentStatusBadge({ status, className = '' }: PaymentStatusBadgeProps) {
  const { t } = useTranslation(['sales', 'common'])

  const config = statusConfig[status]
  const Icon = config.icon

  return (
    <StatusBadge tone={config.tone} className={`gap-1.5 ${className}`}>
      <Icon className="h-3 w-3" />
      {t(`sales:invoices.paymentStatus.${status}`, { defaultValue: config.label })}
    </StatusBadge>
  )
}
