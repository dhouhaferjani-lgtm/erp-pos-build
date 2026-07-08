import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { AlertCircle, Upload, X } from 'lucide-react'
import { getErrorMessage } from '@/lib/api'
import { cn } from '@/lib/utils'
import { textColors, tokens, typography } from '@/lib/designTokens'
import { useUploadDocumentIngestion } from './queries'
import type { DocumentKind } from './types'

interface UploadIngestionDialogProps {
  open: boolean
  onClose: () => void
}

const MAX_UPLOAD_BYTES = 20 * 1024 * 1024

function apiErrorCode(error: unknown): string | null {
  if (
    typeof error === 'object'
    && error !== null
    && 'response' in error
    && typeof error.response === 'object'
    && error.response !== null
    && 'data' in error.response
    && typeof error.response.data === 'object'
    && error.response.data !== null
    && 'error' in error.response.data
    && typeof error.response.data.error === 'object'
    && error.response.data.error !== null
    && 'code' in error.response.data.error
    && typeof error.response.data.error.code === 'string'
  ) {
    return error.response.data.error.code
  }
  return null
}

export function UploadIngestionDialog({ open, onClose }: UploadIngestionDialogProps) {
  const { t } = useTranslation(['documentIngestions'])
  const uploadMutation = useUploadDocumentIngestion()
  const [kind, setKind] = useState<DocumentKind | ''>('')
  const [file, setFile] = useState<File | null>(null)
  const [error, setError] = useState<string | null>(null)
  const fileInputRef = useRef<HTMLInputElement>(null)

  if (!open) return null

  function resetAndClose(): void {
    setKind('')
    setFile(null)
    setError(null)
    onClose()
  }

  function validate(): boolean {
    if (kind === '') {
      setError(t('upload.errors.kindRequired'))
      return false
    }
    if (!file) {
      setError(t('upload.errors.fileRequired'))
      return false
    }
    if (file.size > MAX_UPLOAD_BYTES) {
      setError(t('upload.errors.fileTooLarge'))
      return false
    }
    return true
  }

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault()
    setError(null)
    if (!validate() || kind === '' || !file) return

    try {
      await uploadMutation.mutateAsync({ kind, file })
      resetAndClose()
    } catch (uploadError) {
      if (apiErrorCode(uploadError) === 'DUPLICATE_DOCUMENT') {
        setError(t('upload.errors.duplicate'))
        return
      }
      setError(getErrorMessage(uploadError))
    }
  }

  return (
    <div className={tokens.modal.backdrop} role="dialog" aria-modal="true" aria-labelledby="upload-ingestion-title">
      <form className={tokens.modal.container} onSubmit={(event) => { void handleSubmit(event) }}>
        <div className={tokens.modal.header}>
          <h2 id="upload-ingestion-title" className={tokens.modal.title}>{t('upload.title')}</h2>
          <button
            type="button"
            className={tokens.modal.closeButton}
            onClick={resetAndClose}
            aria-label={t('actions.close')}
          >
            <X className="h-4 w-4" aria-hidden="true" />
          </button>
        </div>

        <div className="space-y-4">
          <div>
            <label htmlFor="ingestion-kind" className={tokens.label.base}>{t('fields.kind')}</label>
            <select
              id="ingestion-kind"
              aria-label={t('fields.kind')}
              className={tokens.select.base}
              value={kind}
              onChange={(event) => { setKind(event.target.value as DocumentKind | '') }}
            >
              <option value="">{t('upload.placeholders.kind')}</option>
              <option value="supplier_delivery_note">{t('kinds.supplier_delivery_note')}</option>
              <option value="supplier_invoice">{t('kinds.supplier_invoice')}</option>
            </select>
          </div>

          <div>
            <label htmlFor="ingestion-file" className={tokens.label.base}>{t('fields.file')}</label>
            <input
              ref={fileInputRef}
              id="ingestion-file"
              aria-label={t('fields.file')}
              className={tokens.input.base}
              type="file"
              accept="image/*,application/pdf"
              onChange={(event) => {
                setFile(event.target.files?.[0] ?? null)
                setError(null)
              }}
            />
            <p className={cn(typography.fontSize.sm, textColors.tertiary)}>{t('upload.fileHint')}</p>
          </div>

          {error && (
            <div className={cn(tokens.alert.error, 'flex items-center gap-2')}>
              <AlertCircle className="h-4 w-4" aria-hidden="true" />
              <span>{error}</span>
            </div>
          )}
        </div>

        <div className={tokens.modal.footer}>
          <button
            type="button"
            className={`${tokens.button.base} ${tokens.button.secondary} ${tokens.button.sizes.md}`}
            onClick={resetAndClose}
          >
            {t('actions.cancel')}
          </button>
          <button
            type="submit"
            className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.md}`}
            disabled={uploadMutation.isPending}
          >
            <Upload className="me-2 h-4 w-4" aria-hidden="true" />
            {t('actions.startExtraction')}
          </button>
        </div>
      </form>
    </div>
  )
}
