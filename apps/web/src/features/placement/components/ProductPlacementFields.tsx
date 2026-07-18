import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { MapPin, X } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Button } from '@/components/atoms'
import { fetchLocations, type LocationApiResponse } from '@/features/locations/api'
import { getErrorMessage } from '@/lib/api'
import { semanticColorTokens as colors, tokens } from '@/lib/designTokens'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { cn } from '@/lib/utils'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import {
  listLocationNodes,
  listProductPlacements,
  setProductPlacement,
  type ProductPlacement,
} from '../api'
import { NodePicker } from './NodePicker'

const productPlacementPrefix = (productId: string) => ['placement', 'product', productId] as const

interface ProductPlacementFieldsProps {
  productId: string
  canEdit: boolean
}

interface SetPlacementVariables {
  locationId: string
  nodeId: string | null
}

export function ProductPlacementFields({ productId, canEdit }: ProductPlacementFieldsProps) {
  const { t } = useTranslation('inventory')
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const enabled = tenantId !== null && companyId !== null

  const locationsQuery = useQuery({
    queryKey: tenantScopedKey(['locations']),
    queryFn: fetchLocations,
    enabled,
  })
  const placementsQuery = useQuery({
    queryKey: tenantScopedKey([...productPlacementPrefix(productId)]),
    queryFn: () => listProductPlacements(productId),
    enabled: enabled && productId !== '',
  })

  const mutation = useMutation({
    mutationFn: ({ locationId, nodeId }: SetPlacementVariables) => setProductPlacement(productId, locationId, nodeId),
    onSuccess: async (_, variables) => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: [...productPlacementPrefix(productId)] }),
        queryClient.invalidateQueries({ queryKey: ['placement', 'nodes', variables.locationId] }),
      ])
      toast.success(t(variables.nodeId === null
        ? 'placement.productField.cleared'
        : 'placement.productField.saved'))
    },
    onError: (error: unknown) => { toast.error(getErrorMessage(error)) },
  })

  const locations = (locationsQuery.data ?? []).filter((location) => location.is_active)
  const placements = placementsQuery.data ?? []

  return (
    <section className={tokens.card.base} aria-labelledby="product-placement-heading">
      <div className="mb-4 flex items-start gap-3">
        <MapPin className={cn('mt-0.5 h-5 w-5', colors.intent.primary.text)} aria-hidden="true" />
        <div>
          <h2 id="product-placement-heading" className={tokens.heading.section}>{t('placement.productField.title')}</h2>
          <p className={cn('mt-1 text-sm', colors.text.subtle)}>{t('placement.productField.description')}</p>
        </div>
      </div>

      {locationsQuery.isLoading || placementsQuery.isLoading ? (
        <p className={cn('text-sm', colors.text.subtle)}>{t('placement.productField.loading')}</p>
      ) : locations.length === 0 ? (
        <p className={cn('text-sm', colors.text.subtle)}>{t('placement.location.empty')}</p>
      ) : (
        <div className="space-y-3">
          {locations.map((location) => (
            <LocationPlacementField
              key={location.id}
              location={location}
              placement={placements.find((candidate) => candidate.location_id === location.id) ?? null}
              canEdit={canEdit}
              enabled={enabled}
              isSaving={mutation.isPending}
              onSet={(nodeId) => { mutation.mutate({ locationId: location.id, nodeId }) }}
            />
          ))}
        </div>
      )}
    </section>
  )
}

interface LocationPlacementFieldProps {
  location: LocationApiResponse
  placement: ProductPlacement | null
  canEdit: boolean
  enabled: boolean
  isSaving: boolean
  onSet: (nodeId: string | null) => void
}

function LocationPlacementField({
  location,
  placement,
  canEdit,
  enabled,
  isSaving,
  onSet,
}: LocationPlacementFieldProps) {
  const { t } = useTranslation('inventory')
  const [pickerOpen, setPickerOpen] = useState(false)
  const nodesQuery = useQuery({
    queryKey: tenantScopedKey(['placement', 'nodes', location.id, false]),
    queryFn: () => listLocationNodes(location.id),
    enabled,
  })
  const nodes = nodesQuery.data ?? []
  const currentNode = nodes.find((node) => node.id === placement?.node_id) ?? null

  return (
    <div className={cn('rounded-lg border p-3', colors.border.subtle, colors.surface.pageAlpha)}>
      <div className="flex flex-wrap items-center gap-3">
        <div className="min-w-0 flex-1">
          <p className={cn('text-sm font-semibold', colors.text.primary)}>{location.name}</p>
          <p className={cn('text-xs', colors.text.subtle)}>{location.code}</p>
        </div>
        <code className={cn('rounded px-2 py-1 text-xs', colors.surface.muted, colors.text.secondary)}>
          {nodesQuery.isLoading
            ? t('placement.productField.loadingPath')
            : currentNode?.path ?? t('placement.productField.unassigned')}
        </code>
        {canEdit && (
          <div className="flex gap-2">
            <Button
              type="button"
              variant="secondary"
              size="sm"
              aria-label={`${t('placement.productField.change')}:${location.name}`}
              onClick={() => { setPickerOpen((open) => !open) }}
            >
              {t('placement.productField.change')}
            </Button>
            {placement !== null && (
              <Button
                type="button"
                variant="ghost"
                size="sm"
                disabled={isSaving}
                aria-label={`${t('placement.productField.clear')}:${location.name}`}
                onClick={() => { onSet(null) }}
              >
                <X className="me-1 h-4 w-4" />{t('placement.productField.clear')}
              </Button>
            )}
          </div>
        )}
      </div>
      {pickerOpen && canEdit && (
        <div className={cn('mt-3 border-t pt-3', colors.border.subtle)}>
          <NodePicker
            nodes={nodes}
            selectedId={placement?.node_id ?? null}
            label={`${t('placement.picker.label')}: ${location.name}`}
            onSelect={(node) => {
              onSet(node.id)
              setPickerOpen(false)
            }}
          />
        </div>
      )}
    </div>
  )
}
