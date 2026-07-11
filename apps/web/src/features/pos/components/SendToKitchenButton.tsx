import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import { textColors, borderColors, colors , semanticColorTokens as colorTokens } from '@/lib/designTokens'

export interface SendToKitchenButtonProps {
  onConfirm: () => void
  disabled?: boolean
  loading?: boolean
}

/**
 * Button with confirmation dialog to fire an order to the kitchen.
 */
export function SendToKitchenButton({
  onConfirm,
  disabled = false,
  loading = false,
}: SendToKitchenButtonProps) {
  const { t } = useTranslation('pos')
  const [showConfirm, setShowConfirm] = useState(false)

  const handleClick = () => {
    if (showConfirm) {
      onConfirm()
      setShowConfirm(false)
    } else {
      setShowConfirm(true)
    }
  }

  const handleCancel = () => {
    setShowConfirm(false)
  }

  if (showConfirm) {
    return (
      <div className="flex items-center gap-2">
        <span className={cn('text-sm', textColors.tertiary)}>
          {t('orders.confirmSend')}
        </span>
        <button
          type="button"
          onClick={handleClick}
          disabled={loading}
          className={`rounded-lg ${colorTokens.intent.caution.bgStrong} px-3 py-1.5 text-sm font-medium text-white ${colorTokens.variants.hoverBgAmber700} disabled:opacity-50`}
        >
          {loading ? '...' : t('orders.actions.sendToKitchen')}
        </button>
        <button
          type="button"
          onClick={handleCancel}
          className={cn(
            'rounded-lg border px-3 py-1.5 text-sm font-medium',
            borderColors.default,
            textColors.secondary,
            colors.hover.gray50,
          )}
        >
          {t('barcode.cancel')}
        </button>
      </div>
    )
  }

  return (
    <button
      type="button"
      onClick={handleClick}
      disabled={disabled || loading}
      className={`inline-flex items-center gap-2 rounded-lg ${colorTokens.intent.caution.bgStrong} px-4 py-2 text-sm font-medium text-white ${colorTokens.variants.hoverBgAmber700} disabled:opacity-50`}
    >
      {t('orders.actions.sendToKitchen')}
    </button>
  )
}
