import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Plus, Pencil, Trash2 } from 'lucide-react'
import { Button } from '@/components/atoms'
import { Spinner } from '@/components/atoms/Spinner/Spinner'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'

import { useTiers, useCreateTier, useUpdateTier, useDeleteTier } from '../hooks/useTiers'
import { TierFormModal } from './TierFormModal'
import type { Tier, CreateTierData } from '../types/loyalty'

interface TiersTabProps {
  programId: string
}

export function TiersTab({ programId }: TiersTabProps) {
  const { t } = useTranslation(['loyalty', 'common'])
  const { data: tiers, isLoading } = useTiers(programId)
  const createMutation = useCreateTier(programId)
  const updateMutation = useUpdateTier(programId)
  const deleteMutation = useDeleteTier(programId)

  const [isModalOpen, setIsModalOpen] = useState(false)
  const [editingTier, setEditingTier] = useState<Tier | null>(null)
  const [deleteTarget, setDeleteTarget] = useState<Tier | null>(null)

  const handleOpenCreate = () => {
    setEditingTier(null)
    setIsModalOpen(true)
  }

  const handleOpenEdit = (tier: Tier) => {
    setEditingTier(tier)
    setIsModalOpen(true)
  }

  const handleSubmit = (data: CreateTierData) => {
    if (editingTier) {
      updateMutation.mutate(
        { id: editingTier.id, data },
        { onSuccess: () => { setIsModalOpen(false); } },
      )
    } else {
      createMutation.mutate(data, { onSuccess: () => { setIsModalOpen(false); } })
    }
  }

  if (isLoading) {
    return (
      <div className="flex justify-center py-8">
        <Spinner />
      </div>
    )
  }

  const tiersList = (tiers ?? []).sort((a, b) => a.level - b.level)

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h3 className="text-lg font-semibold text-gray-900">{t('loyalty:tiers.title')}</h3>
        <Button size="sm" onClick={handleOpenCreate}>
          <Plus className="w-4 h-4 mr-1" />
          {t('loyalty:tiers.create')}
        </Button>
      </div>

      {tiersList.length === 0 ? (
        <div className="text-center py-8">
          <p className="text-gray-500">{t('loyalty:tiers.noTiers')}</p>
          <p className="text-gray-400 text-sm mt-1">{t('loyalty:tiers.noTiersDescription')}</p>
        </div>
      ) : (
        <div className="overflow-x-auto rounded-lg border border-gray-200">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">{t('loyalty:fields.level')}</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">{t('loyalty:fields.name')}</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">{t('loyalty:fields.qualificationType')}</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">{t('loyalty:fields.qualificationThreshold')}</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">{t('loyalty:fields.earningMultiplier')}</th>
                <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">{t('common:table.actions')}</th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {tiersList.map((tier) => (
                <tr key={tier.id} className="hover:bg-gray-50">
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-2">
                      {tier.color ? (
                        <span className="w-3 h-3 rounded-full" style={{ backgroundColor: tier.color }} />
                      ) : null}
                      <span className="font-medium text-gray-900">{tier.level}</span>
                    </div>
                  </td>
                  <td className="px-4 py-3 font-medium text-gray-900">{tier.name}</td>
                  <td className="px-4 py-3 text-sm text-gray-700">{t(`loyalty:qualificationTypes.${tier.qualification_type}`)}</td>
                  <td className="px-4 py-3 text-sm text-gray-700">{tier.qualification_threshold}</td>
                  <td className="px-4 py-3 text-sm text-gray-700">{tier.earning_multiplier}x</td>
                  <td className="px-4 py-3 text-right">
                    <div className="flex items-center justify-end gap-1">
                      <button
                        onClick={() => { handleOpenEdit(tier); }}
                        className="p-1.5 rounded hover:bg-gray-100 text-gray-600"
                      >
                        <Pencil className="w-4 h-4" />
                      </button>
                      <button
                        onClick={() => { setDeleteTarget(tier); }}
                        className="p-1.5 rounded hover:bg-red-50 text-red-500"
                      >
                        <Trash2 className="w-4 h-4" />
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <TierFormModal
        isOpen={isModalOpen}
        onClose={() => { setIsModalOpen(false); }}
        onSubmit={handleSubmit}
        isPending={createMutation.isPending || updateMutation.isPending}
        editingTier={editingTier}
      />

      <ConfirmDialog
        isOpen={deleteTarget !== null}
        onClose={() => { setDeleteTarget(null); }}
        onConfirm={() => {
          if (deleteTarget) {
            deleteMutation.mutate(deleteTarget.id, { onSettled: () => { setDeleteTarget(null); } })
          }
        }}
        isLoading={deleteMutation.isPending}
        title={t('loyalty:actions.delete')}
        message={t('loyalty:tiers.deleteConfirm')}
        variant="danger"
      />
    </div>
  )
}
