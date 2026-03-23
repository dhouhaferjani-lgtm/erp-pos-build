import { useState } from 'react'
import { createPortal } from 'react-dom'
import { useTranslation } from 'react-i18next'

interface Props {
  open: boolean
  onClose: () => void
  onConfirm: (reason: string) => void
  isLoading: boolean
}

export function CancelCountingDialog({
  open,
  onClose,
  onConfirm,
  isLoading,
}: Props) {
  const { t } = useTranslation('inventory')
  const [reason, setReason] = useState('')

  if (!open) {
    return null
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (reason.trim()) {
      onConfirm(reason.trim())
    }
  }

  return createPortal(
    <div className="fixed inset-0 z-50 overflow-y-auto">
      {/* Backdrop */}
      <div
        className="fixed inset-0 bg-black/50 transition-opacity"
        onClick={onClose}
      />

      {/* Dialog */}
      <div className="flex min-h-full items-center justify-center p-4">
        <div className="relative bg-white rounded-lg shadow-xl max-w-md w-full p-6">
          <h2 className="text-lg font-semibold text-red-600 mb-2">
            {t('counting.cancelDialog.title')}
          </h2>
          <p className="text-gray-600 mb-4">
            {t('counting.cancelDialog.description')}
          </p>

          <form onSubmit={handleSubmit}>
            <div className="mb-6">
              <label className="block text-sm font-medium text-gray-700 mb-1">
                {t('counting.cancelDialog.reason')}
                <span className="text-red-500 ms-1">*</span>
              </label>
              <textarea
                value={reason}
                onChange={(e) => { setReason(e.target.value); }}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-red-500"
                rows={3}
                placeholder={t('counting.cancelDialog.reasonPlaceholder')}
                required
              />
            </div>

            <div className="flex gap-3 justify-end">
              <button
                type="button"
                onClick={onClose}
                className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
                disabled={isLoading}
              >
                {t('cancel')}
              </button>
              <button
                type="submit"
                className="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-md hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed"
                disabled={isLoading || !reason.trim()}
              >
                {isLoading
                  ? t('processing')
                  : t('counting.cancelDialog.confirm')}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>,
    document.body,
  )
}
