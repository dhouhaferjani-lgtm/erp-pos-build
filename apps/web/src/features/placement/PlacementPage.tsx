import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { LayoutList, Network, Plus, Warehouse } from 'lucide-react'
import { Button, Checkbox, Select } from '@/components/atoms'
import { DataTable, type DataTableColumn } from '@/components/molecules/DataTable'
import { PageHeader } from '@/components/molecules/PageHeader'
import { fetchLocations } from '@/features/locations/api'
import { getErrorMessage } from '@/lib/api'
import { semanticColorTokens as colors, tokens } from '@/lib/designTokens'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { cn } from '@/lib/utils'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { usePermissions } from '@/hooks/usePermissions'
import {
  createLocationNode,
  deleteLocationNode,
  listLocationNodes,
  moveLocationNode,
  restoreLocationNode,
  updateLocationNode,
  type LocationNode,
  type NodeInput,
} from './api'
import { buildNodeTree, flattenVisibleNodes, isDescendant } from './tree'
import { NodeDetail } from './components/NodeDetail'
import { NodeFormDialog } from './components/NodeFormDialog'
import { NodeTree } from './components/NodeTree'

const nodePrefix = (locationId: string) => ['placement', 'nodes', locationId] as const

type ViewMode = 'tree' | 'table'

export function PlacementPage() {
  const { t } = useTranslation('inventory')
  const queryClient = useQueryClient()
  const [searchParams, setSearchParams] = useSearchParams()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const { hasPermission } = usePermissions()
  const canEdit = hasPermission('inventory.adjust')
  const [viewMode, setViewMode] = useState<ViewMode>('tree')
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [drawerNodeId, setDrawerNodeId] = useState<string | null>(null)
  const [includeDeleted, setIncludeDeleted] = useState(false)
  const [formState, setFormState] = useState<{ parentId: string | null; node: LocationNode | null } | null>(null)
  const [tableSelected, setTableSelected] = useState<Set<string>>(() => new Set())

  const locationsQuery = useQuery({
    queryKey: tenantScopedKey(['locations']),
    queryFn: fetchLocations,
    enabled: tenantId !== null && companyId !== null,
  })
  const locations = locationsQuery.data ?? []
  const requestedLocation = searchParams.get('location_id')
  const locationId = locations.some((location) => location.id === requestedLocation)
    ? requestedLocation ?? ''
    : locations[0]?.id ?? ''

  useEffect(() => {
    if (locationId !== '' && requestedLocation !== locationId) {
      setSearchParams({ location_id: locationId }, { replace: true })
    }
  }, [locationId, requestedLocation, setSearchParams])

  const nodesQuery = useQuery({
    queryKey: tenantScopedKey([...nodePrefix(locationId), includeDeleted]),
    queryFn: () => listLocationNodes(locationId, includeDeleted),
    enabled: locationId !== '' && tenantId !== null && companyId !== null,
  })
  const nodes = useMemo(() => nodesQuery.data ?? [], [nodesQuery.data])
  const selectedNode = nodes.find((node) => node.id === selectedId) ?? null
  const drawerNode = nodes.find((node) => node.id === drawerNodeId) ?? null

  const invalidateNodes = async (): Promise<void> => {
    await queryClient.invalidateQueries({ queryKey: [...nodePrefix(locationId)] })
  }

  const createMutation = useMutation({
    mutationFn: createLocationNode,
    onSuccess: async (node) => { await invalidateNodes(); setFormState(null); setSelectedId(node.id); toast.success(t('placement.messages.created')) },
    onError: (error: unknown) => { toast.error(getErrorMessage(error)) },
  })
  const updateMutation = useMutation({
    mutationFn: ({ nodeId, input }: { nodeId: string; input: NodeInput }) => updateLocationNode(nodeId, {
      node_type: input.node_type,
      name: input.name,
      code: input.code,
      sort_order: input.sort_order,
      is_active: input.is_active,
    }),
    onSuccess: async () => { await invalidateNodes(); setFormState(null); toast.success(t('placement.messages.updated')) },
    onError: (error: unknown) => { toast.error(getErrorMessage(error)) },
  })
  const moveMutation = useMutation({
    mutationFn: ({ nodeId, parentId }: { nodeId: string; parentId: string | null }) => moveLocationNode(nodeId, parentId),
    onSuccess: async () => { await invalidateNodes(); toast.success(t('placement.messages.moved')) },
    onError: (error: unknown) => { toast.error(getErrorMessage(error)) },
  })
  const reorderMutation = useMutation({
    mutationFn: ({ nodeId, sortOrder }: { nodeId: string; sortOrder: number }) => updateLocationNode(nodeId, { sort_order: sortOrder }),
    onSuccess: invalidateNodes,
    onError: (error: unknown) => { toast.error(getErrorMessage(error)) },
  })
  const deleteMutation = useMutation({
    mutationFn: (nodeId: string) => deleteLocationNode(nodeId, true),
    onSuccess: async () => { await invalidateNodes(); setSelectedId(null); setDrawerNodeId(null); toast.success(t('placement.messages.deleted')) },
    onError: (error: unknown) => { toast.error(getErrorMessage(error)) },
  })
  const restoreMutation = useMutation({
    mutationFn: restoreLocationNode,
    onSuccess: async () => { await invalidateNodes(); toast.success(t('placement.messages.restored')) },
    onError: (error: unknown) => { toast.error(getErrorMessage(error)) },
  })

  const tableRows = useMemo(() => {
    const tree = buildNodeTree(nodes)
    return flattenVisibleNodes(tree, new Set(nodes.map((node) => node.id)))
  }, [nodes])

  const tableColumns: DataTableColumn<LocationNode>[] = [
    {
      key: 'code',
      header: t('placement.table.code'),
      render: (node) => <span className="font-mono text-xs">{node.code}</span>,
    },
    {
      key: 'name',
      header: t('placement.table.name'),
      render: (node) => (
        <Button
          type="button"
          variant="ghost"
          size="xs"
          className="justify-start px-0"
          style={{ marginInlineStart: `${String(node.depth * 20)}px` }}
          onClick={(event) => {
            event.stopPropagation()
            setDrawerNodeId(node.id)
          }}
        >
          {node.name}
        </Button>
      ),
    },
    {
      key: 'type',
      header: t('placement.table.type'),
      render: (node) => <span className={cn(tokens.badge.base, tokens.badge.blue)}>{t(`placement.types.${node.node_type}`)}</span>,
    },
    {
      key: 'products',
      header: t('placement.table.products'),
      numeric: true,
      accessor: (node) => node.product_count,
    },
    {
      key: 'active',
      header: t('placement.table.active'),
      accessor: (node) => node.is_active ? t('placement.status.active') : t('placement.status.inactive'),
    },
  ]

  const detailProps = {
    nodes,
    canEdit,
    onEdit: (node: LocationNode) => { setFormState({ parentId: node.parent_id, node }) },
    onAddChild: (node: LocationNode) => { setFormState({ parentId: node.id, node: null }) },
    onDelete: (node: LocationNode) => { deleteMutation.mutate(node.id) },
    onRestore: (node: LocationNode) => { restoreMutation.mutate(node.id) },
  }

  return (
    <div className="space-y-5">
      <PageHeader title={t('placement.title')} subtitle={t('placement.subtitle')} />
      <p className={cn('rounded-lg border px-4 py-3 text-sm', colors.border.subtle, colors.surface.page, colors.text.secondary)}>
        {t('placement.importExplainer')}
      </p>
      <div className={cn('rounded-xl border', colors.border.subtle, colors.surface.base)}>
        <div className={cn('flex flex-wrap items-center gap-3 border-b p-4', colors.border.subtle)}>
          <Warehouse className={cn('h-5 w-5', colors.intent.primary.text)} />
          <Select
            aria-label={t('placement.location.label')}
            value={locationId}
            onChange={(event) => {
              setSelectedId(null)
              setDrawerNodeId(null)
              setTableSelected(new Set())
              setSearchParams({ location_id: event.target.value })
            }}
            className="min-w-64"
          >
            {locations.map((location) => <option key={location.id} value={location.id}>{location.name} — {location.code}</option>)}
          </Select>
          <div className={cn('ms-auto flex rounded-lg p-1', colors.surface.muted)}>
            <Button variant={viewMode === 'tree' ? 'primary' : 'ghost'} size="sm" aria-label={t('placement.views.tree')} onClick={() => { setViewMode('tree') }}><Network className="me-1 h-4 w-4" />{t('placement.views.tree')}</Button>
            <Button variant={viewMode === 'table' ? 'primary' : 'ghost'} size="sm" aria-label={t('placement.views.table')} onClick={() => { setViewMode('table') }}><LayoutList className="me-1 h-4 w-4" />{t('placement.views.table')}</Button>
          </div>
          {canEdit && <Button size="sm" aria-label={t('placement.actions.addRoot')} onClick={() => { setFormState({ parentId: null, node: null }) }}><Plus className="me-1 h-4 w-4" />{t('placement.actions.addRoot')}</Button>}
          <label className={cn('flex items-center gap-2 text-xs', colors.text.subtle)}>
            <Checkbox checked={includeDeleted} onChange={(event) => { setIncludeDeleted(event.target.checked) }} />
            {t('placement.actions.showDeleted')}
          </label>
        </div>

        {locationId === '' ? (
          <p className={cn('p-10 text-center text-sm', colors.text.subtle)}>{t('placement.location.empty')}</p>
        ) : viewMode === 'tree' ? (
          <div className="grid min-h-[36rem] lg:grid-cols-[minmax(19rem,0.82fr)_minmax(28rem,1.4fr)]">
            <div className={cn('border-e', colors.border.subtle)}>
              <NodeTree
                nodes={nodes}
                selectedId={selectedId}
                onSelect={(node) => { setSelectedId(node.id) }}
                canEdit={canEdit}
                onDropNode={(dragged, target) => {
                  if (isDescendant(target, dragged)) return
                  if (dragged.parent_id === target.parent_id) reorderMutation.mutate({ nodeId: dragged.id, sortOrder: target.sort_order })
                  else moveMutation.mutate({ nodeId: dragged.id, parentId: target.id })
                }}
              />
            </div>
            <div className="min-w-0">
              {selectedNode === null ? (
                <div className="flex h-full min-h-96 items-center justify-center p-8">
                  <p className={cn('max-w-sm text-center text-sm', colors.text.subtle)}>{t('placement.detail.selectPrompt')}</p>
                </div>
              ) : <NodeDetail node={selectedNode} {...detailProps} />}
            </div>
          </div>
        ) : (
          <div className="overflow-x-auto p-4">
            {tableSelected.size > 0 && <p className={cn('mb-3 text-sm', colors.text.secondary)}>{t('placement.table.selected', { count: tableSelected.size })}</p>}
            <DataTable
              ariaLabel={t('placement.table.label')}
              columns={tableColumns}
              data={tableRows}
              keyExtractor={(node) => node.id}
              onRowClick={(node) => { setDrawerNodeId(node.id) }}
              selection={{
                selectedIds: tableSelected,
                onToggle: (nodeId) => {
                  setTableSelected((current) => {
                    const next = new Set(current)
                    if (next.has(nodeId)) next.delete(nodeId)
                    else next.add(nodeId)
                    return next
                  })
                },
                onToggleAll: () => {
                  setTableSelected((current) => current.size === tableRows.length
                    ? new Set()
                    : new Set(tableRows.map((node) => node.id)))
                },
                getRowLabel: (node) => t('placement.table.selectNode', { name: node.name }),
                selectAllLabel: t('placement.table.select'),
              }}
            />
          </div>
        )}
      </div>

      {drawerNode !== null && <NodeDetail node={drawerNode} drawer onClose={() => { setDrawerNodeId(null) }} {...detailProps} />}
      <NodeFormDialog
        isOpen={formState !== null}
        locationId={locationId}
        parentId={formState?.parentId ?? null}
        node={formState?.node ?? null}
        isPending={createMutation.isPending || updateMutation.isPending}
        onClose={() => { setFormState(null) }}
        onSubmit={(input) => {
          const editing = formState?.node
          if (editing === null || editing === undefined) createMutation.mutate(input)
          else updateMutation.mutate({ nodeId: editing.id, input })
        }}
      />
    </div>
  )
}
