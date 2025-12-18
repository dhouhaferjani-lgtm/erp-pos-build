/**
 * Delivery Note Consolidation Component
 *
 * Tunisia Model: Creates a single invoice from multiple confirmed delivery notes.
 * All DNs must belong to the same partner and use the same currency.
 */

import { useState, useMemo } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { FileText, CheckCircle, AlertCircle, Loader2 } from 'lucide-react'
import {
  useInvoiceableDeliveryNotes,
  useConsolidateDeliveryNotes,
  groupDeliveryNotesByPartner,
  calculateConsolidationTotals,
} from '../hooks/useDeliveryNotes'
import type { DeliveryNote } from '../api/deliveryNotes'
import { getErrorMessage } from '@/lib/api'
import { useCompany } from '@/hooks/useCompany'

interface DeliveryNoteConsolidationProps {
  /**
   * Optional partner ID to pre-filter delivery notes.
   * If provided, only shows DNs for this partner.
   */
  partnerId?: string
  /**
   * Callback when consolidation is successful.
   * If not provided, navigates to the new invoice.
   */
  onSuccess?: (invoiceId: string) => void
  /**
   * Callback when user cancels.
   */
  onCancel?: () => void
}

export function DeliveryNoteConsolidation({
  partnerId,
  onSuccess,
  onCancel,
}: DeliveryNoteConsolidationProps) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { currentCompany } = useCompany()
  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set())
  const [error, setError] = useState<string | null>(null)

  // Fetch invoiceable delivery notes
  const {
    data: deliveryNotes = [],
    isLoading,
    error: fetchError,
  } = useInvoiceableDeliveryNotes(partnerId)

  // Consolidation mutation
  const consolidateMutation = useConsolidateDeliveryNotes()

  // Group delivery notes by partner
  const groupedByPartner = useMemo(
    () => groupDeliveryNotesByPartner(deliveryNotes),
    [deliveryNotes]
  )

  // Get selected delivery notes
  const selectedDeliveryNotes = useMemo(
    () => deliveryNotes.filter((dn) => selectedIds.has(dn.id)),
    [deliveryNotes, selectedIds]
  )

  // Calculate totals for selected DNs
  const totals = useMemo(
    () => calculateConsolidationTotals(selectedDeliveryNotes),
    [selectedDeliveryNotes]
  )

  // Check if selection is valid (all from same partner)
  const selectionValidation = useMemo(() => {
    if (selectedDeliveryNotes.length === 0) {
      return { valid: false, error: null }
    }

    const partners = new Set(selectedDeliveryNotes.map((dn) => dn.partner_id))
    if (partners.size > 1) {
      return {
        valid: false,
        error: t('sales:deliveryNotes.consolidation.errors.differentPartners'),
      }
    }

    const currencies = new Set(selectedDeliveryNotes.map((dn) => dn.currency))
    if (currencies.size > 1) {
      return {
        valid: false,
        error: t('sales:deliveryNotes.consolidation.errors.differentCurrencies'),
      }
    }

    return { valid: true, error: null }
  }, [selectedDeliveryNotes, t])

  // Toggle selection of a delivery note
  const toggleSelection = (id: string) => {
    setSelectedIds((prev) => {
      const next = new Set(prev)
      if (next.has(id)) {
        next.delete(id)
      } else {
        next.add(id)
      }
      return next
    })
    setError(null)
  }

  // Select all DNs for a partner
  const selectAllForPartner = (partnerDns: DeliveryNote[]) => {
    setSelectedIds((prev) => {
      const next = new Set(prev)
      const allSelected = partnerDns.every((dn) => next.has(dn.id))
      if (allSelected) {
        // Deselect all
        partnerDns.forEach((dn) => next.delete(dn.id))
      } else {
        // Select all
        partnerDns.forEach((dn) => next.add(dn.id))
      }
      return next
    })
    setError(null)
  }

  // Handle consolidation
  const handleConsolidate = async () => {
    if (!selectionValidation.valid) {
      setError(selectionValidation.error ?? t('sales:deliveryNotes.consolidation.errors.invalidSelection'))
      return
    }

    setError(null)

    try {
      const response = await consolidateMutation.mutateAsync(
        Array.from(selectedIds)
      )
      if (onSuccess) {
        onSuccess(response.data.id)
      } else {
        navigate(`/sales/invoices/${response.data.id}`)
      }
    } catch (err) {
      setError(getErrorMessage(err))
    }
  }

  // Format currency
  const formatCurrency = (amount: number | string, currency?: string) => {
    const currencyCode = currency || currentCompany?.currency || 'TND'
    const num = typeof amount === 'string' ? parseFloat(amount) : amount
    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency: currencyCode,
    }).format(num)
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <Loader2 className="h-8 w-8 animate-spin text-gray-400" />
      </div>
    )
  }

  if (fetchError) {
    return (
      <div className="rounded-lg bg-red-50 p-4 text-red-700">
        {t('errors.loadingFailed')}
      </div>
    )
  }

  if (deliveryNotes.length === 0) {
    return (
      <div className="rounded-lg border-2 border-dashed border-gray-300 p-12 text-center">
        <FileText className="mx-auto h-12 w-12 text-gray-400" />
        <h3 className="mt-2 text-sm font-semibold text-gray-900">
          {t('sales:deliveryNotes.consolidation.noDeliveryNotes')}
        </h3>
        <p className="mt-1 text-sm text-gray-500">
          {t('sales:deliveryNotes.consolidation.noDeliveryNotesDescription')}
        </p>
        {onCancel && (
          <button
            type="button"
            onClick={onCancel}
            className="mt-4 text-sm text-blue-600 hover:text-blue-800"
          >
            {t('actions.back')}
          </button>
        )}
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <h2 className="text-lg font-semibold text-gray-900">
          {t('sales:deliveryNotes.consolidation.title')}
        </h2>
        <p className="mt-1 text-sm text-gray-500">
          {t('sales:deliveryNotes.consolidation.description')}
        </p>
      </div>

      {/* Error message */}
      {error && (
        <div className="rounded-lg bg-red-50 p-4 text-red-700 flex items-center gap-2">
          <AlertCircle className="h-5 w-5 flex-shrink-0" />
          <span>{error}</span>
        </div>
      )}

      {/* Validation warning */}
      {selectionValidation.error && (
        <div className="rounded-lg bg-yellow-50 p-4 text-yellow-700 flex items-center gap-2">
          <AlertCircle className="h-5 w-5 flex-shrink-0" />
          <span>{selectionValidation.error}</span>
        </div>
      )}

      {/* Delivery notes grouped by partner */}
      <div className="space-y-4">
        {Array.from(groupedByPartner.entries()).map(([partnerIdKey, partnerDns]) => {
          const partnerName = partnerDns[0]?.partner_name ?? t('common.unknown')
          const allSelected = partnerDns.every((dn) => selectedIds.has(dn.id))
          const someSelected = partnerDns.some((dn) => selectedIds.has(dn.id))

          return (
            <div
              key={partnerIdKey}
              className="rounded-lg border border-gray-200 bg-white overflow-hidden"
            >
              {/* Partner header */}
              <div className="bg-gray-50 px-4 py-3 border-b border-gray-200 flex items-center justify-between">
                <div className="flex items-center gap-3">
                  <input
                    type="checkbox"
                    checked={allSelected}
                    ref={(el) => {
                      if (el) {
                        el.indeterminate = someSelected && !allSelected
                      }
                    }}
                    onChange={() => { selectAllForPartner(partnerDns); }}
                    className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                  />
                  <span className="font-medium text-gray-900">{partnerName}</span>
                  <span className="text-sm text-gray-500">
                    ({partnerDns.length} {partnerDns.length === 1 ? t('sales:deliveryNotes.singular') : t('sales:deliveryNotes.plural')})
                  </span>
                </div>
              </div>

              {/* Delivery notes list */}
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="w-12 px-4 py-3"></th>
                    <th className="px-4 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                      {t('fields.documentNumber')}
                    </th>
                    <th className="px-4 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                      {t('fields.date')}
                    </th>
                    <th className="px-4 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                      {t('fields.amount')}
                    </th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200">
                  {partnerDns.map((dn) => (
                    <tr
                      key={dn.id}
                      className={`${
                        selectedIds.has(dn.id) ? 'bg-blue-50' : 'hover:bg-gray-50'
                      } cursor-pointer`}
                      onClick={() => { toggleSelection(dn.id); }}
                    >
                      <td className="px-4 py-3">
                        <input
                          type="checkbox"
                          checked={selectedIds.has(dn.id)}
                          onChange={() => { toggleSelection(dn.id); }}
                          onClick={(e) => { e.stopPropagation(); }}
                          className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                        />
                      </td>
                      <td className="px-4 py-3 text-sm font-medium text-gray-900">
                        {dn.document_number}
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-500">
                        {new Date(dn.document_date).toLocaleDateString()}
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-900 text-end font-medium">
                        {formatCurrency(dn.total ?? 0, dn.currency)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )
        })}
      </div>

      {/* Selection summary and actions */}
      {selectedDeliveryNotes.length > 0 && (
        <div className="rounded-lg border border-blue-200 bg-blue-50 p-4">
          <div className="flex items-center justify-between">
            <div>
              <div className="flex items-center gap-2 text-blue-800">
                <CheckCircle className="h-5 w-5" />
                <span className="font-medium">
                  {selectedDeliveryNotes.length}{' '}
                  {selectedDeliveryNotes.length === 1
                    ? t('sales:deliveryNotes.singular')
                    : t('sales:deliveryNotes.plural')}{' '}
                  {t('sales:deliveryNotes.consolidation.selected')}
                </span>
              </div>
              <div className="mt-2 text-sm text-blue-700">
                <span className="font-medium">{t('sales:deliveryNotes.consolidation.invoiceTotal')}:</span>{' '}
                {formatCurrency(totals.total, selectedDeliveryNotes[0]?.currency)}
                <span className="ms-4 text-blue-600">
                  ({totals.lineCount} {totals.lineCount === 1 ? 'line' : 'lines'})
                </span>
              </div>
            </div>
            <div className="flex items-center gap-3">
              {onCancel && (
                <button
                  type="button"
                  onClick={onCancel}
                  className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50"
                >
                  {t('actions.cancel')}
                </button>
              )}
              <button
                type="button"
                onClick={handleConsolidate}
                disabled={!selectionValidation.valid || consolidateMutation.isPending}
                className="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {consolidateMutation.isPending ? (
                  <>
                    <Loader2 className="h-4 w-4 animate-spin" />
                    {t('status.processing')}
                  </>
                ) : (
                  t('sales:deliveryNotes.consolidation.createInvoice')
                )}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
