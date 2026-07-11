import { ChevronDown, ChevronUp, ChevronsUpDown } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { cn } from '../../lib/utils'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

export interface SortableTableHeaderProps {
  column: string
  label: string
  currentSort: string | null
  currentDirection: 'asc' | 'desc'
  onSort: (column: string) => void
  align?: 'left' | 'center' | 'right'
  className?: string
}

export function SortableTableHeader({
  column,
  label,
  currentSort,
  currentDirection,
  onSort,
  align = 'left',
  className,
}: SortableTableHeaderProps) {
  const { t } = useTranslation('common')
  const isActive = currentSort === column

  const handleClick = () => {
    onSort(column)
  }

  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault()
      onSort(column)
    }
  }

  return (
    <th
      className={cn(
        `px-4 py-3 text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider cursor-pointer ${colorTokens.variants.hoverBgGray100} transition-colors select-none`,
        align === 'left' && 'text-start',
        align === 'center' && 'text-center',
        align === 'right' && 'text-end',
        isActive && `${colorTokens.surface.page} ${colorTokens.text.secondary}`,
        className
      )}
      onClick={handleClick}
      onKeyDown={handleKeyDown}
      role="button"
      tabIndex={0}
      aria-sort={
        isActive
          ? currentDirection === 'asc'
            ? 'ascending'
            : 'descending'
          : 'none'
      }
      aria-label={t('table.sortBy', { label })}
    >
      <div
        className={cn(
          'flex items-center gap-2',
          align === 'center' && 'justify-center',
          align === 'right' && 'justify-end'
        )}
      >
        <span>{label}</span>
        {isActive ? (
          currentDirection === 'asc' ? (
            <ChevronUp className="w-4 h-4" data-testid="chevron-up" />
          ) : (
            <ChevronDown className="w-4 h-4" data-testid="chevron-down" />
          )
        ) : (
          <ChevronsUpDown className="w-4 h-4 opacity-30" />
        )}
      </div>
    </th>
  )
}
