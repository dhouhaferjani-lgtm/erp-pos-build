/**
 * Return Note Detail Page
 * Displays full return note details with actions
 */

import { Link, useParams } from 'react-router-dom'
import { useQuery, useQueryClient, useMutation } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, FileText, Package, CreditCard, CheckCircle } from 'lucide-react'
import { toast } from 'sonner'
import { api } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import type { ReturnNote } from '@/types/returnNote'
import { useState } from 'react'

interface ReturnNoteResponse {
  data: ReturnNote
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

export function ReturnNoteDetailPage() {
  const { id } = useParams<{ id: string }>()
  const { t } = useTranslation(['sales', 'common'])
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const [showConfirmDialog, setShowConfirmDialog] = useState(false)

  // Fetch return note
  const { data, isLoading } = useQuery({
    queryKey: tenantScopedKey(['return-note', id]),
    queryFn: async () => {
      const response = await api.get<ReturnNoteResponse>(`/return-notes/${id}`)
      return response.data
    },
    enabled: tenantId !== null && companyId !== null && !!id,
  })

  // Confirm return note mutation
  const confirmMutation = useMutation({
    mutationFn: async () => {
      await api.post(`/return-notes/${id}/confirm`)
    },
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: tenantScopedKey(['return-note', id]) }),
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('return-notes', tenantId, companyId),
        }),
      ])
      toast.success(t('sales:returnNotes.messages.confirmed'))
      setShowConfirmDialog(false)
    },
    onError: (error: Error) => {
      toast.error(error.message)
    },
  })

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-96">
        <div className="inline-block h-8 w-8 animate-spin rounded-full border-4 border-solid border-blue-600 border-e-transparent"></div>
      </div>
    )
  }

  if (!data?.data) {
    return (
      <div className="text-center py-12">
        <p className="text-gray-500">{t('common:errors.notFound')}</p>
      </div>
    )
  }

  const returnNote = data.data
  const canConfirm = returnNote.status === 'draft'

  return (
    <div className="space-y-6">
      {/* Back Button */}
      <Link
        to="/sales/return-notes"
        className="inline-flex items-center gap-2 text-sm text-gray-600 hover:text-gray-900"
      >
        <ArrowLeft className="h-4 w-4" />
        {t('common:actions.back')}
      </Link>

      {/* Header */}
      <div className="flex items-start justify-between">
        <div>
          <div className="flex items-center gap-3">
            <h1 className="text-2xl font-semibold text-gray-900">
              {returnNote.document_number}
            </h1>
            <span
              className={`inline-flex rounded-full px-3 py-1 text-sm font-medium ${
                returnNote.status === 'confirmed'
                  ? 'bg-green-100 text-green-800'
                  : returnNote.status === 'cancelled'
                    ? 'bg-red-100 text-red-800'
                    : 'bg-gray-100 text-gray-800'
              }`}
            >
              {t(`sales:returnNotes.status.${returnNote.status}`)}
            </span>
          </div>
          <p className="mt-1 text-sm text-gray-500">
            {new Date(returnNote.document_date).toLocaleDateString()}
          </p>
        </div>

        {/* Actions */}
        <div className="flex gap-2">
          {canConfirm && (
            <button
              onClick={() => { setShowConfirmDialog(true) }}
              className="inline-flex items-center gap-2 rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700"
            >
              <CheckCircle className="h-4 w-4" />
              {t('common:actions.confirm')}
            </button>
          )}
        </div>
      </div>

      {/* Return Information Card */}
      <div className="rounded-lg border border-gray-200 bg-white p-6">
        <h2 className="text-lg font-medium text-gray-900 mb-4">
          {t('sales:returnNotes.title')}
        </h2>

        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          {/* Partner */}
          <div>
            <label className="block text-sm font-medium text-gray-700">
              {t('sales:documents.partner')}
            </label>
            <p className="mt-1 text-sm text-gray-900">
              {returnNote.partner?.name || '-'}
            </p>
          </div>

          {/* Return Reason */}
          <div>
            <label className="block text-sm font-medium text-gray-700">
              {t('sales:returnNotes.reason.title')}
            </label>
            <p className="mt-1 text-sm text-gray-900">
              {t(`sales:returnNotes.reason.${returnNote.metadata.return_reason}`)}
            </p>
          </div>

          {/* Return Condition */}
          {returnNote.metadata.return_condition && (
            <div>
              <label className="block text-sm font-medium text-gray-700">
                {t('sales:returnNotes.condition.title')}
              </label>
              <p className="mt-1 text-sm text-gray-900">
                {t(`sales:returnNotes.condition.${returnNote.metadata.return_condition}`)}
              </p>
            </div>
          )}

          {/* Refund Method */}
          {returnNote.metadata.refund_method && (
            <div>
              <label className="block text-sm font-medium text-gray-700">
                {t('sales:returnNotes.refundMethod.title')}
              </label>
              <p className="mt-1 text-sm text-gray-900">
                {t(`sales:returnNotes.refundMethod.${returnNote.metadata.refund_method}`)}
              </p>
            </div>
          )}

          {/* Notes */}
          {returnNote.metadata.notes && (
            <div className="md:col-span-2">
              <label className="block text-sm font-medium text-gray-700">
                {t('sales:returnNotes.notes')}
              </label>
              <p className="mt-1 text-sm text-gray-900">
                {returnNote.metadata.notes}
              </p>
            </div>
          )}
        </div>
      </div>

      {/* Source Documents */}
      <div className="rounded-lg border border-gray-200 bg-white p-6">
        <h2 className="text-lg font-medium text-gray-900 mb-4">
          {t('sales:relatedDocuments.sourceDocuments')}
        </h2>

        <div className="space-y-3">
          {returnNote.metadata.source_invoice_id && (
            <Link
              to={`/sales/invoices/${returnNote.metadata.source_invoice_id}`}
              className="flex items-center gap-3 p-3 rounded-lg border border-gray-200 hover:bg-gray-50 transition-colors"
            >
              <FileText className="h-5 w-5 text-gray-400" />
              <div className="flex-1">
                <p className="text-sm font-medium text-gray-900">
                  {t('sales:returnNotes.sourceInvoice')}
                </p>
                <p className="text-xs text-gray-500">
                  {t('common:actions.view')}
                </p>
              </div>
            </Link>
          )}

          {returnNote.metadata.source_delivery_note_id && (
            <Link
              to={`/sales/delivery-notes/${returnNote.metadata.source_delivery_note_id}`}
              className="flex items-center gap-3 p-3 rounded-lg border border-gray-200 hover:bg-gray-50 transition-colors"
            >
              <Package className="h-5 w-5 text-gray-400" />
              <div className="flex-1">
                <p className="text-sm font-medium text-gray-900">
                  {t('sales:returnNotes.sourceDeliveryNote')}
                </p>
                <p className="text-xs text-gray-500">
                  {t('common:actions.view')}
                </p>
              </div>
            </Link>
          )}
        </div>
      </div>

      {/* Linked Credit Note */}
      {returnNote.metadata.linked_credit_note_id && (
        <div className="rounded-lg border border-gray-200 bg-white p-6">
          <h2 className="text-lg font-medium text-gray-900 mb-4">
            {t('sales:returnNotes.linkedCreditNote')}
          </h2>

          <Link
            to={`/sales/credit-notes/${returnNote.metadata.linked_credit_note_id}`}
            className="flex items-center gap-3 p-3 rounded-lg border border-gray-200 hover:bg-gray-50 transition-colors"
          >
            <CreditCard className="h-5 w-5 text-gray-400" />
            <div className="flex-1">
              <p className="text-sm font-medium text-gray-900">
                {t('sales:documents.types.credit_note')}
              </p>
              <p className="text-xs text-gray-500">
                {t('common:actions.view')}
              </p>
            </div>
          </Link>
        </div>
      )}

      {/* Confirm Dialog */}
      <ConfirmDialog
        isOpen={showConfirmDialog}
        title={t('sales:returnNotes.confirmations.confirm.title')}
        message={t('sales:returnNotes.confirmations.confirm.message')}
        confirmText={t('sales:returnNotes.confirmations.confirm.button')}
        onConfirm={() => { confirmMutation.mutate() }}
        onClose={() => { setShowConfirmDialog(false) }}
        isLoading={confirmMutation.isPending}
        variant="info"
      />
    </div>
  )
}
