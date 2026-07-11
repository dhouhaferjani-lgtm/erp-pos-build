import { Filter } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { cn } from '../../lib/utils'
import { Button } from '../atoms/Button'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

export interface FilterPanelProps {
  isOpen: boolean
  onToggle: () => void
  onClear: () => void
  hasActiveFilters: boolean
  activeFilterCount?: number
  children: React.ReactNode
  className?: string
}

export function FilterPanel({
  isOpen,
  onToggle,
  onClear,
  hasActiveFilters,
  activeFilterCount,
  children,
  className,
}: FilterPanelProps) {
  const { t } = useTranslation('common')

  return (
    <div className={cn(`border rounded-lg ${colorTokens.surface.base}`, className)}>
      <div className={`flex items-center justify-between p-4 ${colorTokens.surface.page} rounded-t-lg`}>
        <button
          onClick={onToggle}
          className={`flex items-center gap-2 font-medium ${colorTokens.text.secondary} ${colorTokens.variants.hoverTextGray900} transition-colors`}
          aria-expanded={isOpen}
          aria-controls="filter-panel-content"
        >
          <Filter className="w-4 h-4" />
          <span>{t('filtersLabel')}</span>
          {hasActiveFilters && activeFilterCount !== undefined && (
            <span className={`ms-2 inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full text-xs font-medium ${colorTokens.intent.primary.bgStrong} ${colorTokens.text.inverse}`}>
              {activeFilterCount}
            </span>
          )}
        </button>
        {hasActiveFilters && (
          <Button
            variant="ghost"
            size="sm"
            onClick={onClear}
            className={`${colorTokens.intent.primary.text} ${colorTokens.variants.hoverTextBlue800} ${colorTokens.variants.hoverBgBlue50}`}
          >
            {t('clearAll')}
          </Button>
        )}
      </div>
      {isOpen && (
        <div id="filter-panel-content" className="p-4 space-y-4 border-t">
          {children}
        </div>
      )}
    </div>
  )
}
