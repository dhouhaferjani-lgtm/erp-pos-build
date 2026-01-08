import { Filter } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { cn } from '../../lib/utils'
import { Button } from '../atoms'

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
    <div className={cn('border rounded-lg bg-white', className)}>
      <div className="flex items-center justify-between p-4 bg-gray-50 rounded-t-lg">
        <button
          onClick={onToggle}
          className="flex items-center gap-2 font-medium text-gray-700 hover:text-gray-900 transition-colors"
          aria-expanded={isOpen}
          aria-controls="filter-panel-content"
        >
          <Filter className="w-4 h-4" />
          <span>{t('filters')}</span>
          {hasActiveFilters && activeFilterCount !== undefined && (
            <span className="ms-2 inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full text-xs font-medium bg-blue-600 text-white">
              {activeFilterCount}
            </span>
          )}
        </button>
        {hasActiveFilters && (
          <Button
            variant="ghost"
            size="sm"
            onClick={onClear}
            className="text-blue-600 hover:text-blue-800 hover:bg-blue-50"
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
