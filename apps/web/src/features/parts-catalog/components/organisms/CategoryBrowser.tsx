import { useState, useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { ChevronRight, ChevronLeft, FolderOpen, Loader2 } from 'lucide-react'
import { cn } from '@/lib/utils'
import { useSearchTreeRoots, useSearchTreeChildren } from '../../hooks/useSearchTree'
import type { SearchTreeNode, VehicleType } from '../../types/catalog'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface CategoryBrowserProps {
  treeType?: VehicleType
  onCategorySelected: (nodeId: string) => void
  className?: string
}

interface BreadcrumbItem {
  id: string
  name: string
}

export function CategoryBrowser({
  treeType = 'pc',
  onCategorySelected,
  className,
}: CategoryBrowserProps) {
  const { t } = useTranslation(['parts-catalog'])
  const [breadcrumbs, setBreadcrumbs] = useState<BreadcrumbItem[]>([])
  const [activeNodeId, setActiveNodeId] = useState('')

  const { data: rootNodes, isLoading: loadingRoots } = useSearchTreeRoots(treeType)
  const { data: childNodes, isLoading: loadingChildren } = useSearchTreeChildren(activeNodeId)

  const currentNodes = activeNodeId ? childNodes : rootNodes
  const isLoading = activeNodeId ? loadingChildren : loadingRoots
  const isRootLevel = breadcrumbs.length === 0

  const handleNodeClick = useCallback(
    (node: SearchTreeNode) => {
      if (node.has_children) {
        setBreadcrumbs((prev) => [...prev, { id: node.id, name: node.name }])
        setActiveNodeId(node.id)
      } else {
        onCategorySelected(node.id)
      }
    },
    [onCategorySelected]
  )

  const handleBack = useCallback(() => {
    setBreadcrumbs((prev) => {
      const next = prev.slice(0, -1)
      setActiveNodeId(next.length > 0 ? next[next.length - 1].id : '')
      return next
    })
  }, [])

  const handleBreadcrumbClick = useCallback((index: number) => {
    setBreadcrumbs((prev) => {
      const next = prev.slice(0, index + 1)
      setActiveNodeId(next.length > 0 ? next[next.length - 1].id : '')
      return next
    })
  }, [])

  return (
    <div className={cn('flex flex-col', className)}>
      {/* Breadcrumb */}
      {breadcrumbs.length > 0 && (
        <div className="flex items-center gap-1 text-sm mb-3 flex-wrap">
          <button
            type="button"
            onClick={() => { setBreadcrumbs([]); setActiveNodeId('') }}
            className={`rounded px-1.5 py-0.5 ${colorTokens.intent.primary.text} ${colorTokens.intent.primary.bgHover} transition-colors`}
          >
            {t('parts-catalog:category.rootCategories')}
          </button>
          {breadcrumbs.map((crumb, i) => (
            <span key={crumb.id} className="inline-flex items-center">
              <ChevronRight className={`h-3.5 w-3.5 ${colorTokens.text.faint}`} />
              <button
                type="button"
                onClick={() => { handleBreadcrumbClick(i) }}
                className={cn(
                  'rounded px-1.5 py-0.5 transition-colors',
                  i === breadcrumbs.length - 1
                    ? `${colorTokens.text.primary} font-medium`
                    : `${colorTokens.intent.primary.text} ${colorTokens.intent.primary.bgHover}`
                )}
              >
                {crumb.name}
              </button>
            </span>
          ))}
        </div>
      )}

      {/* Back button (mobile-friendly) */}
      {breadcrumbs.length > 0 && (
        <button
          type="button"
          onClick={handleBack}
          className={`flex items-center gap-1.5 text-sm ${colorTokens.text.subtle} ${colorTokens.intent.neutral.textHoverStrong} mb-3 transition-colors`}
        >
          <ChevronLeft className="h-4 w-4" />
          {t('parts-catalog:category.backToParent')}
        </button>
      )}

      {/* Loading state */}
      {isLoading && (
        <div className="flex items-center justify-center py-12">
          <Loader2 className={`h-8 w-8 animate-spin ${colorTokens.intent.primary.text}`} />
        </div>
      )}

      {/* Category grid (multi-column for root, single column for children) */}
      {!isLoading && currentNodes && currentNodes.length > 0 && (
        <div className={cn(
          isRootLevel
            ? 'grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2'
            : `space-y-0.5 rounded-lg border ${colorTokens.border.hairline} overflow-hidden`
        )}>
          {currentNodes.map((node) => (
            <button
              key={node.id}
              type="button"
              onClick={() => { handleNodeClick(node) }}
              className={cn(
                'w-full flex items-center justify-between text-start transition-colors',
                isRootLevel
                  ? `rounded-lg border ${colorTokens.border.hairline} ${colorTokens.surface.base} ${colorTokens.intent.primary.hoverBorderSoft} ${colorTokens.intent.primary.bgHoverSubtleAlpha} px-4 py-3`
                  : `px-4 py-3 ${colorTokens.intent.neutral.bgHover}`
              )}
            >
              <div className="flex items-center gap-3">
                <FolderOpen className={`h-4 w-4 ${colorTokens.text.disabled} shrink-0`} />
                <div>
                  <span className={`text-sm font-medium ${colorTokens.text.secondary}`}>{node.name}</span>
                  {node.article_count !== null && node.article_count > 0 && (
                    <span className={`ms-2 text-xs ${colorTokens.text.disabled}`}>
                      {t('parts-catalog:category.articlesInCategory', {
                        count: node.article_count,
                      })}
                    </span>
                  )}
                </div>
              </div>
              <ChevronRight className={`h-4 w-4 ${colorTokens.text.faint} shrink-0`} />
            </button>
          ))}
        </div>
      )}

      {/* Empty state */}
      {!isLoading && currentNodes?.length === 0 && (
        <p className={`py-8 text-center text-sm ${colorTokens.text.disabled}`}>
          {t('parts-catalog:category.noCategories')}
        </p>
      )}
    </div>
  )
}
