import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Star, Trash2, Loader2 } from 'lucide-react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import {
  deleteProductImage,
  setProductImagePrimary,
  getProductImageDownloadUrl,
} from '../api/productImages'
import type { ProductMediaItem } from '../types'
import { getErrorMessage } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

interface ProductImageGalleryProps {
  productId: string
  images: ProductMediaItem[]
  readOnly?: boolean
}

export function ProductImageGallery({
  productId,
  images,
  readOnly = false,
}: ProductImageGalleryProps) {
  const { t } = useTranslation(['products', 'common'])
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const [deletingId, setDeletingId] = useState<string | null>(null)
  const [settingPrimaryId, setSettingPrimaryId] = useState<string | null>(null)

  const deleteMutation = useMutation({
    mutationFn: (imageId: string) => deleteProductImage(productId, imageId),
    onMutate: (imageId) => {
      setDeletingId(imageId)
    },
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: tenantScopedKey(['product-images', productId]) }),
        queryClient.invalidateQueries({ queryKey: tenantScopedKey(['product', productId]) }),
      ])
      // tenantId/companyId referenced so eslint doesn't drop the subscription;
      // the closure above relies on the host re-render captured via these reads.
      void tenantId
      void companyId
    },
    onError: (error) => {
      alert(getErrorMessage(error))
    },
    onSettled: () => {
      setDeletingId(null)
    },
  })

  const setPrimaryMutation = useMutation({
    mutationFn: (imageId: string) => setProductImagePrimary(productId, imageId),
    onMutate: (imageId) => {
      setSettingPrimaryId(imageId)
    },
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: tenantScopedKey(['product-images', productId]) }),
        queryClient.invalidateQueries({ queryKey: tenantScopedKey(['product', productId]) }),
      ])
    },
    onError: (error) => {
      alert(getErrorMessage(error))
    },
    onSettled: () => {
      setSettingPrimaryId(null)
    },
  })

  const handleDelete = (imageId: string) => {
    if (readOnly) return
    if (confirm(t('products:images.confirmDelete'))) {
      deleteMutation.mutate(imageId)
    }
  }

  const handleSetPrimary = (imageId: string) => {
    if (readOnly) return
    setPrimaryMutation.mutate(imageId)
  }

  if (images.length === 0) {
    return (
      <div className="rounded-lg border border-gray-200 bg-gray-50 p-8 text-center">
        <p className="text-sm text-gray-500">{t('products:images.noImages')}</p>
      </div>
    )
  }

  // Sort images by sort_order
  const sortedImages = [...images].sort((a, b) => a.sort_order - b.sort_order)

  return (
    <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
      {sortedImages.map((image) => (
        <div key={image.id} className="group relative">
          {/* Image Container */}
          <div className="relative aspect-square overflow-hidden rounded-lg border border-gray-200 bg-gray-100">
            <img
              src={getProductImageDownloadUrl(productId, image.id)}
              alt={image.alt ?? ''}
              className="h-full w-full object-cover"
              loading="lazy"
            />

            {/* Primary Badge */}
            {image.is_primary && (
              <div className="absolute left-2 top-2">
                <div className="flex items-center gap-1 rounded-full bg-yellow-500 px-2 py-1 text-xs font-medium text-white shadow-sm">
                  <Star className="h-3 w-3 fill-white" />
                  {t('products:images.primary')}
                </div>
              </div>
            )}

            {/* Overlay with Actions - Only show if not readOnly */}
            {!readOnly && (
              <div className="absolute inset-0 flex items-center justify-center gap-2 bg-black/50 opacity-0 transition-opacity group-hover:opacity-100">
                {!image.is_primary && (
                  <button
                    onClick={() => { handleSetPrimary(image.id); }}
                    disabled={settingPrimaryId === image.id}
                    className="flex h-8 w-8 items-center justify-center rounded-full bg-white text-gray-700 shadow-sm transition-colors hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-50"
                    title={t('products:images.setPrimary')}
                  >
                    {settingPrimaryId === image.id ? (
                      <Loader2 className="h-4 w-4 animate-spin" />
                    ) : (
                      <Star className="h-4 w-4" />
                    )}
                  </button>
                )}

                <button
                  onClick={() => { handleDelete(image.id); }}
                  disabled={deletingId === image.id}
                  className="flex h-8 w-8 items-center justify-center rounded-full bg-white text-red-600 shadow-sm transition-colors hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-50"
                  title={t('common:delete')}
                >
                  {deletingId === image.id ? (
                    <Loader2 className="h-4 w-4 animate-spin" />
                  ) : (
                    <Trash2 className="h-4 w-4" />
                  )}
                </button>
              </div>
            )}
          </div>

          {/* Image Info */}
          <div className="mt-1 px-1">
            <p className="truncate text-xs text-gray-600" title={image.alt ?? ''}>
              {image.alt ?? ''}
            </p>
          </div>
        </div>
      ))}
    </div>
  )
}
