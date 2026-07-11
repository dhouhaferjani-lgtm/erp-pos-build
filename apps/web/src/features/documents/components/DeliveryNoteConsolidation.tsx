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
import { colorClasses } from '@/lib/designTokens'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

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
        <Loader2 className={`h-8 w-8 animate-spin ${colorClasses.textGray400}`} />
      </div>
    )
  }

  if (fetchError) {
    return (
      <div className={`rounded-lg ${colorClasses.bgRed50} p-4 ${colorClasses.textRed700}`}>
        {t('errors.loadingFailed')}
      </div>
    )
  }

  if (deliveryNotes.length === 0) {
    return (
      <div className={`rounded-lg border-2 border-dashed ${colorClasses.borderGray300} p-12 text-center`}>
        <FileText className={`mx-auto h-12 w-12 ${colorClasses.textGray400}`} />
        <h3 className={`mt-2 text-sm font-semibold ${colorClasses.textGray900}`}>
          {t('sales:deliveryNotes.consolidation.noDeliveryNotes')}
        </h3>
        <p className={`mt-1 text-sm ${colorClasses.textGray500}`}>
          {t('sales:deliveryNotes.consolidation.noDeliveryNotesDescription')}
        </p>
        {onCancel && (
          <button
            type="button"
            onClick={onCancel}
            className={`mt-4 text-sm ${colorClasses.textBlue600} ${colorClasses.hoverTextBlue800}`}
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
        <h2 className={`text-lg font-semibold ${colorClasses.textGray900}`}>
          {t('sales:deliveryNotes.consolidation.title')}
        </h2>
        <p className={`mt-1 text-sm ${colorClasses.textGray500}`}>
          {t('sales:deliveryNotes.consolidation.description')}
        </p>
      </div>

      {/* Error message */}
      {error && (
        <div className={`rounded-lg ${colorClasses.bgRed50} p-4 ${colorClasses.textRed700} flex items-center gap-2`}>
          <AlertCircle className="h-5 w-5 flex-shrink-0" />
          <span>{error}</span>
        </div>
      )}

      {/* Validation warning */}
      {selectionValidation.error && (
        <div className={`rounded-lg ${colorClasses.bgYellow50} p-4 ${colorClasses.textYellow700} flex items-center gap-2`}>
          <AlertCircle className="h-5 w-5 flex-shrink-0" />
          <span>{selectionValidation.error}</span>
        </div>
      )}

      {/* Delivery notes grouped by partner */}
      <div className="space-y-4">
        {Array.from(groupedByPartner.entries()).map(([partnerIdKey, partnerDns]) => {
          const partnerName = partnerDns[0]?.partner_name ?? t('status.unknown')
          const allSelected = partnerDns.every((dn) => selectedIds.has(dn.id))
          const someSelected = partnerDns.some((dn) => selectedIds.has(dn.id))

          return (
            <div
              key={partnerIdKey}
              className={`rounded-lg border ${colorClasses.borderGray200} bg-white overflow-hidden`}
            >
              {/* Partner header */}
              <div className={`${colorClasses.bgGray50} px-4 py-3 border-b ${colorClasses.borderGray200} flex items-center justify-between`}>
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
                    className={`h-4 w-4 rounded ${colorClasses.borderGray300} ${colorClasses.textBlue600} ${colorClasses.focusRingBlue500}`}
                  />
                  <span className={`font-medium ${colorClasses.textGray900}`}>{partnerName}</span>
                  <span className={`text-sm ${colorClasses.textGray500}`}>
                    ({partnerDns.length} {partnerDns.length === 1 ? t('sales:deliveryNotes.singular') : t('sales:deliveryNotes.plural')})
                  </span>
                </div>
              </div>

              {/* Delivery notes list */}
              <DataTable className={`min-w-full divide-y ${colorClasses.divideGray200}`}>
                <thead className={`${colorClasses.bgGray50}`}>
                  <tr>
                    <th className="w-12 px-4 py-3"></th>
                    <th className={`px-4 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                      {t('fields.documentNumber')}
                    </th>
                    <th className={`px-4 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                      {t('fields.date')}
                    </th>
                    <th className={`px-4 py-3 text-end text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                      {t('fields.amount')}
                    </th>
                  </tr>
                </thead>
                <tbody className={`divide-y ${colorClasses.divideGray200}`}>
                  {partnerDns.map((dn) => (
                    <tr
                      key={dn.id}
                      className={`${
                        selectedIds.has(dn.id) ? `${colorClasses.bgBlue50}` : `${colorClasses.hoverBgGray50}`
                      } cursor-pointer`}
                      onClick={() => { toggleSelection(dn.id); }}
                    >
                      <td className="px-4 py-3">
                        <input
                          type="checkbox"
                          checked={selectedIds.has(dn.id)}
                          onChange={() => { toggleSelection(dn.id); }}
                          onClick={(e) => { e.stopPropagation(); }}
                          className={`h-4 w-4 rounded ${colorClasses.borderGray300} ${colorClasses.textBlue600} ${colorClasses.focusRingBlue500}`}
                        />
                      </td>
                      <td className={`px-4 py-3 text-sm font-medium ${colorClasses.textGray900}`}>
                        {dn.document_number}
                      </td>
                      <td className={`px-4 py-3 text-sm ${colorClasses.textGray500}`}>
                        {new Date(dn.document_date).toLocaleDateString()}
                      </td>
                      <td className={`px-4 py-3 text-sm ${colorClasses.textGray900} text-end font-medium`}>
                        {formatCurrency(dn.total ?? 0, dn.currency)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </DataTable>
            </div>
          )
        })}
      </div>

      {/* Selection summary and actions */}
      {selectedDeliveryNotes.length > 0 && (
        <div className={`rounded-lg border ${colorClasses.borderBlue200} ${colorClasses.bgBlue50} p-4`}>
          <div className="flex items-center justify-between">
            <div>
              <div className={`flex items-center gap-2 ${colorClasses.textBlue800}`}>
                <CheckCircle className="h-5 w-5" />
                <span className="font-medium">
                  {selectedDeliveryNotes.length}{' '}
                  {selectedDeliveryNotes.length === 1
                    ? t('sales:deliveryNotes.singular')
                    : t('sales:deliveryNotes.plural')}{' '}
                  {t('sales:deliveryNotes.consolidation.selected')}
                </span>
              </div>
              <div className={`mt-2 text-sm ${colorClasses.textBlue700}`}>
                <span className="font-medium">{t('sales:deliveryNotes.consolidation.invoiceTotal')}:</span>{' '}
                {formatCurrency(totals.total, selectedDeliveryNotes[0]?.currency)}
                <span className={`ms-4 ${colorClasses.textBlue600}`}>
                  ({totals.lineCount} {totals.lineCount === 1 ? 'line' : 'lines'})
                </span>
              </div>
            </div>
            <div className="flex items-center gap-3">
              {onCancel && (
                <button
                  type="button"
                  onClick={onCancel}
                  className={`px-4 py-2 text-sm font-medium ${colorClasses.textGray700} bg-white border ${colorClasses.borderGray300} rounded-lg ${colorClasses.hoverBgGray50}`}
                >
                  {t('actions.cancel')}
                </button>
              )}
              <button
                type="button"
                onClick={handleConsolidate}
                disabled={!selectionValidation.valid || consolidateMutation.isPending}
                className={`inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white ${colorClasses.bgBlue600} rounded-lg ${colorClasses.hoverBgBlue700} disabled:opacity-50 disabled:cursor-not-allowed`}
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
