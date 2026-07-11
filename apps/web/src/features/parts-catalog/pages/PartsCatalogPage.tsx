import { useState, useCallback, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { Wrench, SlidersHorizontal } from 'lucide-react'
import { SearchModeSelector } from '../components/molecules/SearchModeSelector'
import { RecentVehiclesList } from '../components/molecules/RecentVehiclesList'
import { VehicleNavigator } from '../components/organisms/VehicleNavigator'
import { StickyVehicleBar } from '../components/organisms/StickyVehicleBar'
import { PartNumberSearch } from '../components/organisms/PartNumberSearch'
import { TireDimensionSearch } from '../components/organisms/TireDimensionSearch'
import { FilterSidebar } from '../components/organisms/FilterSidebar'
import { CategoryBrowser } from '../components/organisms/CategoryBrowser'
import { ArticleGrid } from '../components/organisms/ArticleGrid'
import { AddToInventoryModal } from '../components/organisms/AddToInventoryModal'
import { useVehicleArticles, useCategoryArticles } from '../hooks/useArticles'
import { useSearchTreeRoots } from '../hooks/useSearchTree'
import { useVehicleStore } from '../stores/useVehicleStore'
import { useCatalogStore } from '../stores/useCatalogStore'
import { SEARCH_MODES_BY_VERTICAL } from '../types/catalog'
import type { EnrichedArticle, TenantVertical } from '../types/catalog'
import { useCompanyConfig } from '../../../contexts/CompanyConfigContext'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'

export function PartsCatalogPage() {
  const { t } = useTranslation(['parts-catalog'])
  const navigate = useNavigate()
  const { config } = useCompanyConfig()

  const vertical = (config?.vertical ?? 'mechanic') as TenantVertical
  const verticalConfig = SEARCH_MODES_BY_VERTICAL[vertical]

  // Stores
  const { selectedVehicle } = useVehicleStore()
  const { searchMode, setSearchMode, activeProductGroupId, setProductGroup } = useCatalogStore()

  // Initialize search mode from vertical config on mount
  useEffect(() => {
    setSearchMode(verticalConfig.default)
  }, [verticalConfig.default, setSearchMode])

  // Vehicle navigator visibility (hidden when vehicle selected unless "Change" clicked)
  const [showVehicleSelector, setShowVehicleSelector] = useState(true)

  // Category state
  const [selectedCategoryId, setSelectedCategoryId] = useState('')

  // Inventory modal
  const [inventoryModalArticle, setInventoryModalArticle] = useState<EnrichedArticle | null>(null)

  // Filter drawer state (mobile)
  const [filterDrawerOpen, setFilterDrawerOpen] = useState(false)

  // VIN/Plate form state
  const [vinInput, setVinInput] = useState('')
  const [plateInput, setPlateInput] = useState('')

  // Article queries
  const vehicleArticles = useVehicleArticles(
    'pc',
    selectedVehicle?.id ?? '',
    activeProductGroupId ?? undefined
  )
  const categoryArticles = useCategoryArticles(selectedCategoryId)

  useSearchTreeRoots() // Prefetch root nodes

  // When a vehicle is selected from any source, collapse the navigator
  useEffect(() => {
    if (selectedVehicle) {
      setShowVehicleSelector(false)
    }
  }, [selectedVehicle])

  const handleArticleSelected = useCallback(
    (article: EnrichedArticle) => {
      void navigate(`/parts-catalog/${article.id}`)
    },
    [navigate]
  )

  const handleCategorySelected = useCallback((nodeId: string) => {
    setSelectedCategoryId(nodeId)
  }, [])

  const handleInventorySuccess = useCallback(() => {
    setInventoryModalArticle(null)
  }, [])

  const handleChangeVehicle = useCallback(() => {
    setShowVehicleSelector(true)
  }, [])

  const handleModeChange = useCallback((mode: typeof searchMode) => {
    setSearchMode(mode)
    setSelectedCategoryId('')
    setProductGroup(null)
  }, [setSearchMode, setProductGroup])

  // Determine active article query based on mode
  const showArticleGrid =
    (searchMode === 'vehicle' && selectedVehicle) ||
    (searchMode === 'category' && selectedCategoryId)

  const activeArticleQuery =
    searchMode === 'vehicle' ? vehicleArticles :
    searchMode === 'category' ? categoryArticles :
    null

  // Render the results section (shared between vehicle + category modes)
  const renderResultsSection = () => {
    if (!showArticleGrid || !activeArticleQuery) return null

    return (
      <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
        {/* Filter sidebar (desktop) */}
        <div className="hidden xl:block lg:col-span-3">
          <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-4`}>
            <FilterSidebar />
          </div>
        </div>

        {/* Article results */}
        <div className="lg:col-span-12 xl:col-span-9">
          {/* Mobile filter toggle */}
          <div className="flex items-center justify-end mb-3 xl:hidden">
            <button
              type="button"
              onClick={() => { setFilterDrawerOpen(true) }}
              className={`inline-flex items-center gap-2 rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} px-3 py-2 text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.intent.neutral.bgHover} transition-colors`}
            >
              <SlidersHorizontal className="h-4 w-4" />
              {t('parts-catalog:filters.openFilters')}
            </button>
          </div>

          <ArticleGrid
            pages={activeArticleQuery.data?.pages}
            isLoading={activeArticleQuery.isLoading}
            hasNextPage={activeArticleQuery.hasNextPage}
            isFetchingNextPage={activeArticleQuery.isFetchingNextPage}
            fetchNextPage={() => { void activeArticleQuery.fetchNextPage() }}
            onArticleSelected={handleArticleSelected}
          />
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Page header */}
      <div>
        <div className="flex items-center gap-3 mb-1">
          <div className={`flex items-center justify-center h-9 w-9 rounded-lg ${colorTokens.intent.primary.bgSubtle}`}>
            <Wrench className={`h-5 w-5 ${colorTokens.intent.primary.text}`} />
          </div>
          <div>
            <PageHeaderTitle className={`text-2xl font-bold ${colorTokens.text.primary}`}>
              {t('parts-catalog:title')}
            </PageHeaderTitle>
            <p className={`text-sm ${colorTokens.text.subtle}`}>
              {t('parts-catalog:subtitle')}
            </p>
          </div>
        </div>
      </div>

      {/* Sticky vehicle bar */}
      <StickyVehicleBar
        onChangeVehicle={handleChangeVehicle}
      />

      {/* Search mode tabs */}
      <SearchModeSelector
        availableModes={verticalConfig.available}
        activeMode={searchMode}
        onModeChange={handleModeChange}
      />

      {/* Main content — each tab fills full width, min height prevents layout shift */}
      <div className="min-h-[480px]">
        {/* ===== VEHICLE TAB ===== */}
        {searchMode === 'vehicle' && !selectedVehicle && (
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div className="lg:col-span-2">
              <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-6`}>
                <VehicleNavigator />
              </div>
            </div>
            <div>
              <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-6`}>
                <RecentVehiclesList />
              </div>
            </div>
          </div>
        )}

        {searchMode === 'vehicle' && selectedVehicle && (
          <div className="space-y-6">
            {/* Show vehicle selector if requested */}
            {showVehicleSelector && (
              <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div className="lg:col-span-2">
                  <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-6`}>
                    <VehicleNavigator />
                  </div>
                </div>
                <div>
                  <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-6`}>
                    <RecentVehiclesList />
                  </div>
                </div>
              </div>
            )}

            {/* Category browser + results */}
            <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
              <div className="lg:col-span-3">
                <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-4`}>
                  <h3 className={`text-sm font-medium ${colorTokens.text.secondary} mb-3`}>
                    {t('parts-catalog:vehicle.browseCategories')}
                  </h3>
                  <CategoryBrowser onCategorySelected={(nodeId) => {
                    setProductGroup(nodeId)
                  }} />
                </div>
              </div>
              <div className="lg:col-span-9">
                {showArticleGrid && activeArticleQuery ? (
                  <div className="space-y-3">
                    {/* Mobile filter toggle */}
                    <div className="flex items-center justify-end xl:hidden">
                      <button
                        type="button"
                        onClick={() => { setFilterDrawerOpen(true) }}
                        className={`inline-flex items-center gap-2 rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} px-3 py-2 text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.intent.neutral.bgHover} transition-colors`}
                      >
                        <SlidersHorizontal className="h-4 w-4" />
                        {t('parts-catalog:filters.openFilters')}
                      </button>
                    </div>

                    <div className="flex gap-6">
                      {/* Filter sidebar (desktop) */}
                      <div className="hidden xl:block w-56 shrink-0">
                        <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-4`}>
                          <FilterSidebar />
                        </div>
                      </div>
                      {/* Article results */}
                      <div className="flex-1 min-w-0">
                        <ArticleGrid
                          pages={activeArticleQuery.data?.pages}
                          isLoading={activeArticleQuery.isLoading}
                          hasNextPage={activeArticleQuery.hasNextPage}
                          isFetchingNextPage={activeArticleQuery.isFetchingNextPage}
                          fetchNextPage={() => { void activeArticleQuery.fetchNextPage() }}
                          onArticleSelected={handleArticleSelected}
                        />
                      </div>
                    </div>
                  </div>
                ) : (
                  <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-6`}>
                    <RecentVehiclesList />
                  </div>
                )}
              </div>
            </div>
          </div>
        )}

        {/* ===== PART NUMBER TAB ===== */}
        {searchMode === 'partNumber' && (
          <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-6`}>
            <PartNumberSearch onArticleSelected={handleArticleSelected} />
          </div>
        )}

        {/* ===== CATEGORY TAB ===== */}
        {searchMode === 'category' && !selectedCategoryId && (
          <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-6`}>
            <h2 className={`text-lg font-semibold ${colorTokens.text.primary} mb-4`}>
              {t('parts-catalog:category.title')}
            </h2>
            <CategoryBrowser onCategorySelected={handleCategorySelected} />
          </div>
        )}

        {searchMode === 'category' && selectedCategoryId && (
          <div className="space-y-4">
            <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-4`}>
              <CategoryBrowser onCategorySelected={handleCategorySelected} />
            </div>
            {renderResultsSection()}
          </div>
        )}

        {/* ===== VIN/PLATE TAB ===== */}
        {searchMode === 'vinPlate' && (
          <div className="space-y-6">
            <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-6`}>
              <h2 className={`text-lg font-semibold ${colorTokens.text.primary} mb-2`}>
                {t('parts-catalog:vinPlate.title')}
              </h2>
              <p className={`text-sm ${colorTokens.text.subtle} mb-6`}>
                {t('parts-catalog:vinPlate.description')}
              </p>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-2xl">
                <div>
                  <label className={`block text-sm font-medium ${colorTokens.text.secondary} mb-1.5`}>
                    {t('parts-catalog:vinPlate.vinLabel')}
                  </label>
                  <input
                    type="text"
                    value={vinInput}
                    onChange={(e) => { setVinInput(e.target.value.toUpperCase()) }}
                    placeholder={t('parts-catalog:vinPlate.vinPlaceholder')}
                    maxLength={17}
                    className={`w-full rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.page} px-3 py-2.5 font-mono uppercase text-sm ${colorTokens.placeholder.textMuted} ${colorTokens.focus.primaryBorder} ${colorTokens.surface.baseOnFocus} focus:outline-none focus:ring-1 ${colorTokens.focus.primaryRing} transition-colors`}
                  />
                </div>
                <div>
                  <label className={`block text-sm font-medium ${colorTokens.text.secondary} mb-1.5`}>
                    {t('parts-catalog:vinPlate.plateLabel')}
                  </label>
                  <input
                    type="text"
                    value={plateInput}
                    onChange={(e) => { setPlateInput(e.target.value.toUpperCase()) }}
                    placeholder={t('parts-catalog:vinPlate.platePlaceholder')}
                    className={`w-full rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.page} px-3 py-2.5 font-mono uppercase text-sm ${colorTokens.placeholder.textMuted} ${colorTokens.focus.primaryBorder} ${colorTokens.surface.baseOnFocus} focus:outline-none focus:ring-1 ${colorTokens.focus.primaryRing} transition-colors`}
                  />
                </div>
              </div>

              <div className="mt-4 flex items-center gap-3">
                <button
                  type="button"
                  disabled
                  className={`rounded-lg ${colorTokens.intent.primary.bgStrong} px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} opacity-50 cursor-not-allowed`}
                >
                  {t('parts-catalog:vinPlate.searchButton')}
                </button>
                <span className={`text-xs ${colorTokens.text.disabled}`}>
                  {t('parts-catalog:vinPlate.comingSoon')}
                </span>
              </div>
            </div>

            <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-6`}>
              <RecentVehiclesList />
            </div>
          </div>
        )}

        {/* ===== TIRE SIZE TAB ===== */}
        {searchMode === 'tireSize' && (
          <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-6`}>
            <TireDimensionSearch onArticleSelected={handleArticleSelected} />
          </div>
        )}
      </div>

      {/* Filter drawer (mobile) */}
      <FilterSidebar
        variant="drawer"
        open={filterDrawerOpen}
        onClose={() => { setFilterDrawerOpen(false) }}
      />

      {/* Add to inventory modal */}
      <AddToInventoryModal
        isOpen={inventoryModalArticle !== null}
        article={inventoryModalArticle}
        onClose={() => { setInventoryModalArticle(null) }}
        onSuccess={handleInventorySuccess}
      />
    </div>
  )
}
