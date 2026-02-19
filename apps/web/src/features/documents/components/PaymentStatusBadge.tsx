import { CircleX, Clock, CheckCircle, AlertCircle } from 'lucide-react'
import { useTranslation } from 'react-i18next'

export type PaymentStatus = 'unpaid' | 'partially_paid' | 'in_payment' | 'paid' | 'overpaid'

export interface PaymentStatusBadgeProps {
  status: PaymentStatus
  className?: string
}

const statusConfig: Record<
  PaymentStatus,
  {
    label: string
    colorClasses: string
    icon: typeof CircleX
  }
> = {
  unpaid: {
    label: 'Unpaid',
    colorClasses: 'bg-red-100 text-red-800 border-red-200',
    icon: CircleX,
  },
  partially_paid: {
    label: 'Partially Paid',
    colorClasses: 'bg-yellow-100 text-yellow-800 border-yellow-200',
    icon: Clock,
  },
  in_payment: {
    label: 'In Payment',
    colorClasses: 'bg-blue-100 text-blue-800 border-blue-200',
    icon: Clock,
  },
  paid: {
    label: 'Paid',
    colorClasses: 'bg-green-100 text-green-800 border-green-200',
    icon: CheckCircle,
  },
  overpaid: {
    label: 'Overpaid',
    colorClasses: 'bg-purple-100 text-purple-800 border-purple-200',
    icon: AlertCircle,
  },
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
    <span
      className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium ${config.colorClasses} ${className}`}
    >
      <Icon className="h-3.5 w-3.5" />
      {t(`sales:invoices.paymentStatus.${status}`, { defaultValue: config.label })}
    </span>
  )
}
