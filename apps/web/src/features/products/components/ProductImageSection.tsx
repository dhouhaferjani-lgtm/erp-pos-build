import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { Image } from 'lucide-react'
import { getProductImages } from '../api/productImages'
import { ProductImageUpload } from './ProductImageUpload'
import { ProductImageGallery } from './ProductImageGallery'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

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
        <Image className={`h-5 w-5 ${colorTokens.text.disabled}`} />
        <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>{t('sections.images')}</h2>
      </div>

      {/* Upload Area */}
      <div className={`rounded-lg border ${colorTokens.border.subtle} bg-white p-6`}>
        <h3 className={`mb-4 text-sm font-medium ${colorTokens.text.primary}`}>{t('images.uploadNew')}</h3>
        <ProductImageUpload productId={productId} />
      </div>

      {/* Current Images */}
      {!isLoading && images.length > 0 && (
        <div className={`rounded-lg border ${colorTokens.border.subtle} bg-white p-6`}>
          <h3 className={`mb-4 text-sm font-medium ${colorTokens.text.primary}`}>
            {t('images.gallery')} ({images.length})
          </h3>
          <ProductImageGallery productId={productId} images={images} readOnly={false} />
        </div>
      )}
    </div>
  )
}
