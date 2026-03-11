import { useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, ExternalLink, Loader2, Package, Plus, Copy, Check } from 'lucide-react'
import { cn } from '@/lib/utils'
import { useArticleDetailParallel } from '../../hooks/useArticleDetail'
import { InventoryBadge } from '../atoms/InventoryBadge'
import { FitmentConfidenceBadge } from '../atoms/FitmentConfidenceBadge'
import { SpecificationsTable } from './SpecificationsTable'
import { CrossReferenceList } from './CrossReferenceList'
import { VehicleCompatibilityList } from './VehicleCompatibilityList'
import type { EnrichedArticle } from '../../types/catalog'
import { useCopied } from '../../hooks/useCopied'

interface ArticleDetailPanelProps {
  articleId: string
  showDataSource?: boolean
  onBack?: () => void
  onAddToInventory?: (article: EnrichedArticle) => void
  className?: string
}

export function ArticleDetailPanel({
  articleId,
  showDataSource = false,
  onBack,
  onAddToInventory,
  className,
}: ArticleDetailPanelProps) {
  const { t } = useTranslation(['parts-catalog', 'common'])
  const { article, linkages, isLoading, isError } = useArticleDetailParallel(articleId)
  const { copied, copyToClipboard } = useCopied()

  const handleCopyArticleNumber = useCallback(() => {
    if (article) {
      void copyToClipboard(article.article_number)
    }
  }, [article, copyToClipboard])

  if (isLoading) {
    return (
      <div className={cn('flex items-center justify-center py-20', className)}>
        <Loader2 className="h-6 w-6 animate-spin text-gray-400" />
      </div>
    )
  }

  if (isError || !article) {
    return (
      <div className={cn('flex flex-col items-center justify-center py-20', className)}>
        <p className="text-sm text-gray-500">{t('parts-catalog:errors.loadFailed')}</p>
      </div>
    )
  }

  const vehicles = linkages?.vehicles ?? article.compatible_vehicles ?? []
  const maxConfidence = vehicles.length > 0
    ? Math.max(...vehicles.map((v) => v.fitment_confidence))
    : null

  return (
    <div className={cn('flex flex-col pb-20', className)}>
      {/* SECTION 1: Header */}
      <div className="mb-6">
        {onBack && (
          <button
            type="button"
            onClick={onBack}
            className="flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700 mb-3 transition-colors"
          >
            <ArrowLeft className="h-4 w-4" />
            {t('parts-catalog:category.backToParent')}
          </button>
        )}
        <div className="flex items-center gap-2.5 mb-2 flex-wrap">
          <span className="inline-flex items-center rounded-md bg-blue-50 px-2.5 py-1 text-sm font-bold text-blue-700 ring-1 ring-inset ring-blue-600/20 uppercase tracking-wide">
            {article.supplier?.brand ?? 'Unknown'}
          </span>
          {article.local_inventory && <InventoryBadge inventory={article.local_inventory} showQuantity />}
          {maxConfidence !== null && (
            <FitmentConfidenceBadge confidence={maxConfidence} />
          )}
        </div>
        <div className="flex items-center gap-2">
          <h2 className="text-xl font-mono font-bold text-gray-900">
            {article.article_number}
          </h2>
          <button
            type="button"
            onClick={handleCopyArticleNumber}
            className="rounded-md p-1 text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors"
            aria-label={t('parts-catalog:article.copyArticleNumber')}
          >
            {copied ? (
              <Check className="h-4 w-4 text-emerald-500" />
            ) : (
              <Copy className="h-4 w-4" />
            )}
          </button>
        </div>
      </div>

      {/* Your Inventory section (if linked) */}
      {article.local_inventory?.in_stock && article.local_inventory?.product_id && (
        <div className="rounded-lg border border-emerald-200 bg-emerald-50/50 p-4 mb-6">
          <div className="flex items-center gap-2 mb-2">
            <Package className="h-4 w-4 text-emerald-600" />
            <h3 className="text-sm font-semibold text-emerald-800">
              {t('parts-catalog:article.yourInventory')}
            </h3>
          </div>
          <div className="grid grid-cols-2 gap-2 text-sm">
            <p className="text-emerald-700">
              {t('parts-catalog:inventory.quantity', {
                available: article.local_inventory.available_quantity,
                total: article.local_inventory.total_quantity,
              })}
            </p>
            {article.local_inventory.sale_price && (
              <p className="text-emerald-700">
                {t('parts-catalog:inventory.salePrice', {
                  price: article.local_inventory.sale_price,
                })}
              </p>
            )}
          </div>
          <a
            href={`/products/${article.local_inventory.product_id}`}
            className="inline-flex items-center gap-1 mt-2 text-xs text-emerald-600 hover:text-emerald-700 transition-colors"
          >
            {t('parts-catalog:inventory.editProduct')}
            <ExternalLink className="h-3 w-3" />
          </a>
        </div>
      )}

      {/* SECTION 3: Specifications */}
      {(article.criteria ?? []).length > 0 && (
        <div className="mb-6">
          <h3 className="text-sm font-semibold text-gray-900 mb-3">
            {t('parts-catalog:article.specifications')}
          </h3>
          <SpecificationsTable criteria={article.criteria ?? []} />
        </div>
      )}

      {/* SECTION 4: Cross References */}
      {(article.cross_references ?? []).length > 0 && (
        <div className="mb-6">
          <h3 className="text-sm font-semibold text-gray-900 mb-3">
            {t('parts-catalog:article.crossReferences')}
          </h3>
          <CrossReferenceList crossReferences={article.cross_references ?? []} />
        </div>
      )}

      {/* SECTION 5: Vehicle Compatibility */}
      {vehicles.length > 0 && (
        <div className="mb-6">
          <h3 className="text-sm font-semibold text-gray-900 mb-3">
            {t('parts-catalog:article.vehicleCompatibility')}
          </h3>
          <VehicleCompatibilityList
            vehicles={vehicles}
            showDataSource={showDataSource}
          />
        </div>
      )}

      {/* SECTION 6: Pricing */}
      {(article.prices ?? []).length > 0 && (
        <div className="mb-6">
          <h3 className="text-sm font-semibold text-gray-900 mb-3">
            {t('parts-catalog:article.pricing')}
          </h3>
          <div className="space-y-1.5">
            {(article.prices ?? []).map((price, i) => (
              <div
                key={`${price.price_type}-${String(i)}`}
                className="flex items-center justify-between rounded-md bg-gray-50 px-3.5 py-2.5"
              >
                <span className="text-sm text-gray-600 capitalize">{price.price_type}</span>
                <span className="text-sm font-semibold text-gray-900">
                  {price.price} {price.currency_code}
                </span>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Sticky bottom action bar */}
      {onAddToInventory && !article.local_inventory?.product_id && (
        <div className="fixed bottom-0 inset-x-0 z-20 bg-white border-t border-gray-200 px-6 py-3 shadow-lg">
          <div className="mx-auto max-w-5xl flex items-center justify-between">
            <div className="text-sm text-gray-600">
              <span className="font-medium">{article.supplier?.brand ?? 'Unknown'}</span>{' '}
              <span className="font-mono">{article.article_number}</span>
            </div>
            <button
              type="button"
              onClick={() => { onAddToInventory(article) }}
              className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-blue-700 transition-colors"
            >
              <Plus className="h-4 w-4" />
              {t('parts-catalog:inventory.addToInventory')}
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
