import { useState } from 'react'
import { Link, useParams, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, Edit, Package, Calendar, AlertTriangle, Trash2 } from 'lucide-react'
import { useBatch, useBatchStock, useDeleteBatch, useRecallBatch } from '../hooks/useBatches'
import { BatchStatusBadge } from '../components/BatchStatusBadge'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'

type ConfirmAction = 'delete' | 'recall' | null

export function BatchDetailPage() {
  const { t } = useTranslation(['batches', 'common'])
  const { uuid = '' } = useParams<{ uuid: string }>()
  const navigate = useNavigate()

  const [confirmAction, setConfirmAction] = useState<ConfirmAction>(null)
  const [recallReason, setRecallReason] = useState('')

  // Fetch batch details
  const { data: batch, isLoading, error } = useBatch(uuid)

  // Fetch stock levels by location
  const { data: stockLevels } = useBatchStock(uuid)

  // Mutations
  const deleteMutation = useDeleteBatch()
  const recallMutation = useRecallBatch()

  const handleDelete = () => {
    deleteMutation.mutate(uuid, {
      onSuccess: () => {
        navigate('/inventory/batches')
      },
    })
    setConfirmAction(null)
  }

  const handleRecall = () => {
    if (!recallReason.trim()) {
      return
    }

    recallMutation.mutate(
      { uuid, input: { recall_reason: recallReason } },
      {
        onSuccess: () => {
          setConfirmAction(null)
          setRecallReason('')
        },
      }
    )
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className="text-gray-500">{t('common:status.loading')}</div>
      </div>
    )
  }

  if (error || !batch) {
    return (
      <div className="rounded-lg bg-red-50 p-4 text-red-700">
        {t('batches:messages.loadError')}
      </div>
    )
  }

  const canEdit = batch.is_active && !batch.is_recalled
  const canRecall = batch.is_active && !batch.is_recalled && !batch.is_expired
  const canDelete = batch.is_active

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-start justify-between">
        <div className="flex items-start gap-4">
          <Link
            to="/inventory/batches"
            className="mt-1 text-gray-400 hover:text-gray-600"
          >
            <ArrowLeft className="h-6 w-6" />
          </Link>
          <div>
            <h1 className="text-2xl font-bold text-gray-900">
              {batch.batch_number}
            </h1>
            <div className="mt-2 flex items-center gap-2">
              <BatchStatusBadge
                status={batch.expiry_status}
                daysUntilExpiry={batch.days_until_expiry}
                size="md"
              />
              {batch.is_recalled && (
                <span className="inline-flex items-center gap-1 rounded-full bg-red-100 px-3 py-1 text-sm font-medium text-red-800">
                  <AlertTriangle className="h-4 w-4" />
                  {t('batches:status.recalled')}
                </span>
              )}
              {!batch.is_active && (
                <span className="inline-flex items-center rounded-full bg-gray-100 px-3 py-1 text-sm font-medium text-gray-800">
                  {t('batches:status.inactive')}
                </span>
              )}
            </div>
          </div>
        </div>

        {/* Actions */}
        <div className="flex items-center gap-2">
          {canRecall && (
            <button
              onClick={() => { setConfirmAction('recall'); }}
              className="inline-flex items-center gap-2 rounded-lg border border-orange-600 px-4 py-2 text-sm font-medium text-orange-600 hover:bg-orange-50"
            >
              <AlertTriangle className="h-4 w-4" />
              {t('batches:actions.recallBatch')}
            </button>
          )}
          {canEdit && (
            <Link
              to={`/inventory/batches/${uuid}/edit`}
              className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
            >
              <Edit className="h-4 w-4" />
              {t('common:actions.edit')}
            </Link>
          )}
          {canDelete && (
            <button
              onClick={() => { setConfirmAction('delete'); }}
              className="inline-flex items-center gap-2 rounded-lg border border-red-600 px-4 py-2 text-sm font-medium text-red-600 hover:bg-red-50"
            >
              <Trash2 className="h-4 w-4" />
              {t('common:actions.delete')}
            </button>
          )}
        </div>
      </div>

      {/* Product Information */}
      {batch.product && (
        <div className="rounded-lg border border-gray-200 bg-white p-6">
          <h2 className="mb-4 flex items-center gap-2 text-lg font-semibold text-gray-900">
            <Package className="h-5 w-5" />
            {t('batches:detail.productInfo')}
          </h2>
          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <label className="text-sm text-gray-500">
                {t('batches:fields.product')}
              </label>
              <Link
                to={`/inventory/products/${batch.product.id}`}
                className="block font-medium text-blue-600 hover:text-blue-800"
              >
                {batch.product.name}
              </Link>
            </div>
            <div>
              <label className="text-sm text-gray-500">
                {t('common:fields.sku', 'SKU')}
              </label>
              <div className="font-medium text-gray-900">{batch.product.sku}</div>
            </div>
          </div>
        </div>
      )}

      {/* Batch Details */}
      <div className="rounded-lg border border-gray-200 bg-white p-6">
        <h2 className="mb-4 flex items-center gap-2 text-lg font-semibold text-gray-900">
          <Calendar className="h-5 w-5" />
          {t('batches:detail.title')}
        </h2>
        <div className="grid gap-4 sm:grid-cols-2">
          <div>
            <label className="text-sm text-gray-500">
              {t('batches:fields.batchNumber')}
            </label>
            <div className="font-medium text-gray-900">{batch.batch_number}</div>
          </div>
          <div>
            <label className="text-sm text-gray-500">
              {t('batches:fields.expiryDate')}
            </label>
            <div className="font-medium text-gray-900">
              {new Date(batch.expiry_date).toLocaleDateString()}
            </div>
          </div>
          {batch.manufacturing_date && (
            <div>
              <label className="text-sm text-gray-500">
                {t('batches:fields.manufacturingDate')}
              </label>
              <div className="font-medium text-gray-900">
                {new Date(batch.manufacturing_date).toLocaleDateString()}
              </div>
            </div>
          )}
          {batch.days_until_expiry !== undefined && (
            <div>
              <label className="text-sm text-gray-500">
                {t('batches:daysRemaining')}
              </label>
              <div className="font-medium text-gray-900">
                {batch.days_until_expiry} {t('batches:daysRemaining')}
              </div>
            </div>
          )}
          {batch.notes && (
            <div className="sm:col-span-2">
              <label className="text-sm text-gray-500">
                {t('batches:fields.notes')}
              </label>
              <div className="text-gray-900">{batch.notes}</div>
            </div>
          )}
          {batch.is_recalled && batch.recall_reason && (
            <div className="sm:col-span-2">
              <label className="text-sm text-gray-500">
                {t('batches:fields.recallReason')}
              </label>
              <div className="rounded-lg bg-red-50 p-3 text-red-900">
                {batch.recall_reason}
              </div>
            </div>
          )}
        </div>
      </div>

      {/* Stock by Location */}
      {stockLevels && stockLevels.length > 0 && (
        <div className="rounded-lg border border-gray-200 bg-white p-6">
          <h2 className="mb-4 text-lg font-semibold text-gray-900">
            {t('batches:detail.stockByLocation')}
          </h2>
          <div className="overflow-hidden rounded-lg border border-gray-200">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                    {t('batches:fields.location')}
                  </th>
                  <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                    {t('batches:fields.quantity')}
                  </th>
                  <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                    {t('batches:fields.reservedQuantity')}
                  </th>
                  <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                    {t('batches:fields.availableQuantity')}
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 bg-white">
                {stockLevels.map((level) => (
                  <tr key={level.location_id}>
                    <td className="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">
                      {level.location_name}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900">
                      {parseFloat(level.quantity).toFixed(2)}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-500">
                      {parseFloat(level.reserved_quantity).toFixed(2)}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-end text-sm font-medium text-gray-900">
                      {parseFloat(level.available_quantity).toFixed(2)}
                    </td>
                  </tr>
                ))}
              </tbody>
              <tfoot className="bg-gray-50">
                <tr>
                  <td className="px-6 py-3 text-sm font-semibold text-gray-900">
                    {t('common:total')}
                  </td>
                  <td className="px-6 py-3 text-end text-sm font-semibold text-gray-900">
                    {stockLevels
                      .reduce((sum, level) => sum + parseFloat(level.quantity), 0)
                      .toFixed(2)}
                  </td>
                  <td className="px-6 py-3 text-end text-sm font-semibold text-gray-500">
                    {stockLevels
                      .reduce((sum, level) => sum + parseFloat(level.reserved_quantity), 0)
                      .toFixed(2)}
                  </td>
                  <td className="px-6 py-3 text-end text-sm font-semibold text-gray-900">
                    {stockLevels
                      .reduce((sum, level) => sum + parseFloat(level.available_quantity), 0)
                      .toFixed(2)}
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      )}

      {/* Delete Confirmation Dialog */}
      <ConfirmDialog
        isOpen={confirmAction === 'delete'}
        onClose={() => { setConfirmAction(null); }}
        onConfirm={handleDelete}
        title={t('batches:actions.deleteBatch')}
        message={t('batches:form.confirmDelete')}
        confirmText={t('common:actions.delete')}
        variant="danger"
      />

      {/* Recall Confirmation Dialog */}
      {confirmAction === 'recall' && (
        <div className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-black/50">
          <div className="relative mx-4 w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
            <h3 className="text-lg font-semibold text-gray-900">{t('batches:actions.recallBatch')}</h3>
            <div className="mt-4 space-y-4">
              <p className="text-sm text-gray-500">{t('batches:form.confirmRecall')}</p>
              <div>
                <label className="block text-sm font-medium text-gray-700">
                  {t('batches:fields.recallReason')}
                </label>
                <textarea
                  value={recallReason}
                  onChange={(e) => { setRecallReason(e.target.value); }}
                  rows={3}
                  className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                  placeholder={t('batches:form.enterRecallReason')}
                />
              </div>
            </div>
            <div className="mt-6 flex justify-end gap-3">
              <button
                type="button"
                onClick={() => { setConfirmAction(null); setRecallReason('') }}
                className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
              >
                {t('common:actions.cancel')}
              </button>
              <button
                type="button"
                onClick={handleRecall}
                disabled={!recallReason.trim()}
                className="rounded-lg bg-yellow-600 px-4 py-2 text-sm font-medium text-white hover:bg-yellow-700 disabled:opacity-50 transition-colors"
              >
                {t('batches:actions.recallBatch')}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
