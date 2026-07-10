import { useCallback, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Upload, FileText, X, AlertCircle } from 'lucide-react'
import { cn } from '@/lib/utils'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface FileUploadProps {
  onFileSelect: (file: File) => void
  accept?: string
  maxSize?: number // in bytes
  disabled?: boolean
}

export function FileUpload({
  onFileSelect,
  accept = '.csv,.txt',
  maxSize = 10 * 1024 * 1024, // 10MB default
  disabled = false,
}: FileUploadProps) {
  const { t } = useTranslation('import')
  const [isDragOver, setIsDragOver] = useState(false)
  const [selectedFile, setSelectedFile] = useState<File | null>(null)
  const [error, setError] = useState<string | null>(null)

  const validateFile = useCallback(
    (file: File): string | null => {
      // Check file type
      const acceptedTypes = accept.split(',').map((t) => t.trim())
      const fileExtension = `.${file.name.split('.').pop()?.toLowerCase()}`
      if (!acceptedTypes.some((t) => fileExtension === t || file.type.includes(t.replace('.', '')))) {
        return t('errors.invalidFileType', { types: accept })
      }

      // Check file size
      if (file.size > maxSize) {
        const maxSizeMB = Math.round(maxSize / (1024 * 1024))
        return t('errors.fileTooLarge', { maxSize: `${String(maxSizeMB)}MB` })
      }

      return null
    },
    [accept, maxSize, t]
  )

  const handleFile = useCallback(
    (file: File) => {
      const validationError = validateFile(file)
      if (validationError) {
        setError(validationError)
        setSelectedFile(null)
        return
      }

      setError(null)
      setSelectedFile(file)
      onFileSelect(file)
    },
    [validateFile, onFileSelect]
  )

  const handleDragOver = useCallback((e: React.DragEvent) => {
    e.preventDefault()
    e.stopPropagation()
    setIsDragOver(true)
  }, [])

  const handleDragLeave = useCallback((e: React.DragEvent) => {
    e.preventDefault()
    e.stopPropagation()
    setIsDragOver(false)
  }, [])

  const handleDrop = useCallback(
    (e: React.DragEvent) => {
      e.preventDefault()
      e.stopPropagation()
      setIsDragOver(false)

      if (disabled) return

      const file = e.dataTransfer.files[0]
      if (file) {
        handleFile(file)
      }
    },
    [disabled, handleFile]
  )

  const handleInputChange = useCallback(
    (e: React.ChangeEvent<HTMLInputElement>) => {
      const file = e.target.files?.[0]
      if (file) {
        handleFile(file)
      }
    },
    [handleFile]
  )

  const clearFile = useCallback(() => {
    setSelectedFile(null)
    setError(null)
  }, [])

  const formatFileSize = (bytes: number): string => {
    if (bytes < 1024) return `${String(bytes)} B`
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
  }

  return (
    <div className="space-y-4">
      {/* Drop Zone */}
      <div
        onDragOver={handleDragOver}
        onDragLeave={handleDragLeave}
        onDrop={handleDrop}
        className={cn(
          'relative rounded-lg border-2 border-dashed p-8 text-center transition-colors',
          isDragOver && !disabled && `${colorTokens.intent.primary.borderMid} ${colorTokens.intent.primary.bgSubtle}`,
          !isDragOver && !disabled && `${colorTokens.border.default} ${colorTokens.border.hoverStrong}`,
          disabled && `cursor-not-allowed ${colorTokens.border.subtle} ${colorTokens.surface.page}`,
          error && `${colorTokens.intent.danger.borderSubtle} ${colorTokens.intent.danger.bgSubtle}`
        )}
      >
        <input
          type="file"
          accept={accept}
          onChange={handleInputChange}
          disabled={disabled}
          className="absolute inset-0 cursor-pointer opacity-0 disabled:cursor-not-allowed"
        />

        {selectedFile ? (
          <div className="flex items-center justify-center gap-3">
            <FileText className={`h-10 w-10 ${colorTokens.intent.primary.textSubtle}`} />
            <div className="text-start">
              <p className={`font-medium ${colorTokens.text.primary}`}>{selectedFile.name}</p>
              <p className={`text-sm ${colorTokens.text.subtle}`}>
                {formatFileSize(selectedFile.size)}
              </p>
            </div>
            <button
              type="button"
              onClick={(e) => {
                e.stopPropagation()
                clearFile()
              }}
              className={`ms-4 rounded-full p-1 ${colorTokens.text.disabled} ${colorTokens.intent.neutral.bgHoverSoft} ${colorTokens.intent.neutral.textHover}`}
            >
              <X className="h-5 w-5" />
            </button>
          </div>
        ) : (
          <>
            <Upload
              className={cn(
                'mx-auto h-12 w-12',
                error ? `${colorTokens.intent.danger.textMuted}` : `${colorTokens.text.disabled}`
              )}
            />
            <p className={`mt-2 text-sm font-medium ${colorTokens.text.primary}`}>
              {t('upload.dragDrop')}
            </p>
            <p className={`mt-1 text-sm ${colorTokens.text.subtle}`}>
              {t('upload.or')}{' '}
              <span className={`${colorTokens.intent.primary.text} underline`}>{t('upload.browse')}</span>
            </p>
            <p className={`mt-2 text-xs ${colorTokens.text.disabled}`}>
              {t('upload.formats', { formats: accept })} -{' '}
              {t('upload.maxSize', { size: `${String(Math.round(maxSize / (1024 * 1024)))}MB` })}
            </p>
          </>
        )}
      </div>

      {/* Error Message */}
      {error && (
        <div className={`flex items-center gap-2 rounded-lg ${colorTokens.intent.danger.bgSubtle} p-3 text-sm ${colorTokens.intent.danger.textStrong}`}>
          <AlertCircle className="h-4 w-4 flex-shrink-0" />
          {error}
        </div>
      )}
    </div>
  )
}
