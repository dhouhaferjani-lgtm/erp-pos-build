import { useState } from 'react'
import { Link, useParams, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, Edit, Package, Calendar, AlertTriangle, Trash2 } from 'lucide-react'
import { useBatch, useBatchStock, useDeleteBatch, useRecallBatch } from '../hooks/useBatches'
import { BatchStatusBadge } from '../components/BatchStatusBadge'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { usePermissions } from '@/hooks/usePermissions'
import { useCurrency } from '@/hooks/useCurrency'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

type ConfirmAction = 'delete' | 'recall' | null

export function BatchDetailPage() {
  const { t } = useTranslation(['batches', 'common'])
  const { uuid = '' } = useParams<{ uuid: string }>()
  const navigate = useNavigate()

  const { decimals } = useCurrency()
  const { hasPermission } = usePermissions()
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
        <div className={`${colorTokens.text.subtle}`}>{t('common:status.loading')}</div>
      </div>
    )
  }

  if (error || !batch) {
    return (
      <div className={`rounded-lg ${colorTokens.intent.danger.bgSubtle} p-4 ${colorTokens.intent.danger.textStrong}`}>
        {t('batches:messages.loadError')}
      </div>
    )
  }

  const canEdit = hasPermission('batches.update') && batch.is_active && !batch.is_recalled
  const canRecall = hasPermission('batches.recall') && batch.is_active && !batch.is_recalled && !batch.is_expired
  const canDelete = hasPermission('batches.delete') && batch.is_active

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-start justify-between">
        <div className="flex items-start gap-4">
          <Link
            to="/inventory/batches"
            className={`mt-1 ${colorTokens.text.disabled} ${colorTokens.variants.hoverTextGray600}`}
          >
            <ArrowLeft className="h-6 w-6" />
          </Link>
          <div>
            <PageHeaderTitle className={`text-2xl font-bold ${colorTokens.text.primary}`}>
              {batch.batch_number}
            </PageHeaderTitle>
            <div className="mt-2 flex items-center gap-2">
              <BatchStatusBadge
                status={batch.expiry_status}
                daysUntilExpiry={batch.days_until_expiry}
                size="md"
              />
              {batch.is_recalled && (
                <span className={`inline-flex items-center gap-1 rounded-full ${colorTokens.intent.danger.bgSoft} px-3 py-1 text-sm font-medium ${colorTokens.intent.danger.textStronger}`}>
                  <AlertTriangle className="h-4 w-4" />
                  {t('batches:status.recalled')}
                </span>
              )}
              {!batch.is_active && (
                <span className={`inline-flex items-center rounded-full ${colorTokens.surface.muted} px-3 py-1 text-sm font-medium ${colorTokens.text.strong}`}>
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
              className={`inline-flex items-center gap-2 rounded-lg border ${colorTokens.intent.notice.borderStrong} px-4 py-2 text-sm font-medium ${colorTokens.intent.notice.text} ${colorTokens.variants.hoverBgOrange50}`}
            >
              <AlertTriangle className="h-4 w-4" />
              {t('batches:actions.recallBatch')}
            </button>
          )}
          {canEdit && (
            <Link
              to={`/inventory/batches/${uuid}/edit`}
              className={`inline-flex items-center gap-2 rounded-lg ${colorTokens.intent.primary.bgStrong} px-4 py-2 text-sm font-medium text-white ${colorTokens.intent.primary.bgStrongHover}`}
            >
              <Edit className="h-4 w-4" />
              {t('common:actions.edit')}
            </Link>
          )}
          {canDelete && (
            <button
              onClick={() => { setConfirmAction('delete'); }}
              className={`inline-flex items-center gap-2 rounded-lg border ${colorTokens.intent.danger.borderStrong} px-4 py-2 text-sm font-medium ${colorTokens.intent.danger.text} ${colorTokens.variants.hoverBgRed50}`}
            >
              <Trash2 className="h-4 w-4" />
              {t('common:actions.delete')}
            </button>
          )}
        </div>
      </div>

      {/* Product Information */}
      {batch.product && (
        <div className={`rounded-lg border ${colorTokens.border.subtle} bg-white p-6`}>
          <h2 className={`mb-4 flex items-center gap-2 text-lg font-semibold ${colorTokens.text.primary}`}>
            <Package className="h-5 w-5" />
            {t('batches:detail.productInfo')}
          </h2>
          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <label className={`text-sm ${colorTokens.text.subtle}`}>
                {t('batches:fields.product')}
              </label>
              <Link
                to={`/inventory/products/${batch.product.id}`}
                className={`block font-medium ${colorTokens.intent.primary.text} ${colorTokens.variants.hoverTextBlue800}`}
              >
                {batch.product.name}
              </Link>
            </div>
            <div>
              <label className={`text-sm ${colorTokens.text.subtle}`}>
                {t('common:fields.sku', 'SKU')}
              </label>
              <div className={`font-medium ${colorTokens.text.primary}`}>{batch.product.sku}</div>
            </div>
          </div>
        </div>
      )}

      {/* Batch Details */}
      <div className={`rounded-lg border ${colorTokens.border.subtle} bg-white p-6`}>
        <h2 className={`mb-4 flex items-center gap-2 text-lg font-semibold ${colorTokens.text.primary}`}>
          <Calendar className="h-5 w-5" />
          {t('batches:detail.title')}
        </h2>
        <div className="grid gap-4 sm:grid-cols-2">
          <div>
            <label className={`text-sm ${colorTokens.text.subtle}`}>
              {t('batches:fields.batchNumber')}
            </label>
            <div className={`font-medium ${colorTokens.text.primary}`}>{batch.batch_number}</div>
          </div>
          <div>
            <label className={`text-sm ${colorTokens.text.subtle}`}>
              {t('batches:fields.expiryDate')}
            </label>
            <div className={`font-medium ${colorTokens.text.primary}`}>
              {batch.expiry_date === null
                ? t('batches:fields.noExpiry', 'No expiry')
                : new Date(batch.expiry_date).toLocaleDateString()}
            </div>
          </div>
          {batch.manufacturing_date && (
            <div>
              <label className={`text-sm ${colorTokens.text.subtle}`}>
                {t('batches:fields.manufacturingDate')}
              </label>
              <div className={`font-medium ${colorTokens.text.primary}`}>
                {new Date(batch.manufacturing_date).toLocaleDateString()}
              </div>
            </div>
          )}
          {batch.days_until_expiry !== undefined && (
            <div>
              <label className={`text-sm ${colorTokens.text.subtle}`}>
                {t('batches:daysRemaining')}
              </label>
              <div className={`font-medium ${colorTokens.text.primary}`}>
                {batch.days_until_expiry} {t('batches:daysRemaining')}
              </div>
            </div>
          )}
          {batch.notes && (
            <div className="sm:col-span-2">
              <label className={`text-sm ${colorTokens.text.subtle}`}>
                {t('batches:fields.notes')}
              </label>
              <div className={`${colorTokens.text.primary}`}>{batch.notes}</div>
            </div>
          )}
          {batch.is_recalled && batch.recall_reason && (
            <div className="sm:col-span-2">
              <label className={`text-sm ${colorTokens.text.subtle}`}>
                {t('batches:fields.recallReason')}
              </label>
              <div className={`rounded-lg ${colorTokens.intent.danger.bgSubtle} p-3 ${colorTokens.intent.danger.textStrongest}`}>
                {batch.recall_reason}
              </div>
            </div>
          )}
        </div>
      </div>

      {/* Stock by Location */}
      {stockLevels && stockLevels.length > 0 && (
        <div className={`rounded-lg border ${colorTokens.border.subtle} bg-white p-6`}>
          <h2 className={`mb-4 text-lg font-semibold ${colorTokens.text.primary}`}>
            {t('batches:detail.stockByLocation')}
          </h2>
          <div className={`overflow-hidden rounded-lg border ${colorTokens.border.subtle}`}>
            <DataTable className={`min-w-full divide-y ${colorTokens.border.divider}`}>
              <thead className={`${colorTokens.surface.page}`}>
                <tr>
                  <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                    {t('batches:fields.location')}
                  </th>
                  <th className={`px-6 py-3 text-end text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                    {t('batches:fields.quantity')}
                  </th>
                  <th className={`px-6 py-3 text-end text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                    {t('batches:fields.reservedQuantity')}
                  </th>
                  <th className={`px-6 py-3 text-end text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                    {t('batches:fields.availableQuantity')}
                  </th>
                </tr>
              </thead>
              <tbody className={`divide-y ${colorTokens.border.divider} bg-white`}>
                {stockLevels.map((level) => (
                  <tr key={level.location_id}>
                    <td className={`whitespace-nowrap px-6 py-4 text-sm font-medium ${colorTokens.text.primary}`}>
                      {level.location_name}
                    </td>
                    <td className={`whitespace-nowrap px-6 py-4 text-end text-sm ${colorTokens.text.primary}`}>
                      {parseFloat(level.quantity).toFixed(decimals)}
                    </td>
                    <td className={`whitespace-nowrap px-6 py-4 text-end text-sm ${colorTokens.text.subtle}`}>
                      {parseFloat(level.reserved_quantity).toFixed(decimals)}
                    </td>
                    <td className={`whitespace-nowrap px-6 py-4 text-end text-sm font-medium ${colorTokens.text.primary}`}>
                      {parseFloat(level.available_quantity).toFixed(decimals)}
                    </td>
                  </tr>
                ))}
              </tbody>
              <tfoot className={`${colorTokens.surface.page}`}>
                <tr>
                  <td className={`px-6 py-3 text-sm font-semibold ${colorTokens.text.primary}`}>
                    {t('common:total')}
                  </td>
                  <td className={`px-6 py-3 text-end text-sm font-semibold ${colorTokens.text.primary}`}>
                    {stockLevels
                      .reduce((sum, level) => sum + parseFloat(level.quantity), 0)
                      .toFixed(decimals)}
                  </td>
                  <td className={`px-6 py-3 text-end text-sm font-semibold ${colorTokens.text.subtle}`}>
                    {stockLevels
                      .reduce((sum, level) => sum + parseFloat(level.reserved_quantity), 0)
                      .toFixed(decimals)}
                  </td>
                  <td className={`px-6 py-3 text-end text-sm font-semibold ${colorTokens.text.primary}`}>
                    {stockLevels
                      .reduce((sum, level) => sum + parseFloat(level.available_quantity), 0)
                      .toFixed(decimals)}
                  </td>
                </tr>
              </tfoot>
            </DataTable>
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
            <h3 className={`text-lg font-semibold ${colorTokens.text.primary}`}>{t('batches:actions.recallBatch')}</h3>
            <div className="mt-4 space-y-4">
              <p className={`text-sm ${colorTokens.text.subtle}`}>{t('batches:form.confirmRecall')}</p>
              <div>
                <label className={`block text-sm font-medium ${colorTokens.text.secondary}`}>
                  {t('batches:fields.recallReason')}
                </label>
                <textarea
                  value={recallReason}
                  onChange={(e) => { setRecallReason(e.target.value); }}
                  rows={3}
                  className={`mt-1 block w-full rounded-md ${colorTokens.border.default} shadow-sm ${colorTokens.variants.focusBorderBlue500} ${colorTokens.variants.focusRingBlue500} sm:text-sm`}
                  placeholder={t('batches:form.enterRecallReason')}
                />
              </div>
            </div>
            <div className="mt-6 flex justify-end gap-3">
              <button
                type="button"
                onClick={() => { setConfirmAction(null); setRecallReason('') }}
                className={`rounded-lg border ${colorTokens.border.default} px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.variants.hoverBgGray50} transition-colors`}
              >
                {t('common:actions.cancel')}
              </button>
              <button
                type="button"
                onClick={handleRecall}
                disabled={!recallReason.trim()}
                className={`rounded-lg ${colorTokens.intent.warning.bgStrong} px-4 py-2 text-sm font-medium text-white ${colorTokens.variants.hoverBgYellow700} disabled:opacity-50 transition-colors`}
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
