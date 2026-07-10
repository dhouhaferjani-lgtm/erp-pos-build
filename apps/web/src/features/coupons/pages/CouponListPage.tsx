import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { Plus, Ticket, Ban, RefreshCw, Trash2 } from 'lucide-react'
import { Button, Input, Select } from '@/components/atoms'
import { StatusBadge, statusTone, type StatusTone } from '@/components/atoms/StatusBadge'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'

import { useCoupons, useRevokeCoupon, useReactivateCoupon, useDeleteCoupon } from '../hooks/useCoupons'
import type { CouponData } from '../api/couponApi'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

const couponToneOverrides: Record<string, StatusTone> = {
  active: 'success',
  exhausted: 'neutral',
  expired: 'danger',
  revoked: 'warning',
}

type ConfirmAction = {
  type: 'revoke' | 'reactivate' | 'delete'
  coupon: CouponData
}

export function CouponListPage() {
  const { t } = useTranslation(['coupons', 'common'])
  const navigate = useNavigate()
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState<string>('')
  const [confirmAction, setConfirmAction] = useState<ConfirmAction | null>(null)

  const listParams = {
    ...(search ? { search } : {}),
    ...(statusFilter ? { status: statusFilter } : {}),
  }
  const { data, isLoading } = useCoupons(listParams)
  const revokeMutation = useRevokeCoupon()
  const reactivateMutation = useReactivateCoupon()
  const deleteMutation = useDeleteCoupon()

  const coupons = data?.data ?? []

  const handleAction = (action: 'revoke' | 'reactivate' | 'delete', coupon: CouponData) => {
    setConfirmAction({ type: action, coupon })
  }

  const handleConfirm = () => {
    if (!confirmAction) return
    const { type, coupon } = confirmAction
    const onSettled = () => { setConfirmAction(null); }

    switch (type) {
      case 'revoke':
        revokeMutation.mutate(coupon.id, { onSettled })
        break
      case 'reactivate':
        reactivateMutation.mutate(coupon.id, { onSettled })
        break
      case 'delete':
        deleteMutation.mutate(coupon.id, { onSettled })
        break
    }
  }

  const getConfirmDialogProps = () => {
    if (!confirmAction) return { title: '', message: '', variant: 'warning' as const }
    switch (confirmAction.type) {
      case 'revoke':
        return {
          title: t('coupons:actions.revoke'),
          message: t('coupons:actions.confirmRevoke'),
          variant: 'warning' as const,
        }
      case 'reactivate':
        return {
          title: t('coupons:actions.reactivate'),
          message: t('coupons:actions.confirmReactivate'),
          variant: 'info' as const,
        }
      case 'delete':
        return {
          title: t('coupons:deleteCoupon'),
          message: t('coupons:actions.confirmDelete'),
          variant: 'danger' as const,
        }
    }
  }

  const isActionPending = revokeMutation.isPending || reactivateMutation.isPending || deleteMutation.isPending

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <PageHeaderTitle className={`text-2xl font-bold ${colorTokens.text.primary}`}>
            {t('coupons:title')}
          </PageHeaderTitle>
          <p className={`text-sm mt-1 ${colorTokens.text.subtle}`}>
            {t('coupons:subtitle')}
          </p>
        </div>
        <Button onClick={() => navigate('/pos/coupons/new')}>
          <Plus className="w-4 h-4 mr-2" />
          {t('coupons:createCoupon')}
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
          <option value="active">{t('coupons:statuses.active')}</option>
          <option value="exhausted">{t('coupons:statuses.exhausted')}</option>
          <option value="expired">{t('coupons:statuses.expired')}</option>
          <option value="revoked">{t('coupons:statuses.revoked')}</option>
        </Select>
      </div>

      {/* Table */}
      {isLoading ? (
        <div className={`text-center py-12 ${colorTokens.text.subtle}`}>{t('common:loading')}</div>
      ) : coupons.length === 0 ? (
        <div className="text-center py-12">
          <Ticket className={`w-12 h-12 mx-auto ${colorTokens.text.faint} mb-4`} />
          <p className={`${colorTokens.text.subtle} font-medium`}>{t('coupons:noCoupons')}</p>
          <p className={`${colorTokens.text.disabled} text-sm mt-1`}>{t('coupons:noCouponsDescription')}</p>
        </div>
      ) : (
        <div className={`overflow-x-auto rounded-lg border ${colorTokens.border.subtle}`}>
          <DataTable className={`min-w-full divide-y ${colorTokens.border.divider}`}>
            <thead className={`${colorTokens.surface.page}`}>
              <tr>
                <th className={`px-4 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase`}>
                  {t('coupons:fields.code')}
                </th>
                <th className={`px-4 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase`}>
                  {t('coupons:fields.name')}
                </th>
                <th className={`px-4 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase`}>
                  {t('coupons:fields.status')}
                </th>
                <th className={`px-4 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase`}>
                  {t('coupons:fields.discountValue')}
                </th>
                <th className={`px-4 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase`}>
                  {t('coupons:fields.useCount')}
                </th>
                <th className={`px-4 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase`}>
                  {t('coupons:fields.expiresAt')}
                </th>
                <th className={`px-4 py-3 text-right text-xs font-medium ${colorTokens.text.subtle} uppercase`}>
                  {t('common:actions')}
                </th>
              </tr>
            </thead>
            <tbody className={`bg-white divide-y ${colorTokens.border.divider}`}>
              {coupons.map((coupon) => (
                <tr
                  key={coupon.id}
                  className={`hover:${colorTokens.surface.page} cursor-pointer`}
                  onClick={() => navigate(`/pos/coupons/${coupon.id}/edit`)}
                >
                  <td className="px-4 py-3">
                    <span className={`font-mono font-medium ${colorTokens.text.primary}`}>{coupon.code}</span>
                  </td>
                  <td className={`px-4 py-3 text-sm ${colorTokens.text.secondary}`}>{coupon.name}</td>
                  <td className="px-4 py-3">
                    <StatusBadge tone={statusTone(coupon.status, couponToneOverrides)}>
                      {t(`coupons:statuses.${coupon.status}`)}
                    </StatusBadge>
                  </td>
                  <td className={`px-4 py-3 text-sm ${colorTokens.text.secondary}`}>
                    {coupon.discount_type === 'percentage'
                      ? `${coupon.discount_value}%`
                      : coupon.discount_value}
                  </td>
                  <td className={`px-4 py-3 text-sm ${colorTokens.text.secondary}`}>
                    {coupon.use_count}
                    {coupon.max_uses !== null && ` / ${coupon.max_uses}`}
                  </td>
                  <td className={`px-4 py-3 text-sm ${colorTokens.text.subtle}`}>
                    {coupon.expires_at
                      ? new Date(coupon.expires_at).toLocaleDateString()
                      : '-'}
                  </td>
                  <td className="px-4 py-3 text-right">
                    <div className="flex items-center justify-end gap-1" onClick={(e) => { e.stopPropagation(); }}>
                      {coupon.status === 'active' ? (
                        <button
                          onClick={() => { handleAction('revoke', coupon); }}
                          className={`p-1.5 rounded hover:${colorTokens.intent.notice.bgSubtle} ${colorTokens.intent.notice.text}`}
                          title={t('coupons:actions.revoke')}
                        >
                          <Ban className="w-4 h-4" />
                        </button>
                      ) : null}
                      {coupon.status === 'revoked' || coupon.status === 'exhausted' ? (
                        <button
                          onClick={() => { handleAction('reactivate', coupon); }}
                          className={`p-1.5 rounded hover:${colorTokens.intent.success.bgSubtle} ${colorTokens.intent.success.text}`}
                          title={t('coupons:actions.reactivate')}
                        >
                          <RefreshCw className="w-4 h-4" />
                        </button>
                      ) : null}
                      {coupon.status !== 'active' ? (
                        <button
                          onClick={() => { handleAction('delete', coupon); }}
                          className={`p-1.5 rounded hover:${colorTokens.intent.danger.bgSubtle} ${colorTokens.intent.danger.textSubtle}`}
                          title={t('coupons:deleteCoupon')}
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
        isLoading={isActionPending}
        {...getConfirmDialogProps()}
      />
    </div>
  )
}
