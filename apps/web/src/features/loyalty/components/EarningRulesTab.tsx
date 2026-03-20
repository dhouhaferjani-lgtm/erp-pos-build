import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Plus, Pencil, Trash2, Play, Pause } from 'lucide-react'
import { Button } from '@/components/atoms'
import { Badge } from '@/components/atoms/Badge/Badge'
import { Spinner } from '@/components/atoms/Spinner/Spinner'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'

import {
  useEarningRules,
  useCreateEarningRule,
  useUpdateEarningRule,
  useDeleteEarningRule,
  useActivateEarningRule,
  useDeactivateEarningRule,
} from '../hooks/useEarningRules'
import { EarningRuleFormModal } from './EarningRuleFormModal'
import type { EarningRule, CreateEarningRuleData } from '../types/loyalty'

interface EarningRulesTabProps {
  programId: string
}

export function EarningRulesTab({ programId }: EarningRulesTabProps) {
  const { t } = useTranslation(['loyalty', 'common'])
  const { data: rules, isLoading } = useEarningRules(programId)
  const createMutation = useCreateEarningRule(programId)
  const updateMutation = useUpdateEarningRule(programId)
  const deleteMutation = useDeleteEarningRule(programId)
  const activateMutation = useActivateEarningRule(programId)
  const deactivateMutation = useDeactivateEarningRule(programId)

  const [isModalOpen, setIsModalOpen] = useState(false)
  const [editingRule, setEditingRule] = useState<EarningRule | null>(null)
  const [deleteTarget, setDeleteTarget] = useState<EarningRule | null>(null)

  const handleOpenCreate = () => {
    setEditingRule(null)
    setIsModalOpen(true)
  }

  const handleOpenEdit = (rule: EarningRule) => {
    setEditingRule(rule)
    setIsModalOpen(true)
  }

  const handleSubmit = (data: CreateEarningRuleData) => {
    if (editingRule) {
      updateMutation.mutate(
        { id: editingRule.id, data },
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

  const rulesList = rules ?? []

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h3 className="text-lg font-semibold text-gray-900">{t('loyalty:earningRules.title')}</h3>
        <Button size="sm" onClick={handleOpenCreate}>
          <Plus className="w-4 h-4 mr-1" />
          {t('loyalty:earningRules.create')}
        </Button>
      </div>

      {rulesList.length === 0 ? (
        <div className="text-center py-8">
          <p className="text-gray-500">{t('loyalty:earningRules.noRules')}</p>
          <p className="text-gray-400 text-sm mt-1">{t('loyalty:earningRules.noRulesDescription')}</p>
        </div>
      ) : (
        <div className="overflow-x-auto rounded-lg border border-gray-200">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">{t('loyalty:fields.name')}</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">{t('loyalty:fields.ruleType')}</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">{t('loyalty:fields.rewardValue')}</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">{t('loyalty:fields.priority')}</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">{t('loyalty:fields.status')}</th>
                <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">{t('common:actions')}</th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {rulesList.map((rule) => (
                <tr key={rule.id} className="hover:bg-gray-50">
                  <td className="px-4 py-3 font-medium text-gray-900">{rule.name}</td>
                  <td className="px-4 py-3 text-sm text-gray-700">{t(`loyalty:ruleTypes.${rule.rule_type}`)}</td>
                  <td className="px-4 py-3 text-sm text-gray-700">{rule.reward_value}</td>
                  <td className="px-4 py-3 text-sm text-gray-700">{rule.priority}</td>
                  <td className="px-4 py-3">
                    <Badge variant={rule.is_active ? 'success' : 'default'}>
                      {rule.is_active ? t('loyalty:statuses.active') : t('loyalty:statuses.inactive')}
                    </Badge>
                  </td>
                  <td className="px-4 py-3 text-right">
                    <div className="flex items-center justify-end gap-1">
                      <button
                        onClick={() => { handleOpenEdit(rule); }}
                        className="p-1.5 rounded hover:bg-gray-100 text-gray-600"
                        title={t('loyalty:earningRules.edit')}
                      >
                        <Pencil className="w-4 h-4" />
                      </button>
                      {rule.is_active ? (
                        <button
                          onClick={() => { deactivateMutation.mutate(rule.id); }}
                          className="p-1.5 rounded hover:bg-orange-50 text-orange-600"
                          title={t('loyalty:actions.deactivate')}
                        >
                          <Pause className="w-4 h-4" />
                        </button>
                      ) : (
                        <button
                          onClick={() => { activateMutation.mutate(rule.id); }}
                          className="p-1.5 rounded hover:bg-green-50 text-green-600"
                          title={t('loyalty:actions.activate')}
                        >
                          <Play className="w-4 h-4" />
                        </button>
                      )}
                      <button
                        onClick={() => { setDeleteTarget(rule); }}
                        className="p-1.5 rounded hover:bg-red-50 text-red-500"
                        title={t('loyalty:actions.delete')}
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

      <EarningRuleFormModal
        isOpen={isModalOpen}
        onClose={() => { setIsModalOpen(false); }}
        onSubmit={handleSubmit}
        isPending={createMutation.isPending || updateMutation.isPending}
        editingRule={editingRule}
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
        message={t('loyalty:earningRules.deleteConfirm')}
        variant="danger"
      />
    </div>
  )
}
