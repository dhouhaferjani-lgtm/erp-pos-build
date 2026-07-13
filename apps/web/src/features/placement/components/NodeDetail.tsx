import { useTranslation } from 'react-i18next'
import { ArchiveRestore, Plus, Pencil, Trash2, X } from 'lucide-react'
import { Button } from '@/components/atoms'
import { semanticColorTokens as colors, tokens } from '@/lib/designTokens'
import { cn } from '@/lib/utils'
import type { LocationNode } from '../api'
import { NodeProductsPanel } from './NodeProductsPanel'

interface NodeDetailProps {
  node: LocationNode
  nodes: LocationNode[]
  canEdit: boolean
  drawer?: boolean
  onClose?: () => void
  onEdit: (node: LocationNode) => void
  onAddChild: (node: LocationNode) => void
  onDelete: (node: LocationNode) => void
  onRestore: (node: LocationNode) => void
}

export function NodeDetail({
  node,
  nodes,
  canEdit,
  drawer = false,
  onClose,
  onEdit,
  onAddChild,
  onDelete,
  onRestore,
}: NodeDetailProps) {
  const { t } = useTranslation('inventory')
  const content = (
    <div className="flex h-full min-h-0 flex-col">
      <header className={cn('border-b p-5', colors.border.subtle)}>
        <div className="flex items-start gap-3">
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-2">
              <h2 className={cn('truncate text-xl font-semibold', colors.text.primary)}>{node.name}</h2>
              <span className={cn(tokens.badge.base, tokens.badge.outline)}>{node.code}</span>
              <span className={cn(tokens.badge.base, tokens.badge.blue)}>{t(`placement.types.${node.node_type}`)}</span>
            </div>
            <nav aria-label={t('placement.detail.breadcrumb')} className={cn('mt-2 flex flex-wrap items-center gap-1 font-mono text-xs', colors.text.subtle)}>
              {node.path.split('/').map((segment, index) => (
                <span key={`${segment}-${String(index)}`} className="flex items-center gap-1">
                  {index > 0 && <span aria-hidden="true">/</span>}
                  <span>{segment}</span>
                </span>
              ))}
            </nav>
          </div>
          {drawer && (
            <Button variant="ghost" size="sm" aria-label={t('placement.detail.close')} onClick={onClose}>
              <X className="h-4 w-4" />
            </Button>
          )}
        </div>
        {canEdit && (
          <div className="mt-4 flex flex-wrap gap-2">
            {node.deleted_at === null ? (
              <>
                <Button variant="secondary" size="sm" onClick={() => { onEdit(node) }}><Pencil className="me-1 h-4 w-4" />{t('placement.actions.edit')}</Button>
                <Button variant="secondary" size="sm" onClick={() => { onAddChild(node) }}><Plus className="me-1 h-4 w-4" />{t('placement.actions.addChild')}</Button>
                <Button variant="ghost" size="sm" onClick={() => { onDelete(node) }}><Trash2 className="me-1 h-4 w-4" />{t('placement.actions.delete')}</Button>
              </>
            ) : (
              <Button variant="secondary" size="sm" onClick={() => { onRestore(node) }}><ArchiveRestore className="me-1 h-4 w-4" />{t('placement.actions.restore')}</Button>
            )}
          </div>
        )}
      </header>
      <div className="min-h-0 flex-1 overflow-y-auto p-5">
        <div className={cn('mb-4 border-b pb-2 text-sm font-semibold', colors.border.subtle, colors.text.primary)}>
          {t('placement.detail.productsTab')}
        </div>
        {node.deleted_at === null && <NodeProductsPanel key={node.id} node={node} nodes={nodes} canEdit={canEdit} />}
      </div>
    </div>
  )

  if (!drawer) return content
  return (
    <div className={cn('fixed inset-0 z-40 flex justify-end', colors.surface.overlaySubtle)} onMouseDown={(event) => { if (event.target === event.currentTarget) onClose?.() }}>
      <aside
        role="dialog"
        aria-modal="true"
        aria-label={t('placement.detail.title')}
        className={cn('h-full w-full max-w-2xl shadow-2xl', colors.surface.base)}
      >
        {content}
      </aside>
    </div>
  )
}
