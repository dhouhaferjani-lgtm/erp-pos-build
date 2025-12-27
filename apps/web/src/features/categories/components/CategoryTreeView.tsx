import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ChevronRight, ChevronDown, FolderOpen, Folder, MoreVertical, Edit, Trash2, Plus } from 'lucide-react'
import type { CategoryApiResponse } from '../api'

interface CategoryTreeViewProps {
  categories: CategoryApiResponse[]
  onEdit?: (category: CategoryApiResponse) => void
  onDelete?: (category: CategoryApiResponse) => void
  onAddSubcategory?: (parentCategory: CategoryApiResponse) => void
}

interface CategoryNodeProps {
  category: CategoryApiResponse
  level: number
  onEdit?: (category: CategoryApiResponse) => void
  onDelete?: (category: CategoryApiResponse) => void
  onAddSubcategory?: (parentCategory: CategoryApiResponse) => void
}

function CategoryNode({ category, level, onEdit, onDelete, onAddSubcategory }: CategoryNodeProps) {
  const { t } = useTranslation(['inventory'])
  const [isExpanded, setIsExpanded] = useState(level < 2) // Auto-expand first 2 levels
  const [showActions, setShowActions] = useState(false)

  const hasChildren = category.children && category.children.length > 0
  const indentWidth = level * 24

  return (
    <div className="select-none">
      {/* Category Row */}
      <div
        className="flex items-center gap-2 py-2 px-3 hover:bg-gray-50 rounded-md group"
        style={{ paddingInlineStart: `${indentWidth}px` }}
        onMouseEnter={() => { setShowActions(true); }}
        onMouseLeave={() => { setShowActions(false); }}
      >
        {/* Expand/Collapse Button */}
        <button
          onClick={() => { setIsExpanded(!isExpanded); }}
          className={`flex-shrink-0 p-0.5 rounded hover:bg-gray-200 ${
            hasChildren ? 'visible' : 'invisible'
          }`}
          aria-label={isExpanded ? t('inventory:categories.collapseAll') : t('inventory:categories.expandAll')}
        >
          {isExpanded ? (
            <ChevronDown className="w-4 h-4 text-gray-600" />
          ) : (
            <ChevronRight className="w-4 h-4 text-gray-600" />
          )}
        </button>

        {/* Folder Icon */}
        <div className="flex-shrink-0">
          {isExpanded ? (
            <FolderOpen className="w-5 h-5 text-blue-500" />
          ) : (
            <Folder className="w-5 h-5 text-gray-400" />
          )}
        </div>

        {/* Category Name */}
        <div className="flex-1 flex items-center gap-2 min-w-0">
          <span className="text-sm font-medium text-gray-900 truncate">
            {category.name}
          </span>
          {!category.is_active && (
            <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800">
              {t('common:inactive')}
            </span>
          )}
        </div>

        {/* Products Count */}
        {category.products_count !== null && category.products_count > 0 && (
          <span className="text-xs text-gray-500">
            {category.products_count} {category.products_count === 1 ? t('inventory:products.singular') : t('inventory:products.plural')}
          </span>
        )}

        {/* Actions Menu */}
        <div className={`flex items-center gap-1 ${showActions ? 'opacity-100' : 'opacity-0'} transition-opacity`}>
          {onAddSubcategory && (
            <button
              onClick={() => { onAddSubcategory(category); }}
              className="p-1 rounded hover:bg-gray-200 text-gray-600 hover:text-blue-600"
              title={t('inventory:categories.actions.addSubcategory')}
            >
              <Plus className="w-4 h-4" />
            </button>
          )}
          {onEdit && (
            <button
              onClick={() => { onEdit(category); }}
              className="p-1 rounded hover:bg-gray-200 text-gray-600 hover:text-blue-600"
              title={t('inventory:categories.actions.edit')}
            >
              <Edit className="w-4 h-4" />
            </button>
          )}
          {onDelete && (
            <button
              onClick={() => { onDelete(category); }}
              className="p-1 rounded hover:bg-gray-200 text-gray-600 hover:text-red-600"
              title={t('inventory:categories.actions.delete')}
            >
              <Trash2 className="w-4 h-4" />
            </button>
          )}
        </div>
      </div>

      {/* Children */}
      {hasChildren && isExpanded && (
        <div>
          {category.children!.map(child => (
            <CategoryNode
              key={child.id}
              category={child}
              level={level + 1}
              onEdit={onEdit}
              onDelete={onDelete}
              onAddSubcategory={onAddSubcategory}
            />
          ))}
        </div>
      )}
    </div>
  )
}

export function CategoryTreeView({ categories, onEdit, onDelete, onAddSubcategory }: CategoryTreeViewProps) {
  const { t } = useTranslation(['inventory'])

  if (categories.length === 0) {
    return (
      <div className="text-center py-12 text-gray-500">
        <Folder className="mx-auto h-12 w-12 text-gray-400" />
        <p className="mt-2 text-sm">{t('inventory:categories.empty.title')}</p>
      </div>
    )
  }

  return (
    <div className="space-y-1">
      {categories.map(category => (
        <CategoryNode
          key={category.id}
          category={category}
          level={0}
          onEdit={onEdit}
          onDelete={onDelete}
          onAddSubcategory={onAddSubcategory}
        />
      ))}
    </div>
  )
}
