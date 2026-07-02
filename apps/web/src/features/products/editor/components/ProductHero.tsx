import { ImageIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { bcadd, bccomp, bcdiv, bcmul, bcsub } from '@/lib/decimal'
import { formatCurrency, formatQuantity } from '@/lib/format'
import { cn } from '@/lib/utils'
import { borderColors, colors, textColors, tokens } from '@/lib/designTokens'

interface ProductHeroBrand {
  id?: string
  name: string
  source?: string | null
}

interface ProductHeroCategory {
  id?: string
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
  currency: string
  locale: string
}

function withMdVariant(url: string | null | undefined): string | null {
  if (url === null || url === undefined || url === '') return null
  if (url.includes('variant=')) return url
  return `${url}${url.includes('?') ? '&' : '?'}variant=md`
}

function salePriceExcludingTax(product: ProductHeroProduct): string | null {
  if (product.sale_price === null) return null
  const taxRate = product.tax_rate ?? '0'
  const divisor = bcadd('1', bcdiv(taxRate, '100', 6), 6)
  return bcdiv(product.sale_price, divisor, 3)
}

function marginPercent(product: ProductHeroProduct): string | null {
  const priceHt = salePriceExcludingTax(product)
  if (priceHt === null || product.cost_price === null || bccomp(product.cost_price, '0') <= 0) {
    return null
  }

  return bcmul(bcdiv(bcsub(priceHt, product.cost_price, 4), product.cost_price, 4), '100', 1)
}

function formatMaybeCurrency(value: string | null, currency: string, locale: string): string {
  if (value === null) return '-'
  return formatCurrency(value, { currency, locale })
}

export function ProductHero({ product, currency, locale }: ProductHeroProps) {
  const { t } = useTranslation(['inventory', 'common'])
  const imageUrl = withMdVariant(product.primary_image_url)
  const priceHt = salePriceExcludingTax(product)
  const margin = marginPercent(product)
  const stockQuantity = product.stock_quantity !== null && product.stock_quantity !== undefined
    ? formatQuantity(product.stock_quantity, 4, locale)
    : '-'

  const stripItems = [
    { label: t('inventory:products.onHandShort'), value: stockQuantity },
    { label: t('inventory:products.costWac'), value: formatMaybeCurrency(product.cost_price, currency, locale) },
    { label: t('inventory:products.marginPercent'), value: margin !== null ? `${margin}%` : '-' },
    { label: t('inventory:products.priceHt'), value: formatMaybeCurrency(priceHt, currency, locale) },
    { label: t('inventory:products.priceTtc'), value: formatMaybeCurrency(product.sale_price, currency, locale) },
  ]

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

      <div className={`${colors.neutral[50]} px-5 py-4`}>
        <div className={cn('mb-3 text-xs font-semibold uppercase tracking-wide', textColors.tertiary)}>
          {t('inventory:products.readyToSell')}
        </div>
        <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
          {stripItems.map((item) => (
            <div key={item.label} className={`rounded-md border ${borderColors.light} ${colors.white} px-3 py-2`}>
              <dt className={cn('text-xs', textColors.tertiary)}>{item.label}</dt>
              <dd className={cn('mt-1 text-sm font-semibold tabular-nums', textColors.primary)}>{item.value}</dd>
            </div>
          ))}
        </dl>
      </div>
    </section>
  )
}
