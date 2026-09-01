import { createPortal } from 'react-dom'
import { useId } from 'react'
import { useTranslation } from 'react-i18next'
import { AlertTriangle, X } from 'lucide-react'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface ConfirmDialogProps {
  isOpen: boolean
  onClose: () => void
  onConfirm: () => void
  title: string
  message: string
  confirmText?: string
  cancelText?: string
  variant?: 'danger' | 'warning' | 'info'
  isLoading?: boolean
}

const variantStyles = {
  danger: {
    icon: `${colorTokens.intent.danger.text}`,
    button: `${colorTokens.intent.danger.bgStrong} ${colorTokens.variants.hoverBgRed700}`,
  },
  warning: {
    icon: `${colorTokens.intent.warning.text}`,
    button: `${colorTokens.intent.warning.bgStrong} ${colorTokens.variants.hoverBgYellow700}`,
  },
  info: {
    icon: `${colorTokens.intent.primary.text}`,
    button: `${colorTokens.intent.primary.bgStrong} ${colorTokens.variants.hoverBgBlue700}`,
  },
}

export function ConfirmDialog({
  isOpen,
  onClose,
  onConfirm,
  title,
  message,
  confirmText,
  cancelText,
  variant = 'warning',
  isLoading = false,
}: ConfirmDialogProps) {
  const { t } = useTranslation('common')
  const headingId = useId()
  const descriptionId = useId()

  if (!isOpen) return null

  const styles = variantStyles[variant]

  return createPortal(
    <div className={`fixed inset-0 z-50 flex items-center justify-center overflow-y-auto ${colorTokens.surface.overlay}`}>
      <div
        role="dialog"
        aria-labelledby={headingId}
        aria-describedby={descriptionId}
        className={`relative mx-4 w-full max-w-md rounded-lg ${colorTokens.surface.base} p-6 shadow-xl`}
      >
        {/* Close button */}
        <button
          type="button"
          onClick={onClose}
          className={`absolute end-4 top-4 rounded-lg p-1 ${colorTokens.text.disabled} ${colorTokens.variants.hoverBgGray100} ${colorTokens.variants.hoverTextGray600}`}
          aria-label={t('actions.close')}
          disabled={isLoading}
        >
          <X className="h-5 w-5" />
        </button>

        {/* Icon and content */}
        <div className="flex items-start gap-4">
          <div className={`flex-shrink-0 ${styles.icon}`}>
            <AlertTriangle className="h-6 w-6" />
          </div>
          {/*
            min-h floors the body height so swapping a short message for a longer
            one (e.g. delete → "has stock, deactivate instead") does not visibly
            resize the modal. min-h only sets a floor: shorter content in other
            dialogs is unaffected since it never shrinks below it, and longer
            content still grows past it.
          */}
          <div className="min-h-[4.5rem] flex-1">
            <h3 id={headingId} className={`text-lg font-semibold ${colorTokens.text.primary}`}>{title}</h3>
            <p id={descriptionId} className={`mt-2 text-sm ${colorTokens.text.muted}`}>{message}</p>
          </div>
        </div>

        {/* Actions */}
        <div className="mt-6 flex justify-end gap-3">
          <button
            type="button"
            onClick={onClose}
            className={`rounded-lg border ${colorTokens.border.default} px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.variants.hoverBgGray50} disabled:opacity-50 transition-colors`}
            disabled={isLoading}
          >
            {cancelText || t('actions.cancel')}
          </button>
          <button
            type="button"
            onClick={onConfirm}
            data-testid="confirm-dialog-confirm"
            className={`rounded-lg px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} disabled:opacity-50 transition-colors ${styles.button}`}
            disabled={isLoading}
          >
            {isLoading ? t('actions.processing') : (confirmText || t('actions.confirm'))}
          </button>
        </div>
      </div>
    </div>,
    document.body,
  )
}
