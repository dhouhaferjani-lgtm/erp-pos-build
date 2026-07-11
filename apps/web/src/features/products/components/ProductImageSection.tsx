import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { Image } from 'lucide-react'
import { getProductImages } from '../api/productImages'
import { ProductImageUpload } from './ProductImageUpload'
import { ProductImageGallery } from './ProductImageGallery'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { borderColors, colors, textColors } from '@/lib/designTokens'
import { cn } from '@/lib/utils'

interface ProductImageSectionProps {
  productId: string
  readOnly?: boolean
  embedded?: boolean
}

export function ProductImageSection({
  productId,
  readOnly = false,
  embedded = false,
}: ProductImageSectionProps) {
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
      {!embedded && (
        <div className="flex items-center gap-2">
          <Image className={cn('h-5 w-5', textColors.disabled)} />
          <h2 className={cn('text-lg font-semibold', textColors.primary)}>{t('sections.images')}</h2>
        </div>
      )}

      {/* Upload Area */}
      {!readOnly && (
        <div className={embedded ? '' : cn('rounded-lg border p-6', borderColors.light, colors.white)}>
          <h3 className={cn('mb-4 text-sm font-medium', textColors.primary)}>{t('images.uploadNew')}</h3>
          <ProductImageUpload productId={productId} />
        </div>
      )}

      {/* Current Images */}
      {!isLoading && (images.length > 0 || readOnly) && (
        <div className={embedded ? '' : cn('rounded-lg border p-6', borderColors.light, colors.white)}>
          <h3 className={cn('mb-4 text-sm font-medium', textColors.primary)}>
            {t('images.gallery')} ({images.length})
          </h3>
          <ProductImageGallery productId={productId} images={images} readOnly={readOnly} />
        </div>
      )}
    </div>
  )
}
