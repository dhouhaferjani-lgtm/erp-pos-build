import { useState } from 'react'
import { Barcode, Camera, ImageIcon, Loader2, RefreshCw, Sparkles } from 'lucide-react'
import { useTranslation } from 'react-i18next'

import { useCatalogBarcodeLookup } from '@/features/inventory/hooks/useCatalogBarcodeLookup'
import type { LookupState, SuggestedProduct } from '@/features/inventory/types/platform'
import { CreateModeImageBuffer, ProductImageUpload } from '@/features/products/components'
import type { ProductHeroChip, ProductHeroEnrichmentState } from '@/features/products/productHeroTypes'
import { tokens } from '@/lib/designTokens'
import { cn } from '@/lib/utils'
import {
  ProductHeroShell,
  withProductHeroImageVariant,
} from '../../sections/ProductHeroShell'

export type EditorHeroEnrichmentState = ProductHeroEnrichmentState

interface ProductEditHeroProps {
  barcode: string
  onBarcodeChange: (value: string) => void
  name: string
  onNameChange: (value: string) => void
  productId?: string
  primaryImageUrl?: string | null
  bufferedFiles: File[]
  onBufferedFilesChange: (files: File[]) => void
  enrichmentState: EditorHeroEnrichmentState
  chips: ProductHeroChip[]
  onManualRefresh?: () => void
  disabled?: boolean
  onProductData?: (data: SuggestedProduct) => void
  onLookupStateChange?: (state: LookupState) => void
}

const NOOP = (): void => undefined

function statusKey(state: EditorHeroEnrichmentState): string | null {
  switch (state) {
    case 'pending':
      return 'catalog:editor.hero.statusPending'
    case 'ready-for-review':
      return 'catalog:editor.hero.statusReview'
    case 'enriched':
      return 'catalog:editor.hero.statusEnriched'
    case 'unavailable':
      return 'catalog:editor.hero.statusUnavailable'
    case 'never-submitted':
      return null
  }
}

export function ProductEditHero({
  barcode,
  onBarcodeChange,
  name,
  onNameChange,
  productId,
  primaryImageUrl,
  bufferedFiles,
  onBufferedFilesChange,
  enrichmentState,
  chips,
  onManualRefresh,
  disabled,
  onProductData,
  onLookupStateChange,
}: ProductEditHeroProps): React.JSX.Element {
  const { t } = useTranslation(['catalog', 'inventory'])
  const [isUploadOpen, setIsUploadOpen] = useState(false)
  const imageUrl = withProductHeroImageVariant(primaryImageUrl)
  const statusLabelKey = statusKey(enrichmentState)
  const isLoading = enrichmentState === 'pending'

  const { isSearching } = useCatalogBarcodeLookup({
    barcode,
    onProductData: onProductData ?? NOOP,
    onLookupStateChange: onLookupStateChange ?? NOOP,
    onScan: onBarcodeChange,
  })

  return (
    <ProductHeroShell
      image={(
        <>
          {imageUrl !== null ? (
            <img src={imageUrl} alt={name} className="h-full w-full object-cover" />
          ) : (
            <div className="flex h-full w-full flex-col items-center justify-center gap-2 text-center">
              <ImageIcon className="h-8 w-8" aria-hidden="true" />
              <span className="px-2 text-xs font-medium">{t('catalog:editor.hero.addPhoto')}</span>
            </div>
          )}
          <button
            type="button"
            aria-label={imageUrl === null ? t('catalog:editor.hero.addPhoto') : t('catalog:editor.hero.replacePhoto')}
            onClick={() => { setIsUploadOpen((current) => !current) }}
            className={cn(
              'absolute inset-x-2 bottom-2 inline-flex items-center justify-center gap-1 rounded-md px-2 py-1.5 text-xs font-semibold opacity-100 transition focus:outline-none focus:ring-2 sm:opacity-0 sm:group-hover:opacity-100',
              tokens.productHero.imageAction,
            )}
          >
            <Camera className="h-3.5 w-3.5" aria-hidden="true" />
            {imageUrl === null ? t('catalog:editor.hero.addPhoto') : t('catalog:editor.hero.replacePhoto')}
          </button>
        </>
      )}
      identity={(
        <>
          <input
            type="text"
            value={name}
            onChange={(event) => { onNameChange(event.target.value) }}
            placeholder={t('editor.hero.namePlaceholder')}
            disabled={disabled}
            aria-label={t('editor.hero.namePlaceholder')}
            className={cn(tokens.productHero.input, 'px-3 py-2.5 text-[17px] font-semibold')}
          />

          <div className="flex flex-wrap items-center gap-2">
            <div className="relative min-w-[14rem] flex-1 sm:max-w-xs">
              <Barcode
                aria-hidden="true"
                className={cn(
                  'pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2',
                  tokens.productHero.barcodeIcon,
                )}
              />
              <input
                type="text"
                value={barcode}
                onChange={(event) => { onBarcodeChange(event.target.value) }}
                placeholder={t('editor.hero.barcodePlaceholder')}
                disabled={disabled === true || isSearching}
                aria-label={t('editor.hero.barcodePlaceholder')}
                className={cn(tokens.productHero.input, 'py-[9px] pe-3 ps-9 font-mono text-[15px]')}
              />
            </div>
            <span className={cn('inline-flex h-2.5 w-2.5 rounded-full', tokens.productHero.successDot)} aria-hidden="true" />
            {onManualRefresh !== undefined && (
              <button
                type="button"
                aria-label={t('editor.hero.refreshLabel')}
                onClick={onManualRefresh}
                disabled={isLoading}
                className={cn(
                  'flex h-[38px] w-[38px] shrink-0 items-center justify-center rounded-[var(--radius-input)] border transition-colors disabled:cursor-not-allowed disabled:opacity-60',
                  tokens.productHero.refreshButton,
                )}
              >
                <RefreshCw className="h-4 w-4" aria-hidden="true" />
              </button>
            )}
          </div>
        </>
      )}
      enrichment={(
        <div className="flex flex-wrap items-center gap-2 text-start">
          {statusLabelKey !== null && (
            <span
              className={cn(
                'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold',
                enrichmentState === 'unavailable'
                  ? tokens.productHero.statusUnavailable
                  : enrichmentState === 'ready-for-review'
                    ? tokens.productHero.statusReview
                    : tokens.productHero.statusEnriched,
              )}
            >
              {isLoading ? (
                <Loader2 className="h-3 w-3 animate-spin" aria-hidden="true" />
              ) : (
                <Sparkles className="h-3 w-3" aria-hidden="true" />
              )}
              {t(statusLabelKey)}
            </span>
          )}
          {chips.map((chip) => (
            <span key={chip.id} className={cn('rounded-md px-2.5 py-1 text-xs font-medium', tokens.productHero.chip)}>
              {chip.label}{chip.enriched === true ? ' ✦' : ''}
            </span>
          ))}
        </div>
      )}
      helper={<p className={cn('text-start text-[11px]', tokens.productHero.helper)}>{t('editor.hero.helper')}</p>}
      after={isUploadOpen ? (
        <div className={cn('mt-4 rounded-lg border p-3', tokens.productHero.uploadPanel)}>
          {productId !== undefined ? (
            <ProductImageUpload productId={productId} onUploadSuccess={() => { setIsUploadOpen(false) }} />
          ) : (
            <CreateModeImageBuffer bufferedFiles={bufferedFiles} onFilesChange={onBufferedFilesChange} />
          )}
        </div>
      ) : null}
    />
  )
}
