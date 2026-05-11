import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { Image } from 'lucide-react'
import { getProductImages } from '../api/productImages'
import { ProductImageUpload } from './ProductImageUpload'
import { ProductImageGallery } from './ProductImageGallery'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

interface ProductImageSectionProps {
  productId: string
}

export function ProductImageSection({ productId }: ProductImageSectionProps) {
  const { t } = useTranslation('products')
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  const { data: images = [], isLoading } = useQuery({
    queryKey: tenantScopedKey(['product-images', productId]),
    queryFn: () => getProductImages(productId),
    enabled: !!productId && !!tenantId && !!companyId,
  })

  return (
    <div className="space-y-6">
      {/* Section Header */}
      <div className="flex items-center gap-2">
        <Image className="h-5 w-5 text-gray-400" />
        <h2 className="text-lg font-semibold text-gray-900">{t('sections.images')}</h2>
      </div>

      {/* Upload Area */}
      <div className="rounded-lg border border-gray-200 bg-white p-6">
        <h3 className="mb-4 text-sm font-medium text-gray-900">{t('images.uploadNew')}</h3>
        <ProductImageUpload productId={productId} />
      </div>

      {/* Current Images */}
      {!isLoading && images.length > 0 && (
        <div className="rounded-lg border border-gray-200 bg-white p-6">
          <h3 className="mb-4 text-sm font-medium text-gray-900">
            {t('images.gallery')} ({images.length})
          </h3>
          <ProductImageGallery productId={productId} images={images} readOnly={false} />
        </div>
      )}
    </div>
  )
}
