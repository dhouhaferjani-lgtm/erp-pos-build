import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { X, Grid3x3, Image as ImageIcon, ChevronLeft, ChevronRight } from 'lucide-react'
import type { ProductImage } from '../types'
import { getProductImageDownloadUrl } from '../api/productImages'

interface ImageGalleryModalProps {
  productId: string
  images: ProductImage[]
  isOpen: boolean
  onClose: () => void
  initialIndex?: number
  initialView?: 'single' | 'grid'
}

export function ImageGalleryModal({
  productId,
  images,
  isOpen,
  onClose,
  initialIndex = 0,
  initialView = 'grid',
}: ImageGalleryModalProps) {
  const { t } = useTranslation('products')
  const [viewMode, setViewMode] = useState<'single' | 'grid'>(initialView)
  const [currentIndex, setCurrentIndex] = useState(initialIndex)

  // Reset state when modal opens
  useEffect(() => {
    if (isOpen) {
      setViewMode(initialView)
      setCurrentIndex(initialIndex)
    }
  }, [isOpen, initialView, initialIndex])

  // Handle ESC key to close
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (!isOpen) return

      if (e.key === 'Escape') {
        onClose()
      } else if (viewMode === 'single') {
        if (e.key === 'ArrowLeft') {
          handlePrevious()
        } else if (e.key === 'ArrowRight') {
          handleNext()
        }
      }
    }

    window.addEventListener('keydown', handleKeyDown)
    return () => { window.removeEventListener('keydown', handleKeyDown); }
  }, [isOpen, viewMode, currentIndex, images.length])

  // Prevent body scroll when modal is open
  useEffect(() => {
    if (isOpen) {
      document.body.style.overflow = 'hidden'
    } else {
      document.body.style.overflow = 'unset'
    }
    return () => {
      document.body.style.overflow = 'unset'
    }
  }, [isOpen])

  const handlePrevious = () => {
    setCurrentIndex((prev) => (prev === 0 ? images.length - 1 : prev - 1))
  }

  const handleNext = () => {
    setCurrentIndex((prev) => (prev === images.length - 1 ? 0 : prev + 1))
  }

  const handleImageClick = (index: number) => {
    setCurrentIndex(index)
    setViewMode('single')
  }

  const toggleViewMode = () => {
    setViewMode((prev) => (prev === 'grid' ? 'single' : 'grid'))
  }

  if (!isOpen) return null

  const currentImage = images[currentIndex]

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/90"
      onClick={onClose}
    >
      {/* Modal Content */}
      <div
        className="relative max-h-[95vh] max-w-[95vw]"
        onClick={(e) => { e.stopPropagation(); }}
      >
        {/* Top Controls Bar */}
        <div className="absolute top-0 left-0 right-0 z-10 flex items-center justify-between p-4">
          {/* View Mode Toggle (Left) */}
          <button
            onClick={toggleViewMode}
            className="rounded-lg bg-black/60 p-2 text-white transition-colors hover:bg-black/80"
            title={viewMode === 'grid' ? t('images.viewSingle') : t('images.viewGrid')}
          >
            {viewMode === 'grid' ? (
              <ImageIcon className="h-5 w-5" />
            ) : (
              <Grid3x3 className="h-5 w-5" />
            )}
          </button>

          {/* Close Button (Right) */}
          <button
            onClick={onClose}
            className="rounded-lg bg-black/60 p-2 text-white transition-colors hover:bg-black/80"
            title={t('common:actions.close')}
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        {/* Grid View */}
        {viewMode === 'grid' && (
          <div className="max-h-[90vh] overflow-y-auto p-16">
            <div className="mb-6 text-center">
              <p className="text-lg font-medium text-white">
                {t('images.imageCount', { count: images.length })}
              </p>
            </div>
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
              {images.map((image, index) => (
                <button
                  key={image.id}
                  onClick={() => { handleImageClick(index); }}
                  className="group relative aspect-square overflow-hidden rounded-lg bg-gray-800 transition-transform hover:scale-105"
                >
                  <img
                    src={getProductImageDownloadUrl(productId, image.id)}
                    alt={image.original_filename}
                    className="h-full w-full object-cover"
                  />
                  {image.is_primary && (
                    <div className="absolute top-2 right-2 rounded bg-yellow-500 px-2 py-1 text-xs font-medium text-white">
                      {t('images.primary')}
                    </div>
                  )}
                  <div className="absolute inset-0 flex items-center justify-center bg-black/0 opacity-0 transition-all group-hover:bg-black/40 group-hover:opacity-100">
                    <ImageIcon className="h-8 w-8 text-white" />
                  </div>
                </button>
              ))}
            </div>
          </div>
        )}

        {/* Single Image View */}
        {viewMode === 'single' && currentImage && (
          <div className="flex items-center justify-center p-16">
            {/* Left Arrow */}
            {images.length > 1 && (
              <button
                onClick={handlePrevious}
                className="absolute left-4 top-1/2 -translate-y-1/2 rounded-lg bg-black/60 p-3 text-white transition-colors hover:bg-black/80"
                title={t('common:actions.previous')}
              >
                <ChevronLeft className="h-6 w-6" />
              </button>
            )}

            {/* Image */}
            <div className="relative max-h-[90vh] max-w-[90vw]">
              <img
                src={getProductImageDownloadUrl(productId, currentImage.id)}
                alt={currentImage.original_filename}
                className="max-h-[90vh] max-w-[90vw] rounded-lg object-contain"
              />
              {currentImage.is_primary && (
                <div className="absolute top-4 right-4 rounded-lg bg-yellow-500 px-3 py-1.5 text-sm font-medium text-white">
                  {t('images.primary')}
                </div>
              )}
            </div>

            {/* Right Arrow */}
            {images.length > 1 && (
              <button
                onClick={handleNext}
                className="absolute right-4 top-1/2 -translate-y-1/2 rounded-lg bg-black/60 p-3 text-white transition-colors hover:bg-black/80"
                title={t('common:actions.next')}
              >
                <ChevronRight className="h-6 w-6" />
              </button>
            )}

            {/* Image Counter */}
            {images.length > 1 && (
              <div className="absolute bottom-4 left-1/2 -translate-x-1/2 rounded-lg bg-black/60 px-4 py-2 text-sm text-white">
                {currentIndex + 1} / {images.length}
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  )
}
