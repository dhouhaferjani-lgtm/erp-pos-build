import { useState, useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { X, Minus, Plus } from 'lucide-react'
import { SearchModeSelector } from '../molecules/SearchModeSelector'
import { VehicleNavigator } from '../organisms/VehicleNavigator'
import { PartNumberSearch } from '../organisms/PartNumberSearch'
import { CategoryBrowser } from '../organisms/CategoryBrowser'
import { ArticleDetailPanel } from '../organisms/ArticleDetailPanel'
import { ArticleGrid } from '../organisms/ArticleGrid'
import { useVehicleArticles, useCategoryArticles } from '../../hooks/useArticles'
import { useSearchTreeRoots } from '../../hooks/useSearchTree'
import type {
  EnrichedArticle,
  SearchMode,
  Vehicle,
  PartsCatalogPanelProps,
} from '../../types/catalog'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

const PANEL_MODES: SearchMode[] = ['vehicle', 'partNumber', 'category']

export function PartsCatalogPanel({
  isOpen,
  onClose,
  onAddArticle,
  documentType,
  vehicleId: preSelectedVehicleId,
}: PartsCatalogPanelProps) {
  const { t } = useTranslation(['parts-catalog', 'common'])
  const [searchMode, setSearchMode] = useState<SearchMode>('partNumber')
  const [selectedVehicle, setSelectedVehicle] = useState<Vehicle | null>(null)
  const [selectedCategoryId, setSelectedCategoryId] = useState('')
  const [selectedArticle, setSelectedArticle] = useState<EnrichedArticle | null>(null)
  const [addQuantity, setAddQuantity] = useState(1)

  const vehicleIdForArticles = preSelectedVehicleId ?? selectedVehicle?.id ?? ''

  const vehicleArticles = useVehicleArticles('pc', vehicleIdForArticles)
  const categoryArticles = useCategoryArticles(selectedCategoryId)

  // Determine which article source to use
  const activeArticleQuery =
    searchMode === 'vehicle' ? vehicleArticles :
    searchMode === 'category' ? categoryArticles :
    null

  useSearchTreeRoots() // Prefetch

  const handleArticleSelected = useCallback((article: EnrichedArticle) => {
    setSelectedArticle(article)
    setAddQuantity(1)
  }, [])

  const handleAddToDocument = useCallback(() => {
    if (!selectedArticle) return
    onAddArticle(selectedArticle, addQuantity)
    setSelectedArticle(null)
    setAddQuantity(1)
  }, [selectedArticle, addQuantity, onAddArticle])

  const addLabel =
    documentType === 'purchase_order' ? t('parts-catalog:panel.addToPurchaseOrder') :
    documentType === 'sales_order' ? t('parts-catalog:panel.addToSalesOrder') :
    t('parts-catalog:panel.addToQuote')

  if (!isOpen) return null

  return (
    <div className="fixed inset-0 z-50 flex justify-end">
      {/* Backdrop */}
      <div
        className={`absolute inset-0 ${colorTokens.surface.overlaySubtle}`}
        onClick={onClose}
        role="presentation"
      />

      {/* Panel */}
      <div className={`relative w-full max-w-lg ${colorTokens.surface.base} shadow-2xl flex flex-col h-full animate-in slide-in-from-right duration-300`}>
        {/* Header */}
        <div className={`flex items-center justify-between border-b ${colorTokens.border.subtle} px-5 py-4`}>
          <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>
            {t('parts-catalog:panel.title')}
          </h2>
          <button
            type="button"
            onClick={onClose}
            className={`p-1.5 rounded-lg ${colorTokens.text.disabled} ${colorTokens.intent.neutral.textHover} ${colorTokens.intent.neutral.bgHoverSoft} transition-colors`}
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        {/* Content */}
        <div className="flex-1 overflow-y-auto px-5 py-4">
          {selectedArticle ? (
            // Article detail with add-to-document action
            <div className="flex flex-col h-full">
              <ArticleDetailPanel
                articleId={selectedArticle.id}
                onBack={() => { setSelectedArticle(null) }}
                className="flex-1"
              />

              {/* Add to document footer */}
              <div className={`sticky bottom-0 ${colorTokens.surface.base} border-t ${colorTokens.border.subtle} pt-4 mt-4`}>
                <div className="flex items-center gap-3">
                  {/* Quantity selector */}
                  <div className={`flex items-center rounded-lg border ${colorTokens.border.subtle}`}>
                    <button
                      type="button"
                      onClick={() => { setAddQuantity((q) => Math.max(1, q - 1)) }}
                      className={`p-2 ${colorTokens.text.subtle} ${colorTokens.intent.neutral.bgHover}`}
                    >
                      <Minus className="h-4 w-4" />
                    </button>
                    <span className={`px-3 text-sm font-medium ${colorTokens.text.primary} tabular-nums min-w-[2rem] text-center`}>
                      {addQuantity}
                    </span>
                    <button
                      type="button"
                      onClick={() => { setAddQuantity((q) => q + 1) }}
                      className={`p-2 ${colorTokens.text.subtle} ${colorTokens.intent.neutral.bgHover}`}
                    >
                      <Plus className="h-4 w-4" />
                    </button>
                  </div>

                  {/* Add button */}
                  <button
                    type="button"
                    onClick={handleAddToDocument}
                    className={`flex-1 rounded-lg ${colorTokens.intent.primary.bgStrong} px-4 py-2.5 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.primary.bgStrongHover} transition-colors`}
                  >
                    {addLabel}
                  </button>
                </div>
              </div>
            </div>
          ) : (
            <>
              {/* Search mode selector */}
              <SearchModeSelector
                availableModes={PANEL_MODES}
                activeMode={searchMode}
                onModeChange={setSearchMode}
                className="mb-5"
              />

              {/* Search content */}
              {searchMode === 'vehicle' && (
                <div>
                  {!selectedVehicle ? (
                    <VehicleNavigator onVehicleSelected={setSelectedVehicle} />
                  ) : (
                    <ArticleGrid
                      pages={activeArticleQuery?.data?.pages}
                      isLoading={activeArticleQuery?.isLoading ?? false}
                      hasNextPage={activeArticleQuery?.hasNextPage ?? false}
                      isFetchingNextPage={activeArticleQuery?.isFetchingNextPage ?? false}
                      fetchNextPage={() => { void activeArticleQuery?.fetchNextPage() }}
                      onArticleSelected={handleArticleSelected}
                    />
                  )}
                </div>
              )}

              {searchMode === 'partNumber' && (
                <PartNumberSearch onArticleSelected={handleArticleSelected} />
              )}

              {searchMode === 'category' && (
                <div>
                  {!selectedCategoryId ? (
                    <CategoryBrowser onCategorySelected={setSelectedCategoryId} />
                  ) : (
                    <ArticleGrid
                      pages={activeArticleQuery?.data?.pages}
                      isLoading={activeArticleQuery?.isLoading ?? false}
                      hasNextPage={activeArticleQuery?.hasNextPage ?? false}
                      isFetchingNextPage={activeArticleQuery?.isFetchingNextPage ?? false}
                      fetchNextPage={() => { void activeArticleQuery?.fetchNextPage() }}
                      onArticleSelected={handleArticleSelected}
                    />
                  )}
                </div>
              )}
            </>
          )}
        </div>
      </div>
    </div>
  )
}
