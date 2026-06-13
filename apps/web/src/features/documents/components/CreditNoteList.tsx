/**
 * CreditNoteList Component
 * Displays sortable and filterable list of credit notes
 */

import { useState, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { AlertCircle } from 'lucide-react'
import { useCurrency } from '@/hooks/useCurrency'
import type { CreditNote, CreditNoteReason } from '@/types/creditNote'

interface CreditNoteListProps {
  creditNotes: CreditNote[]
  onSelect: (creditNote: CreditNote) => void
  isLoading?: boolean
}

type SortField = 'date' | 'amount' | 'number'

/**
 * List component showing credit notes with sorting and filtering
 *
 * Features:
 * - Sortable by date, amount, number
 * - Filter by reason
 * - Clickable rows to view details
 * - Empty state handling
 * - Loading state
 */
export function CreditNoteList({
  creditNotes,
  onSelect,
  isLoading,
}: CreditNoteListProps) {
  const { t } = useTranslation(['sales', 'common'])
  const { decimals } = useCurrency()
  const [sortField, setSortField] = useState<SortField>('date')
  const [filterReason, setFilterReason] = useState<CreditNoteReason | 'all'>('all')

  // Format amount to currency-aware decimals
  const formatAmount = (amount: string): string => {
    return parseFloat(amount).toFixed(decimals)
  }

  // Format date to locale string
  const formatDate = (date: string): string => {
    return date // Keep ISO format for now, can enhance with locale formatting later
  }

  // Get reason label
  const getReasonLabel = (reason: CreditNoteReason): string => {
    return t(`sales:creditNotes.reasons.${reason}`)
  }

  // Filter credit notes by reason
  const filteredCreditNotes = useMemo(() => {
    if (filterReason === 'all') return creditNotes
    return creditNotes.filter((cn) => cn.reason === filterReason)
  }, [creditNotes, filterReason])

  // Sort credit notes
  const sortedCreditNotes = useMemo(() => {
    return [...filteredCreditNotes].sort((a, b) => {
      switch (sortField) {
        case 'date':
          // Newest first
          return new Date(b.document_date).getTime() - new Date(a.document_date).getTime()
        case 'amount':
          // Largest first
          return parseFloat(b.total) - parseFloat(a.total)
        case 'number':
          // Alphabetical
          return a.document_number.localeCompare(b.document_number)
        default:
          return 0
      }
    })
  }, [filteredCreditNotes, sortField])

  // Handle row click
  const handleRowClick = (creditNote: CreditNote) => {
    onSelect(creditNote)
  }

  // Loading state
  if (isLoading) {
    return (
      <div className="rounded-lg border border-gray-200 bg-white p-4">
        <div className="flex items-center gap-2">
          <div className="h-5 w-5 animate-spin rounded-full border-2 border-gray-300 border-t-blue-600" />
          <span className="text-sm text-gray-600">{t('common:status.loading')}</span>
        </div>
      </div>
    )
  }

  // Empty state
  if (creditNotes.length === 0) {
    return (
      <div className="rounded-lg border border-gray-200 bg-gray-50 p-8 text-center">
        <AlertCircle className="mx-auto h-12 w-12 text-gray-400" />
        <h3 className="mt-2 text-sm font-medium text-gray-900">
          {t('sales:creditNotes.empty.title')}
        </h3>
        <p className="mt-1 text-sm text-gray-600">
          {t('sales:creditNotes.empty.description')}
        </p>
      </div>
    )
  }

  return (
    <div className="rounded-lg border border-gray-200 bg-white">
      {/* Header */}
      <div className="flex items-center justify-between border-b border-gray-200 bg-gray-50 px-4 py-3">
        <h3 className="text-sm font-medium text-gray-900">
          {t('sales:creditNotes.title')}
        </h3>

        <div className="flex items-center gap-4">
          {/* Filter by Reason */}
          <div className="flex items-center gap-2">
            <label htmlFor="reason-filter" className="text-sm text-gray-600">
              {t('sales:creditNotes.filterByReason')}:
            </label>
            <select
              id="reason-filter"
              value={filterReason}
              onChange={(e) => { setFilterReason(e.target.value as CreditNoteReason | 'all'); }}
              className="rounded-md border border-gray-300 px-2 py-1 text-sm"
              aria-label={t('sales:creditNotes.filterByReason')}
            >
              <option value="all">{t('sales:creditNotes.allReasons')}</option>
              <option value="return">{t('sales:creditNotes.reasons.return')}</option>
              <option value="price_adjustment">
                {t('sales:creditNotes.reasons.price_adjustment')}
              </option>
              <option value="billing_error">
                {t('sales:creditNotes.reasons.billing_error')}
              </option>
              <option value="damaged_goods">
                {t('sales:creditNotes.reasons.damaged_goods')}
              </option>
              <option value="service_issue">
                {t('sales:creditNotes.reasons.service_issue')}
              </option>
              <option value="other">{t('sales:creditNotes.reasons.other')}</option>
            </select>
          </div>

          {/* Sort Dropdown */}
          <div className="flex items-center gap-2">
            <label htmlFor="sort-select" className="text-sm text-gray-600">
              {t('sales:creditNotes.sortBy')}:
            </label>
            <select
              id="sort-select"
              value={sortField}
              onChange={(e) => { setSortField(e.target.value as SortField); }}
              className="rounded-md border border-gray-300 px-2 py-1 text-sm"
              aria-label={t('sales:creditNotes.sortBy')}
            >
              <option value="date">{t('sales:creditNotes.sortByDate')}</option>
              <option value="amount">{t('sales:creditNotes.sortByAmount')}</option>
              <option value="number">{t('sales:creditNotes.sortByNumber')}</option>
            </select>
          </div>
        </div>
      </div>

      {/* Table */}
      <div className="overflow-x-auto">
        <table className="min-w-full divide-y divide-gray-200">
          <thead className="bg-gray-50">
            <tr>
              <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('sales:creditNotes.number')}
              </th>
              <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('sales:creditNotes.date')}
              </th>
              <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('sales:creditNotes.sourceInvoice')}
              </th>
              <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('sales:creditNotes.amount')}
              </th>
              <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('sales:creditNotes.reason')}
              </th>
              <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('sales:creditNotes.status')}
              </th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-200 bg-white">
            {sortedCreditNotes.map((creditNote) => (
              <tr
                key={creditNote.id}
                onClick={() => { handleRowClick(creditNote); }}
                className="cursor-pointer transition-colors hover:bg-gray-50"
              >
                <td className="whitespace-nowrap px-4 py-3 text-sm font-medium text-gray-900">
                  {creditNote.document_number}
                </td>
                <td className="whitespace-nowrap px-4 py-3 text-sm text-gray-700">
                  {formatDate(creditNote.document_date)}
                </td>
                <td className="whitespace-nowrap px-4 py-3 text-sm text-blue-600 hover:text-blue-800">
                  {creditNote.source_invoice_number}
                </td>
                <td className="whitespace-nowrap px-4 py-3 text-sm text-gray-700">
                  {formatAmount(creditNote.total)}
                </td>
                <td className="whitespace-nowrap px-4 py-3 text-sm text-gray-700">
                  {getReasonLabel(creditNote.reason)}
                </td>
                <td className="whitespace-nowrap px-4 py-3 text-sm">
                  {creditNote.status === 'posted' ? (
                    <span className="inline-flex rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-800">
                      {t('common:status.posted')}
                    </span>
                  ) : (
                    <span className="inline-flex rounded-full bg-gray-100 px-2 py-1 text-xs font-medium text-gray-800">
                      {t('common:status.draft')}
                    </span>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
