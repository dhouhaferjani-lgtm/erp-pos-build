import { useState } from 'react'
import { Barcode, Camera, ImageIcon, Loader2, RefreshCw, Sparkles } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import { colors } from '@/lib/designTokens'
import { useCatalogBarcodeLookup } from '@/features/inventory/hooks/useCatalogBarcodeLookup'
import { CreateModeImageBuffer, ProductImageUpload } from '@/features/products/components'
import type { LookupState, SuggestedProduct } from '@/features/inventory/types/platform'

export type EditorHeroEnrichmentState =
  | 'never-submitted'
  | 'pending'
  | 'ready-for-review'
  | 'enriched'
  | 'unavailable'

interface HeroChip {
  id: string
  label: string
  enriched?: boolean
  href?: string
}

interface ProductEditHeroProps {
  barcode: string
  onBarcodeChange: (value: string) => void
  name: string
  onNameChange: (value: string) => void
  productId?: string | undefined
  primaryImageUrl?: string | null
  bufferedFiles: File[]
  onBufferedFilesChange: (files: File[]) => void
  enrichmentState: EditorHeroEnrichmentState
  chips: HeroChip[]
  onManualRefresh?: () => void
  disabled?: boolean
  onProductData?: (data: SuggestedProduct) => void
  onLookupStateChange?: (state: LookupState) => void
}

const NOOP = (): void => undefined

const HERO_INPUT_BASE =
  'w-full rounded-[var(--radius-input)] border border-[#2C4A6E] bg-[#0F2138] text-[#EAF1FA] ' +
  'placeholder:text-[#5B7AA3] focus:border-[#5B7AA3] focus:outline-none focus:ring-0 ' +
  'disabled:cursor-not-allowed disabled:opacity-60'

function withMdVariant(url: string | null | undefined): string | null {
  if (url === null || url === undefined || url === '') return null
  if (url.includes('variant=')) return url
  return `${url}${url.includes('?') ? '&' : '?'}variant=md`
}

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
  const imageUrl = withMdVariant(primaryImageUrl)
  const statusLabelKey = statusKey(enrichmentState)
  const isLoading = enrichmentState === 'pending'

  const { isSearching } = useCatalogBarcodeLookup({
    barcode,
    onProductData: onProductData ?? NOOP,
    onLookupStateChange: onLookupStateChange ?? NOOP,
    onScan: onBarcodeChange,
  })

  return (
    <section className={`overflow-hidden rounded-xl ${colors.neutral[900]}`}>
      <div className="p-4 sm:p-5">
        <div className="grid gap-4 md:grid-cols-[176px_minmax(0,1fr)] md:gap-5">
          <div className="group relative h-24 w-24 overflow-hidden rounded-xl border border-[#2C4A6E] bg-[#1E3A57] text-[#7E97B5] sm:h-44 sm:w-44">
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
              className="absolute inset-x-2 bottom-2 inline-flex items-center justify-center gap-1 rounded-md bg-black/65 px-2 py-1.5 text-xs font-semibold text-white opacity-100 transition hover:bg-black/80 focus:outline-none focus:ring-2 focus:ring-white sm:opacity-0 sm:group-hover:opacity-100"
            >
              <Camera className="h-3.5 w-3.5" aria-hidden="true" />
              {imageUrl === null ? t('catalog:editor.hero.addPhoto') : t('catalog:editor.hero.replacePhoto')}
            </button>
          </div>

          <div className="min-w-0 space-y-3">
            <input
              type="text"
              value={name}
              onChange={(e) => { onNameChange(e.target.value) }}
              placeholder={t('editor.hero.namePlaceholder')}
              disabled={disabled}
              aria-label={t('editor.hero.namePlaceholder')}
              className={cn(HERO_INPUT_BASE, 'px-3 py-2.5 text-[17px] font-semibold')}
            />

            <div className="flex flex-wrap items-center gap-2">
              <div className="relative min-w-[14rem] flex-1 sm:max-w-xs">
                <Barcode
                  aria-hidden="true"
                  className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[#5B7AA3]"
                />
                <input
                  type="text"
                  value={barcode}
                  onChange={(e) => { onBarcodeChange(e.target.value) }}
                  placeholder={t('editor.hero.barcodePlaceholder')}
                  disabled={disabled === true || isSearching}
                  aria-label={t('editor.hero.barcodePlaceholder')}
                  className={cn(HERO_INPUT_BASE, 'py-[9px] pe-3 ps-9 font-mono text-[15px]')}
                />
              </div>
              <span className="inline-flex h-2.5 w-2.5 rounded-full bg-[#7BE0B0]" aria-hidden="true" />
              {onManualRefresh !== undefined && (
                <button
                  type="button"
                  aria-label={t('editor.hero.refreshLabel')}
                  onClick={onManualRefresh}
                  disabled={isLoading}
                  className="flex h-[38px] w-[38px] shrink-0 items-center justify-center rounded-[var(--radius-input)] border border-[#2C4A6E] bg-transparent text-[#9FB4CE] transition-colors hover:border-[#5B7AA3] hover:text-white disabled:cursor-not-allowed disabled:opacity-60"
                >
                  <RefreshCw className="h-4 w-4" aria-hidden="true" />
                </button>
              )}
            </div>

            <div className="min-h-10 rounded-lg border border-[#2C4A6E] bg-[#0F2138] p-2.5">
              <div className="flex flex-wrap items-center gap-2 text-start">
                {statusLabelKey !== null && (
                  <span
                    className={cn(
                      'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold',
                      enrichmentState === 'unavailable'
                        ? 'bg-white/10 text-[#B7C6D9]'
                        : enrichmentState === 'ready-for-review'
                          ? 'bg-amber-300/20 text-amber-100'
                          : 'bg-[rgba(31,138,91,.18)] text-[#7BE0B0]',
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
                  <span key={chip.id} className="rounded-md bg-white/10 px-2.5 py-1 text-xs font-medium text-[#EAF1FA]">
                    {chip.label}{chip.enriched === true ? ' \u2726' : ''}
                  </span>
                ))}
              </div>
            </div>

            <p className="text-start text-[11px] text-[#6E86A5]">{t('editor.hero.helper')}</p>
          </div>
        </div>

        {isUploadOpen && (
          <div className="mt-4 rounded-lg border border-[#2C4A6E] bg-white p-3 text-gray-900">
            {productId !== undefined ? (
              <ProductImageUpload productId={productId} onUploadSuccess={() => { setIsUploadOpen(false) }} />
            ) : (
              <CreateModeImageBuffer bufferedFiles={bufferedFiles} onFilesChange={onBufferedFilesChange} />
            )}
          </div>
        )}
      </div>

    </section>
  )
}
