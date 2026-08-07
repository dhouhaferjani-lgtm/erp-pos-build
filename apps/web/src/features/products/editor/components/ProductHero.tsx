import { ImageIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'

import { textColors, tokens } from '@/lib/designTokens'
import { cn } from '@/lib/utils'
import { productHeroImageSrc } from '../../productHeroImage'
import { ProductHeroShell } from '../../sections/ProductHeroShell'

export type ProductHeroProduct = Pick<
  App.Modules.Product.Application.DTOs.ProductData,
  | 'id'
  | 'name'
  | 'sku'
  | 'barcode'
  | 'is_active'
  | 'primary_image_url'
  | 'brand'
  | 'brand_source'
  | 'category'
>

export function ProductHero({ product }: { product: ProductHeroProduct }) {
  const { t } = useTranslation(['inventory', 'common'])
  const imageUrl = productHeroImageSrc(product.primary_image_url)

  return (
    <ProductHeroShell
      image={imageUrl !== null ? (
        <img src={imageUrl} alt={product.name} className="h-full w-full object-cover" />
      ) : (
        <div className="flex h-full w-full items-center justify-center">
          <ImageIcon className="h-12 w-12" aria-hidden="true" />
        </div>
      )}
      identity={(
        <div className="min-w-0">
          <h2 className={cn('truncate text-2xl font-semibold', textColors.inverse)}>{product.name}</h2>
          <div className="mt-2 flex flex-wrap items-center gap-2">
            <span className={cn('rounded-md px-2 py-1 font-mono text-xs', tokens.productHero.mutedChip)}>
              {t('inventory:products.sku')}: {product.sku}
            </span>
            {product.barcode !== null && product.barcode !== '' && (
              <span className={cn('rounded-md px-2 py-1 font-mono text-xs', tokens.productHero.mutedChip)}>
                {product.barcode}
              </span>
            )}
            <span className={cn(tokens.statusBadge.base, product.is_active ? tokens.statusBadge.completed : tokens.statusBadge.cancelled)}>
              {product.is_active ? t('common:status.active') : t('common:status.inactive')}
            </span>
          </div>
        </div>
      )}
      enrichment={(
        <div className="flex flex-wrap items-center gap-2 text-start">
          {product.brand !== null && product.brand !== undefined && (
            <span className={cn('rounded-md px-2.5 py-1 text-xs font-medium', tokens.productHero.chip)}>
              {product.brand.name}{product.brand_source === 'enriched' ? ' ✦' : ''}
            </span>
          )}
          {product.category !== null && product.category !== undefined && (
            <span className={cn('rounded-md px-2.5 py-1 text-xs font-medium', tokens.productHero.chip)}>
              {product.category.name}
            </span>
          )}
        </div>
      )}
    />
  )
}
