import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Plus, Pencil, Trash2, PackagePlus, MapPinned } from 'lucide-react'
import { Button } from '@/components/atoms'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { EmptyState } from '@/components/molecules'
import { getErrorMessage } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { tokens, textColors, borderColors } from '@/lib/designTokens'
import { cn } from '@/lib/utils'
import {
  listZones,
  createZone,
  updateZone,
  deleteZone,
  assignProductsToZone,
  type Zone,
} from './api'
import { ZoneFormDialog, type ZoneFormValues } from './ZoneFormDialog'
import { BulkAssignDialog } from './BulkAssignDialog'

interface ZonesPanelProps {
  locationId: string
}

const zonesKey = (locationId: string) => ['inventory-zones', locationId] as const

export function ZonesPanel({ locationId }: ZonesPanelProps) {
  const { t } = useTranslation(['inventory', 'common'])
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  const [isFormOpen, setIsFormOpen] = useState(false)
  const [editingZone, setEditingZone] = useState<Zone | null>(null)
  const [deleteTarget, setDeleteTarget] = useState<Zone | null>(null)
  const [assignTarget, setAssignTarget] = useState<Zone | null>(null)

  const queryKey = tenantScopedKey([...zonesKey(locationId)])

  const { data: zones, isLoading } = useQuery({
    queryKey,
    queryFn: () => listZones(locationId),
    enabled: locationId !== '' && tenantId !== null && companyId !== null,
  })

  const invalidateZones = () => queryClient.invalidateQueries({ queryKey })

  const createMutation = useMutation({
    mutationFn: (data: ZoneFormValues) => createZone({ location_id: locationId, ...data }),
    onSuccess: async () => {
      await invalidateZones()
      toast.success(t('inventory:zones.messages.created'))
      closeForm()
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })

  const updateMutation = useMutation({
    mutationFn: ({ id, data }: { id: string; data: ZoneFormValues }) => updateZone(id, data),
    onSuccess: async () => {
      await invalidateZones()
      toast.success(t('inventory:zones.messages.updated'))
      closeForm()
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })

  const deleteMutation = useMutation({
    mutationFn: (id: string) => deleteZone(id),
    onSuccess: async () => {
      await invalidateZones()
      toast.success(t('inventory:zones.messages.deleted'))
      setDeleteTarget(null)
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })

  const assignMutation = useMutation({
    mutationFn: ({ zoneId, productIds }: { zoneId: string; productIds: string[] }) =>
      assignProductsToZone(zoneId, productIds),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        queryKey: tenantScopedKey(['zone-products', assignTarget?.id ?? null]),
      })
      toast.success(t('inventory:zones.bulkAssign.success'))
      setAssignTarget(null)
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })

  const openCreateForm = () => {
    setEditingZone(null)
    setIsFormOpen(true)
  }

  const openEditForm = (zone: Zone) => {
    setEditingZone(zone)
    setIsFormOpen(true)
  }

  const closeForm = () => {
    setIsFormOpen(false)
    setEditingZone(null)
  }

  const handleFormSubmit = (data: ZoneFormValues) => {
    if (editingZone) {
      updateMutation.mutate({ id: editingZone.id, data })
    } else {
      createMutation.mutate(data)
    }
  }

  const isMutatingForm = createMutation.isPending || updateMutation.isPending

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <p className={textColors.tertiary}>{t('common:status.loading')}</p>
      </div>
    )
  }

  const zonesList = zones ?? []

  return (
    <div className="space-y-4" data-testid="zones-panel">
      <div className="flex items-center justify-between">
        <p className={cn('text-sm', textColors.tertiary)}>{t('inventory:zones.subtitle')}</p>
        <Button size="sm" className="gap-2" onClick={openCreateForm}>
          <Plus className="h-4 w-4" />
          {t('inventory:zones.addZone')}
        </Button>
      </div>

      {zonesList.length === 0 ? (
        <div className="py-6">
          <EmptyState
            icon={<MapPinned className={cn('mx-auto h-12 w-12', textColors.disabled)} />}
            title={t('inventory:zones.empty.title')}
            description={t('inventory:zones.empty.description')}
          />
          <div className="mt-6 flex justify-center">
            <Button size="sm" className="gap-2" onClick={openCreateForm}>
              <Plus className="h-4 w-4" />
              {t('inventory:zones.addZone')}
            </Button>
          </div>
        </div>
      ) : (
        <ul className="space-y-2">
          {zonesList.map((zone) => (
            <li
              key={zone.id}
              className={cn(
                'flex items-center justify-between rounded-lg border p-3',
                borderColors.default,
              )}
            >
              <div className="min-w-0">
                <div className="flex items-center gap-2">
                  <span className={cn('font-medium', textColors.primary)}>{zone.name}</span>
                  <span className={cn(tokens.badge.base, tokens.badge.outline)}>{zone.code}</span>
                  <span
                    className={cn(
                      tokens.badge.base,
                      zone.is_active ? tokens.badge.green : tokens.badge.gray,
                    )}
                  >
                    {zone.is_active ? t('inventory:zones.list.active') : t('inventory:zones.list.inactive')}
                  </span>
                </div>
                <p className={cn('mt-1 text-xs', textColors.tertiary)}>
                  {t('inventory:zones.list.sortOrder', { order: zone.sort_order })}
                </p>
              </div>

              <div className="flex items-center gap-1">
                <Button
                  variant="ghost"
                  size="sm"
                  className="gap-1"
                  onClick={() => { setAssignTarget(zone) }}
                >
                  <PackagePlus className="h-3.5 w-3.5" />
                  {t('inventory:zones.actions.assignProducts')}
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  className="gap-1"
                  onClick={() => { openEditForm(zone) }}
                >
                  <Pencil className="h-3.5 w-3.5" />
                  {t('common:actions.edit')}
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  className="gap-1"
                  onClick={() => { setDeleteTarget(zone) }}
                >
                  <Trash2 className="h-3.5 w-3.5" />
                  {t('common:actions.delete')}
                </Button>
              </div>
            </li>
          ))}
        </ul>
      )}

      <ZoneFormDialog
        isOpen={isFormOpen}
        onClose={closeForm}
        onSubmit={handleFormSubmit}
        isPending={isMutatingForm}
        editingZone={editingZone}
      />

      <BulkAssignDialog
        isOpen={assignTarget !== null}
        onClose={() => { setAssignTarget(null) }}
        zone={assignTarget}
        isPending={assignMutation.isPending}
        onSubmit={(productIds) => {
          if (assignTarget) {
            assignMutation.mutate({ zoneId: assignTarget.id, productIds })
          }
        }}
      />

      <ConfirmDialog
        isOpen={deleteTarget !== null}
        onClose={() => { setDeleteTarget(null) }}
        onConfirm={() => {
          if (deleteTarget) {
            deleteMutation.mutate(deleteTarget.id)
          }
        }}
        title={t('inventory:zones.deleteConfirm.title')}
        message={t('inventory:zones.deleteConfirm.message', { name: deleteTarget?.name ?? '' })}
        confirmText={t('common:actions.delete')}
        variant="danger"
        isLoading={deleteMutation.isPending}
      />
    </div>
  )
}
