import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, CheckCircle2, XCircle } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/atoms/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { textColors, borderColors } from '@/lib/designTokens'
import {
  useCancelStockTransfer,
  useCompleteStockTransfer,
  useStockTransfer,
} from '../api/queries'
import { StockTransferStatusBadge } from '../components/StockTransferStatusBadge'

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
    return (
      <div className={`p-10 text-center ${textColors.tertiary}`}>...</div>
    )
  }

  const canComplete = transfer.status === 'in_transit'
  const canCancel = transfer.status === 'draft' || transfer.status === 'in_transit'

  const handleComplete = async () => {
    try {
      await completeMutation.mutateAsync(transfer.id)
      toast.success(t('detail.success.completed'))
      setConfirmComplete(false)
    } catch {
      toast.error(t('detail.errors.completeFailed'))
    }
  }

  const handleCancel = async () => {
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

  return (
    <div className="space-y-6">
      <div>
        <Link
          to="/inventory/stock-transfers"
          className={`mb-2 inline-flex items-center text-sm ${textColors.tertiary} hover:text-gray-900`}
        >
          <ArrowLeft className="me-1 h-4 w-4" />
          {t('detail.back')}
        </Link>
        <div className="flex items-center justify-between">
          <div>
            <h1 className={`text-2xl font-semibold ${textColors.primary}`}>
              {transfer.transfer_number}
            </h1>
            <div className="mt-1">
              <StockTransferStatusBadge status={transfer.status} />
            </div>
          </div>
          <div className="flex gap-2">
            {canComplete && (
              <Button variant="primary" onClick={() => setConfirmComplete(true)}>
                <CheckCircle2 className="me-2 h-4 w-4" />
                {t('detail.actions.complete')}
              </Button>
            )}
            {canCancel && (
              <Button variant="danger" onClick={() => setConfirmCancel(true)}>
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
            value={transfer.initiated_at ?? null}
          />
          {transfer.completed_at !== null && (
            <>
              <SummaryRow
                label={t('detail.summary.completedBy')}
                value={transfer.completed_by_name}
              />
              <SummaryRow
                label={t('detail.summary.completedAt')}
                value={transfer.completed_at}
              />
            </>
          )}
          {transfer.cancelled_at !== null && (
            <>
              <SummaryRow
                label={t('detail.summary.cancelledBy')}
                value={transfer.cancelled_by_name}
              />
              <SummaryRow
                label={t('detail.summary.cancelledAt')}
                value={transfer.cancelled_at}
              />
              <SummaryRow
                label={t('detail.summary.cancellationReason')}
                value={transfer.cancellation_reason}
              />
            </>
          )}
          {transfer.notes !== null && transfer.notes !== '' && (
            <div className="md:col-span-2">
              <dt className={`text-xs uppercase ${textColors.tertiary}`}>{t('detail.summary.notes')}</dt>
              <dd className={`mt-1 ${textColors.primary}`}>{transfer.notes}</dd>
            </div>
          )}
        </dl>
      </section>

      <section className={`rounded-lg border ${borderColors.light} bg-white`}>
        <div className="border-b border-gray-200 px-6 py-4">
          <h2 className={`text-lg font-semibold ${textColors.primary}`}>
            {t('detail.section.lines')}
          </h2>
        </div>
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('detail.table.product')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('detail.table.sku')}
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('detail.table.quantity')}
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('detail.table.unitCost')}
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('detail.table.allocatedCost')}
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200">
              {(transfer.lines ?? []).map((line) => (
                <tr key={line.id}>
                  <td className={`px-6 py-3 text-sm ${textColors.primary}`}>
                    {line.product_name ?? '—'}
                  </td>
                  <td className={`px-6 py-3 text-sm ${textColors.tertiary}`}>
                    {line.product_sku ?? '—'}
                  </td>
                  <td className={`px-6 py-3 text-end text-sm ${textColors.secondary}`}>
                    {line.quantity}
                  </td>
                  <td className={`px-6 py-3 text-end text-sm ${textColors.secondary}`}>
                    {line.unit_cost_snapshot ?? '—'}
                  </td>
                  <td className={`px-6 py-3 text-end text-sm ${textColors.secondary}`}>
                    {line.allocated_transfer_cost}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      <ConfirmDialog
        isOpen={confirmComplete}
        title={t('detail.confirmComplete.title')}
        message={t('detail.confirmComplete.description')}
        onConfirm={handleComplete}
        onClose={() => setConfirmComplete(false)}
        isLoading={completeMutation.isPending}
        variant="info"
      />

      {confirmCancel && (
        <div className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-black/50">
          <div className="relative mx-4 w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
            <h3 className={`text-lg font-semibold ${textColors.primary}`}>
              {t('detail.confirmCancel.title')}
            </h3>
            <p className={`mt-2 text-sm ${textColors.tertiary}`}>
              {t('detail.confirmCancel.description')}
            </p>
            <div className="mt-3">
              <label className={`mb-1 block text-sm font-medium ${textColors.secondary}`}>
                {t('detail.confirmCancel.reasonLabel')}
              </label>
              <textarea
                rows={2}
                value={cancelReason}
                onChange={(e) => setCancelReason(e.target.value)}
                className="w-full rounded-md border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500"
              />
            </div>
            <div className="mt-6 flex justify-end gap-3">
              <Button
                type="button"
                variant="secondary"
                onClick={() => {
                  setConfirmCancel(false)
                  setCancelReason('')
                }}
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
      <dt className="text-xs uppercase text-gray-500">{label}</dt>
      <dd className="mt-1 text-sm text-gray-900">{value ?? '—'}</dd>
    </div>
  )
}
