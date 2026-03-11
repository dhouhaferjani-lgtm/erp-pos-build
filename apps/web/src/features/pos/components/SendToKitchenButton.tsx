import { useState } from 'react'
import { useTranslation } from 'react-i18next'

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
        <span className="text-sm text-gray-600 dark:text-gray-400">
          {t('orders.confirmSend')}
        </span>
        <button
          type="button"
          onClick={handleClick}
          disabled={loading}
          className="rounded-lg bg-amber-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-amber-700 disabled:opacity-50"
        >
          {loading ? '...' : t('orders.actions.sendToKitchen')}
        </button>
        <button
          type="button"
          onClick={handleCancel}
          className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700"
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
      className="inline-flex items-center gap-2 rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700 disabled:opacity-50"
    >
      {t('orders.actions.sendToKitchen')}
    </button>
  )
}
