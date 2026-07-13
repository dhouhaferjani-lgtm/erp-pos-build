import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { ArrowRight, Search, Trash2 } from 'lucide-react'
import { Button, Checkbox, Input, Select } from '@/components/atoms'
import { ProductPicker, type ProductPickerValue } from '@/components/molecules/pickers/ProductPicker'
import { getErrorMessage } from '@/lib/api'
import { semanticColorTokens as colors } from '@/lib/designTokens'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { cn } from '@/lib/utils'
import {
  assignProducts,
  bulkMoveProducts,
  listNodeProducts,
  unassignProduct,
  type LocationNode,
} from '../api'

const productPrefix = (nodeId: string) => ['placement', 'node-products', nodeId] as const

interface NodeProductsPanelProps {
  node: LocationNode
  nodes: LocationNode[]
  canEdit: boolean
}

export function NodeProductsPanel({ node, nodes, canEdit }: NodeProductsPanelProps) {
  const { t } = useTranslation('inventory')
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [product, setProduct] = useState<ProductPickerValue | null>(null)
  const [selected, setSelected] = useState<Set<string>>(() => new Set())
  const [targetId, setTargetId] = useState('')

  const query = useQuery({
    queryKey: tenantScopedKey([...productPrefix(node.id), search, page]),
    queryFn: () => listNodeProducts(node.id, search, page),
    enabled: tenantId !== null && companyId !== null,
  })

  const invalidate = async (additionalNodeId?: string): Promise<void> => {
    await queryClient.invalidateQueries({ queryKey: [...productPrefix(node.id)] })
    if (additionalNodeId !== undefined) {
      await queryClient.invalidateQueries({ queryKey: [...productPrefix(additionalNodeId)] })
    }
    await queryClient.invalidateQueries({ queryKey: ['placement', 'nodes', node.location_id] })
  }

  const assign = useMutation({
    mutationFn: (productId: string) => assignProducts(node.id, [productId]),
    onSuccess: async () => { await invalidate(); setProduct(null); toast.success(t('placement.messages.productsAssigned')) },
    onError: (error: unknown) => { toast.error(getErrorMessage(error)) },
  })
  const remove = useMutation({
    mutationFn: (productId: string) => unassignProduct(node.id, productId),
    onSuccess: async () => { await invalidate(); toast.success(t('placement.messages.productUnassigned')) },
    onError: (error: unknown) => { toast.error(getErrorMessage(error)) },
  })
  const move = useMutation({
    mutationFn: () => bulkMoveProducts(targetId, [...selected]),
    onSuccess: async () => { await invalidate(targetId); setSelected(new Set()); setTargetId(''); toast.success(t('placement.messages.productsMoved')) },
    onError: (error: unknown) => { toast.error(getErrorMessage(error)) },
  })

  const placements = query.data?.data ?? []

  return (
    <div className="space-y-4">
      <div className="relative">
        <Search className={cn('pointer-events-none absolute start-3 top-2.5 h-4 w-4', colors.text.disabled)} />
        <Input className="ps-9" value={search} onChange={(event) => {
          setSearch(event.target.value)
          setPage(1)
          setSelected(new Set())
        }} placeholder={t('placement.products.search')} />
      </div>

      {canEdit && (
        <div className={cn('grid gap-2 rounded-lg border p-3 sm:grid-cols-[1fr_auto]', colors.border.subtle, colors.surface.pageAlpha)}>
          <ProductPicker value={product} onChange={setProduct} productType="all" placeholder={t('placement.products.addPlaceholder')} />
          <Button size="sm" disabled={product === null || assign.isPending} onClick={() => { if (product !== null) assign.mutate(product.id) }}>
            {t('placement.products.assign')}
          </Button>
        </div>
      )}

      {canEdit && selected.size > 0 && (
        <div className={cn('flex flex-wrap items-center gap-2 rounded-lg border p-3', colors.intent.primary.borderSubtle, colors.intent.primary.bgSubtle)}>
          <Select aria-label={t('placement.products.moveTarget')} value={targetId} onChange={(event) => { setTargetId(event.target.value) }} className="min-w-52 flex-1">
            <option value="">{t('placement.products.chooseTarget')}</option>
            {nodes.filter((candidate) => candidate.id !== node.id && candidate.deleted_at === null).map((candidate) => (
              <option key={candidate.id} value={candidate.id}>{candidate.path} — {candidate.name}</option>
            ))}
          </Select>
          <Button size="sm" disabled={targetId === '' || move.isPending} onClick={() => { move.mutate() }}>
            <ArrowRight className="me-1 h-4 w-4" />{t('placement.products.bulkMove')}
          </Button>
        </div>
      )}

      <div className={cn('divide-y rounded-lg border', colors.border.subtle, colors.border.divider)}>
        {query.isLoading && <p className={cn('p-4 text-sm', colors.text.subtle)}>{t('placement.products.loading')}</p>}
        {!query.isLoading && placements.length === 0 && <p className={cn('p-4 text-sm', colors.text.subtle)}>{t('placement.products.empty')}</p>}
        {placements.map((placement) => {
          const name = placement.product_name ?? placement.product_sku ?? placement.product_id
          return (
            <div key={placement.id} className="flex items-center gap-3 p-3">
              {canEdit && (
                <Checkbox
                  aria-label={name}
                  checked={selected.has(placement.product_id)}
                  onChange={(event) => {
                    setSelected((current) => {
                      const next = new Set(current)
                      if (event.target.checked) next.add(placement.product_id)
                      else next.delete(placement.product_id)
                      return next
                    })
                  }}
                />
              )}
              <div className="min-w-0 flex-1">
                <p className={cn('truncate text-sm font-medium', colors.text.primary)}>{name}</p>
                {placement.product_sku !== null && <p className={cn('font-mono text-xs', colors.text.subtle)}>{placement.product_sku}</p>}
              </div>
              {canEdit && (
                <Button variant="ghost" size="sm" aria-label={`${t('placement.products.unassign')}:${name}`} onClick={() => { remove.mutate(placement.product_id) }}>
                  <Trash2 className="h-4 w-4" />
                </Button>
              )}
            </div>
          )
        })}
      </div>

      {(query.data?.meta.last_page ?? 1) > 1 && (
        <div className="flex items-center justify-between">
          <Button variant="secondary" size="sm" disabled={page <= 1} onClick={() => { setPage((value) => value - 1) }}>{t('placement.products.previous')}</Button>
          <span className={cn('text-xs', colors.text.subtle)}>{page} / {query.data?.meta.last_page}</span>
          <Button variant="secondary" size="sm" disabled={page >= (query.data?.meta.last_page ?? 1)} onClick={() => { setPage((value) => value + 1) }}>{t('placement.products.next')}</Button>
        </div>
      )}
    </div>
  )
}
