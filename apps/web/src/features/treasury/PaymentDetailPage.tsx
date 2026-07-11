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
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { ConfirmDialog } from '../../components/ui/ConfirmDialog'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { cn } from '../../lib/utils'
import { tokens, textColors, borderColors } from '../../lib/designTokens'
import { Button } from '../../components/atoms/Button'
import { EntityLink } from '../../components/molecules/EntityLink'
import { MoneyInput } from '../../components/atoms/MoneyInput'
import { Textarea } from '../../components/atoms/Textarea'
import {
  StatusBadge,
  statusTone,
  type StatusTone,
} from '../../components/atoms/StatusBadge'
import { PageHeader } from '../../components/molecules/PageHeader'
import { Modal, ModalContent, ModalFooter } from '../../components/organisms/Modal'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

interface PaymentAllocation {
  id: string
  document_id: string
  document_number: string
  document_type?: 'invoice' | 'sales_order' | 'purchase_order' | 'supplier_invoice'
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

/**
 * Payment-status tone overrides for the shared StatusBadge. `reversed` is not a
 * built-in status; map it to neutral. The rest (completed/pending/failed) are
 * covered by the built-in statusTone map.
 */
const statusToneOverrides: Record<string, StatusTone> = {
  reversed: 'neutral',
}

function allocationDocumentType(
  paymentType: PaymentType | null,
  allocationType: PaymentAllocation['document_type'],
): 'invoice' | 'sales_order' | 'purchase_order' | 'supplier_invoice' {
  if (allocationType) return allocationType
  if (paymentType === 'supplier_payment') return 'supplier_invoice'
  if (paymentType === 'advance') return 'sales_order'
  return 'invoice'
}

function scopedNamespacePredicate(
  namespace: string,
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      k.length >= 3 &&
      k[0] === namespace &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

export function PaymentDetailPage() {
  const { t } = useTranslation(['treasury', 'common'])
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

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
    queryKey: tenantScopedKey(['payment', id]),
    queryFn: async () => {
      if (!id) throw new Error('No payment ID')
      const response = await api.get<PaymentResponse>(`/payments/${id}`)
      return response.data
    },
    enabled: Boolean(id) && tenantId !== null && companyId !== null,
  })

  const { data: canRefundData } = useQuery({
    queryKey: tenantScopedKey(['payment', id, 'can-refund']),
    queryFn: async () => {
      if (!id) throw new Error('No payment ID')
      const response = await api.get<CanRefundResponse>(`/payments/${id}/can-refund`)
      return response.data
    },
    enabled: Boolean(id) && tenantId !== null && companyId !== null && data?.data?.status === 'completed',
  })

  const { data: refundHistoryData } = useQuery({
    queryKey: tenantScopedKey(['payment', id, 'refund-history']),
    queryFn: async () => {
      if (!id) throw new Error('No payment ID')
      const response = await api.get<RefundHistoryResponse>(`/payments/${id}/refund-history`)
      return response.data
    },
    enabled: Boolean(id) && tenantId !== null && companyId !== null && data?.data?.status === 'completed',
  })

  const deleteMutation = useMutation({
    mutationFn: async () => {
      if (!id) throw new Error('No payment ID')
      return apiDelete(`/payments/${id}`)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: scopedNamespacePredicate('payments', tenantId, companyId),
      })
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
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: tenantScopedKey(['payment', id]) }),
        queryClient.invalidateQueries({ queryKey: tenantScopedKey(['payment', id, 'refund-history']) }),
        queryClient.invalidateQueries({ queryKey: tenantScopedKey(['payment', id, 'can-refund']) }),
      ])
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
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: tenantScopedKey(['payment', id]) }),
        queryClient.invalidateQueries({ queryKey: tenantScopedKey(['payment', id, 'refund-history']) }),
        queryClient.invalidateQueries({ queryKey: tenantScopedKey(['payment', id, 'can-refund']) }),
      ])
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
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: tenantScopedKey(['payment', id]) }),
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('payments', tenantId, companyId),
        }),
      ])
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
        <div className={textColors.tertiary}>{t('common:status.loading')}</div>
      </div>
    )
  }

  if (error || !data?.data) {
    return (
      <div className="space-y-6">
        <Link
          to="/treasury/payments"
          className={cn(
            'inline-flex items-center gap-2 text-sm',
            textColors.tertiary,
            textColors.hoverPrimary,
          )}
        >
          <ArrowLeft className="h-4 w-4" />
          {t('common:actions.back')}
        </Link>
        <div className={cn(tokens.alert.base, tokens.alert.error)}>
          {t('payments.messages.notFound')}
        </div>
      </div>
    )
  }

  const payment = data.data

  const backLink = (
    <Link
      to="/treasury/payments"
      className={cn(
        'inline-flex items-center gap-2 text-sm',
        textColors.tertiary,
        textColors.hoverPrimary,
      )}
    >
      <ArrowLeft className="h-4 w-4" />
      {t('common:actions.back')}
    </Link>
  )

  return (
    <div className="space-y-6">
      {/* Header */}
      <PageHeader
        title={t('payments.title')}
        breadcrumb={backLink}
        subtitle={`${formatCurrency(payment.amount)} · ${getStatusLabel(payment.status)}`}
        actions={
          <>
            {payment.status === 'pending' && (
              <Button
                variant="danger"
                onClick={handleCancel}
                disabled={deleteMutation.isPending}
              >
                {t('payments.messages.cancelPayment')}
              </Button>
            )}
            {payment.status === 'completed' && (
              <>
                <Button
                  variant="secondary"
                  onClick={() => { setShowRefundModal(true); }}
                  disabled={!canRefund || remainingAmount <= 0}
                >
                  <RotateCcw className="me-2 h-4 w-4" />
                  {t('payments.refund.refund')}
                </Button>
                <Button
                  variant="secondary"
                  onClick={() => { setShowPartialRefundModal(true); }}
                  disabled={!canRefund || remainingAmount <= 0}
                >
                  {t('payments.refund.partialRefund')}
                </Button>
                <Button
                  variant="danger"
                  onClick={() => { setShowReverseModal(true); }}
                >
                  {t('payments.refund.reverse')}
                </Button>
              </>
            )}
          </>
        }
      />

      <div className="grid gap-6 lg:grid-cols-2">
        {/* Payment Info */}
        <div className={tokens.card.base}>
          <h2 className={cn(tokens.heading.section, 'mb-4 flex items-center gap-2')}>
            <CreditCard className={cn('h-5 w-5', textColors.disabled)} />
            {t('payments.sections.paymentInfo')}
          </h2>
          <dl className="grid grid-cols-2 gap-4">
            <div>
              <dt className={cn('text-sm font-medium', textColors.tertiary)}>{t('payments.amount')}</dt>
              <dd className={cn('mt-1 text-lg font-semibold tabular-nums', textColors.primary)}>
                {formatCurrency(payment.amount)}
              </dd>
            </div>
            <div>
              <dt className={cn('text-sm font-medium', textColors.tertiary)}>{t('payments.date')}</dt>
              <dd className={cn('mt-1 flex items-center gap-1 text-sm', textColors.primary)}>
                <Calendar className={cn('h-4 w-4', textColors.disabled)} />
                {new Date(payment.payment_date).toLocaleDateString()}
              </dd>
            </div>
            <div>
              <dt className={cn('text-sm font-medium', textColors.tertiary)}>{t('payments.method')}</dt>
              <dd className={cn('mt-1 text-sm', textColors.primary)}>
                {payment.payment_method?.name ?? '-'}
              </dd>
            </div>
            <div>
              <dt className={cn('text-sm font-medium', textColors.tertiary)}>{t('payments.status')}</dt>
              <dd className="mt-1">
                <StatusBadge tone={statusTone(payment.status, statusToneOverrides)}>
                  {getStatusLabel(payment.status)}
                </StatusBadge>
              </dd>
            </div>
            {payment.status === 'completed' && refundHistory.length > 0 && (
              <div className="col-span-2">
                <dt className={cn('text-sm font-medium', textColors.tertiary)}>{t('payments.refund.remaining')}</dt>
                <dd className={cn('mt-1 text-lg font-semibold tabular-nums', textColors.success)}>
                  {formatCurrency(remainingAmount)}
                </dd>
              </div>
            )}
            {parseFloat(payment.allocated_amount) > 0 && (
              <div>
                <dt className={cn('text-sm font-medium', textColors.tertiary)}>{t('payments.allocatedToInvoices')}</dt>
                <dd className={cn('mt-1 text-sm font-semibold tabular-nums', textColors.primary)}>
                  {formatCurrency(payment.allocated_amount)}
                </dd>
              </div>
            )}
            {parseFloat(payment.unallocated_amount) > 0 && (
              <div>
                <dt className={cn('text-sm font-medium', textColors.tertiary)}>{t('payments.creditBalance')}</dt>
                <dd className={cn('mt-1 text-sm font-semibold tabular-nums', textColors.brand)}>
                  {formatCurrency(payment.unallocated_amount)}
                </dd>
                <dd className={cn('mt-0.5 text-xs', textColors.tertiary)}>
                  {t('payments.creditBalanceExplanation')}
                </dd>
              </div>
            )}
            {payment.reference && (
              <div className="col-span-2">
                <dt className={cn('text-sm font-medium', textColors.tertiary)}>{t('payments.reference')}</dt>
                <dd className={cn('mt-1 font-mono text-sm', textColors.primary)}>
                  {payment.reference}
                </dd>
              </div>
            )}
          </dl>
        </div>

        {/* Partner Info */}
        <div className={tokens.card.base}>
          <h2 className={cn(tokens.heading.section, 'mb-4 flex items-center gap-2')}>
            <User className={cn('h-5 w-5', textColors.disabled)} />
            {t('payments.sections.partner')}
          </h2>
          {payment.partner ? (
            <div>
              <EntityLink
                type="partner"
                id={payment.partner.id}
                partnerType={payment.partner.type === 'supplier' || payment.payment_type === 'supplier_payment' ? 'supplier' : 'customer'}
                label={payment.partner.name}
                className="font-medium"
              />
              {payment.payment_type && (
                <div className="mt-2">
                  <StatusBadge tone="info">
                    {getPaymentTypeLabel(payment.payment_type)}
                  </StatusBadge>
                </div>
              )}
            </div>
          ) : (
            <p className={cn('text-sm', textColors.tertiary)}>{t('payments.messages.noPartnerLinked')}</p>
          )}
        </div>
      </div>

      {/* Allocations */}
      {payment.allocations.length > 0 && (
        <div className={tokens.card.base}>
          <h2 className={cn(tokens.heading.section, 'mb-4 flex items-center gap-2')}>
            <FileText className={cn('h-5 w-5', textColors.disabled)} />
            {t('payments.sections.allocations')}
          </h2>
          <div className={cn('overflow-hidden rounded-lg border', borderColors.light)}>
            <DataTable className={cn('min-w-full divide-y', borderColors.divideDefault)}>
              <thead className={tokens.table.header}>
                <tr>
                  <th className={cn('px-4 py-3 text-start text-xs font-medium uppercase tracking-wider', textColors.tertiary)}>
                    {t('payments.fields.document')}
                  </th>
                  <th className={cn('px-4 py-3 text-end text-xs font-medium uppercase tracking-wider', textColors.tertiary)}>
                    {t('payments.amount')}
                  </th>
                </tr>
              </thead>
              <tbody className={cn('divide-y bg-white', borderColors.divideDefault)}>
                {payment.allocations.map((allocation) => (
                  <tr key={allocation.id}>
                    <td className="whitespace-nowrap px-4 py-3">
                      <EntityLink
                        type="document"
                        id={allocation.document_id}
                        documentType={allocationDocumentType(payment.payment_type, allocation.document_type)}
                        label={allocation.document_number}
                        className="font-medium"
                      />
                    </td>
                    <td className={cn('whitespace-nowrap px-4 py-3 text-end text-sm font-medium tabular-nums', textColors.primary)}>
                      {formatCurrency(allocation.amount)}
                    </td>
                  </tr>
                ))}
              </tbody>
              <tfoot className={tokens.table.header}>
                <tr>
                  <td className={cn('whitespace-nowrap px-4 py-3 text-sm font-semibold', textColors.primary)}>
                    {t('payments.fields.totalAllocated')}
                  </td>
                  <td className={cn('whitespace-nowrap px-4 py-3 text-end text-sm font-semibold tabular-nums', textColors.primary)}>
                    {formatCurrency(
                      payment.allocations.reduce(
                        (sum, a) => sum + parseFloat(a.amount),
                        0
                      )
                    )}
                  </td>
                </tr>
              </tfoot>
            </DataTable>
          </div>
        </div>
      )}

      {/* Refund History */}
      {refundHistory.length > 0 && (
        <div className={tokens.card.base}>
          <h2 className={cn(tokens.heading.section, 'mb-4 flex items-center gap-2')}>
            <History className={cn('h-5 w-5', textColors.disabled)} />
            {t('payments.refund.history')}
          </h2>
          <div className="space-y-4">
            {refundHistory.map((refund) => (
              <div
                key={refund.id}
                className={cn(
                  'flex items-start justify-between border-b pb-4 last:border-0 last:pb-0',
                  borderColors.light,
                )}
              >
                <div>
                  <div className="flex items-center gap-2">
                    <StatusBadge tone="warning">
                      {refund.type === 'full' ? t('payments.refund.fullRefund') : t('payments.refund.partialRefund')}
                    </StatusBadge>
                    <span className={cn('text-sm', textColors.tertiary)}>
                      {new Date(refund.created_at).toLocaleDateString()}
                    </span>
                  </div>
                  <p className={cn('mt-1 text-sm', textColors.secondary)}>{refund.reason}</p>
                  <p className={cn('mt-1 text-xs', textColors.tertiary)}>{t('common:by')} {refund.created_by}</p>
                </div>
                <div className="text-end">
                  <span className={cn('text-lg font-semibold tabular-nums', textColors.error)}>
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
        <div className={tokens.card.base}>
          <h2 className={cn(tokens.heading.section, 'mb-4 flex items-center gap-2')}>
            <Receipt className={cn('h-5 w-5', textColors.disabled)} />
            {t('payments.notes')}
          </h2>
          <p className={cn('whitespace-pre-wrap text-sm', textColors.secondary)}>{payment.notes}</p>
        </div>
      )}

      {/* Metadata */}
      <div className={cn('text-sm', textColors.tertiary)}>
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
      <Modal
        isOpen={showRefundModal}
        onClose={() => {
          setShowRefundModal(false)
          setRefundReason('')
        }}
        title={t('payments.refund.refundTitle')}
      >
        <ModalContent>
          <p className={cn('text-sm', textColors.tertiary)}>
            {t('payments.refund.refundMessage', { amount: formatCurrency(remainingAmount) })}
          </p>
          <div>
            <label htmlFor="refund-reason" className={cn('mb-1 block text-sm font-medium', textColors.secondary)}>
              {t('payments.refund.reason')}
            </label>
            <Textarea
              id="refund-reason"
              value={refundReason}
              onChange={(e) => { setRefundReason(e.target.value); }}
              rows={3}
              placeholder={t('payments.refund.reasonPlaceholder')}
            />
          </div>
        </ModalContent>
        <ModalFooter>
          <Button
            variant="secondary"
            onClick={() => {
              setShowRefundModal(false)
              setRefundReason('')
            }}
          >
            {t('common:actions.cancel')}
          </Button>
          <Button
            variant="primary"
            onClick={handleRefund}
            disabled={!refundReason.trim() || refundMutation.isPending}
          >
            {refundMutation.isPending ? t('common:status.loading') : t('common:actions.confirm')}
          </Button>
        </ModalFooter>
      </Modal>

      {/* Partial Refund Modal */}
      <Modal
        isOpen={showPartialRefundModal}
        onClose={() => {
          setShowPartialRefundModal(false)
          setPartialRefundAmount('')
          setPartialRefundReason('')
        }}
        title={t('payments.refund.partialRefundTitle')}
      >
        <ModalContent>
          <p className={cn('text-sm', textColors.tertiary)}>
            {t('payments.refund.maxRefundable')}: {formatCurrency(remainingAmount)}
          </p>
          <div>
            <label htmlFor="partial-refund-amount" className={cn('mb-1 block text-sm font-medium', textColors.secondary)}>
              {t('payments.amount')}
            </label>
            <MoneyInput
              id="partial-refund-amount"
              value={partialRefundAmount}
              onChange={(value) => { setPartialRefundAmount(value); }}
              currency={payment.currency}
              max={remainingAmount}
              placeholder="0.00"
            />
          </div>
          <div>
            <label htmlFor="partial-refund-reason" className={cn('mb-1 block text-sm font-medium', textColors.secondary)}>
              {t('payments.refund.reason')}
            </label>
            <Textarea
              id="partial-refund-reason"
              value={partialRefundReason}
              onChange={(e) => { setPartialRefundReason(e.target.value); }}
              rows={3}
              placeholder={t('payments.refund.reasonPlaceholder')}
            />
          </div>
        </ModalContent>
        <ModalFooter>
          <Button
            variant="secondary"
            onClick={() => {
              setShowPartialRefundModal(false)
              setPartialRefundAmount('')
              setPartialRefundReason('')
            }}
          >
            {t('common:actions.cancel')}
          </Button>
          <Button
            variant="primary"
            onClick={handlePartialRefund}
            disabled={
              !partialRefundAmount ||
              !partialRefundReason.trim() ||
              parseFloat(partialRefundAmount) > remainingAmount ||
              partialRefundMutation.isPending
            }
          >
            {partialRefundMutation.isPending ? t('common:status.loading') : t('common:actions.confirm')}
          </Button>
        </ModalFooter>
      </Modal>

      {/* Reverse Payment Modal */}
      <Modal
        isOpen={showReverseModal}
        onClose={() => {
          setShowReverseModal(false)
          setReverseReason('')
        }}
        title={t('payments.refund.reverseTitle')}
      >
        <ModalContent>
          <p className={cn('text-sm', textColors.tertiary)}>
            {t('payments.refund.reverseMessage')}
          </p>
          <div>
            <label htmlFor="reverse-reason" className={cn('mb-1 block text-sm font-medium', textColors.secondary)}>
              {t('payments.refund.reason')}
            </label>
            <Textarea
              id="reverse-reason"
              value={reverseReason}
              onChange={(e) => { setReverseReason(e.target.value); }}
              rows={3}
              placeholder={t('payments.refund.reasonPlaceholder')}
            />
          </div>
        </ModalContent>
        <ModalFooter>
          <Button
            variant="secondary"
            onClick={() => {
              setShowReverseModal(false)
              setReverseReason('')
            }}
          >
            {t('common:actions.cancel')}
          </Button>
          <Button
            variant="danger"
            onClick={handleReverse}
            disabled={!reverseReason.trim() || reverseMutation.isPending}
          >
            {reverseMutation.isPending ? t('common:status.loading') : t('common:actions.confirm')}
          </Button>
        </ModalFooter>
      </Modal>
    </div>
  )
}
