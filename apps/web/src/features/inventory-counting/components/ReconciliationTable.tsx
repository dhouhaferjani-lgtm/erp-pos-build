import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { format } from 'date-fns'
import { Check, AlertTriangle, X } from 'lucide-react'
import {
  useReconciliation,
  useTriggerThirdCount,
  useManualOverride,
  useSetOpeningCost,
} from '../api/queries'
import { ManualOverrideDialog } from './ManualOverrideDialog'
import { isBlockingFlag, type ReconciliationItem, type CountingItemCount } from '../types'
import { cn } from '@/lib/utils'
import { bccomp, bcsub, formatQuantity, formatCurrency } from '@/lib/decimal'
import { getQuantityDecimals, QUANTITY_STORAGE_SCALE } from '@/lib/quantityScale'
import { MoneyInput } from '@/components/atoms/MoneyInput'
import { useCurrency } from '@/hooks/useCurrency'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

// Post-finalize backstop reasons: a line the replay skipped at apply time
// (negative-at-apply, or an opening line with no cost) that was therefore NOT
// posted. The deliberate v1 recovery path is a recount in a NEW session —
// surfaced as a hint under the chip so the reviewer knows the quantity is not in
// stock yet.
//
// 🚨 Campaign W4-6 removed `basket_window` from this set. A movement near the
// count instant is now an annotation, not a veto: the correction IS posted, so
// telling the reviewer to recount would be false. It must stay in step with
// CountingItemFlagReason::blocksStockApplication() on the backend.
const NOT_POSTED_RECOUNT_REASONS = new Set([
  'negative_at_apply',
  'pending_opening_cost',
])

// Chips for every flag reason on an item — blocking reasons use a warning style,
// informational reasons (normalized_agreement) a muted style. Skipped-at-apply
// reasons additionally show a "not posted — recount in a new session" hint.
function FlagChips({ item }: { item: ReconciliationItem }) {
  const { t } = useTranslation('inventory')
  const reasons = item.flag_reasons ?? []

  if (reasons.length === 0) {
    return null
  }

  const showRecountHint = reasons.some((r) => NOT_POSTED_RECOUNT_REASONS.has(r))

  return (
    <div className="mt-1 flex flex-wrap gap-1">
      {reasons.map((reason) => {
        const blocking = isBlockingFlag(reason)
        return (
          <span
            key={reason}
            data-testid={`flag-chip-${reason}`}
            className={cn(
              'inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium',
              blocking
                ? `${colorTokens.intent.caution.bgSoft} ${colorTokens.intent.caution.textStronger}`
                : `${colorTokens.surface.muted} ${colorTokens.text.muted}`
            )}
          >
            {blocking && <AlertTriangle className="w-3 h-3 me-1" />}
            {t(`counting.flags.${reason}`)}
          </span>
        )
      })}
      {showRecountHint && (
        <span
          data-testid="flag-hint-not-posted"
          className={`w-full text-xs ${colorTokens.text.subtle} italic`}
        >
          {t('counting.flags.notPostedRecount')}
        </span>
      )}
    </div>
  )
}

function ReplayCells({ item }: { item: ReconciliationItem }) {
  const { t } = useTranslation('inventory')
  const decimals = getQuantityDecimals(item.product)
  const expected = item.replay_preview?.expected_now ?? item.expected_qty_at_apply
  const movements = item.replay_preview?.movements_since_count ?? item.replay_audit?.replayedDelta
  const adjustment = item.replay_preview?.adjustment
    ?? (item.replay_audit === null
      ? null
      : bcsub(
          item.replay_audit.expectedAtApply,
          item.replay_audit.onHandAtApply,
          QUANTITY_STORAGE_SCALE
        ))
  const appliedBlockingReason = (item.flag_reasons ?? []).find((reason) =>
    NOT_POSTED_RECOUNT_REASONS.has(reason)
  )
  const previewBlockingReason = item.replay_preview?.blocked_reason ?? null
  const signedQuantity = (value: string): string => {
    const formatted = formatQuantity(value, decimals)
    if (/^-?0(?:\.0+)?$/.test(formatted)) return formatted.replace('-', '')
    return `${bccomp(value, '0') > 0 ? '+' : ''}${formatted}`
  }
  const adjustmentText = adjustment === null ? '-' : signedQuantity(adjustment)
  const reasonText = previewBlockingReason === null
    ? null
    : t(`counting.flags.${previewBlockingReason}`)

  return (
    <>
      <td className="px-4 py-3 text-center font-mono">
        {expected == null ? '-' : formatQuantity(expected, decimals)}
      </td>
      <td className="px-4 py-3 text-center font-mono">
        {item.replay_preview?.mode === 'legacy_delta' || movements == null
          ? '-'
          : <span className={cn(
              bccomp(movements, '0') > 0 && colorTokens.intent.success.text,
              bccomp(movements, '0') < 0 && colorTokens.intent.danger.text
            )}>{signedQuantity(movements)}</span>}
      </td>
      <td className="px-4 py-3 text-center">
        <div className={cn(
          'font-mono',
          adjustment !== null && bccomp(adjustment, '0') > 0 && colorTokens.intent.success.text,
          adjustment !== null && bccomp(adjustment, '0') < 0 && colorTokens.intent.danger.text
        )}>
          {appliedBlockingReason === undefined ? adjustmentText : '-'}
        </div>
        {item.replay_preview?.mode === 'legacy_delta' && (
          <div className={`mt-1 text-xs ${colorTokens.text.subtle}`}>
            {t('counting.reconciliation.legacyDelta')}
          </div>
        )}
        {item.replay_preview?.will_auto_post === false && reasonText !== null && (
          <div className={`mt-1 text-xs ${colorTokens.intent.caution.textStronger}`}>
            {t(
              previewBlockingReason === 'pending_opening_cost'
                ? 'counting.reconciliation.blocksFinalize'
                : 'counting.reconciliation.willNotAutoPost',
              { reason: reasonText }
            )}
          </div>
        )}
        {appliedBlockingReason !== undefined && (
          <div className={`mt-1 text-xs ${colorTokens.intent.caution.textStronger}`}>
            {t('counting.reconciliation.wasNotAutoPosted', {
              reason: t(`counting.flags.${appliedBlockingReason}`),
            })}
          </div>
        )}
      </td>
    </>
  )
}

interface Props {
  countingId: string
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
        variant === 'success' && `${colorTokens.intent.success.bgSubtle} ${colorTokens.intent.success.borderSubtle}`,
        variant === 'warning' && `${colorTokens.intent.warning.bgSubtle} ${colorTokens.intent.warning.borderSubtle}`,
        variant === 'default' && `${colorTokens.surface.base} ${colorTokens.border.subtle}`
      )}
    >
      <div className="text-2xl font-bold">{value}</div>
      <div className={`text-sm ${colorTokens.text.subtle}`}>{label}</div>
    </div>
  )
}

interface CountCellProps {
  count: CountingItemCount | null
  matchesTheoretical: boolean
  decimals: number
}

function CountCell({ count, matchesTheoretical, decimals }: CountCellProps) {
  if (!count) {
    return <span className={colorTokens.text.disabled}>-</span>
  }

  return (
    <div className="group relative">
      <span
        className={cn('font-mono', matchesTheoretical && colorTokens.intent.success.text)}
      >
        {formatQuantity(count.qty, decimals)}
      </span>
      {/* Tooltip */}
      <div className={`absolute bottom-full left-1/2 -translate-x-1/2 mb-1 px-2 py-1 ${colorTokens.surface.inverseStrong} ${colorTokens.text.inverse} text-xs rounded opacity-0 group-hover:opacity-100 transition-opacity whitespace-nowrap z-10`}>
        <div>{format(new Date(count.at), 'MMM d, h:mm a')}</div>
        {count.notes && <div className="mt-1 italic">{count.notes}</div>}
      </div>
    </div>
  )
}

// Inline opening-cost backfill cell for onboarding opening lines. Editable
// whenever the line WILL post as an opening (`will_post_as_opening`, computed
// pre-finalize) so the reviewer can enter — or explicitly zero — the cost before
// finalize. Emits a canonical decimal STRING (never a JS number) via MoneyInput.
function OpeningCostCell({
  item,
  onSave,
  isSaving,
}: {
  item: ReconciliationItem
  onSave: (itemId: string, unitCost: string) => void
  isSaving: boolean
}) {
  const { t } = useTranslation('inventory')
  const { currency } = useCurrency()
  const [cost, setCost] = useState(item.opening_unit_cost ?? '')

  if (!item.will_post_as_opening) {
    return (
      <span className="font-mono text-sm">
        {item.opening_unit_cost === null
          ? '-'
          : formatCurrency(item.opening_unit_cost, false)}
      </span>
    )
  }

  return (
    <div className="flex items-center gap-1">
      <div className="w-24">
        <MoneyInput
          aria-label={t('counting.reconciliation.openingCost')}
          value={cost}
          onChange={setCost}
          currency={currency}
        />
      </div>
      <button
        type="button"
        onClick={() => {
          if (cost.trim() !== '') {
            onSave(item.id, cost)
          }
        }}
        disabled={isSaving || cost.trim() === ''}
        className={`px-2 py-1 text-xs font-medium ${colorTokens.intent.primary.bgStrong} ${colorTokens.text.inverse} rounded ${colorTokens.intent.primary.bgStrongHover} disabled:opacity-50 disabled:cursor-not-allowed`}
      >
        {t('counting.reconciliation.saveCost')}
      </button>
    </div>
  )
}

export function ReconciliationTable({ countingId }: Props) {
  const { t } = useTranslation('inventory')
  const { data, isLoading } = useReconciliation(countingId)
  const triggerThirdCount = useTriggerThirdCount()
  const manualOverride = useManualOverride(countingId)
  const setOpeningCost = useSetOpeningCost(countingId)

  const handleSetOpeningCost = (itemId: string, unitCost: string) => {
    setOpeningCost.mutate({ itemId, unitCost })
  }

  const [selectedItems, setSelectedItems] = useState<string[]>([])
  const [overrideItem, setOverrideItem] = useState<ReconciliationItem | null>(
    null
  )

  if (isLoading) {
    return (
      <div className={`p-8 text-center ${colorTokens.text.subtle}`}>
        {t('loading')}...
      </div>
    )
  }

  if (!data) {
    return (
      <div className={`p-8 text-center ${colorTokens.text.subtle}`}>
        {t('noData')}
      </div>
    )
  }

  const { summary, items } = data

  // Explicit null check: final_qty is a scale-4 decimal STRING once resolved
  // (e.g. '0.0000' for a genuine zero count) — a falsy check would wrongly
  // treat a resolved zero-quantity item as still "needing action".
  const flaggedPendingItems = items.filter((i) => i.is_flagged && i.final_qty === null)

  const handleSelectAll = (checked: boolean) => {
    if (checked) {
      setSelectedItems(flaggedPendingItems.map((i) => i.id))
    } else {
      setSelectedItems([])
    }
  }

  const handleSelect = (id: string, checked: boolean) => {
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

  const handleOverride = (quantity: string, notes: string) => {
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
          <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${colorTokens.intent.success.bgSoft} ${colorTokens.intent.success.textStronger}`}>
            <Check className="w-3 h-3 me-1" />
            {t('counting.reconciliation.allMatch')}
          </span>
        )
      case 'auto_counters_agree':
        return (
          <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${colorTokens.intent.warning.bgSoft} ${colorTokens.intent.warning.textStronger}`}>
            <AlertTriangle className="w-3 h-3 me-1" />
            {t('counting.reconciliation.variance')}
          </span>
        )
      case 'third_count_decisive':
        return (
          <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${colorTokens.intent.primary.bgSoft} ${colorTokens.intent.primary.textStronger}`}>
            {t('counting.reconciliation.thirdDecisive')}
          </span>
        )
      case 'manual_override':
        return (
          <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${colorTokens.intent.accent.bgSoft} ${colorTokens.intent.accent.textStronger}`}>
            {t('counting.reconciliation.override')}
          </span>
        )
      case 'pending':
        if (item.is_flagged) {
          return (
            <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${colorTokens.intent.danger.bgSoft} ${colorTokens.intent.danger.textStronger}`}>
              <X className="w-3 h-3 me-1" />
              {t('counting.reconciliation.needsAction')}
            </span>
          )
        }
        return (
          <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${colorTokens.surface.muted} ${colorTokens.text.secondary}`}>
            {t('counting.reconciliation.pending')}
          </span>
        )
    }
  }

  const getRowClassName = (item: ReconciliationItem) => {
    if (item.resolution_method === 'auto_all_match') {
      return colorTokens.intent.success.bgSubtleAlpha
    }
    if (item.resolution_method === 'auto_counters_agree') {
      return colorTokens.intent.warning.bgSubtleAlpha
    }
    if (item.is_flagged && item.final_qty === null) {
      return colorTokens.intent.danger.bgSubtleAlpha
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
        <div className={`flex items-center gap-4 p-3 ${colorTokens.intent.primary.bgSubtle} rounded-lg`}>
          <span className="text-sm font-medium">
            {t('counting.reconciliation.itemsSelected', {
              count: selectedItems.length,
            })}
          </span>
          <button
            type="button"
            onClick={handleBulkThirdCount}
            disabled={triggerThirdCount.isPending}
            className={`px-3 py-1.5 text-sm font-medium ${colorTokens.intent.primary.bgStrong} ${colorTokens.text.inverse} rounded-md ${colorTokens.intent.primary.bgStrongHover} disabled:opacity-50`}
          >
            {t('counting.reconciliation.addToThirdCount')}
          </button>
          <button
            type="button"
            onClick={() => { setSelectedItems([]); }}
            className={`px-3 py-1.5 text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.intent.neutral.textHoverStrongest}`}
          >
            {t('clear')}
          </button>
        </div>
      )}

      {/* Table */}
      <div className="border rounded-lg overflow-x-auto">
        <DataTable className={`min-w-full divide-y ${colorTokens.border.divider}`}>
          <thead className={colorTokens.surface.page}>
            <tr>
              <th className="w-12 px-4 py-3">
                <input
                  type="checkbox"
                  checked={
                    selectedItems.length > 0 &&
                    selectedItems.length === flaggedPendingItems.length
                  }
                  onChange={(e) => { handleSelectAll(e.target.checked); }}
                  className={`rounded ${colorTokens.border.default}`}
                />
              </th>
              <th className={`px-4 py-3 text-start text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                {t('counting.reconciliation.status')}
              </th>
              <th className={`px-4 py-3 text-start text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                {t('counting.reconciliation.product')}
              </th>
              <th className={`px-4 py-3 text-start text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                {t('counting.reconciliation.location')}
              </th>
              <th className={`px-4 py-3 text-center text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                {t('counting.reconciliation.theoretical')}
              </th>
              <th className={`px-4 py-3 text-center text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                {t('counting.count1')}
              </th>
              <th className={`px-4 py-3 text-center text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                {t('counting.count2')}
              </th>
              <th className={`px-4 py-3 text-center text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                {t('counting.count3')}
              </th>
              <th className={`px-4 py-3 text-center text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                {t('counting.reconciliation.final')}
              </th>
              <th className={`px-4 py-3 text-center text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                {t('counting.reconciliation.expectedNow')}
              </th>
              <th className={`px-4 py-3 text-center text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                {t('counting.reconciliation.movementsSinceCount')}
              </th>
              <th className={`px-4 py-3 text-center text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                {t('counting.reconciliation.adjustmentToPost')}
              </th>
              <th className={`px-4 py-3 text-center text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                {t('counting.reconciliation.openingCost')}
              </th>
              <th className={`px-4 py-3 text-center text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                {t('counting.reconciliation.varianceShort')}
              </th>
              <th className={`px-4 py-3 text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                {t('actionsLabel')}
              </th>
            </tr>
          </thead>
          <tbody className={`${colorTokens.surface.base} divide-y ${colorTokens.border.divider}`}>
            {items.map((item) => (
              <tr key={item.id} className={getRowClassName(item)}>
                <td className="px-4 py-3">
                  {item.is_flagged && item.final_qty === null && (
                    <input
                      type="checkbox"
                      checked={selectedItems.includes(item.id)}
                      onChange={(e) => { handleSelect(item.id, e.target.checked); }}
                      className={`rounded ${colorTokens.border.default}`}
                    />
                  )}
                </td>
                <td className="px-4 py-3">
                  {getResolutionBadge(item)}
                  <FlagChips item={item} />
                </td>
                <td className="px-4 py-3">
                  <div>
                    <div className={`font-medium ${colorTokens.text.primary}`}>
                      {item.product.name}
                    </div>
                    <div className={`text-sm ${colorTokens.text.subtle}`}>
                      {item.product.sku}
                    </div>
                  </div>
                </td>
                <td className={`px-4 py-3 text-sm ${colorTokens.text.subtle}`}>
                  {item.location.code}
                </td>
                <td className="px-4 py-3 text-center font-mono">
                  {formatQuantity(item.theoretical_qty, getQuantityDecimals(item.product))}
                </td>
                <td className="px-4 py-3 text-center">
                  <CountCell
                    count={item.count_1}
                    matchesTheoretical={
                      item.count_1?.qty === item.theoretical_qty
                    }
                    decimals={getQuantityDecimals(item.product)}
                  />
                </td>
                <td className="px-4 py-3 text-center">
                  <CountCell
                    count={item.count_2}
                    matchesTheoretical={
                      item.count_2?.qty === item.theoretical_qty
                    }
                    decimals={getQuantityDecimals(item.product)}
                  />
                </td>
                <td className="px-4 py-3 text-center">
                  <CountCell
                    count={item.count_3}
                    matchesTheoretical={
                      item.count_3?.qty === item.theoretical_qty
                    }
                    decimals={getQuantityDecimals(item.product)}
                  />
                </td>
                <td className="px-4 py-3 text-center font-mono font-medium">
                  {item.final_qty === null ? '-' : formatQuantity(item.final_qty, getQuantityDecimals(item.product))}
                </td>
                <ReplayCells item={item} />
                <td className="px-4 py-3 text-center">
                  <OpeningCostCell
                    item={item}
                    onSave={handleSetOpeningCost}
                    isSaving={setOpeningCost.isPending}
                  />
                </td>
                <td className="px-4 py-3 text-center">
                  {item.variance !== null && (
                    <span
                      className={cn(
                        'font-mono',
                        item.variance > 0 && colorTokens.intent.success.text,
                        item.variance < 0 && colorTokens.intent.danger.text
                      )}
                    >
                      {item.variance > 0 ? '+' : ''}
                      {item.variance}
                    </span>
                  )}
                </td>
                <td className="px-4 py-3">
                  {item.is_flagged && item.final_qty === null && (
                    <div className="flex gap-1">
                      <button
                        type="button"
                        onClick={() =>
                          { triggerThirdCount.mutate({
                            countingId,
                            itemIds: [item.id],
                          }); }
                        }
                        className={`px-2 py-1 text-xs font-medium border ${colorTokens.border.default} rounded ${colorTokens.intent.neutral.bgHover}`}
                      >
                        {t('counting.reconciliation.thirdCount')}
                      </button>
                      <button
                        type="button"
                        onClick={() => { setOverrideItem(item); }}
                        className={`px-2 py-1 text-xs font-medium ${colorTokens.text.muted} ${colorTokens.intent.neutral.textHoverStrongest}`}
                      >
                        {t('counting.reconciliation.override')}
                      </button>
                    </div>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </DataTable>
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
