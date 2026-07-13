import { useTranslation } from 'react-i18next'
import { NodeTree } from './NodeTree'
import type { LocationNode } from '../api'

interface NodePickerProps {
  nodes: LocationNode[]
  selectedId?: string | null
  onSelect?: (node: LocationNode) => void
  selectedIds?: ReadonlySet<string>
  onSelectionChange?: (selectedIds: Set<string>) => void
  label?: string
}

export function NodePicker({
  nodes,
  selectedId = null,
  onSelect,
  selectedIds,
  onSelectionChange,
  label,
}: NodePickerProps) {
  const { t } = useTranslation('inventory')
  const multi = selectedIds !== undefined && onSelectionChange !== undefined
  return (
    <section aria-label={label ?? t('placement.picker.label')}>
      <NodeTree
        nodes={nodes.filter((node) => node.deleted_at === null && node.is_active)}
        selectedId={selectedId}
        onSelect={(node) => { onSelect?.(node) }}
        {...(selectedIds === undefined ? {} : { multiSelectedIds: selectedIds })}
        {...(!multi ? {} : { onMultiSelect: (nodeId: string, selected: boolean) => {
          const next = new Set(selectedIds)
          if (selected) next.add(nodeId)
          else next.delete(nodeId)
          onSelectionChange(next)
        } })}
      />
    </section>
  )
}
