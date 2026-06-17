import { useTranslation } from 'react-i18next'
import { Plus, X } from 'lucide-react'
import { toast } from 'sonner'
import { Button, StatusBadge } from '@/components/atoms'
import { textColors, borderColors } from '@/lib/designTokens'
import type { ModifierGroupData } from '../types/compositeItem'
import { useModifierGroups, useAssignModifierGroup, useRemoveModifierGroup } from '../hooks/useModifierGroups'

interface ModifierGroupAssignerProps {
  compositeItemId: string
  assignedGroups: ModifierGroupData[]
}

export function ModifierGroupAssigner({ compositeItemId, assignedGroups }: ModifierGroupAssignerProps) {
  const { t } = useTranslation(['catalog', 'common'])
  const { data: allGroupsResponse } = useModifierGroups({ is_active: true, per_page: 100 })
  const assignMutation = useAssignModifierGroup()
  const removeMutation = useRemoveModifierGroup()

  const allGroups = allGroupsResponse?.data ?? []
  const assignedIds = new Set(assignedGroups.map((g) => g.id))
  const availableGroups = allGroups.filter((g) => !assignedIds.has(g.id))

  const handleAssign = (groupId: string) => {
    assignMutation.mutate(
      { compositeItemId, modifierGroupId: groupId },
      { onSuccess: () => toast.success(t('common:saved')) }
    )
  }

  const handleRemove = (groupId: string) => {
    removeMutation.mutate(
      { compositeItemId, modifierGroupId: groupId },
      { onSuccess: () => toast.success(t('common:deleted')) }
    )
  }

  return (
    <div className="space-y-6">
      {/* Assigned groups */}
      <div>
        <h4 className={`text-sm font-medium ${textColors.primary} mb-3`}>{t('catalog:assignedGroups')}</h4>
        {assignedGroups.length === 0 ? (
          <p className={`text-sm ${textColors.tertiary}`}>{t('catalog:noModifierGroups')}</p>
        ) : (
          <div className="space-y-2">
            {assignedGroups.map((group) => (
              <div key={group.id} className={`flex items-center justify-between rounded-lg border ${borderColors.light} p-3`}>
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-medium text-sm">{group.name}</span>
                  <span className={`${textColors.tertiary} text-xs`}>({group.code})</span>
                  <StatusBadge tone="info">
                    {group.selection_type === 'single' ? t('catalog:single') : t('catalog:multiple')}
                    {group.is_required && ` - ${t('catalog:isRequired')}`}
                  </StatusBadge>
                  {group.modifiers && group.modifiers.length > 0 && (
                    <div className={`text-xs ${textColors.disabled} w-full`}>
                      {group.modifiers.map((m) => m.name).join(', ')}
                    </div>
                  )}
                </div>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => { handleRemove(group.id); }}
                  disabled={removeMutation.isPending}
                  className={`!p-1 ${textColors.error} ${textColors.hoverError}`}
                >
                  <X className="h-4 w-4" />
                </Button>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Available groups */}
      {availableGroups.length > 0 && (
        <div>
          <h4 className={`text-sm font-medium ${textColors.primary} mb-3`}>{t('catalog:availableGroups')}</h4>
          <div className="space-y-2">
            {availableGroups.map((group) => (
              <div key={group.id} className={`flex items-center justify-between rounded-lg border border-dashed ${borderColors.default} p-3`}>
                <div>
                  <span className="text-sm">{group.name}</span>
                  <span className={`${textColors.tertiary} text-xs ml-2`}>({group.code})</span>
                </div>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => { handleAssign(group.id); }}
                  disabled={assignMutation.isPending}
                  className={`!p-1 ${textColors.brand}`}
                >
                  <Plus className="h-4 w-4" />
                </Button>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
