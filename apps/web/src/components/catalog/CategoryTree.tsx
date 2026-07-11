import { useState } from 'react'
import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { ChevronRight, ChevronDown, Folder, FolderOpen } from 'lucide-react'
import { cn } from '@/lib/utils'
import type { CategoryTreeNode } from '@/features/catalog/types'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface CategoryTreeProps {
  categories: CategoryTreeNode[]
  selectedId?: number | null | undefined
  onSelect: (category: CategoryTreeNode) => void
  expandedIds?: number[] | undefined
  className?: string | undefined
}

export function CategoryTree({
  categories,
  selectedId,
  onSelect,
  expandedIds: initialExpanded = [],
  className,
}: CategoryTreeProps) {
  const { t } = useTranslation('common')
  const [expandedIds, setExpandedIds] = useState<Set<number>>(new Set(initialExpanded))

  const toggleExpand = (id: number, e: React.MouseEvent) => {
    e.stopPropagation()
    setExpandedIds((prev) => {
      const next = new Set(prev)
      if (next.has(id)) {
        next.delete(id)
      } else {
        next.add(id)
      }
      return next
    })
  }

  const renderCategory = (category: CategoryTreeNode, level: number = 0): ReactNode => {
    const hasChildren = category.children && category.children.length > 0
    const isExpanded = expandedIds.has(category.id)
    const isSelected = selectedId === category.id

    return (
      <div key={category.id}>
        <div
          className={cn(
            `flex items-center gap-2 px-2 py-1.5 rounded cursor-pointer ${colorTokens.variants.hoverBgGray100} transition-colors`,
            isSelected && `${colorTokens.intent.primary.bgSubtle} ${colorTokens.intent.primary.textStrong} ${colorTokens.intent.primary.bgHoverSoft}`
          )}
          style={{ paddingInlineStart: `${level * 16 + 8}px` }}
          onClick={() => { onSelect(category); }}
          role="button"
          tabIndex={0}
          onKeyDown={(e) => {
            if (e.key === 'Enter' || e.key === ' ') {
              e.preventDefault()
              onSelect(category)
            }
          }}
        >
          {hasChildren ? (
            <button
              onClick={(e) => { toggleExpand(category.id, e); }}
              className={`p-0.5 ${colorTokens.variants.hoverBgGray200} rounded shrink-0`}
              aria-label={isExpanded ? t('catalog.categories.collapse') : t('catalog.categories.expand')}
            >
              {isExpanded ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
            </button>
          ) : (
            <span className="w-5 shrink-0" />
          )}

          {isExpanded ? (
            <FolderOpen className={`h-4 w-4 ${colorTokens.variants.textYellow500} shrink-0`} />
          ) : (
            <Folder className={`h-4 w-4 ${colorTokens.variants.textYellow500} shrink-0`} />
          )}

          <span className="flex-1 truncate text-sm">{category.name}</span>

          {category.products_count !== null && category.products_count !== undefined && (
            <span className={`text-xs ${colorTokens.text.disabled} shrink-0`}>({category.products_count})</span>
          )}
        </div>

        {hasChildren && isExpanded && (
          <div>{category.children.map((child) => renderCategory(child, level + 1))}</div>
        )}
      </div>
    )
  }

  return (
    <div className={cn('py-2', className)}>
      {categories.length > 0 ? (
        categories.map((category) => renderCategory(category))
      ) : (
        <p className={`text-sm ${colorTokens.text.subtle} px-2 py-4 text-center`}>{t('catalog.categories.noCategoriesFound')}</p>
      )}
    </div>
  )
}
