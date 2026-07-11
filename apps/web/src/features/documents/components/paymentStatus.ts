import { AlertCircle, CheckCircle, CircleX, Clock, type LucideIcon } from 'lucide-react'

import type { StatusTone } from '../../../components/atoms'

export type PaymentStatus = 'unpaid' | 'partially_paid' | 'in_payment' | 'paid' | 'overpaid'

const tonesByPaymentValue: Record<PaymentStatus, StatusTone> = {
  unpaid: 'danger',
  partially_paid: 'warning',
  in_payment: 'info',
  paid: 'success',
  overpaid: 'warning',
}

const iconsByPaymentValue: Record<PaymentStatus, LucideIcon> = {
  unpaid: CircleX,
  partially_paid: Clock,
  in_payment: Clock,
  paid: CheckCircle,
  overpaid: AlertCircle,
}

const fallbackLabelsByPaymentValue: Record<PaymentStatus, string> = {
  unpaid: 'Unpaid',
  partially_paid: 'Partially Paid',
  in_payment: 'In Payment',
  paid: 'Paid',
  overpaid: 'Overpaid',
}

export function isPaymentStatus(status: string | null | undefined): status is PaymentStatus {
  return status !== undefined && status !== null && status in tonesByPaymentValue
}

export function paymentStatusTone(status: PaymentStatus): StatusTone {
  return tonesByPaymentValue[status]
}

export function paymentStatusIcon(status: PaymentStatus): LucideIcon {
  return iconsByPaymentValue[status]
}

export function paymentStatusFallbackLabel(status: PaymentStatus): string {
  return fallbackLabelsByPaymentValue[status]
}
