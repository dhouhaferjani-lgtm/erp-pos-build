import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { Plus, Tag, Pause, Play, Archive, Trash2 } from 'lucide-react'
import { Button, Input } from '@/components/atoms'
import { tokens } from '@/lib/designTokens'
import {
  usePromotions,
  useActivatePromotion,
  usePausePromotion,
  useArchivePromotion,
  useDeletePromotion,
} from '../hooks/usePromotions'
import type { PromotionData } from '../api/promotionApi'

const STATUS_COLORS: Record<string, string> = {
  draft: 'bg-gray-100 text-gray-700',
  active: 'bg-green-100 text-green-700',
  paused: 'bg-yellow-100 text-yellow-700',
  expired: 'bg-red-100 text-red-700',
  archived: 'bg-gray-200 text-gray-500',
}

export function PromotionListPage() {
  const { t } = useTranslation(['promotions', 'common'])
  const navigate = useNavigate()
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState<string>('')

  const { data, isLoading } = usePromotions({
    search: search || undefined,
    status: statusFilter || undefined,
  })
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
        if (window.confirm(t('promotions:actions.confirmDelete'))) {
          deleteMutation.mutate(promotion.id)
        }
        break
    }
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold" style={{ color: tokens.colors.text.primary }}>
            {t('promotions:title')}
          </h1>
          <p className="text-sm mt-1" style={{ color: tokens.colors.text.secondary }}>
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
          onChange={(e) => setSearch(e.target.value)}
          className="max-w-xs"
        />
        <select
          value={statusFilter}
          onChange={(e) => setStatusFilter(e.target.value)}
          className="rounded-md border border-gray-300 px-3 py-2 text-sm"
        >
          <option value="">{t('common:all')}</option>
          <option value="draft">{t('promotions:statuses.draft')}</option>
          <option value="active">{t('promotions:statuses.active')}</option>
          <option value="paused">{t('promotions:statuses.paused')}</option>
          <option value="expired">{t('promotions:statuses.expired')}</option>
          <option value="archived">{t('promotions:statuses.archived')}</option>
        </select>
      </div>

      {/* Table */}
      {isLoading ? (
        <div className="text-center py-12 text-gray-500">{t('common:loading')}</div>
      ) : promotions.length === 0 ? (
        <div className="text-center py-12">
          <Tag className="w-12 h-12 mx-auto text-gray-300 mb-4" />
          <p className="text-gray-500 font-medium">{t('promotions:noPromotions')}</p>
          <p className="text-gray-400 text-sm mt-1">{t('promotions:noPromotionsDescription')}</p>
        </div>
      ) : (
        <div className="overflow-x-auto rounded-lg border border-gray-200">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                  {t('promotions:fields.name')}
                </th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                  {t('promotions:fields.type')}
                </th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                  {t('promotions:fields.status')}
                </th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                  {t('promotions:fields.discountValue')}
                </th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                  {t('promotions:fields.usageCount')}
                </th>
                <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">
                  {t('common:actions')}
                </th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {promotions.map((promo) => (
                <tr
                  key={promo.id}
                  className="hover:bg-gray-50 cursor-pointer"
                  onClick={() => navigate(`/pos/promotions/${promo.id}/edit`)}
                >
                  <td className="px-4 py-3">
                    <div className="font-medium text-gray-900">{promo.name}</div>
                    {promo.description && (
                      <div className="text-sm text-gray-500 truncate max-w-xs">{promo.description}</div>
                    )}
                  </td>
                  <td className="px-4 py-3 text-sm text-gray-700">
                    {t(`promotions:types.${promo.type}`)}
                  </td>
                  <td className="px-4 py-3">
                    <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${STATUS_COLORS[promo.status] ?? 'bg-gray-100 text-gray-700'}`}>
                      {t(`promotions:statuses.${promo.status}`)}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-sm text-gray-700">
                    {promo.discount_type === 'percentage'
                      ? `${promo.discount_value}%`
                      : promo.discount_type === 'free_item'
                        ? t('promotions:discountTypes.free_item')
                        : promo.discount_value}
                  </td>
                  <td className="px-4 py-3 text-sm text-gray-700">
                    {promo.usage_count}
                    {promo.usage_limit !== null && ` / ${promo.usage_limit}`}
                  </td>
                  <td className="px-4 py-3 text-right">
                    <div className="flex items-center justify-end gap-1" onClick={(e) => e.stopPropagation()}>
                      {promo.status === 'draft' || promo.status === 'paused' ? (
                        <button
                          onClick={() => handleAction('activate', promo)}
                          className="p-1.5 rounded hover:bg-green-50 text-green-600"
                          title={t('promotions:actions.activate')}
                        >
                          <Play className="w-4 h-4" />
                        </button>
                      ) : null}
                      {promo.status === 'active' ? (
                        <button
                          onClick={() => handleAction('pause', promo)}
                          className="p-1.5 rounded hover:bg-yellow-50 text-yellow-600"
                          title={t('promotions:actions.pause')}
                        >
                          <Pause className="w-4 h-4" />
                        </button>
                      ) : null}
                      {promo.status !== 'archived' ? (
                        <button
                          onClick={() => handleAction('archive', promo)}
                          className="p-1.5 rounded hover:bg-gray-100 text-gray-500"
                          title={t('promotions:actions.archive')}
                        >
                          <Archive className="w-4 h-4" />
                        </button>
                      ) : null}
                      {promo.status !== 'active' ? (
                        <button
                          onClick={() => handleAction('delete', promo)}
                          className="p-1.5 rounded hover:bg-red-50 text-red-500"
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
          </table>
        </div>
      )}
    </div>
  )
}
