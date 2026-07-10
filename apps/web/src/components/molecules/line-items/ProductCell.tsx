import { ImageIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { borderColors, colors, textColors, tokens } from '../../../lib/designTokens'

export interface ProductCellProduct {
  name: string
  sku?: string | null
  barcode?: string | null
  primary_image_url?: string | null
}

export interface ProductCellProps {
  product: ProductCellProduct
  barcode?: string | null
  stockLabel?: string | null
  /**
   * `xs` (20px thumbnail) is for tightly-constrained inline chips (e.g. a
   * picker's selected-value trigger) — it is opt-in and does not change the
   * `sm`/`md` sizes any other consumer already relies on.
   */
  size?: 'xs' | 'sm' | 'md'
}

export function ProductCell({
  product,
  barcode = product.barcode,
  stockLabel = null,
  size = 'md',
}: ProductCellProps) {
  const { t } = useTranslation(['sales'])
  const imageSize = size === 'xs' ? 'h-5 w-5' : size === 'sm' ? 'h-8 w-8' : 'h-10 w-10'

  return (
    <div className="flex min-w-0 items-center gap-3 text-start">
      {product.primary_image_url !== null && product.primary_image_url !== undefined && product.primary_image_url !== '' ? (
        <img
          src={product.primary_image_url}
          alt={product.name}
          className={`${imageSize} shrink-0 rounded-md border ${borderColors.light} object-cover`}
        />
      ) : (
        <span
          aria-label={t('sales:lineItems.productImagePlaceholder')}
          className={`${imageSize} inline-flex shrink-0 items-center justify-center rounded-md border ${borderColors.light} ${colors.neutral[50]} ${textColors.disabled}`}
        >
          <ImageIcon className="h-4 w-4" aria-hidden="true" />
        </span>
      )}

      <span className="min-w-0 flex-1">
        <span className={`block truncate text-sm font-medium ${textColors.primary}`} title={product.name}>
          {product.name}
        </span>
        <span className="mt-1 flex flex-wrap items-center gap-1.5">
          {product.sku !== null && product.sku !== undefined && product.sku !== '' && (
            <span className={`${tokens.table.cellMonoBadge} ${textColors.secondary}`}>
              {product.sku}
            </span>
          )}
          {barcode !== null && barcode !== undefined && barcode !== '' && (
            <span className={`font-mono text-xs ${textColors.disabled}`}>
              {barcode}
            </span>
          )}
          {stockLabel !== null && stockLabel !== '' && (
            <span className={`${tokens.badge.gray} rounded-md`}>
              {stockLabel}
            </span>
          )}
        </span>
      </span>
    </div>
  )
}
