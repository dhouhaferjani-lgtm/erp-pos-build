import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { Plus, Tag, Pause, Play, Archive, Trash2 } from 'lucide-react'
import { Button, Input, Select } from '@/components/atoms'
import { StatusBadge, statusTone, type StatusTone } from '@/components/atoms/StatusBadge'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'

import {
  usePromotions,
  useActivatePromotion,
  usePausePromotion,
  useArchivePromotion,
  useDeletePromotion,
} from '../hooks/usePromotions'
import type { PromotionData } from '../api/promotionApi'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

const promotionToneOverrides: Record<string, StatusTone> = {
  draft: 'pending',
  active: 'success',
  paused: 'warning',
  expired: 'danger',
  archived: 'neutral',
}

type ConfirmAction = {
  type: 'delete'
  promotion: PromotionData
}

export function PromotionListPage() {
  const { t } = useTranslation(['promotions', 'common'])
  const navigate = useNavigate()
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState<string>('')
  const [confirmAction, setConfirmAction] = useState<ConfirmAction | null>(null)

  const listParams = {
    ...(search ? { search } : {}),
    ...(statusFilter ? { status: statusFilter } : {}),
  }
  const { data, isLoading } = usePromotions(listParams)
  const activateMutation = useActivatePromotion()
  const pauseMutation = usePausePromotion()
  const archiveMutation = useArchivePromotion()
  const deleteMutation = useDeletePromotion()

  const promotions = data?.data ?? []

  const handleAction = (action: string, promotion: PromotionData) => {
    switch (action) {
      case 'activate':
        activateMutation.mutate(promotion.id)
        break
      case 'pause':
        pauseMutation.mutate(promotion.id)
        break
      case 'archive':
        archiveMutation.mutate(promotion.id)
        break
      case 'delete':
        setConfirmAction({ type: 'delete', promotion })
        break
    }
  }

  const handleConfirm = () => {
    if (!confirmAction) return
    deleteMutation.mutate(confirmAction.promotion.id, {
      onSettled: () => { setConfirmAction(null); },
    })
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <PageHeaderTitle className={`text-2xl font-bold ${colorTokens.text.primary}`}>
            {t('promotions:title')}
          </PageHeaderTitle>
          <p className={`text-sm mt-1 ${colorTokens.text.subtle}`}>
            {t('promotions:subtitle')}
          </p>
        </div>
        <Button onClick={() => navigate('/pos/promotions/new')}>
          <Plus className="w-4 h-4 mr-2" />
          {t('promotions:createPromotion')}
        </Button>
      </div>

      {/* Filters */}
      <div className="flex gap-4">
        <Input
          placeholder={t('common:search')}
          value={search}
          onChange={(e) => { setSearch(e.target.value); }}
          className="max-w-xs"
        />
        <Select
          value={statusFilter}
          onChange={(e) => { setStatusFilter(e.target.value); }}
          className="w-auto"
        >
          <option value="">{t('common:all')}</option>
          <option value="draft">{t('promotions:statuses.draft')}</option>
          <option value="active">{t('promotions:statuses.active')}</option>
          <option value="paused">{t('promotions:statuses.paused')}</option>
          <option value="expired">{t('promotions:statuses.expired')}</option>
          <option value="archived">{t('promotions:statuses.archived')}</option>
        </Select>
      </div>

      {/* Table */}
      {isLoading ? (
        <div className={`text-center py-12 ${colorTokens.text.subtle}`}>{t('common:loading')}</div>
      ) : promotions.length === 0 ? (
        <div className="text-center py-12">
          <Tag className={`w-12 h-12 mx-auto ${colorTokens.text.faint} mb-4`} />
          <p className={`${colorTokens.text.subtle} font-medium`}>{t('promotions:noPromotions')}</p>
          <p className={`${colorTokens.text.disabled} text-sm mt-1`}>{t('promotions:noPromotionsDescription')}</p>
        </div>
      ) : (
        <div className={`overflow-x-auto rounded-lg border ${colorTokens.border.subtle}`}>
          <DataTable className={`min-w-full divide-y ${colorTokens.border.divider}`}>
            <thead className={`${colorTokens.surface.page}`}>
              <tr>
                <th className={`px-4 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase`}>
                  {t('promotions:fields.name')}
                </th>
                <th className={`px-4 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase`}>
                  {t('promotions:fields.type')}
                </th>
                <th className={`px-4 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase`}>
                  {t('promotions:fields.status')}
                </th>
                <th className={`px-4 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase`}>
                  {t('promotions:fields.discountValue')}
                </th>
                <th className={`px-4 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase`}>
                  {t('promotions:fields.usageCount')}
                </th>
                <th className={`px-4 py-3 text-right text-xs font-medium ${colorTokens.text.subtle} uppercase`}>
                  {t('common:actions')}
                </th>
              </tr>
            </thead>
            <tbody className={`bg-white divide-y ${colorTokens.border.divider}`}>
              {promotions.map((promo) => (
                <tr
                  key={promo.id}
                  className={`hover:${colorTokens.surface.page} cursor-pointer`}
                  onClick={() => navigate(`/pos/promotions/${promo.id}/edit`)}
                >
                  <td className="px-4 py-3">
                    <div className={`font-medium ${colorTokens.text.primary}`}>{promo.name}</div>
                    {promo.description && (
                      <div className={`text-sm ${colorTokens.text.subtle} truncate max-w-xs`}>{promo.description}</div>
                    )}
                  </td>
                  <td className={`px-4 py-3 text-sm ${colorTokens.text.secondary}`}>
                    {t(`promotions:types.${promo.type}`)}
                  </td>
                  <td className="px-4 py-3">
                    <StatusBadge tone={statusTone(promo.status, promotionToneOverrides)}>
                      {t(`promotions:statuses.${promo.status}`)}
                    </StatusBadge>
                  </td>
                  <td className={`px-4 py-3 text-sm ${colorTokens.text.secondary}`}>
                    {promo.discount_type === 'percentage'
                      ? `${promo.discount_value}%`
                      : promo.discount_type === 'free_item'
                        ? t('promotions:discountTypes.free_item')
                        : promo.discount_value}
                  </td>
                  <td className={`px-4 py-3 text-sm ${colorTokens.text.secondary}`}>
                    {promo.usage_count}
                    {promo.usage_limit !== null && ` / ${promo.usage_limit}`}
                  </td>
                  <td className="px-4 py-3 text-right">
                    <div className="flex items-center justify-end gap-1" onClick={(e) => { e.stopPropagation(); }}>
                      {promo.status === 'draft' || promo.status === 'paused' ? (
                        <button
                          onClick={() => { handleAction('activate', promo); }}
                          className={`p-1.5 rounded hover:${colorTokens.intent.success.bgSubtle} ${colorTokens.intent.success.text}`}
                          title={t('promotions:actions.activate')}
                        >
                          <Play className="w-4 h-4" />
                        </button>
                      ) : null}
                      {promo.status === 'active' ? (
                        <button
                          onClick={() => { handleAction('pause', promo); }}
                          className={`p-1.5 rounded hover:${colorTokens.intent.warning.bgSubtle} ${colorTokens.intent.warning.text}`}
                          title={t('promotions:actions.pause')}
                        >
                          <Pause className="w-4 h-4" />
                        </button>
                      ) : null}
                      {promo.status !== 'archived' ? (
                        <button
                          onClick={() => { handleAction('archive', promo); }}
                          className={`p-1.5 rounded hover:${colorTokens.surface.muted} ${colorTokens.text.subtle}`}
                          title={t('promotions:actions.archive')}
                        >
                          <Archive className="w-4 h-4" />
                        </button>
                      ) : null}
                      {promo.status !== 'active' ? (
                        <button
                          onClick={() => { handleAction('delete', promo); }}
                          className={`p-1.5 rounded hover:${colorTokens.intent.danger.bgSubtle} ${colorTokens.intent.danger.textSubtle}`}
                          title={t('promotions:deletePromotion')}
                        >
                          <Trash2 className="w-4 h-4" />
                        </button>
                      ) : null}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </DataTable>
        </div>
      )}

      <ConfirmDialog
        isOpen={confirmAction !== null}
        onClose={() => { setConfirmAction(null); }}
        onConfirm={handleConfirm}
        title={t('promotions:deletePromotion')}
        message={t('promotions:actions.confirmDelete')}
        variant="danger"
        isLoading={deleteMutation.isPending}
      />
    </div>
  )
}
