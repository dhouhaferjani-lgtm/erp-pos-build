import { useState, useCallback, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { Search, Loader2, Info, Barcode } from 'lucide-react'
import { cn } from '@/lib/utils'
import { useMultiSearch } from '../../hooks/useMultiSearch'
import { ArticleCard } from '../molecules/ArticleCard'
import type { EnrichedArticle } from '../../types/catalog'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface PartNumberSearchProps {
  onArticleSelected: (article: EnrichedArticle) => void
  className?: string
}

export function PartNumberSearch({ onArticleSelected, className }: PartNumberSearchProps) {
  const { t } = useTranslation(['parts-catalog'])
  const [inputValue, setInputValue] = useState('')
  const [searchQuery, setSearchQuery] = useState('')

  const { data, isLoading, isError } = useMultiSearch(searchQuery)

  const isEanBarcode = useMemo(() => /^\d{13}$/.test(inputValue.trim()), [inputValue])

  const handleSubmit = useCallback(
    (e: React.FormEvent) => {
      e.preventDefault()
      const trimmed = inputValue.trim()
      if (trimmed.length >= 3) {
        setSearchQuery(trimmed)
      }
    },
    [inputValue]
  )

  return (
    <div className={cn('flex flex-col', className)}>
      <h2 className={`text-lg font-semibold ${colorTokens.text.primary} mb-4`}>
        {t('parts-catalog:partNumber.title')}
      </h2>

      <div className="max-w-2xl mx-auto w-full">
        <form onSubmit={handleSubmit} className="relative">
          <Search className={`absolute start-3.5 top-1/2 -translate-y-1/2 h-4.5 w-4.5 ${colorTokens.text.disabled}`} />
          <input
            type="text"
            value={inputValue}
            onChange={(e) => { setInputValue(e.target.value) }}
            placeholder={t('parts-catalog:partNumber.placeholder')}
            className={`w-full rounded-xl border ${colorTokens.border.subtle} ${colorTokens.surface.page} py-3 ps-11 pe-4 text-sm ${colorTokens.placeholder.textMuted} ${colorTokens.focus.primaryBorder} ${colorTokens.surface.baseOnFocus} focus:outline-none focus:ring-1 ${colorTokens.focus.primaryRing} transition-colors`}
          />
          {isLoading && (
            <Loader2 className={`absolute end-3.5 top-1/2 -translate-y-1/2 h-4.5 w-4.5 animate-spin ${colorTokens.text.disabled}`} />
          )}
          {!isLoading && isEanBarcode && (
            <Barcode className={`absolute end-3.5 top-1/2 -translate-y-1/2 h-4.5 w-4.5 ${colorTokens.intent.primary.textSubtle}`} />
          )}
        </form>

        {/* Hint */}
        {!searchQuery && !data && (
          <div className="mt-4 flex flex-col items-center text-center py-8">
            <Search className={`h-12 w-12 ${colorTokens.text.faint} mb-3`} />
            <div className="flex items-start gap-2 rounded-lg ${colorTokens.intent.primary.bgSubtleAlphaStrong} px-3 py-2.5 mt-2">
              <Info className={`h-4 w-4 ${colorTokens.intent.primary.textSubtle} mt-0.5 shrink-0`} />
              <p className={`text-xs ${colorTokens.intent.primary.text} leading-relaxed`}>
                {t('parts-catalog:partNumber.hint')}
              </p>
            </div>
          </div>
        )}
      </div>

      {/* Detected brand notice */}
      {data?.detected_brand && (
        <div className="mt-4 flex items-center gap-2 rounded-lg ${colorTokens.intent.available.bgSubtleAlphaStrong} px-3 py-2">
          <span className={`inline-flex items-center rounded-md ${colorTokens.intent.available.bgSoft} px-2 py-0.5 text-xs font-semibold ${colorTokens.intent.available.textStrong} uppercase tracking-wide`}>
            {data.detected_brand}
          </span>
          <span className={`text-xs ${colorTokens.intent.available.textMid}`}>
            {t('parts-catalog:partNumber.detectedBrand', { brand: data.detected_brand })}
          </span>
        </div>
      )}

      {/* Results */}
      {data && data.articles.length > 0 && (
        <div className="mt-4 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
          {data.articles.map((article) => (
            <ArticleCard
              key={article.id}
              article={article}
              onSelect={onArticleSelected}
            />
          ))}
        </div>
      )}

      {/* No results */}
      {data && data.articles.length === 0 && searchQuery && (
        <div className="mt-8 text-center">
          <p className={`text-sm ${colorTokens.text.subtle}`}>
            {t('parts-catalog:partNumber.noResults', { query: searchQuery })}
          </p>
          <p className={`mt-1 text-xs ${colorTokens.text.disabled}`}>
            {t('parts-catalog:partNumber.tryDrillDown')}
          </p>
        </div>
      )}

      {/* Error */}
      {isError && (
        <div className={`mt-4 rounded-lg ${colorTokens.intent.danger.bgSubtle} px-3 py-2.5 text-sm ${colorTokens.intent.danger.text}`}>
          {t('parts-catalog:errors.searchFailed')}
        </div>
      )}
    </div>
  )
}
