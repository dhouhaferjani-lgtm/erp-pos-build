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
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

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
        <Loader2 className={`h-6 w-6 animate-spin ${colorTokens.text.disabled}`} />
      </div>
    )
  }

  if (isError || !article) {
    return (
      <div className={cn('flex flex-col items-center justify-center py-20', className)}>
        <p className={`text-sm ${colorTokens.text.subtle}`}>{t('parts-catalog:errors.loadFailed')}</p>
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
            className={`flex items-center gap-1 text-sm ${colorTokens.text.subtle} ${colorTokens.intent.neutral.textHoverStrong} mb-3 transition-colors`}
          >
            <ArrowLeft className="h-4 w-4" />
            {t('parts-catalog:category.backToParent')}
          </button>
        )}
        <div className="flex items-center gap-2.5 mb-2 flex-wrap">
          <span className={`inline-flex items-center rounded-md ${colorTokens.intent.primary.bgSubtle} px-2.5 py-1 text-sm font-bold ${colorTokens.intent.primary.textStrong} ring-1 ring-inset ${colorTokens.intent.primary.ringSubtle} uppercase tracking-wide`}>
            {article.supplier?.brand ?? 'Unknown'}
          </span>
          {article.local_inventory && <InventoryBadge inventory={article.local_inventory} showQuantity />}
          {maxConfidence !== null && (
            <FitmentConfidenceBadge confidence={maxConfidence} />
          )}
        </div>
        <div className="flex items-center gap-2">
          <h2 className={`text-xl font-mono font-bold ${colorTokens.text.primary}`}>
            {article.article_number}
          </h2>
          <button
            type="button"
            onClick={handleCopyArticleNumber}
            className={`rounded-md p-1 ${colorTokens.text.disabled} ${colorTokens.intent.neutral.textHover} ${colorTokens.intent.neutral.bgHoverSoft} transition-colors`}
            aria-label={t('parts-catalog:article.copyArticleNumber')}
          >
            {copied ? (
              <Check className={`h-4 w-4 ${colorTokens.intent.available.text}`} />
            ) : (
              <Copy className="h-4 w-4" />
            )}
          </button>
        </div>
      </div>

      {/* Your Inventory section (if linked) */}
      {article.local_inventory?.in_stock && article.local_inventory?.product_id && (
        <div className={`rounded-lg border ${colorTokens.intent.available.borderSubtle} ${colorTokens.intent.available.bgSubtleAlpha} p-4 mb-6`}>
          <div className="flex items-center gap-2 mb-2">
            <Package className={`h-4 w-4 ${colorTokens.intent.available.textMid}`} />
            <h3 className={`text-sm font-semibold ${colorTokens.intent.available.textStronger}`}>
              {t('parts-catalog:article.yourInventory')}
            </h3>
          </div>
          <div className="grid grid-cols-2 gap-2 text-sm">
            <p className={`${colorTokens.intent.available.textStrong}`}>
              {t('parts-catalog:inventory.quantity', {
                available: article.local_inventory.available_quantity,
                total: article.local_inventory.total_quantity,
              })}
            </p>
            {article.local_inventory.sale_price && (
              <p className={`${colorTokens.intent.available.textStrong}`}>
                {t('parts-catalog:inventory.salePrice', {
                  price: article.local_inventory.sale_price,
                })}
              </p>
            )}
          </div>
          <a
            href={`/products/${article.local_inventory.product_id}`}
            className={`inline-flex items-center gap-1 mt-2 text-xs ${colorTokens.intent.available.textMid} ${colorTokens.intent.available.textHoverStrong} transition-colors`}
          >
            {t('parts-catalog:inventory.editProduct')}
            <ExternalLink className="h-3 w-3" />
          </a>
        </div>
      )}

      {/* SECTION 3: Specifications */}
      {(article.criteria ?? []).length > 0 && (
        <div className="mb-6">
          <h3 className={`text-sm font-semibold ${colorTokens.text.primary} mb-3`}>
            {t('parts-catalog:article.specifications')}
          </h3>
          <SpecificationsTable criteria={article.criteria ?? []} />
        </div>
      )}

      {/* SECTION 4: Cross References */}
      {(article.cross_references ?? []).length > 0 && (
        <div className="mb-6">
          <h3 className={`text-sm font-semibold ${colorTokens.text.primary} mb-3`}>
            {t('parts-catalog:article.crossReferences')}
          </h3>
          <CrossReferenceList crossReferences={article.cross_references ?? []} />
        </div>
      )}

      {/* SECTION 5: Vehicle Compatibility */}
      {vehicles.length > 0 && (
        <div className="mb-6">
          <h3 className={`text-sm font-semibold ${colorTokens.text.primary} mb-3`}>
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
          <h3 className={`text-sm font-semibold ${colorTokens.text.primary} mb-3`}>
            {t('parts-catalog:article.pricing')}
          </h3>
          <div className="space-y-1.5">
            {(article.prices ?? []).map((price, i) => (
              <div
                key={`${price.price_type}-${String(i)}`}
                className={`flex items-center justify-between rounded-md ${colorTokens.surface.page} px-3.5 py-2.5`}
              >
                <span className={`text-sm ${colorTokens.text.muted} capitalize`}>{price.price_type}</span>
                <span className={`text-sm font-semibold ${colorTokens.text.primary}`}>
                  {price.price} {price.currency_code}
                </span>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Sticky bottom action bar */}
      {onAddToInventory && !article.local_inventory?.product_id && (
        <div className={`fixed bottom-0 inset-x-0 z-20 ${colorTokens.surface.base} border-t ${colorTokens.border.subtle} px-6 py-3 shadow-lg`}>
          <div className="mx-auto max-w-5xl flex items-center justify-between">
            <div className={`text-sm ${colorTokens.text.muted}`}>
              <span className="font-medium">{article.supplier?.brand ?? 'Unknown'}</span>{' '}
              <span className="font-mono">{article.article_number}</span>
            </div>
            <button
              type="button"
              onClick={() => { onAddToInventory(article) }}
              className={`inline-flex items-center gap-2 rounded-lg ${colorTokens.intent.primary.bgStrong} px-5 py-2.5 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.primary.bgStrongHover} transition-colors`}
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
