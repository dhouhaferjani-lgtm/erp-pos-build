/**
 * CreateModeImageBuffer
 *
 * Renders a file-picker + preview grid for the product editor's Media section
 * when the product does not yet exist (no productId). Files are held client-
 * side and passed up to the parent via onFilesChange so the create-mutation
 * success path can upload them with the new product id.
 *
 * Validation mirrors ProductImageUpload (5 MB / jpeg|png|webp|gif).
 * Previews use URL.createObjectURL; revocation is handled on remove and
 * on unmount via a cleanup effect that reads the preview-URLs state snapshot.
 */

import { useRef, useState, useCallback, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { ImagePlus, X, AlertCircle } from 'lucide-react'
import { cn } from '@/lib/utils'
import { colors, textColors, borderColors } from '@/lib/designTokens'

// ── Validation constants (same as ProductImageUpload) ────────────────────────
const MAX_FILE_SIZE = 5 * 1024 * 1024 // 5 MB
const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif']

interface CreateModeImageBufferProps {
  bufferedFiles: File[]
  onFilesChange: (files: File[]) => void
}

type ValidationError =
  | { key: 'products:images.errors.fileTooLarge'; opts: { max: string } }
  | { key: 'products:images.errors.invalidType'; opts?: never }

function validateFile(file: File): ValidationError | null {
  if (file.size > MAX_FILE_SIZE) {
    return { key: 'products:images.errors.fileTooLarge', opts: { max: '5MB' } }
  }
  if (!ALLOWED_TYPES.includes(file.type)) {
    return { key: 'products:images.errors.invalidType' }
  }
  return null
}

export function CreateModeImageBuffer({
  bufferedFiles,
  onFilesChange,
}: CreateModeImageBufferProps) {
  const { t } = useTranslation(['products'])
  const fileInputRef = useRef<HTMLInputElement>(null)
  const [error, setError] = useState<string | null>(null)

  // previewUrls[i] corresponds to bufferedFiles[i]; managed in parallel.
  // We store them in React state so they're safe to read during render.
  const [previewUrls, setPreviewUrls] = useState<string[]>([])

  // Revoke all remaining object URLs when the component unmounts.
  // The cleanup captures the latest previewUrls via a ref so it always
  // revokes the current set even if this effect ran before the last render.
  const previewUrlsRef = useRef<string[]>(previewUrls)
  useEffect(() => {
    previewUrlsRef.current = previewUrls
  })
  useEffect(() => {
    return () => {
      for (const url of previewUrlsRef.current) {
        URL.revokeObjectURL(url)
      }
    }
  }, [])

  const handleFile = useCallback(
    (file: File) => {
      const desc = validateFile(file)
      if (desc !== null) {
        setError(
          desc.opts !== undefined
            ? t(desc.key, desc.opts)
            : t(desc.key),
        )
        return
      }
      setError(null)
      const url = URL.createObjectURL(file)
      setPreviewUrls((prev) => [...prev, url])
      onFilesChange([...bufferedFiles, file])
    },
    [bufferedFiles, onFilesChange, t],
  )

  const handleFileInput = useCallback(
    (e: React.ChangeEvent<HTMLInputElement>) => {
      const file = e.target.files?.[0]
      if (file) {
        handleFile(file)
      }
      // Reset so the same file can be re-selected after removal
      e.target.value = ''
    },
    [handleFile],
  )

  const handleRemove = useCallback(
    (index: number) => {
      setPreviewUrls((prev) => {
        URL.revokeObjectURL(prev[index])
        return prev.filter((_, i) => i !== index)
      })
      onFilesChange(bufferedFiles.filter((_, i) => i !== index))
    },
    [bufferedFiles, onFilesChange],
  )

  const handleAddClick = useCallback(() => {
    fileInputRef.current?.click()
  }, [])

  return (
    <div className="space-y-3">
      {/* Preview grid + add tile */}
      <div className="flex flex-wrap gap-2">
        {/* Thumbnail previews */}
        {bufferedFiles.map((file, index) => (
          <div key={previewUrls[index] ?? index} className="group relative h-20 w-20">
            <img
              src={previewUrls[index] ?? undefined}
              alt={file.name}
              className="h-full w-full rounded-lg border object-cover"
              style={{ borderColor: 'var(--color-neutral-200, #e5e7eb)' }}
            />
            <button
              type="button"
              aria-label={t('products:media.removeImage')}
              onClick={() => { handleRemove(index) }}
              className={cn(
                'absolute right-0.5 top-0.5 hidden h-5 w-5 items-center justify-center rounded-full',
                colors.error[600],
                textColors.inverse,
                'group-hover:flex',
              )}
            >
              <X className="h-3 w-3" />
            </button>
          </div>
        ))}

        {/* Add-file tile */}
        <button
          type="button"
          aria-label={t('products:media.addImage')}
          onClick={handleAddClick}
          className={cn(
            'flex h-20 w-20 cursor-pointer items-center justify-center rounded-lg border-2 border-dashed',
            borderColors.light,
            colors.neutral[50],
            textColors.disabled,
            borderColors.hover,
            textColors.tertiary,
          )}
        >
          <ImagePlus className="h-7 w-7" />
        </button>
      </div>

      {/* Image count badge */}
      {bufferedFiles.length > 0 && (
        <p
          data-testid="buffer-image-count"
          className={cn('text-xs', textColors.tertiary)}
        >
          {t('products:media.bufferedCount', {
            count: bufferedFiles.length,
            defaultValue: `${bufferedFiles.length.toString()} image(s) ready to upload`,
          })}
        </p>
      )}

      {/* Hidden file input */}
      <input
        ref={fileInputRef}
        data-testid="buffer-file-input"
        type="file"
        className="hidden"
        accept="image/jpeg,image/png,image/webp,image/gif"
        onChange={handleFileInput}
      />

      {/* Validation error */}
      {error !== null && (
        <div
          role="alert"
          className={cn(
            'flex items-start gap-2 rounded-md border p-3',
            borderColors.error,
            colors.error[50],
          )}
        >
          <AlertCircle
            className={cn('mt-0.5 h-4 w-4 shrink-0', textColors.error)}
          />
          <p className={cn('text-sm', textColors.error)}>{error}</p>
        </div>
      )}
    </div>
  )
}
