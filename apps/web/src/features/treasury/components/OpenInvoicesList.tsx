/**
 * OpenInvoicesList Component
 * Displays sortable list of open invoices for payment allocation
 */

import { useState, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { AlertCircle } from 'lucide-react'
import type { OpenInvoice, AllocationMethod, ManualAllocation } from '@/types/treasury'
import { useCurrency } from '@/hooks/useCurrency'

interface OpenInvoicesListProps {
  partnerId: string
  invoices: OpenInvoice[]
  allocationMethod: AllocationMethod
  selectedAllocations?: ManualAllocation[]
  onAllocationChange?: (allocations: ManualAllocation[]) => void
  isLoading?: boolean
}

type SortField = 'date' | 'due_date' | 'amount'

/**
 * List component showing open invoices for allocation
 *
 * Features:
 * - Sortable by date, due date, amount
 * - Manual selection with checkboxes (manual mode only)
 * - Amount input for each invoice (manual mode only)
 * - Overdue indicator
 * - Total balance calculation
 * - Select all / deselect all
 */
export function OpenInvoicesList({
  partnerId: _partnerId,
  invoices,
  allocationMethod,
  selectedAllocations = [],
  onAllocationChange,
  isLoading,
}: OpenInvoicesListProps) {
  const { t } = useTranslation(['treasury', 'common'])
  const { decimals } = useCurrency()
  const [sortField, setSortField] = useState<SortField>('due_date')
  const [sortDirection, setSortDirection] = useState<'asc' | 'desc'>('asc')

  // Sort invoices
  const sortedInvoices = useMemo(() => {
    return [...invoices].sort((a, b) => {
      let compareValue = 0

      switch (sortField) {
        case 'date':
          compareValue = new Date(a.document_date).getTime() - new Date(b.document_date).getTime()
          break
        case 'due_date':
          compareValue = new Date(a.due_date).getTime() - new Date(b.due_date).getTime()
          break
        case 'amount':
          compareValue = parseFloat(b.balance_due) - parseFloat(a.balance_due)
          break
      }

      return sortDirection === 'asc' ? compareValue : -compareValue
    })
  }, [invoices, sortField, sortDirection])

  // Calculate total balance
  const totalBalance = useMemo(() => {
    return invoices.reduce((sum, invoice) => {
      return sum + parseFloat(invoice.balance_due || '0')
    }, 0)
  }, [invoices])

  // Format amount to currency-aware decimals
  const formatAmount = (amount: string | number): string => {
    return parseFloat(String(amount)).toFixed(decimals)
  }

  // Check if invoice is selected
  const isSelected = (invoiceId: string): boolean => {
    return selectedAllocations.some((a) => a.document_id === invoiceId)
  }

  // Get allocation amount for invoice
  const getAllocationAmount = (invoiceId: string): string => {
    const allocation = selectedAllocations.find((a) => a.document_id === invoiceId)
    return allocation?.amount || ''
  }

  // Handle invoice selection (checkbox)
  const handleSelectInvoice = (invoice: OpenInvoice, checked: boolean) => {
    if (!onAllocationChange) return

    if (checked) {
      // Add to selection with remaining balance as default amount
      onAllocationChange([
        ...selectedAllocations,
        {
          document_id: invoice.id,
          amount: formatAmount(invoice.balance_due),
        },
      ])
    } else {
      // Remove from selection
      onAllocationChange(selectedAllocations.filter((a) => a.document_id !== invoice.id))
    }
  }

  // Handle amount change
  const handleAmountChange = (invoiceId: string, amount: string) => {
    if (!onAllocationChange) return

    onAllocationChange(
      selectedAllocations.map((a) =>
        a.document_id === invoiceId ? { ...a, amount } : a
      )
    )
  }

  // Select all invoices
  const handleSelectAll = () => {
    if (!onAllocationChange) return

    const allAllocations: ManualAllocation[] = sortedInvoices.map((invoice) => ({
      document_id: invoice.id,
      amount: formatAmount(invoice.balance_due),
    }))

    onAllocationChange(allAllocations)
  }

  // Deselect all invoices
  const handleDeselectAll = () => {
    if (!onAllocationChange) return
    onAllocationChange([])
  }

  // Change sort
  const handleSort = (field: SortField) => {
    if (sortField === field) {
      setSortDirection(sortDirection === 'asc' ? 'desc' : 'asc')
    } else {
      setSortField(field)
      setSortDirection('asc')
    }
  }

  const isManualMode = allocationMethod === 'manual'

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
  if (invoices.length === 0) {
    return (
      <div className="rounded-lg border border-gray-200 bg-gray-50 p-8 text-center">
        <AlertCircle className="mx-auto h-12 w-12 text-gray-400" />
        <p className="mt-2 text-sm text-gray-600">
          {t('treasury:smartPayment.openInvoices.noInvoices')}
        </p>
      </div>
    )
  }

  return (
    <div className="rounded-lg border border-gray-200 bg-white">
      {/* Header */}
      <div className="flex items-center justify-between border-b border-gray-200 bg-gray-50 px-4 py-3">
        <h3 className="text-sm font-medium text-gray-900">
          {t('treasury:smartPayment.openInvoices.title')}
        </h3>
        <div className="flex items-center gap-3">
          {/* Sort Dropdown */}
          <div className="flex items-center gap-2">
            <label className="text-sm text-gray-600">
              {t('treasury:smartPayment.openInvoices.sortBy')}:
            </label>
            <select
              value={sortField}
              onChange={(e) => { handleSort(e.target.value as SortField); }}
              className="rounded-md border border-gray-300 px-2 py-1 text-sm"
            >
              <option value="date">
                {t('treasury:smartPayment.openInvoices.sortByDate')}
              </option>
              <option value="due_date">
                {t('treasury:smartPayment.openInvoices.sortByDueDate')}
              </option>
              <option value="amount">
                {t('treasury:smartPayment.openInvoices.sortByAmount')}
              </option>
            </select>
          </div>

          {/* Select All / Deselect All (Manual mode only) */}
          {isManualMode && (
            <div className="flex gap-2">
              <button
                type="button"
                onClick={handleSelectAll}
                className="text-sm text-blue-600 hover:text-blue-700"
              >
                {t('treasury:smartPayment.openInvoices.selectAll')}
              </button>
              <span className="text-gray-400">|</span>
              <button
                type="button"
                onClick={handleDeselectAll}
                className="text-sm text-blue-600 hover:text-blue-700"
              >
                {t('treasury:smartPayment.openInvoices.deselectAll')}
              </button>
            </div>
          )}
        </div>
      </div>

      {/* Invoice Table */}
      <div className="overflow-x-auto">
        <table className="min-w-full divide-y divide-gray-200">
          <thead className="bg-gray-50">
            <tr>
              {isManualMode && (
                <th className="w-12 px-4 py-3"></th>
              )}
              <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('treasury:smartPayment.allocation.invoice')}
              </th>
              <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('treasury:smartPayment.allocation.originalBalance')}
              </th>
              {isManualMode && (
                <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('treasury:smartPayment.allocation.amountToAllocate')}
                </th>
              )}
              <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                Status
              </th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-200 bg-white">
            {sortedInvoices.map((invoice) => {
              const isOverdue = (invoice.days_overdue || 0) > 0
              const selected = isSelected(invoice.id)
              const allocationAmount = getAllocationAmount(invoice.id)
              const invoiceBalance = parseFloat(invoice.balance_due)

              return (
                <tr key={invoice.id} className={selected ? 'bg-blue-50' : 'hover:bg-gray-50'}>
                  {isManualMode && (
                    <td className="px-4 py-3">
                      <input
                        type="checkbox"
                        checked={selected}
                        onChange={(e) => { handleSelectInvoice(invoice, e.target.checked); }}
                        className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                      />
                    </td>
                  )}
                  <td className="whitespace-nowrap px-4 py-3 text-sm font-medium text-gray-900">
                    {invoice.document_number}
                  </td>
                  <td className="whitespace-nowrap px-4 py-3 text-sm text-gray-700">
                    {formatAmount(invoice.balance_due)}
                  </td>
                  {isManualMode && (
                    <td className="whitespace-nowrap px-4 py-3">
                      <input
                        type="number"
                        step="0.01"
                        min="0"
                        max={invoiceBalance}
                        value={allocationAmount}
                        onChange={(e) => { handleAmountChange(invoice.id, e.target.value); }}
                        disabled={!selected}
                        className="w-32 rounded-md border border-gray-300 px-2 py-1 text-sm disabled:bg-gray-100"
                      />
                    </td>
                  )}
                  <td className="whitespace-nowrap px-4 py-3 text-sm">
                    {isOverdue ? (
                      <div className="flex items-center gap-2">
                        <span className="inline-flex rounded-full bg-red-100 px-2 py-1 text-xs font-medium text-red-800">
                          {t('common:status.overdue')}
                        </span>
                        <span className="text-xs text-red-600">
                          {t('treasury:smartPayment.allocation.daysOverdue', {
                            days: invoice.days_overdue,
                          })}
                        </span>
                      </div>
                    ) : (
                      <span className="inline-flex rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-800">
                        {t('common:status.current')}
                      </span>
                    )}
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>

      {/* Footer with Total */}
      <div className="border-t border-gray-200 bg-gray-50 px-4 py-3">
        <div className="flex justify-between text-sm">
          <span className="font-medium text-gray-700">
            {t('treasury:smartPayment.openInvoices.totalBalance', {
              amount: formatAmount(totalBalance),
            })}
          </span>
        </div>
      </div>
    </div>
  )
}
