import { useEffect, useRef, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { AlertCircle, FileText, Upload, X } from 'lucide-react'
import { getErrorMessage } from '@/lib/api'
import { cn } from '@/lib/utils'
import { borderColors, textColors, tokens, typography } from '@/lib/designTokens'
import { useUploadDocumentIngestion } from './queries'
import type { DocumentKind } from './types'

const MAX_UPLOAD_BYTES = 20 * 1024 * 1024
const MAX_UPLOAD_MB = MAX_UPLOAD_BYTES / (1024 * 1024)

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

function isAcceptedType(file: File): boolean {
  return file.type.startsWith('image/') || file.type === 'application/pdf'
}

const LOCKABLE_KINDS: readonly DocumentKind[] = ['supplier_invoice', 'supplier_delivery_note']

function lockedKindFromParam(value: string | null): DocumentKind | null {
  return value !== null && (LOCKABLE_KINDS as readonly string[]).includes(value)
    ? (value as DocumentKind)
    : null
}

export function UploadScanPage() {
  const { t } = useTranslation(['documentIngestions'])

  function formatFileSize(bytes: number): string {
    if (bytes < 1024) return `${String(bytes)} ${t('upload.units.b')}`
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} ${t('upload.units.kb')}`
    return `${(bytes / (1024 * 1024)).toFixed(1)} ${t('upload.units.mb')}`
  }
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const lockedKind = lockedKindFromParam(searchParams.get('kind'))
  const uploadMutation = useUploadDocumentIngestion()
  const [kind, setKind] = useState<DocumentKind | ''>(lockedKind ?? '')
  const [file, setFile] = useState<File | null>(null)
  const [previewUrl, setPreviewUrl] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [dragOver, setDragOver] = useState(false)
  const fileInputRef = useRef<HTMLInputElement>(null)

  useEffect(() => {
    return () => {
      if (previewUrl) URL.revokeObjectURL(previewUrl)
    }
  }, [previewUrl])

  function applyFile(selected: File | null): void {
    setError(null)
    setPreviewUrl((current) => {
      if (current) URL.revokeObjectURL(current)
      if (selected?.type.startsWith('image/')) {
        return URL.createObjectURL(selected)
      }
      return null
    })
    setFile(selected)
  }

  function handleRemove(): void {
    applyFile(null)
    if (fileInputRef.current) fileInputRef.current.value = ''
  }

  function handleDrop(event: React.DragEvent<HTMLDivElement>): void {
    event.preventDefault()
    setDragOver(false)
    const dropped = event.dataTransfer.files[0]
    if (dropped !== undefined) applyFile(dropped)
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
    if (!isAcceptedType(file)) {
      setError(t('upload.errors.invalidType'))
      return false
    }
    if (file.size > MAX_UPLOAD_BYTES) {
      setError(t('upload.errors.fileTooLargeDetailed', {
        max: MAX_UPLOAD_MB,
        actual: (file.size / (1024 * 1024)).toFixed(1),
      }))
      return false
    }
    return true
  }

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault()
    setError(null)
    if (!validate() || kind === '' || !file) return

    try {
      const summary = await uploadMutation.mutateAsync({ kind, file })
      void navigate(`/purchases/scans/${summary.id}`)
    } catch (uploadError) {
      if (apiErrorCode(uploadError) === 'DUPLICATE_DOCUMENT') {
        setError(t('upload.errors.duplicate'))
        return
      }
      setError(getErrorMessage(uploadError))
    }
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className={cn(typography.fontSize['2xl'], typography.fontWeight.bold, textColors.primary)}>
          {t('upload.title')}
        </h1>
      </div>

      <form className={cn(tokens.card.base, 'space-y-4 max-w-xl')} onSubmit={(event) => { void handleSubmit(event) }}>
        {lockedKind !== null ? (
          <div>
            <p className={tokens.label.base}>{t('fields.kind')}</p>
            <p className={cn(typography.fontSize.sm, textColors.tertiary, 'mt-1')}>
              {t(`kinds.${lockedKind}`)}
            </p>
          </div>
        ) : (
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
        )}

        <div>
          <label className={tokens.label.base}>{t('fields.file')}</label>
          <div
            data-testid="upload-dropzone"
            onDragOver={(event) => { event.preventDefault(); setDragOver(true) }}
            onDragLeave={() => { setDragOver(false) }}
            onDrop={handleDrop}
            className={cn(
              'mt-1 flex flex-col items-center justify-center gap-3 rounded-lg border-2 border-dashed p-8 text-center transition-colors',
              dragOver ? borderColors.primary : borderColors.default,
            )}
          >
            {file ? (
              <div className="flex flex-col items-center gap-2">
                {previewUrl ? (
                  <img src={previewUrl} alt={file.name} className="h-24 w-24 rounded object-cover" />
                ) : (
                  <FileText data-testid="upload-file-icon" className="h-10 w-10" aria-hidden="true" />
                )}
                <p className={cn(typography.fontSize.sm, textColors.primary)}>
                  {t('upload.selectedFile', { name: file.name, size: formatFileSize(file.size) })}
                </p>
                <button
                  type="button"
                  className={`${tokens.button.base} ${tokens.button.secondary} ${tokens.button.sizes.sm}`}
                  onClick={handleRemove}
                  aria-label={t('upload.remove')}
                >
                  <X className="h-4 w-4" aria-hidden="true" />
                  {t('upload.remove')}
                </button>
              </div>
            ) : (
              <>
                <Upload className={cn('h-8 w-8', textColors.tertiary)} aria-hidden="true" />
                <p className={cn(typography.fontSize.sm, textColors.tertiary)}>{t('upload.dropHint')}</p>
                <button
                  type="button"
                  className={`${tokens.button.base} ${tokens.button.secondary} ${tokens.button.sizes.sm}`}
                  onClick={() => { fileInputRef.current?.click() }}
                >
                  {t('upload.browse')}
                </button>
              </>
            )}
            <input
              ref={fileInputRef}
              type="file"
              aria-label={t('fields.file')}
              className="hidden"
              accept="image/*,application/pdf"
              onChange={(event) => { applyFile(event.target.files?.[0] ?? null) }}
            />
          </div>
          <p className={cn(typography.fontSize.sm, textColors.tertiary, 'mt-1')}>{t('upload.fileHint')}</p>
        </div>

        {error && (
          <div className={cn(tokens.alert.base, tokens.alert.error, 'flex items-center gap-2')}>
            <AlertCircle className="h-4 w-4" aria-hidden="true" />
            <span>{error}</span>
          </div>
        )}

        <div className="flex justify-end">
          <button
            type="submit"
            className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.md}`}
            disabled={uploadMutation.isPending}
          >
            <Upload className="me-2 h-4 w-4" aria-hidden="true" />
            {t('actions.startScan')}
          </button>
        </div>
      </form>
    </div>
  )
}
