import { useState } from 'react'
import { Link, useParams, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import {
  ArrowLeft,
  CreditCard,
  Calendar,
  User,
  Receipt,
  FileText,
  RotateCcw,
  History,
} from 'lucide-react'
import { toast } from 'sonner'
import { api, apiDelete, getErrorMessage } from '../../lib/api'
import { ConfirmDialog } from '../../components/ui/ConfirmDialog'

interface PaymentAllocation {
  id: string
  document_id: string
  document_number: string
  amount: string
}

type PaymentType = 'document_payment' | 'advance' | 'refund' | 'credit_application' | 'supplier_payment'

interface Payment {
  id: string
  partner_id: string
  partner: {
    id: string
    name: string
    type: 'customer' | 'supplier' | 'both'
  } | null
  payment_method_id: string
  payment_method: {
    id: string
    code: string
    name: string
  } | null
  instrument_id: string | null
  repository_id: string | null
  amount: string
  currency: string
  payment_date: string
  status: 'pending' | 'completed' | 'failed' | 'reversed'
  payment_type: PaymentType | null
  allocated_amount: string
  unallocated_amount: string
  reference: string | null
  notes: string | null
  allocations: PaymentAllocation[]
  created_at: string
}

interface PaymentResponse {
  data: Payment
}

interface RefundHistoryItem {
  id: string
  type: 'full' | 'partial'
  amount: string
  reason: string
  created_at: string
  created_by: string
}

interface RefundHistoryResponse {
  data: RefundHistoryItem[]
}

interface CanRefundResponse {
  data: {
    can_refund: boolean
    status: string
    amount: string
  }
}

const statusColors: Record<Payment['status'], string> = {
  pending: 'bg-yellow-100 text-yellow-800',
  completed: 'bg-green-100 text-green-800',
  failed: 'bg-red-100 text-red-800',
  reversed: 'bg-gray-100 text-gray-800',
}

export function PaymentDetailPage() {
  const { t } = useTranslation(['treasury', 'common'])
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const [showCancelDialog, setShowCancelDialog] = useState(false)
  const [showRefundModal, setShowRefundModal] = useState(false)
  const [showPartialRefundModal, setShowPartialRefundModal] = useState(false)
  const [showReverseModal, setShowReverseModal] = useState(false)
  const [refundReason, setRefundReason] = useState('')
  const [partialRefundAmount, setPartialRefundAmount] = useState('')
  const [partialRefundReason, setPartialRefundReason] = useState('')
  const [reverseReason, setReverseReason] = useState('')

  const getStatusLabel = (status: Payment['status']) => t(`payments.statuses.${status}`)
  const getPaymentTypeLabel = (type: PaymentType | null) => type ? t(`payments.types.${type}`) : null

  const { data, isLoading, error } = useQuery({
    queryKey: ['payment', id],
    queryFn: async () => {
      if (!id) throw new Error('No payment ID')
      const response = await api.get<PaymentResponse>(`/payments/${id}`)
      return response.data
    },
    enabled: Boolean(id),
  })

  const { data: canRefundData } = useQuery({
    queryKey: ['payment', id, 'can-refund'],
    queryFn: async () => {
      if (!id) throw new Error('No payment ID')
      const response = await api.get<CanRefundResponse>(`/payments/${id}/can-refund`)
      return response.data
    },
    enabled: Boolean(id) && data?.data?.status === 'completed',
  })

  const { data: refundHistoryData } = useQuery({
    queryKey: ['payment', id, 'refund-history'],
    queryFn: async () => {
      if (!id) throw new Error('No payment ID')
      const response = await api.get<RefundHistoryResponse>(`/payments/${id}/refund-history`)
      return response.data
    },
    enabled: Boolean(id) && data?.data?.status === 'completed',
  })

  const deleteMutation = useMutation({
    mutationFn: async () => {
      if (!id) throw new Error('No payment ID')
      return apiDelete(`/payments/${id}`)
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['payments'] })
      toast.success(t('payments.messages.deleted'))
      void navigate('/treasury/payments')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  const refundMutation = useMutation({
    mutationFn: async (reason: string) => {
      if (!id) throw new Error('No payment ID')
      return api.post(`/payments/${id}/refund`, { reason })
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['payment', id] })
      void queryClient.invalidateQueries({ queryKey: ['payment', id, 'refund-history'] })
      void queryClient.invalidateQueries({ queryKey: ['payment', id, 'can-refund'] })
      toast.success(t('payments.messages.refunded'))
      setShowRefundModal(false)
      setRefundReason('')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  const partialRefundMutation = useMutation({
    mutationFn: async ({ amount, reason }: { amount: string; reason: string }) => {
      if (!id) throw new Error('No payment ID')
      return api.post(`/payments/${id}/partial-refund`, { amount, reason })
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['payment', id] })
      void queryClient.invalidateQueries({ queryKey: ['payment', id, 'refund-history'] })
      void queryClient.invalidateQueries({ queryKey: ['payment', id, 'can-refund'] })
      toast.success(t('payments.messages.partialRefunded'))
      setShowPartialRefundModal(false)
      setPartialRefundAmount('')
      setPartialRefundReason('')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  const reverseMutation = useMutation({
    mutationFn: async (reason: string) => {
      if (!id) throw new Error('No payment ID')
      return api.post(`/payments/${id}/reverse`, { reason })
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['payment', id] })
      void queryClient.invalidateQueries({ queryKey: ['payments'] })
      toast.success(t('payments.messages.reversed'))
      setShowReverseModal(false)
      setReverseReason('')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  const handleCancel = () => {
    setShowCancelDialog(true)
  }

  const confirmCancel = () => {
    void deleteMutation.mutateAsync()
    setShowCancelDialog(false)
  }

  const handleRefund = () => {
    if (refundReason.trim()) {
      void refundMutation.mutateAsync(refundReason)
    }
  }

  const handlePartialRefund = () => {
    if (partialRefundAmount && partialRefundReason.trim()) {
      void partialRefundMutation.mutateAsync({
        amount: partialRefundAmount,
        reason: partialRefundReason,
      })
    }
  }

  const handleReverse = () => {
    if (reverseReason.trim()) {
      void reverseMutation.mutateAsync(reverseReason)
    }
  }

  const formatCurrency = (amount: string | number) => {
    const num = typeof amount === 'string' ? parseFloat(amount) : amount
    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency: data?.data.currency ?? 'USD',
    }).format(num)
  }

  // Calculate remaining refundable amount
  const refundHistory = Array.isArray(refundHistoryData?.data) ? refundHistoryData.data : []
  const totalRefunded = refundHistory.reduce((sum: number, r: RefundHistoryItem) => sum + parseFloat(r.amount), 0)
  const originalAmount = data?.data ? parseFloat(data.data.amount) : 0
  const remainingAmount = originalAmount - totalRefunded

  const canRefund = canRefundData?.data?.can_refund ?? false

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className="text-gray-500">{t('common:status.loading')}</div>
      </div>
    )
  }

  if (error || !data?.data) {
    return (
      <div className="space-y-6">
        <Link
          to="/treasury/payments"
          className="inline-flex items-center gap-2 text-sm text-gray-600 hover:text-gray-900"
        >
          <ArrowLeft className="h-4 w-4" />
          {t('common:actions.back')}
        </Link>
        <div className="rounded-lg bg-red-50 p-4 text-red-700">
          {t('payments.messages.notFound')}
        </div>
      </div>
    )
  }

  const payment = data.data

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Link
            to="/treasury/payments"
            className="inline-flex items-center gap-2 text-sm text-gray-600 hover:text-gray-900"
          >
            <ArrowLeft className="h-4 w-4" />
            {t('common:actions.back')}
          </Link>
          <div>
            <h1 className="text-2xl font-bold text-gray-900">
              {t('payments.title')}
            </h1>
            <div className="flex items-center gap-3 mt-1">
              <span className="text-gray-500">{formatCurrency(payment.amount)}</span>
              <span
                className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${statusColors[payment.status]}`}
              >
                {getStatusLabel(payment.status)}
              </span>
              {payment.payment_type && (
                <span className="inline-flex rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-medium text-blue-800">
                  {getPaymentTypeLabel(payment.payment_type)}
                </span>
              )}
            </div>
          </div>
        </div>
        <div className="flex items-center gap-2">
          {payment.status === 'pending' && (
            <button
              onClick={handleCancel}
              disabled={deleteMutation.isPending}
              className="inline-flex items-center gap-2 rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50 disabled:opacity-50"
            >
              {t('payments.messages.cancelPayment')}
            </button>
          )}
          {payment.status === 'completed' && (
            <>
              <button
                onClick={() => { setShowRefundModal(true); }}
                disabled={!canRefund || remainingAmount <= 0}
                className="inline-flex items-center gap-2 rounded-lg border border-orange-300 bg-white px-4 py-2 text-sm font-medium text-orange-700 hover:bg-orange-50 disabled:opacity-50"
              >
                <RotateCcw className="h-4 w-4" />
                {t('payments.refund.refund')}
              </button>
              <button
                onClick={() => { setShowPartialRefundModal(true); }}
                disabled={!canRefund || remainingAmount <= 0}
                className="inline-flex items-center gap-2 rounded-lg border border-yellow-300 bg-white px-4 py-2 text-sm font-medium text-yellow-700 hover:bg-yellow-50 disabled:opacity-50"
              >
                {t('payments.refund.partialRefund')}
              </button>
              <button
                onClick={() => { setShowReverseModal(true); }}
                className="inline-flex items-center gap-2 rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50 disabled:opacity-50"
              >
                {t('payments.refund.reverse')}
              </button>
            </>
          )}
        </div>
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        {/* Payment Info */}
        <div className="rounded-lg border border-gray-200 bg-white p-6">
          <h2 className="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
            <CreditCard className="h-5 w-5 text-gray-400" />
            {t('payments.sections.paymentInfo')}
          </h2>
          <dl className="grid grid-cols-2 gap-4">
            <div>
              <dt className="text-sm font-medium text-gray-500">{t('payments.amount')}</dt>
              <dd className="mt-1 text-lg font-semibold text-gray-900">
                {formatCurrency(payment.amount)}
              </dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">{t('payments.date')}</dt>
              <dd className="mt-1 text-sm text-gray-900 flex items-center gap-1">
                <Calendar className="h-4 w-4 text-gray-400" />
                {new Date(payment.payment_date).toLocaleDateString()}
              </dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">{t('payments.method')}</dt>
              <dd className="mt-1 text-sm text-gray-900">
                {payment.payment_method?.name ?? '-'}
              </dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">{t('payments.status')}</dt>
              <dd className="mt-1">
                <span
                  className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${statusColors[payment.status]}`}
                >
                  {getStatusLabel(payment.status)}
                </span>
              </dd>
            </div>
            {payment.status === 'completed' && refundHistory.length > 0 && (
              <div className="col-span-2">
                <dt className="text-sm font-medium text-gray-500">{t('payments.refund.remaining')}</dt>
                <dd className="mt-1 text-lg font-semibold text-green-600">
                  {formatCurrency(remainingAmount)}
                </dd>
              </div>
            )}
            {parseFloat(payment.allocated_amount) > 0 && (
              <div>
                <dt className="text-sm font-medium text-gray-500">{t('payments.allocatedToInvoices')}</dt>
                <dd className="mt-1 text-sm font-semibold text-gray-900">
                  {formatCurrency(payment.allocated_amount)}
                </dd>
              </div>
            )}
            {parseFloat(payment.unallocated_amount) > 0 && (
              <div>
                <dt className="text-sm font-medium text-gray-500">{t('payments.creditBalance')}</dt>
                <dd className="mt-1 text-sm font-semibold text-blue-600">
                  {formatCurrency(payment.unallocated_amount)}
                </dd>
                <dd className="text-xs text-gray-500 mt-0.5">
                  {t('payments.creditBalanceExplanation')}
                </dd>
              </div>
            )}
            {payment.reference && (
              <div className="col-span-2">
                <dt className="text-sm font-medium text-gray-500">{t('payments.reference')}</dt>
                <dd className="mt-1 text-sm text-gray-900 font-mono">
                  {payment.reference}
                </dd>
              </div>
            )}
          </dl>
        </div>

        {/* Partner Info */}
        <div className="rounded-lg border border-gray-200 bg-white p-6">
          <h2 className="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
            <User className="h-5 w-5 text-gray-400" />
            {t('payments.sections.partner')}
          </h2>
          {payment.partner ? (
            <div>
              <Link
                to={
                  payment.partner.type === 'supplier' || payment.payment_type === 'supplier_payment'
                    ? `/purchases/suppliers/${payment.partner.id}`
                    : `/sales/customers/${payment.partner.id}`
                }
                className="text-blue-600 hover:text-blue-800 font-medium"
              >
                {payment.partner.name}
              </Link>
            </div>
          ) : (
            <p className="text-sm text-gray-500">{t('payments.messages.noPartnerLinked')}</p>
          )}
        </div>
      </div>

      {/* Allocations */}
      {payment.allocations.length > 0 && (
        <div className="rounded-lg border border-gray-200 bg-white p-6">
          <h2 className="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
            <FileText className="h-5 w-5 text-gray-400" />
            {t('payments.sections.allocations')}
          </h2>
          <div className="overflow-hidden rounded-lg border border-gray-200">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                    {t('payments.fields.document')}
                  </th>
                  <th className="px-4 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                    {t('payments.amount')}
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 bg-white">
                {payment.allocations.map((allocation) => (
                  <tr key={allocation.id}>
                    <td className="whitespace-nowrap px-4 py-3">
                      <Link
                        to={`/sales/invoices/${allocation.document_id}`}
                        className="font-medium text-blue-600 hover:text-blue-800"
                      >
                        {allocation.document_number}
                      </Link>
                    </td>
                    <td className="whitespace-nowrap px-4 py-3 text-end text-sm font-medium text-gray-900">
                      {formatCurrency(allocation.amount)}
                    </td>
                  </tr>
                ))}
              </tbody>
              <tfoot className="bg-gray-50">
                <tr>
                  <td className="whitespace-nowrap px-4 py-3 text-sm font-semibold text-gray-900">
                    {t('payments.fields.totalAllocated')}
                  </td>
                  <td className="whitespace-nowrap px-4 py-3 text-end text-sm font-semibold text-gray-900">
                    {formatCurrency(
                      payment.allocations.reduce(
                        (sum, a) => sum + parseFloat(a.amount),
                        0
                      )
                    )}
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      )}

      {/* Refund History */}
      {refundHistory.length > 0 && (
        <div className="rounded-lg border border-gray-200 bg-white p-6">
          <h2 className="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
            <History className="h-5 w-5 text-gray-400" />
            {t('payments.refund.history')}
          </h2>
          <div className="space-y-4">
            {refundHistory.map((refund) => (
              <div
                key={refund.id}
                className="flex items-start justify-between border-b border-gray-100 pb-4 last:border-0 last:pb-0"
              >
                <div>
                  <div className="flex items-center gap-2">
                    <span className="inline-flex rounded-full bg-orange-100 px-2 py-0.5 text-xs font-medium text-orange-800">
                      {refund.type === 'full' ? t('payments.refund.fullRefund') : t('payments.refund.partialRefund')}
                    </span>
                    <span className="text-sm text-gray-500">
                      {new Date(refund.created_at).toLocaleDateString()}
                    </span>
                  </div>
                  <p className="mt-1 text-sm text-gray-700">{refund.reason}</p>
                  <p className="mt-1 text-xs text-gray-500">{t('common:by')} {refund.created_by}</p>
                </div>
                <div className="text-end">
                  <span className="text-lg font-semibold text-red-600">
                    -{formatCurrency(refund.amount)}
                  </span>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Notes */}
      {payment.notes && (
        <div className="rounded-lg border border-gray-200 bg-white p-6">
          <h2 className="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
            <Receipt className="h-5 w-5 text-gray-400" />
            {t('payments.notes')}
          </h2>
          <p className="text-sm text-gray-700 whitespace-pre-wrap">{payment.notes}</p>
        </div>
      )}

      {/* Metadata */}
      <div className="text-sm text-gray-500">
        <p>{t('payments.created')}: {new Date(payment.created_at).toLocaleString()}</p>
      </div>

      {/* Cancel Confirmation Dialog */}
      <ConfirmDialog
        isOpen={showCancelDialog}
        onClose={() => { setShowCancelDialog(false) }}
        onConfirm={confirmCancel}
        title={t('payments.messages.cancelPayment')}
        message={t('payments.messages.confirmCancelPayment', { amount: formatCurrency(payment.amount) })}
        confirmText={t('payments.messages.cancelPayment')}
        variant="danger"
        isLoading={deleteMutation.isPending}
      />

      {/* Full Refund Modal */}
      {showRefundModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
          <div className="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
            <h3 className="text-lg font-semibold text-gray-900 mb-4">
              {t('payments.refund.refundTitle')}
            </h3>
            <p className="text-sm text-gray-600 mb-4">
              {t('payments.refund.refundMessage', { amount: formatCurrency(remainingAmount) })}
            </p>
            <div className="mb-4">
              <label htmlFor="refund-reason" className="block text-sm font-medium text-gray-700 mb-1">
                {t('payments.refund.reason')}
              </label>
              <textarea
                id="refund-reason"
                value={refundReason}
                onChange={(e) => { setRefundReason(e.target.value); }}
                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                rows={3}
                placeholder={t('payments.refund.reasonPlaceholder')}
              />
            </div>
            <div className="flex justify-end gap-3">
              <button
                onClick={() => {
                  setShowRefundModal(false)
                  setRefundReason('')
                }}
                className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
              >
                {t('common:actions.cancel')}
              </button>
              <button
                onClick={handleRefund}
                disabled={!refundReason.trim() || refundMutation.isPending}
                className="rounded-lg bg-orange-600 px-4 py-2 text-sm font-medium text-white hover:bg-orange-700 disabled:opacity-50"
              >
                {refundMutation.isPending ? t('common:status.loading') : t('common:actions.confirm')}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Partial Refund Modal */}
      {showPartialRefundModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
          <div className="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
            <h3 className="text-lg font-semibold text-gray-900 mb-4">
              {t('payments.refund.partialRefundTitle')}
            </h3>
            <p className="text-sm text-gray-600 mb-4">
              {t('payments.refund.maxRefundable')}: {formatCurrency(remainingAmount)}
            </p>
            <div className="mb-4">
              <label htmlFor="partial-refund-amount" className="block text-sm font-medium text-gray-700 mb-1">
                {t('payments.amount')}
              </label>
              <input
                type="number"
                id="partial-refund-amount"
                value={partialRefundAmount}
                onChange={(e) => { setPartialRefundAmount(e.target.value); }}
                max={remainingAmount}
                min={0.01}
                step="0.01"
                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                placeholder="0.00"
              />
            </div>
            <div className="mb-4">
              <label htmlFor="partial-refund-reason" className="block text-sm font-medium text-gray-700 mb-1">
                {t('payments.refund.reason')}
              </label>
              <textarea
                id="partial-refund-reason"
                value={partialRefundReason}
                onChange={(e) => { setPartialRefundReason(e.target.value); }}
                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                rows={3}
                placeholder={t('payments.refund.reasonPlaceholder')}
              />
            </div>
            <div className="flex justify-end gap-3">
              <button
                onClick={() => {
                  setShowPartialRefundModal(false)
                  setPartialRefundAmount('')
                  setPartialRefundReason('')
                }}
                className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
              >
                {t('common:actions.cancel')}
              </button>
              <button
                onClick={handlePartialRefund}
                disabled={
                  !partialRefundAmount ||
                  !partialRefundReason.trim() ||
                  parseFloat(partialRefundAmount) > remainingAmount ||
                  partialRefundMutation.isPending
                }
                className="rounded-lg bg-yellow-600 px-4 py-2 text-sm font-medium text-white hover:bg-yellow-700 disabled:opacity-50"
              >
                {partialRefundMutation.isPending ? t('common:status.loading') : t('common:actions.confirm')}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Reverse Payment Modal */}
      {showReverseModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
          <div className="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
            <h3 className="text-lg font-semibold text-gray-900 mb-4">
              {t('payments.refund.reverseTitle')}
            </h3>
            <p className="text-sm text-gray-600 mb-4">
              {t('payments.refund.reverseMessage')}
            </p>
            <div className="mb-4">
              <label htmlFor="reverse-reason" className="block text-sm font-medium text-gray-700 mb-1">
                {t('payments.refund.reason')}
              </label>
              <textarea
                id="reverse-reason"
                value={reverseReason}
                onChange={(e) => { setReverseReason(e.target.value); }}
                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                rows={3}
                placeholder={t('payments.refund.reasonPlaceholder')}
              />
            </div>
            <div className="flex justify-end gap-3">
              <button
                onClick={() => {
                  setShowReverseModal(false)
                  setReverseReason('')
                }}
                className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
              >
                {t('common:actions.cancel')}
              </button>
              <button
                onClick={handleReverse}
                disabled={!reverseReason.trim() || reverseMutation.isPending}
                className="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50"
              >
                {reverseMutation.isPending ? t('common:status.loading') : t('common:actions.confirm')}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
