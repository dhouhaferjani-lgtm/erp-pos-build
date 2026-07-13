import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import {
  Boxes,
  ChevronDown,
  ChevronRight,
  Columns3,
  GripVertical,
  LayoutGrid,
  Map,
  Package,
  Rows3,
} from 'lucide-react'
import { Button, Checkbox } from '@/components/atoms'
import { semanticColorTokens as colors, tokens } from '@/lib/designTokens'
import { cn } from '@/lib/utils'
import type { LocationNode, LocationNodeType } from '../api'
import { buildNodeTree, flattenVisibleNodes } from '../tree'

const nodeIcons: Record<LocationNodeType, typeof Map> = {
  zone: Map,
  aisle: Rows3,
  rack: Columns3,
  shelf: LayoutGrid,
  bin: Boxes,
  section: Package,
}

interface NodeTreeProps {
  nodes: LocationNode[]
  selectedId: string | null
  onSelect: (node: LocationNode) => void
  canEdit?: boolean
  onDropNode?: (dragged: LocationNode, target: LocationNode) => void
  multiSelectedIds?: ReadonlySet<string>
  onMultiSelect?: (nodeId: string, selected: boolean) => void
}

export function NodeTree({
  nodes,
  selectedId,
  onSelect,
  canEdit = false,
  onDropNode,
  multiSelectedIds,
  onMultiSelect,
}: NodeTreeProps) {
  const { t } = useTranslation('inventory')
  const tree = useMemo(() => buildNodeTree(nodes), [nodes])
  const [expanded, setExpanded] = useState<Set<string>>(() => new Set())
  const [draggedId, setDraggedId] = useState<string | null>(null)
  const rows = flattenVisibleNodes(tree, expanded)

  const toggle = (nodeId: string): void => {
    setExpanded((current) => {
      const next = new Set(current)
      if (next.has(nodeId)) next.delete(nodeId)
      else next.add(nodeId)
      return next
    })
  }

  if (nodes.length === 0) {
    return <p className={cn('px-4 py-10 text-center text-sm', colors.text.subtle)}>{t('placement.empty.description')}</p>
  }

  return (
    <div className="py-2" role="tree" aria-label={t('placement.tree.label')}>
      {rows.map((node) => {
        const branch = treeNodeHasChildren(nodes, node.id)
        const open = expanded.has(node.id)
        const Icon = nodeIcons[node.node_type]
        return (
          <div
            key={node.id}
            role="treeitem"
            aria-expanded={branch ? open : undefined}
            draggable={canEdit}
            onDragStart={() => { setDraggedId(node.id) }}
            onDragEnd={() => { setDraggedId(null) }}
            onDragOver={(event) => { if (canEdit) event.preventDefault() }}
            onDrop={() => {
              const dragged = nodes.find((candidate) => candidate.id === draggedId)
              if (dragged !== undefined && dragged.id !== node.id) onDropNode?.(dragged, node)
              setDraggedId(null)
            }}
            className={cn(
              'group flex min-h-11 items-center gap-1 border-l-2 pr-2 transition-colors',
              selectedId === node.id
                ? cn(colors.intent.primary.bgSubtle, colors.intent.primary.borderFocus)
                : cn('border-l-transparent', colors.intent.neutral.bgHover),
            )}
            style={{ paddingInlineStart: `${String(node.depth * 18 + 6)}px` }}
          >
            {canEdit && <GripVertical className={cn('h-4 w-4 cursor-grab', colors.text.disabled)} aria-hidden="true" />}
            {branch ? (
              <Button
                type="button"
                variant="ghost"
                size="xs"
                aria-label={open ? t('placement.tree.collapse') : t('placement.tree.expand')}
                onClick={() => { toggle(node.id) }}
                className="p-1"
              >
                {open ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
              </Button>
            ) : <span className="w-6" />}
            {onMultiSelect !== undefined && (
              <Checkbox
                checked={multiSelectedIds?.has(node.id) ?? false}
                onChange={(event) => { onMultiSelect(node.id, event.target.checked) }}
                aria-label={node.name}
              />
            )}
            <Button
              type="button"
              variant="ghost"
              size="sm"
              onClick={() => { onSelect(node) }}
              className="min-w-0 flex-1 justify-start gap-2 px-0 py-2 text-start"
              aria-label={node.name}
            >
              <Icon className={cn('h-4 w-4 shrink-0', node.is_active ? colors.intent.primary.text : colors.text.disabled)} />
              <span className={cn('shrink-0 font-mono text-xs font-semibold', colors.text.secondary)}>{node.code}</span>
              <span className={cn('truncate text-sm', colors.text.primary)}>{node.name}</span>
              <span
                aria-label={`${t('placement.productCount')}:${String(node.product_count)}`}
                className={cn('ms-auto shrink-0', tokens.badge.base, tokens.badge.gray)}
              >
                {node.product_count}
              </span>
            </Button>
          </div>
        )
      })}
    </div>
  )
}

function treeNodeHasChildren(nodes: LocationNode[], nodeId: string): boolean {
  return nodes.some((node) => node.parent_id === nodeId)
}
