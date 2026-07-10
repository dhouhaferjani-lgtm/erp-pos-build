import { useState } from 'react'
import { createPortal } from 'react-dom'
import { useTranslation } from 'react-i18next'
import type { ReconciliationItem } from '../types'
import { formatQuantity } from '@/lib/decimal'
import { QuantityInput } from '@/components/atoms/QuantityInput'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface Props {
  open: boolean
  item: ReconciliationItem | null
  onClose: () => void
  onSubmit: (quantity: string, notes: string) => void
  isLoading: boolean
}

// Inner component that receives non-null item, uses key to reset state
function ManualOverrideDialogContent({
  item,
  onClose,
  onSubmit,
  isLoading,
}: Omit<Props, 'open'> & { item: ReconciliationItem }) {
  const { t } = useTranslation('inventory')
  // Initialize with count_1 qty as starting point. Both are already
  // canonical scale-4 decimal strings — never parsed through a JS number.
  const initialQty = item.count_1?.qty ?? item.theoretical_qty
  const [quantity, setQuantity] = useState(initialQty)
  const [notes, setNotes] = useState('')

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    // No parseFloat/Number() on the quantity — the raw canonical string is
    // sent as-is; the backend's `numeric` validation + bcadd normalization
    // is the single source of truth for scale (see ManualOverrideRequest).
    if (quantity.trim() !== '' && notes.trim()) {
      onSubmit(quantity, notes.trim())
    }
  }

  return createPortal(
    <div className="fixed inset-0 z-50 overflow-y-auto">
      {/* Backdrop */}
      <div
        className={`fixed inset-0 ${colorTokens.surface.overlay} transition-opacity`}
        onClick={onClose}
      />

      {/* Dialog */}
      <div className="flex min-h-full items-center justify-center p-4">
        <div className={`relative ${colorTokens.surface.base} rounded-lg shadow-xl max-w-md w-full p-6`}>
          <h2 className="text-lg font-semibold mb-4">
            {t('counting.reconciliation.manualOverride')}
          </h2>

          {/* Product Info */}
          <div className={`${colorTokens.surface.page} rounded-lg p-3 mb-4`}>
            <div className="font-medium">{item.product.name}</div>
            <div className={`text-sm ${colorTokens.text.subtle}`}>
              {item.product.sku} - {item.location.code}
            </div>
          </div>

          {/* Current counts */}
          <div className="grid grid-cols-4 gap-2 mb-4 text-sm">
            <div className={`${colorTokens.surface.muted} rounded p-2 text-center`}>
              <div className={`${colorTokens.text.subtle}`}>{t('counting.reconciliation.theoretical')}</div>
              <div className="font-mono font-medium">{formatQuantity(item.theoretical_qty)}</div>
            </div>
            <div className={`${colorTokens.surface.muted} rounded p-2 text-center`}>
              <div className={`${colorTokens.text.subtle}`}>{t('counting.count1')}</div>
              <div className="font-mono font-medium">
                {item.count_1 ? formatQuantity(item.count_1.qty) : '-'}
              </div>
            </div>
            <div className={`${colorTokens.surface.muted} rounded p-2 text-center`}>
              <div className={`${colorTokens.text.subtle}`}>{t('counting.count2')}</div>
              <div className="font-mono font-medium">
                {item.count_2 ? formatQuantity(item.count_2.qty) : '-'}
              </div>
            </div>
            <div className={`${colorTokens.surface.muted} rounded p-2 text-center`}>
              <div className={`${colorTokens.text.subtle}`}>{t('counting.count3')}</div>
              <div className="font-mono font-medium">
                {item.count_3 ? formatQuantity(item.count_3.qty) : '-'}
              </div>
            </div>
          </div>

          <form onSubmit={handleSubmit}>
            {/* Quantity Input */}
            <div className="mb-4">
              <label htmlFor="manual-override-quantity" className={`block text-sm font-medium ${colorTokens.text.secondary} mb-1`}>
                {t('counting.reconciliation.finalQuantity')}
              </label>
              <QuantityInput
                id="manual-override-quantity"
                value={quantity}
                onChange={setQuantity}
                decimalPlaces={4}
                required
              />
            </div>

            {/* Notes Input */}
            <div className="mb-6">
              <label className={`block text-sm font-medium ${colorTokens.text.secondary} mb-1`}>
                {t('counting.reconciliation.overrideReason')}
                <span className={`${colorTokens.intent.danger.textSubtle} ms-1`}>*</span>
              </label>
              <textarea
                value={notes}
                onChange={(e) => { setNotes(e.target.value); }}
                className={`w-full px-3 py-2 border ${colorTokens.border.default} rounded-md focus:outline-none focus:ring-2 ${colorTokens.focus.primaryRing} ${colorTokens.focus.primaryBorder}`}
                rows={3}
                placeholder={t('counting.reconciliation.overrideReasonPlaceholder')}
                required
              />
            </div>

            {/* Actions */}
            <div className="flex gap-3 justify-end">
              <button
                type="button"
                onClick={onClose}
                className={`px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.surface.base} border ${colorTokens.border.default} rounded-md ${colorTokens.intent.neutral.bgHover}`}
                disabled={isLoading}
              >
                {t('cancel')}
              </button>
              <button
                type="submit"
                className={`px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.primary.bgStrong} rounded-md ${colorTokens.intent.primary.bgStrongHover} disabled:opacity-50 disabled:cursor-not-allowed`}
                disabled={isLoading || !notes.trim()}
              >
                {isLoading
                  ? t('saving')
                  : t('counting.reconciliation.applyOverride')}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>,
    document.body,
  )
}

export function ManualOverrideDialog({
  open,
  item,
  onClose,
  onSubmit,
  isLoading,
}: Props) {
  if (!open || !item) {
    return null
  }

  // Key by item.id to reset state when item changes
  return (
    <ManualOverrideDialogContent
      key={item.id}
      item={item}
      onClose={onClose}
      onSubmit={onSubmit}
      isLoading={isLoading}
    />
  )
}
