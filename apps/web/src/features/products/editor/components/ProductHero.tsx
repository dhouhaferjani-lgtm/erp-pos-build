import { ImageIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import { borderColors, colors, textColors, tokens } from '@/lib/designTokens'

interface ProductHeroBrand {
  id?: string
  name: string
  source?: string | null
}

interface ProductHeroCategory {
  id?: number | string
  name: string
}

export interface ProductHeroProduct {
  id: string
  name: string
  sku: string
  barcode: string | null
  is_active: boolean
  sale_price: string | null
  cost_price: string | null
  tax_rate: string | null
  primary_image_url?: string | null
  stock_quantity?: string | null
  brand?: ProductHeroBrand | null
  category?: ProductHeroCategory | null
}

interface ProductHeroProps {
  product: ProductHeroProduct
}

function withMdVariant(url: string | null | undefined): string | null {
  if (url === null || url === undefined || url === '') return null
  if (url.includes('variant=')) return url
  return `${url}${url.includes('?') ? '&' : '?'}variant=md`
}

export function ProductHero({ product }: ProductHeroProps) {
  const { t } = useTranslation(['inventory', 'common'])
  const imageUrl = withMdVariant(product.primary_image_url)

  return (
    <section className={`overflow-hidden rounded-lg border ${borderColors.light} ${colors.white}`}>
      <div className={cn('p-5', tokens.productHero.band)}>
        <div className="grid gap-5 md:grid-cols-[176px_minmax(0,1fr)]">
          <div className={cn('flex h-44 w-44 items-center justify-center overflow-hidden rounded-lg border', borderColors.dark, tokens.productHero.imageSlot)}>
            {imageUrl !== null ? (
              <img src={imageUrl} alt={product.name} className="h-full w-full object-cover" />
            ) : (
              <ImageIcon className={cn('h-12 w-12', tokens.productHero.imageIcon)} aria-hidden="true" />
            )}
          </div>

          <div className="min-w-0 space-y-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
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
            </div>

            <div className="flex flex-wrap gap-2">
              {product.brand !== null && product.brand !== undefined && (
                <span className={cn('rounded-md px-2.5 py-1 text-xs font-medium', tokens.productHero.chip)}>
                  {product.brand.name}{product.brand.source === 'enriched' ? ' \u2726' : ''}
                </span>
              )}
              {product.category !== null && product.category !== undefined && (
                <span className={cn('rounded-md px-2.5 py-1 text-xs font-medium', tokens.productHero.chip)}>
                  {product.category.name}
                </span>
              )}
            </div>
          </div>
        </div>
      </div>
    </section>
  )
}
