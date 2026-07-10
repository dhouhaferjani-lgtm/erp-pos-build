import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { Search, ImageOff, Images } from 'lucide-react'
import { getProductImages } from '../api/productImages'
import { ImageGalleryModal } from './ImageGalleryModal'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
interface ProductPrimaryImageDisplayProps {
  productId: string
}

export function ProductPrimaryImageDisplay({ productId }: ProductPrimaryImageDisplayProps) {
  const { t } = useTranslation('products')
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const [selectedImageId, setSelectedImageId] = useState<string | null>(null)
  const [isGalleryOpen, setIsGalleryOpen] = useState(false)
  const [galleryInitialView, setGalleryInitialView] = useState<'single' | 'grid'>('grid')
  const [imageErrors, setImageErrors] = useState<Set<string>>(new Set())

  const { data: images = [], isLoading } = useQuery({
    queryKey: tenantScopedKey(['product-images', productId]),
    queryFn: () => getProductImages(productId),
    enabled: !!productId && !!tenantId && !!companyId,
  })

  const handleImageError = (imageId: string) => {
    setImageErrors((prev) => new Set(prev).add(imageId))
  }

  // Filter out images with errors
  const validImages = images.filter((img) => !imageErrors.has(img.id))

  // Determine which image to display as primary
  const primaryImage =
    validImages.find((img) => img.id === selectedImageId) ||
    validImages.find((img) => img.is_primary) ||
    validImages[0]

  const handleThumbnailClick = (imageId: string) => {
    setSelectedImageId(imageId)
  }

  const handleMagnifierClick = () => {
    setGalleryInitialView('single')
    setIsGalleryOpen(true)
  }

  const handleViewGalleryClick = () => {
    setGalleryInitialView('grid')
    setIsGalleryOpen(true)
  }

  const getCurrentImageIndex = () => {
    if (!primaryImage) return 0
    return validImages.findIndex((img) => img.id === primaryImage.id)
  }

  if (isLoading) {
    return (
      <div className="space-y-3">
        <div className={`aspect-square w-full animate-pulse rounded-lg ${colorTokens.surface.subdued}`} />
        <div className="grid grid-cols-4 gap-2">
          {[1, 2, 3, 4].map((i) => (
            <div key={i} className={`aspect-square animate-pulse rounded ${colorTokens.surface.subdued}`} />
          ))}
        </div>
      </div>
    )
  }

  // No images placeholder (or all images failed to load)
  if (validImages.length === 0) {
    return (
      <div className="space-y-3">
        <div className={`flex aspect-square w-full flex-col items-center justify-center rounded-lg border-2 border-dashed ${colorTokens.border.default} ${colorTokens.surface.muted}`}>
          <ImageOff className={`h-12 w-12 ${colorTokens.text.disabled}`} />
          <p className={`mt-2 text-sm ${colorTokens.text.subtle}`}>{t('images.noImage')}</p>
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-3">
      {/* Primary Image */}
      <div className={`group relative aspect-square w-full overflow-hidden rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.muted}`}>
        <img
          src={primaryImage.url ?? undefined}
          alt={primaryImage.alt ?? ''}
          className="h-full w-full object-cover"
          onError={() => { handleImageError(primaryImage.id); }}
        />

        {/* Magnifier Icon Overlay */}
        <button
          onClick={handleMagnifierClick}
          className="absolute top-2 right-2 rounded-full bg-black/40 p-2 text-white opacity-0 transition-opacity hover:bg-black/60 group-hover:opacity-100"
          title={t('images.clickToExpand')}
        >
          <Search className="h-5 w-5" />
        </button>

        {/* Primary Badge */}
        {primaryImage.is_primary && (
          <div className={`absolute top-2 left-2 rounded ${colorTokens.intent.warning.bg} px-2 py-1 text-xs font-medium text-white`}>
            {t('images.primary')}
          </div>
        )}
      </div>

      {/* Thumbnail Grid */}
      {validImages.length > 1 && (
        <div className="grid grid-cols-4 gap-2">
          {validImages.slice(0, 8).map((image) => (
            <button
              key={image.id}
              onClick={() => { handleThumbnailClick(image.id); }}
              className={`group relative aspect-square overflow-hidden rounded border-2 transition-all hover:scale-105 ${
                image.id === primaryImage.id
                  ? `${colorTokens.intent.primary.borderFocus} ring-2 ${colorTokens.intent.primary.ringSoft}`
                  : `border-transparent hover:${colorTokens.border.default}`
              }`}
            >
              <img
                src={image.url ?? undefined}
                alt={image.alt ?? ''}
                className="h-full w-full object-cover"
                onError={() => { handleImageError(image.id); }}
              />
              {image.is_primary && (
                <div className="absolute inset-0 flex items-center justify-center bg-black/0 transition-all group-hover:bg-black/20">
                  <div className={`rounded ${colorTokens.intent.warning.bg} px-1.5 py-0.5 text-[10px] font-medium text-white opacity-0 group-hover:opacity-100`}>
                    {t('images.primary')}
                  </div>
                </div>
              )}
            </button>
          ))}
        </div>
      )}

      {/* View Gallery Button */}
      <button
        onClick={handleViewGalleryClick}
        className={`flex w-full items-center justify-center gap-2 rounded-lg border ${colorTokens.border.default} bg-white px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} transition-colors hover:${colorTokens.surface.page}`}
      >
        <Images className="h-4 w-4" />
        {t('images.viewGallery', { count: validImages.length })}
      </button>

      {/* Image Gallery Modal */}
      <ImageGalleryModal
        productId={productId}
        images={validImages}
        isOpen={isGalleryOpen}
        onClose={() => { setIsGalleryOpen(false); }}
        initialIndex={getCurrentImageIndex()}
        initialView={galleryInitialView}
      />
    </div>
  )
}
