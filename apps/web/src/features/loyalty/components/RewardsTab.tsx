import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Plus, Pencil, Trash2, Play, Pause } from 'lucide-react'
import { Button } from '@/components/atoms'
import { Badge } from '@/components/atoms/Badge/Badge'
import { Spinner } from '@/components/atoms/Spinner/Spinner'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'

import {
  useRewards,
  useCreateReward,
  useUpdateReward,
  useDeleteReward,
  useActivateReward,
  useDeactivateReward,
} from '../hooks/useRewards'
import { RewardFormModal } from './RewardFormModal'
import type { Reward, CreateRewardData } from '../types/loyalty'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

interface RewardsTabProps {
  programId: string
}

export function RewardsTab({ programId }: RewardsTabProps) {
  const { t } = useTranslation(['loyalty', 'common'])
  const { data: rewards, isLoading } = useRewards(programId)
  const createMutation = useCreateReward(programId)
  const updateMutation = useUpdateReward(programId)
  const deleteMutation = useDeleteReward(programId)
  const activateMutation = useActivateReward(programId)
  const deactivateMutation = useDeactivateReward(programId)

  const [isModalOpen, setIsModalOpen] = useState(false)
  const [editingReward, setEditingReward] = useState<Reward | null>(null)
  const [deleteTarget, setDeleteTarget] = useState<Reward | null>(null)

  const handleOpenCreate = () => {
    setEditingReward(null)
    setIsModalOpen(true)
  }

  const handleOpenEdit = (reward: Reward) => {
    setEditingReward(reward)
    setIsModalOpen(true)
  }

  const handleSubmit = (data: CreateRewardData) => {
    if (editingReward) {
      updateMutation.mutate(
        { id: editingReward.id, data },
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

  const rewardsList = rewards ?? []

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h3 className={`text-lg font-semibold ${colorTokens.text.primary}`}>{t('loyalty:rewards.title')}</h3>
        <Button size="sm" onClick={handleOpenCreate}>
          <Plus className="w-4 h-4 mr-1" />
          {t('loyalty:rewards.create')}
        </Button>
      </div>

      {rewardsList.length === 0 ? (
        <div className="text-center py-8">
          <p className={`${colorTokens.text.subtle}`}>{t('loyalty:rewards.noRewards')}</p>
          <p className={`${colorTokens.text.disabled} text-sm mt-1`}>{t('loyalty:rewards.noRewardsDescription')}</p>
        </div>
      ) : (
        <div className={`overflow-x-auto rounded-lg border ${colorTokens.border.subtle}`}>
          <DataTable className={`min-w-full divide-y ${colorTokens.border.divider}`}>
            <thead className={`${colorTokens.surface.page}`}>
              <tr>
                <th className={`px-4 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase`}>{t('loyalty:fields.name')}</th>
                <th className={`px-4 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase`}>{t('loyalty:fields.rewardType')}</th>
                <th className={`px-4 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase`}>{t('loyalty:fields.pointsCost')}</th>
                <th className={`px-4 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase`}>{t('loyalty:fields.rewardValue')}</th>
                <th className={`px-4 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase`}>{t('loyalty:fields.status')}</th>
                <th className={`px-4 py-3 text-right text-xs font-medium ${colorTokens.text.subtle} uppercase`}>{t('common:table.actions')}</th>
              </tr>
            </thead>
            <tbody className={`${colorTokens.surface.base} divide-y ${colorTokens.border.divider}`}>
              {rewardsList.map((reward) => (
                <tr key={reward.id} className={`${colorTokens.intent.neutral.bgHover}`}>
                  <td className={`px-4 py-3 font-medium ${colorTokens.text.primary}`}>{reward.name}</td>
                  <td className={`px-4 py-3 text-sm ${colorTokens.text.secondary}`}>{t(`loyalty:rewardTypes.${reward.reward_type}`)}</td>
                  <td className={`px-4 py-3 text-sm ${colorTokens.text.secondary}`}>{reward.points_cost}</td>
                  <td className={`px-4 py-3 text-sm ${colorTokens.text.secondary}`}>{reward.reward_value ?? '-'}</td>
                  <td className="px-4 py-3">
                    <Badge variant={reward.is_active ? 'success' : 'default'}>
                      {reward.is_active ? t('loyalty:statuses.active') : t('loyalty:statuses.inactive')}
                    </Badge>
                  </td>
                  <td className="px-4 py-3 text-right">
                    <div className="flex items-center justify-end gap-1">
                      <button
                        onClick={() => { handleOpenEdit(reward); }}
                        className={`p-1.5 rounded ${colorTokens.intent.neutral.bgHoverSoft} ${colorTokens.text.muted}`}
                      >
                        <Pencil className="w-4 h-4" />
                      </button>
                      {reward.is_active ? (
                        <button
                          onClick={() => { deactivateMutation.mutate(reward.id); }}
                          className={`p-1.5 rounded ${colorTokens.intent.notice.bgHover} ${colorTokens.intent.notice.text}`}
                        >
                          <Pause className="w-4 h-4" />
                        </button>
                      ) : (
                        <button
                          onClick={() => { activateMutation.mutate(reward.id); }}
                          className={`p-1.5 rounded ${colorTokens.intent.success.bgHover} ${colorTokens.intent.success.text}`}
                        >
                          <Play className="w-4 h-4" />
                        </button>
                      )}
                      <button
                        onClick={() => { setDeleteTarget(reward); }}
                        className={`p-1.5 rounded ${colorTokens.intent.danger.bgHover} ${colorTokens.intent.danger.textSubtle}`}
                      >
                        <Trash2 className="w-4 h-4" />
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </DataTable>
        </div>
      )}

      <RewardFormModal
        isOpen={isModalOpen}
        onClose={() => { setIsModalOpen(false); }}
        onSubmit={handleSubmit}
        isPending={createMutation.isPending || updateMutation.isPending}
        editingReward={editingReward}
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
        message={t('loyalty:rewards.deleteConfirm')}
        variant="danger"
      />
    </div>
  )
}
