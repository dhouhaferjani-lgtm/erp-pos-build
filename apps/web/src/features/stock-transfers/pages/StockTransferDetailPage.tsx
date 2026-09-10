import { Fragment, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, CheckCircle2, XCircle } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/atoms/Button'
import { EntityLink } from '@/components/molecules/EntityLink'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { textColors, borderColors, tokens , semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { formatDate } from '@/lib/format'
import { formatQuantity } from '@/lib/decimal'
import { getQuantityDecimals } from '@/lib/quantityScale'
import {
  useCancelStockTransfer,
  useCompleteStockTransfer,
  useStockTransfer,
} from '../api/queries'
import { StockTransferStatusBadge } from '../components/StockTransferStatusBadge'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'
import { DataTable } from '@/components/molecules/DataTable/DataTable'
import { Textarea } from '@/components/atoms'

export function StockTransferDetailPage() {
  const { t } = useTranslation('stock-transfers')
  const { id } = useParams<{ id: string }>()
  const { data: transfer, isLoading } = useStockTransfer(id)
  const completeMutation = useCompleteStockTransfer()
  const cancelMutation = useCancelStockTransfer()

  const [confirmComplete, setConfirmComplete] = useState(false)
  const [confirmCancel, setConfirmCancel] = useState(false)
  const [cancelReason, setCancelReason] = useState('')

  if (isLoading || transfer === undefined) {
    return <div className={`p-10 text-center ${textColors.tertiary}`}>...</div>
  }

  const canComplete = transfer.status === 'in_transit' || transfer.status === 'partially_received'
  const canCancel = transfer.status === 'draft' || transfer.status === 'in_transit'

  const completeTransfer = async (): Promise<void> => {
    try {
      await completeMutation.mutateAsync(transfer.id)
      toast.success(t('detail.success.completed'))
      setConfirmComplete(false)
    } catch {
      toast.error(t('detail.errors.completeFailed'))
    }
  }

  const cancelTransfer = async (): Promise<void> => {
    try {
      const trimmed = cancelReason.trim()
      const payload: { id: string; reason?: string } =
        trimmed === ''
          ? { id: transfer.id }
          : { id: transfer.id, reason: trimmed }
      await cancelMutation.mutateAsync(payload)
      toast.success(t('detail.success.cancelled'))
      setConfirmCancel(false)
      setCancelReason('')
    } catch {
      toast.error(t('detail.errors.cancelFailed'))
    }
  }

  const handleComplete = () => {
    void completeTransfer()
  }
  const handleCancel = () => {
    void cancelTransfer()
  }
  const closeCancelDialog = () => {
    setConfirmCancel(false)
    setCancelReason('')
  }

  return (
    <div className="space-y-6">
      <div>
        <Link
          to="/inventory/stock-transfers"
          className={`mb-2 inline-flex items-center text-sm ${textColors.tertiary} ${textColors.hoverPrimary}`}
        >
          <ArrowLeft className="me-1 h-4 w-4" />
          {t('detail.back')}
        </Link>
        <div className="flex items-center justify-between">
          <div>
            <PageHeaderTitle className={`text-2xl font-semibold ${textColors.primary}`}>
              {transfer.transfer_number}
            </PageHeaderTitle>
            <div className="mt-1">
              <StockTransferStatusBadge status={transfer.status} />
            </div>
          </div>
          <div className="flex gap-2">
            {canComplete && (
              <Button
                variant="primary"
                onClick={() => {
                  setConfirmComplete(true)
                }}
              >
                <CheckCircle2 className="me-2 h-4 w-4" />
                {t('detail.actions.complete')}
              </Button>
            )}
            {canCancel && (
              <Button
                variant="danger"
                onClick={() => {
                  setConfirmCancel(true)
                }}
              >
                <XCircle className="me-2 h-4 w-4" />
                {t('detail.actions.cancel')}
              </Button>
            )}
          </div>
        </div>
      </div>

      <section className={`rounded-lg border ${borderColors.light} bg-white p-6`}>
        <h2 className={`mb-4 text-lg font-semibold ${textColors.primary}`}>
          {t('detail.section.summary')}
        </h2>
        <dl className="grid grid-cols-1 gap-4 text-sm md:grid-cols-2">
          <SummaryRow label={t('detail.summary.transferNumber')} value={transfer.transfer_number} />
          <SummaryRow label={t('detail.summary.type')} value={transfer.transfer_type} />
          <SummaryRow label={t('detail.summary.source')} value={transfer.source_location_name} />
          <SummaryRow
            label={t('detail.summary.destination')}
            value={transfer.destination_location_name}
          />
          <SummaryRow label={t('detail.summary.transferCost')} value={transfer.transfer_cost} />
          <SummaryRow
            label={t('detail.summary.transferCostDistribution')}
            value={transfer.transfer_cost_distribution}
          />
          <SummaryRow label={t('detail.summary.initiatedBy')} value={transfer.initiated_by_name} />
          <SummaryRow
            label={t('detail.summary.initiatedAt')}
            value={transfer.initiated_at}
          />
          {transfer.completed_at !== null && (
            <>
              <SummaryRow
                label={t('detail.summary.completedBy')}
                value={transfer.completed_by_name}
              />
              <SummaryRow label={t('detail.summary.completedAt')} value={transfer.completed_at} />
            </>
          )}
          {transfer.cancelled_at !== null && (
            <>
              <SummaryRow
                label={t('detail.summary.cancelledBy')}
                value={transfer.cancelled_by_name}
              />
              <SummaryRow label={t('detail.summary.cancelledAt')} value={transfer.cancelled_at} />
              <SummaryRow
                label={t('detail.summary.cancellationReason')}
                value={transfer.cancellation_reason}
              />
            </>
          )}
          {transfer.notes !== null && transfer.notes !== '' && (
            <div className="md:col-span-2">
              <dt className={`text-xs uppercase ${textColors.tertiary}`}>
                {t('detail.summary.notes')}
              </dt>
              <dd className={`mt-1 ${textColors.primary}`}>{transfer.notes}</dd>
            </div>
          )}
        </dl>
      </section>

      <section className={`rounded-lg border ${borderColors.light} bg-white`}>
        <div className={`border-b ${borderColors.light} px-6 py-4`}>
          <h2 className={`text-lg font-semibold ${textColors.primary}`}>
            {t('detail.section.lines')}
          </h2>
        </div>
        <div className="overflow-x-auto">
          <DataTable className={`min-w-full divide-y ${colorTokens.border.divider}`}>
            <thead className={tokens.badge.gray}>
              <tr>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${textColors.tertiary}`}>
                  {t('detail.table.product')}
                </th>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${textColors.tertiary}`}>
                  {t('detail.table.sku')}
                </th>
                <th className={`px-6 py-3 text-end text-xs font-medium uppercase tracking-wider ${textColors.tertiary}`}>
                  {t('detail.table.quantity')}
                </th>
                <th className={`px-6 py-3 text-end text-xs font-medium uppercase tracking-wider ${textColors.tertiary}`}>
                  {t('detail.table.unitCost')}
                </th>
                <th className={`px-6 py-3 text-end text-xs font-medium uppercase tracking-wider ${textColors.tertiary}`}>
                  {t('detail.table.allocatedCost')}
                </th>
              </tr>
            </thead>
            <tbody className={`divide-y ${colorTokens.border.divider}`}>
              {(transfer.lines ?? []).map((line) => (
                <Fragment key={line.id}>
                  <tr>
                    <td className={`px-6 py-3 text-sm ${textColors.primary}`}>
                      <EntityLink
                        type="product"
                        id={line.product_id}
                        label={line.product_name ?? '—'}
                        className={`font-medium ${textColors.hoverPrimary}`}
                      />
                      {line.variant_id !== null && line.variant_name !== null ? (
                        <span className={`block text-xs ${textColors.tertiary}`}>{line.variant_name}</span>
                      ) : null}
                    </td>
                    <td className={`px-6 py-3 text-sm ${textColors.tertiary}`}>
                      {line.variant_sku ?? line.product_sku ?? '—'}
                    </td>
                    <td className={`px-6 py-3 text-end text-sm ${textColors.secondary}`}>
                      {formatQuantity(line.quantity, getQuantityDecimals(line))}
                    </td>
                    <td className={`px-6 py-3 text-end text-sm ${textColors.secondary}`}>
                      {line.unit_cost_snapshot ?? '—'}
                    </td>
                    <td className={`px-6 py-3 text-end text-sm ${textColors.secondary}`}>
                      {line.allocated_transfer_cost}
                    </td>
                  </tr>
                  {line.batch_allocations.length > 0 && (
                    <tr data-testid={`batch-allocations-${line.id}`}>
                      <td colSpan={5} className={`px-6 pb-3 pt-0`}>
                        <div className={`rounded-md border ${borderColors.light} ${tokens.badge.gray} px-4 py-2`}>
                          <div className={`mb-1 text-xs font-medium uppercase tracking-wider ${textColors.tertiary}`}>
                            {t('detail.batch.heading')}
                          </div>
                          <ul className="space-y-1">
                            {line.batch_allocations.map((allocation) => (
                              <li
                                key={allocation.id}
                                className="flex flex-wrap items-baseline gap-x-4 gap-y-0.5 text-sm"
                              >
                                <span className={`font-medium ${textColors.primary}`}>
                                  {allocation.batch_number}
                                </span>
                                {allocation.expiry_date !== null && (
                                  <span className={textColors.tertiary}>
                                    {t('detail.batch.expiry')}:{' '}
                                    <span>{formatDate(allocation.expiry_date)}</span>
                                  </span>
                                )}
                                <span className={textColors.secondary}>
                                  {t('detail.batch.quantity')}:{' '}
                                  <span>{formatQuantity(allocation.quantity, getQuantityDecimals(line))}</span>
                                </span>
                              </li>
                            ))}
                          </ul>
                        </div>
                      </td>
                    </tr>
                  )}
                </Fragment>
              ))}
            </tbody>
          </DataTable>
        </div>
      </section>

      <ConfirmDialog
        isOpen={confirmComplete}
        title={t('detail.confirmComplete.title')}
        message={transfer.status === 'partially_received'
          ? t('detail.confirmComplete.description', {
            shortLines: (transfer.lines ?? []).filter(line => line.quantity_remaining !== '0.0000').length,
            totalLines: (transfer.lines ?? []).length,
          })
          : t('detail.confirmComplete.inTransitDescription')}
        onConfirm={handleComplete}
        onClose={() => {
          setConfirmComplete(false)
        }}
        isLoading={completeMutation.isPending}
        variant="info"
      />

      {confirmCancel && (
        <div className={tokens.modal.backdrop}>
          <div className={tokens.modal.container}>
            <h3 className={tokens.modal.title}>{t('detail.confirmCancel.title')}</h3>
            <p className={`mt-2 text-sm ${textColors.tertiary}`}>
              {t('detail.confirmCancel.description')}
            </p>
            <div className="mt-3">
              <label className={tokens.label.base}>
                {t('detail.confirmCancel.reasonLabel')}
              </label>
              <Textarea
                rows={2}
                value={cancelReason}
                onChange={(e) => {
                  setCancelReason(e.target.value)
                }}
              />
            </div>
            <div className={tokens.modal.footer}>
              <Button
                type="button"
                variant="secondary"
                onClick={closeCancelDialog}
                disabled={cancelMutation.isPending}
              >
                {t('create.cancel')}
              </Button>
              <Button
                type="button"
                variant="danger"
                onClick={handleCancel}
                disabled={cancelMutation.isPending}
              >
                {t('detail.actions.cancel')}
              </Button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

function SummaryRow({ label, value }: { label: string; value: string | null }) {
  return (
    <div>
      <dt className={`text-xs uppercase ${textColors.tertiary}`}>{label}</dt>
      <dd className={`mt-1 text-sm ${textColors.primary}`}>{value ?? '—'}</dd>
    </div>
  )
}
