import { Loader2, Plus, Trash2, Upload } from 'lucide-react'
import { useState } from 'react'
import type React from 'react'
import { useTranslation } from 'react-i18next'
import { borderColors, colors, textColors, tokens } from '@/lib/designTokens'
import { cn } from '@/lib/utils'
import type { EnrichmentAttributeRow } from '@/features/products/enrichmentCaptureTypes'
import {
  MAX_PHOTO_BYTES,
  type UploadedPhoto,
  uploadEnrichmentPhoto,
} from '../api/enrichmentPhotos'

export type { EnrichmentAttributeRow } from '@/features/products/enrichmentCaptureTypes'

interface EnrichmentCapturePanelProps {
  photos: UploadedPhoto[]
  onPhotosChange: (photos: UploadedPhoto[]) => void
  brand: string
  onBrandChange: (value: string) => void
  attributes: EnrichmentAttributeRow[]
  onAttributesChange: (rows: EnrichmentAttributeRow[]) => void
  disabled?: boolean
}

export function EnrichmentCapturePanel({
  photos,
  onPhotosChange,
  brand,
  onBrandChange,
  attributes,
  onAttributesChange,
  disabled = false,
}: EnrichmentCapturePanelProps): React.JSX.Element {
  const { t } = useTranslation('inventory')
  const [uploading, setUploading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const photoLimitReached = photos.length >= 2
  const uploadDisabled = disabled || uploading || photoLimitReached

  const handleFileChange = async (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0]
    event.target.value = ''

    if (!file || uploadDisabled) {
      return
    }

    if (file.size > MAX_PHOTO_BYTES) {
      setError(t('barcodeLookup.captureSizeError'))
      return
    }

    setError(null)
    setUploading(true)

    try {
      const uploaded = await uploadEnrichmentPhoto(file)
      onPhotosChange([...photos, uploaded].slice(0, 2))
    } catch {
      setError(t('barcodeLookup.captureUploadError'))
    } finally {
      setUploading(false)
    }
  }

  const removePhoto = (photoId: string) => {
    onPhotosChange(photos.filter((photo) => photo.photoId !== photoId))
  }

  const updateAttribute = (index: number, patch: Partial<EnrichmentAttributeRow>) => {
    onAttributesChange(
      attributes.map((row, rowIndex) => (rowIndex === index ? { ...row, ...patch } : row)),
    )
  }

  return (
    <section className={cn(tokens.card.base, 'space-y-4')}>
      <div className="space-y-2">
        <div className="flex items-start gap-3">
          <div className={cn(colors.primary[50], textColors.brand, 'rounded-md p-2')}>
            <Upload className="h-4 w-4" aria-hidden="true" />
          </div>
          <div className="min-w-0">
            <h3 className={tokens.heading.section}>
              {t('barcodeLookup.captureTitle')}
            </h3>
            <p className={tokens.helperText.base}>
              {t('barcodeLookup.capturePhotoHelp')}
            </p>
          </div>
        </div>

        <div className="flex flex-wrap gap-2">
          {photos.map((photo) => (
            <div
              key={photo.photoId}
              className={cn(
                colors.neutral[50],
                borderColors.light,
                'flex min-h-10 items-center gap-2 rounded-md border px-3 py-2',
              )}
            >
              <span className={cn(textColors.secondary, 'max-w-48 truncate text-sm')}>
                {photo.filename}
              </span>
              <button
                type="button"
                className={cn(tokens.button.base, tokens.button.ghost, 'p-1')}
                onClick={() => removePhoto(photo.photoId)}
                disabled={disabled}
                aria-label={t('barcodeLookup.captureRemovePhoto')}
              >
                <Trash2 className="h-4 w-4" aria-hidden="true" />
              </button>
            </div>
          ))}
        </div>

        <label className={cn(tokens.button.base, tokens.button.secondary, tokens.button.sizes.sm)}>
          {uploading ? (
            <Loader2 className="mr-2 h-4 w-4 animate-spin" aria-hidden="true" />
          ) : (
            <Plus className="mr-2 h-4 w-4" aria-hidden="true" />
          )}
          {t('barcodeLookup.captureAddPhoto')}
          <input
            type="file"
            className="sr-only"
            accept="image/*"
            aria-label={t('barcodeLookup.captureAddPhoto')}
            disabled={uploadDisabled}
            onChange={handleFileChange}
          />
        </label>
        <p className={tokens.helperText.base}>{t('barcodeLookup.capturePhotoLimit')}</p>
        {error ? <p className={tokens.helperText.error}>{error}</p> : null}
      </div>

      <div>
        <label className={tokens.label.base} htmlFor="enrichment-capture-brand">
          {t('barcodeLookup.captureBrandLabel')}
        </label>
        <input
          id="enrichment-capture-brand"
          className={tokens.input.base}
          value={brand}
          onChange={(event) => onBrandChange(event.target.value)}
          disabled={disabled}
          placeholder={t('barcodeLookup.captureBrandPlaceholder')}
        />
      </div>

      <div className="space-y-2">
        <div className="flex items-center justify-between gap-3">
          <p className={cn(tokens.label.base, 'mb-0')}>
            {t('barcodeLookup.captureAttributesLabel')}
          </p>
          <button
            type="button"
            className={cn(tokens.button.base, tokens.button.secondary, tokens.button.sizes.sm)}
            onClick={() => onAttributesChange([...attributes, { key: '', value: '' }])}
            disabled={disabled}
          >
            <Plus className="mr-2 h-4 w-4" aria-hidden="true" />
            {t('barcodeLookup.captureAddAttribute')}
          </button>
        </div>

        {attributes.length === 0 ? (
          <p className={tokens.helperText.base}>{t('barcodeLookup.captureAttributesHelp')}</p>
        ) : null}

        {attributes.map((row, index) => (
          <div key={index} className="grid grid-cols-[1fr_1fr_auto] gap-2">
            <input
              className={tokens.input.base}
              value={row.key}
              onChange={(event) => updateAttribute(index, { key: event.target.value })}
              disabled={disabled}
              aria-label={t('barcodeLookup.captureAttributeKey')}
              placeholder={t('barcodeLookup.captureAttributeKey')}
            />
            <input
              className={tokens.input.base}
              value={row.value}
              onChange={(event) => updateAttribute(index, { value: event.target.value })}
              disabled={disabled}
              aria-label={t('barcodeLookup.captureAttributeValue')}
              placeholder={t('barcodeLookup.captureAttributeValue')}
            />
            <button
              type="button"
              className={cn(tokens.button.base, tokens.button.ghost, 'mt-1 p-2')}
              onClick={() => onAttributesChange(attributes.filter((_, rowIndex) => rowIndex !== index))}
              disabled={disabled}
              aria-label={t('barcodeLookup.captureRemoveAttribute')}
            >
              <Trash2 className="h-4 w-4" aria-hidden="true" />
            </button>
          </div>
        ))}
      </div>
    </section>
  )
}
