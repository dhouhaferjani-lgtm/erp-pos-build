import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { format } from 'date-fns'
import { Check, AlertTriangle, X } from 'lucide-react'
import { useReconciliation, useTriggerThirdCount, useManualOverride } from '../api/queries'
import { ManualOverrideDialog } from './ManualOverrideDialog'
import type { ReconciliationItem, CountingItemCount } from '../types'
import { cn } from '@/lib/utils'

interface Props {
  countingId: number
}

interface SummaryCardProps {
  label: string
  value: number
  variant?: 'default' | 'success' | 'warning'
}

function SummaryCard({ label, value, variant = 'default' }: SummaryCardProps) {
  return (
    <div
      className={cn(
        'p-4 rounded-lg border',
        variant === 'success' && 'bg-green-50 border-green-200',
        variant === 'warning' && 'bg-yellow-50 border-yellow-200',
        variant === 'default' && 'bg-white border-gray-200'
      )}
    >
      <div className="text-2xl font-bold">{value}</div>
      <div className="text-sm text-gray-500">{label}</div>
    </div>
  )
}

interface CountCellProps {
  count: CountingItemCount | null
  matchesTheoretical: boolean
}

function CountCell({ count, matchesTheoretical }: CountCellProps) {
  if (!count) {
    return <span className="text-gray-400">-</span>
  }

  return (
    <div className="group relative">
      <span
        className={cn('font-mono', matchesTheoretical && 'text-green-600')}
      >
        {count.qty}
      </span>
      {/* Tooltip */}
      <div className="absolute bottom-full left-1/2 -translate-x-1/2 mb-1 px-2 py-1 bg-gray-900 text-white text-xs rounded opacity-0 group-hover:opacity-100 transition-opacity whitespace-nowrap z-10">
        <div>{format(new Date(count.at), 'MMM d, h:mm a')}</div>
        {count.notes && <div className="mt-1 italic">{count.notes}</div>}
      </div>
    </div>
  )
}

export function ReconciliationTable({ countingId }: Props) {
  const { t } = useTranslation('inventory')
  const { data, isLoading } = useReconciliation(countingId)
  const triggerThirdCount = useTriggerThirdCount()
  const manualOverride = useManualOverride(countingId)

  const [selectedItems, setSelectedItems] = useState<number[]>([])
  const [overrideItem, setOverrideItem] = useState<ReconciliationItem | null>(
    null
  )

  if (isLoading) {
    return (
      <div className="p-8 text-center text-gray-500">
        {t('loading')}...
      </div>
    )
  }

  if (!data) {
    return (
      <div className="p-8 text-center text-gray-500">
        {t('noData')}
      </div>
    )
  }

  const { summary, items } = data

  const flaggedPendingItems = items.filter((i) => i.is_flagged && !i.final_qty)

  const handleSelectAll = (checked: boolean) => {
    if (checked) {
      setSelectedItems(flaggedPendingItems.map((i) => i.id))
    } else {
      setSelectedItems([])
    }
  }

  const handleSelect = (id: number, checked: boolean) => {
    if (checked) {
      setSelectedItems([...selectedItems, id])
    } else {
      setSelectedItems(selectedItems.filter((i) => i !== id))
    }
  }

  const handleBulkThirdCount = () => {
    triggerThirdCount.mutate({
      countingId,
      itemIds: selectedItems,
    })
    setSelectedItems([])
  }

  const handleOverride = (quantity: number, notes: string) => {
    if (!overrideItem) return

    manualOverride.mutate({
      itemId: overrideItem.id,
      quantity,
      notes,
    })
    setOverrideItem(null)
  }

  const getResolutionBadge = (item: ReconciliationItem) => {
    switch (item.resolution_method) {
      case 'auto_all_match':
        return (
          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
            <Check className="w-3 h-3 me-1" />
            {t('counting.reconciliation.allMatch')}
          </span>
        )
      case 'auto_counters_agree':
        return (
          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
            <AlertTriangle className="w-3 h-3 me-1" />
            {t('counting.reconciliation.variance')}
          </span>
        )
      case 'third_count_decisive':
        return (
          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
            {t('counting.reconciliation.thirdDecisive')}
          </span>
        )
      case 'manual_override':
        return (
          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
            {t('counting.reconciliation.override')}
          </span>
        )
      case 'pending':
        if (item.is_flagged) {
          return (
            <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
              <X className="w-3 h-3 me-1" />
              {t('counting.reconciliation.needsAction')}
            </span>
          )
        }
        return (
          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700">
            {t('counting.reconciliation.pending')}
          </span>
        )
    }
  }

  const getRowClassName = (item: ReconciliationItem) => {
    if (item.resolution_method === 'auto_all_match') {
      return 'bg-green-50/50'
    }
    if (item.resolution_method === 'auto_counters_agree') {
      return 'bg-yellow-50/50'
    }
    if (item.is_flagged && !item.final_qty) {
      return 'bg-red-50/50'
    }
    return ''
  }

  return (
    <div className="space-y-4">
      {/* Summary */}
      <div className="grid grid-cols-4 gap-4">
        <SummaryCard label={t('counting.reconciliation.totalItems')} value={summary.total} />
        <SummaryCard
          label={t('counting.reconciliation.autoResolved')}
          value={summary.auto_resolved}
          variant="success"
        />
        <SummaryCard
          label={t('counting.reconciliation.needsAttention')}
          value={summary.needs_attention}
          variant="warning"
        />
        <SummaryCard
          label={t('counting.reconciliation.manuallyOverridden')}
          value={summary.manually_overridden}
        />
      </div>

      {/* Bulk Actions */}
      {selectedItems.length > 0 && (
        <div className="flex items-center gap-4 p-3 bg-blue-50 rounded-lg">
          <span className="text-sm font-medium">
            {t('counting.reconciliation.itemsSelected', {
              count: selectedItems.length,
            })}
          </span>
          <button
            type="button"
            onClick={handleBulkThirdCount}
            disabled={triggerThirdCount.isPending}
            className="px-3 py-1.5 text-sm font-medium bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50"
          >
            {t('counting.reconciliation.addToThirdCount')}
          </button>
          <button
            type="button"
            onClick={() => { setSelectedItems([]); }}
            className="px-3 py-1.5 text-sm font-medium text-gray-700 hover:text-gray-900"
          >
            {t('clear')}
          </button>
        </div>
      )}

      {/* Table */}
      <div className="border rounded-lg overflow-hidden">
        <table className="min-w-full divide-y divide-gray-200">
          <thead className="bg-gray-50">
            <tr>
              <th className="w-12 px-4 py-3">
                <input
                  type="checkbox"
                  checked={
                    selectedItems.length > 0 &&
                    selectedItems.length === flaggedPendingItems.length
                  }
                  onChange={(e) => { handleSelectAll(e.target.checked); }}
                  className="rounded border-gray-300"
                />
              </th>
              <th className="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
                {t('counting.reconciliation.status')}
              </th>
              <th className="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
                {t('counting.reconciliation.product')}
              </th>
              <th className="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
                {t('counting.reconciliation.location')}
              </th>
              <th className="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                {t('counting.reconciliation.theoretical')}
              </th>
              <th className="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                {t('counting.count1')}
              </th>
              <th className="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                {t('counting.count2')}
              </th>
              <th className="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                {t('counting.count3')}
              </th>
              <th className="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                {t('counting.reconciliation.final')}
              </th>
              <th className="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                {t('counting.reconciliation.varianceShort')}
              </th>
              <th className="px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">
                {t('actionsLabel')}
              </th>
            </tr>
          </thead>
          <tbody className="bg-white divide-y divide-gray-200">
            {items.map((item) => (
              <tr key={item.id} className={getRowClassName(item)}>
                <td className="px-4 py-3">
                  {item.is_flagged && !item.final_qty && (
                    <input
                      type="checkbox"
                      checked={selectedItems.includes(item.id)}
                      onChange={(e) => { handleSelect(item.id, e.target.checked); }}
                      className="rounded border-gray-300"
                    />
                  )}
                </td>
                <td className="px-4 py-3">{getResolutionBadge(item)}</td>
                <td className="px-4 py-3">
                  <div>
                    <div className="font-medium text-gray-900">
                      {item.product.name}
                    </div>
                    <div className="text-sm text-gray-500">
                      {item.product.sku}
                    </div>
                  </div>
                </td>
                <td className="px-4 py-3 text-sm text-gray-500">
                  {item.location.code}
                </td>
                <td className="px-4 py-3 text-center font-mono">
                  {item.theoretical_qty}
                </td>
                <td className="px-4 py-3 text-center">
                  <CountCell
                    count={item.count_1}
                    matchesTheoretical={
                      item.count_1?.qty === item.theoretical_qty
                    }
                  />
                </td>
                <td className="px-4 py-3 text-center">
                  <CountCell
                    count={item.count_2}
                    matchesTheoretical={
                      item.count_2?.qty === item.theoretical_qty
                    }
                  />
                </td>
                <td className="px-4 py-3 text-center">
                  <CountCell
                    count={item.count_3}
                    matchesTheoretical={
                      item.count_3?.qty === item.theoretical_qty
                    }
                  />
                </td>
                <td className="px-4 py-3 text-center font-mono font-medium">
                  {item.final_qty ?? '-'}
                </td>
                <td className="px-4 py-3 text-center">
                  {item.variance !== null && (
                    <span
                      className={cn(
                        'font-mono',
                        item.variance > 0 && 'text-green-600',
                        item.variance < 0 && 'text-red-600'
                      )}
                    >
                      {item.variance > 0 ? '+' : ''}
                      {item.variance}
                    </span>
                  )}
                </td>
                <td className="px-4 py-3">
                  {item.is_flagged && !item.final_qty && (
                    <div className="flex gap-1">
                      <button
                        type="button"
                        onClick={() =>
                          { triggerThirdCount.mutate({
                            countingId,
                            itemIds: [item.id],
                          }); }
                        }
                        className="px-2 py-1 text-xs font-medium border border-gray-300 rounded hover:bg-gray-50"
                      >
                        {t('counting.reconciliation.thirdCount')}
                      </button>
                      <button
                        type="button"
                        onClick={() => { setOverrideItem(item); }}
                        className="px-2 py-1 text-xs font-medium text-gray-600 hover:text-gray-900"
                      >
                        {t('counting.reconciliation.override')}
                      </button>
                    </div>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Manual Override Dialog */}
      <ManualOverrideDialog
        open={!!overrideItem}
        item={overrideItem}
        onClose={() => { setOverrideItem(null); }}
        onSubmit={handleOverride}
        isLoading={manualOverride.isPending}
      />
    </div>
  )
}
