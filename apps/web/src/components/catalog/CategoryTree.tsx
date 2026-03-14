import { useState } from 'react'
import type { JSX } from 'react'
import { ChevronRight, ChevronDown, Folder, FolderOpen } from 'lucide-react'
import { cn } from '@/lib/utils'
import type { CategoryTreeNode } from '@/features/catalog/types'

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

  const renderCategory = (category: CategoryTreeNode, level: number = 0): JSX.Element => {
    const hasChildren = category.children && category.children.length > 0
    const isExpanded = expandedIds.has(category.id)
    const isSelected = selectedId === category.id

    return (
      <div key={category.id}>
        <div
          className={cn(
            'flex items-center gap-2 px-2 py-1.5 rounded cursor-pointer hover:bg-gray-100 transition-colors',
            isSelected && 'bg-blue-50 text-blue-700 hover:bg-blue-100'
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
              className="p-0.5 hover:bg-gray-200 rounded shrink-0"
              aria-label={isExpanded ? 'Collapse' : 'Expand'}
            >
              {isExpanded ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
            </button>
          ) : (
            <span className="w-5 shrink-0" />
          )}

          {isExpanded ? (
            <FolderOpen className="h-4 w-4 text-yellow-500 shrink-0" />
          ) : (
            <Folder className="h-4 w-4 text-yellow-500 shrink-0" />
          )}

          <span className="flex-1 truncate text-sm">{category.name}</span>

          {category.products_count !== null && category.products_count !== undefined && (
            <span className="text-xs text-gray-400 shrink-0">({category.products_count})</span>
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
        <p className="text-sm text-gray-500 px-2 py-4 text-center">No categories found</p>
      )}
    </div>
  )
}
