import { useState } from 'react'
import { createPortal } from 'react-dom'
import { useTranslation } from 'react-i18next'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

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
        className={`fixed inset-0 ${colorTokens.surface.overlay} transition-opacity`}
        onClick={onClose}
      />

      {/* Dialog */}
      <div className="flex min-h-full items-center justify-center p-4">
        <div className={`relative ${colorTokens.surface.base} rounded-lg shadow-xl max-w-md w-full p-6`}>
          <h2 className={`text-lg font-semibold ${colorTokens.intent.danger.text} mb-2`}>
            {t('counting.cancelDialog.title')}
          </h2>
          <p className={`${colorTokens.text.muted} mb-4`}>
            {t('counting.cancelDialog.description')}
          </p>

          <form onSubmit={handleSubmit}>
            <div className="mb-6">
              <label className={`block text-sm font-medium ${colorTokens.text.secondary} mb-1`}>
                {t('counting.cancelDialog.reason')}
                <span className={`${colorTokens.intent.danger.textSubtle} ms-1`}>*</span>
              </label>
              <textarea
                value={reason}
                onChange={(e) => { setReason(e.target.value); }}
                className={`w-full px-3 py-2 border ${colorTokens.border.default} rounded-md focus:outline-none focus:ring-2 ${colorTokens.focus.dangerRing} ${colorTokens.focus.dangerBorder}`}
                rows={3}
                placeholder={t('counting.cancelDialog.reasonPlaceholder')}
                required
              />
            </div>

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
                className={`px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.danger.bgStrong} rounded-md ${colorTokens.intent.danger.bgStrongHover} disabled:opacity-50 disabled:cursor-not-allowed`}
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
