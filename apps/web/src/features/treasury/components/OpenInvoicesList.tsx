/**
 * OpenInvoicesList Component
 * Displays sortable list of open invoices for payment allocation
 */

import { useState, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { AlertCircle } from 'lucide-react'
import type { OpenInvoice, AllocationMethod, ManualAllocation } from '@/types/treasury'
import { useCurrency } from '@/hooks/useCurrency'
import { cn } from '@/lib/utils'
import { tokens, textColors, borderColors, colors } from '@/lib/designTokens'
import { Checkbox } from '@/components/atoms'
import { Spinner } from '@/components/atoms/Spinner'
import { Select } from '@/components/atoms/Select'
import { Button } from '@/components/atoms/Button'
import { MoneyInput } from '@/components/atoms/MoneyInput'
import { StatusBadge } from '@/components/atoms/StatusBadge'

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
  const { currency, decimals } = useCurrency()
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
    return allocation?.amount ?? ''
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
      <div className={cn('rounded-lg border bg-white p-4', borderColors.light)}>
        <div className="flex items-center gap-2">
          <Spinner size="sm" />
          <span className={cn('text-sm', textColors.tertiary)}>{t('common:status.loading')}</span>
        </div>
      </div>
    )
  }

  // Empty state
  if (invoices.length === 0) {
    return (
      <div className={cn('rounded-lg border p-8 text-center', colors.neutral[50], borderColors.light)}>
        <AlertCircle className={cn('mx-auto h-12 w-12', textColors.disabled)} />
        <p className={cn('mt-2 text-sm', textColors.tertiary)}>
          {t('treasury:smartPayment.openInvoices.noInvoices')}
        </p>
      </div>
    )
  }

  return (
    <div className={cn('rounded-lg border bg-white', borderColors.light)}>
      {/* Header */}
      <div className={cn('flex items-center justify-between border-b px-4 py-3', borderColors.light, tokens.table.header)}>
        <h3 className={cn('text-sm font-medium', textColors.primary)}>
          {t('treasury:smartPayment.openInvoices.title')}
        </h3>
        <div className="flex items-center gap-3">
          {/* Sort Dropdown */}
          <div className="flex items-center gap-2">
            <label className={cn('text-sm', textColors.tertiary)}>
              {t('treasury:smartPayment.openInvoices.sortBy')}:
            </label>
            <Select
              value={sortField}
              onChange={(e) => { handleSort(e.target.value as SortField); }}
              className="mt-0 w-auto px-2 py-1 text-sm"
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
            </Select>
          </div>

          {/* Select All / Deselect All (Manual mode only) */}
          {isManualMode && (
            <div className="flex items-center gap-2">
              <Button variant="ghost" size="sm" onClick={handleSelectAll}>
                {t('treasury:smartPayment.openInvoices.selectAll')}
              </Button>
              <span className={textColors.disabled}>|</span>
              <Button variant="ghost" size="sm" onClick={handleDeselectAll}>
                {t('treasury:smartPayment.openInvoices.deselectAll')}
              </Button>
            </div>
          )}
        </div>
      </div>

      {/* Invoice Table */}
      <div className="overflow-x-auto">
        <table className={cn('min-w-full divide-y', borderColors.divideDefault)}>
          <thead className={tokens.table.header}>
            <tr>
              {isManualMode && (
                <th className="w-12 px-4 py-3"></th>
              )}
              <th className={cn('px-4 py-3 text-start text-xs font-medium uppercase tracking-wider', textColors.tertiary)}>
                {t('treasury:smartPayment.allocation.invoice')}
              </th>
              <th className={cn('px-4 py-3 text-end text-xs font-medium uppercase tracking-wider', textColors.tertiary)}>
                {t('treasury:smartPayment.allocation.originalBalance')}
              </th>
              {isManualMode && (
                <th className={cn('px-4 py-3 text-start text-xs font-medium uppercase tracking-wider', textColors.tertiary)}>
                  {t('treasury:smartPayment.allocation.amountToAllocate')}
                </th>
              )}
              <th className={cn('px-4 py-3 text-start text-xs font-medium uppercase tracking-wider', textColors.tertiary)}>
                {t('common:fields.status')}
              </th>
            </tr>
          </thead>
          <tbody className={cn('divide-y bg-white', borderColors.divideDefault)}>
            {sortedInvoices.map((invoice) => {
              const isOverdue = (invoice.days_overdue || 0) > 0
              const selected = isSelected(invoice.id)
              const allocationAmount = getAllocationAmount(invoice.id)
              const invoiceBalance = parseFloat(invoice.balance_due)

              return (
                <tr key={invoice.id} className={selected ? tokens.alert.info : tokens.table.rowHover}>
                  {isManualMode && (
                    <td className="px-4 py-3">
                      <Checkbox
                        checked={selected}
                        onChange={(e) => { handleSelectInvoice(invoice, e.target.checked); }}
                      />
                    </td>
                  )}
                  <td className={cn('whitespace-nowrap px-4 py-3 text-sm font-medium', textColors.primary)}>
                    {invoice.document_number}
                  </td>
                  <td className={cn('whitespace-nowrap px-4 py-3 text-end text-sm tabular-nums', textColors.secondary)}>
                    {formatAmount(invoice.balance_due)}
                  </td>
                  {isManualMode && (
                    <td className="whitespace-nowrap px-4 py-3">
                      <MoneyInput
                        value={allocationAmount}
                        onChange={(value) => { handleAmountChange(invoice.id, value); }}
                        currency={currency}
                        min="0"
                        max={invoiceBalance}
                        disabled={!selected}
                        className="mt-0 w-32 px-2 py-1 text-sm"
                      />
                    </td>
                  )}
                  <td className="whitespace-nowrap px-4 py-3 text-sm">
                    {isOverdue ? (
                      <div className="flex items-center gap-2">
                        <StatusBadge tone="danger">
                          {t('common:status.overdue')}
                        </StatusBadge>
                        <span className={cn('text-xs', textColors.error)}>
                          {t('treasury:smartPayment.allocation.daysOverdue', {
                            days: invoice.days_overdue,
                          })}
                        </span>
                      </div>
                    ) : (
                      <StatusBadge tone="success">
                        {t('common:status.current')}
                      </StatusBadge>
                    )}
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>

      {/* Footer with Total */}
      <div className={cn('border-t px-4 py-3', borderColors.light, tokens.table.header)}>
        <div className="flex justify-between text-sm">
          <span className={cn('font-medium tabular-nums', textColors.secondary)}>
            {t('treasury:smartPayment.openInvoices.totalBalance', {
              amount: formatAmount(totalBalance),
            })}
          </span>
        </div>
      </div>
    </div>
  )
}
