import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { Plus, Ticket, Ban, RefreshCw, Trash2 } from 'lucide-react'
import { Button, Input, Select } from '@/components/atoms'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'

import { useCoupons, useRevokeCoupon, useReactivateCoupon, useDeleteCoupon } from '../hooks/useCoupons'
import type { CouponData } from '../api/couponApi'

const STATUS_COLORS: Record<string, string> = {
  active: 'bg-green-100 text-green-700',
  exhausted: 'bg-gray-200 text-gray-500',
  expired: 'bg-red-100 text-red-700',
  revoked: 'bg-orange-100 text-orange-700',
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
    const onSettled = () => setConfirmAction(null)

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
          <h1 className="text-2xl font-bold text-gray-900">
            {t('coupons:title')}
          </h1>
          <p className="text-sm mt-1 text-gray-500">
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
          onChange={(e) => setSearch(e.target.value)}
          className="max-w-xs"
        />
        <Select
          value={statusFilter}
          onChange={(e) => setStatusFilter(e.target.value)}
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
        <div className="text-center py-12 text-gray-500">{t('common:loading')}</div>
      ) : coupons.length === 0 ? (
        <div className="text-center py-12">
          <Ticket className="w-12 h-12 mx-auto text-gray-300 mb-4" />
          <p className="text-gray-500 font-medium">{t('coupons:noCoupons')}</p>
          <p className="text-gray-400 text-sm mt-1">{t('coupons:noCouponsDescription')}</p>
        </div>
      ) : (
        <div className="overflow-x-auto rounded-lg border border-gray-200">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                  {t('coupons:fields.code')}
                </th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                  {t('coupons:fields.name')}
                </th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                  {t('coupons:fields.status')}
                </th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                  {t('coupons:fields.discountValue')}
                </th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                  {t('coupons:fields.useCount')}
                </th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                  {t('coupons:fields.expiresAt')}
                </th>
                <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">
                  {t('common:actions')}
                </th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {coupons.map((coupon) => (
                <tr
                  key={coupon.id}
                  className="hover:bg-gray-50 cursor-pointer"
                  onClick={() => navigate(`/pos/coupons/${coupon.id}/edit`)}
                >
                  <td className="px-4 py-3">
                    <span className="font-mono font-medium text-gray-900">{coupon.code}</span>
                  </td>
                  <td className="px-4 py-3 text-sm text-gray-700">{coupon.name}</td>
                  <td className="px-4 py-3">
                    <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${STATUS_COLORS[coupon.status] ?? 'bg-gray-100 text-gray-700'}`}>
                      {t(`coupons:statuses.${coupon.status}`)}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-sm text-gray-700">
                    {coupon.discount_type === 'percentage'
                      ? `${coupon.discount_value}%`
                      : coupon.discount_value}
                  </td>
                  <td className="px-4 py-3 text-sm text-gray-700">
                    {coupon.use_count}
                    {coupon.max_uses !== null && ` / ${coupon.max_uses}`}
                  </td>
                  <td className="px-4 py-3 text-sm text-gray-500">
                    {coupon.expires_at
                      ? new Date(coupon.expires_at).toLocaleDateString()
                      : '-'}
                  </td>
                  <td className="px-4 py-3 text-right">
                    <div className="flex items-center justify-end gap-1" onClick={(e) => e.stopPropagation()}>
                      {coupon.status === 'active' ? (
                        <button
                          onClick={() => handleAction('revoke', coupon)}
                          className="p-1.5 rounded hover:bg-orange-50 text-orange-600"
                          title={t('coupons:actions.revoke')}
                        >
                          <Ban className="w-4 h-4" />
                        </button>
                      ) : null}
                      {coupon.status === 'revoked' || coupon.status === 'exhausted' ? (
                        <button
                          onClick={() => handleAction('reactivate', coupon)}
                          className="p-1.5 rounded hover:bg-green-50 text-green-600"
                          title={t('coupons:actions.reactivate')}
                        >
                          <RefreshCw className="w-4 h-4" />
                        </button>
                      ) : null}
                      {coupon.status !== 'active' ? (
                        <button
                          onClick={() => handleAction('delete', coupon)}
                          className="p-1.5 rounded hover:bg-red-50 text-red-500"
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
          </table>
        </div>
      )}

      <ConfirmDialog
        isOpen={confirmAction !== null}
        onClose={() => setConfirmAction(null)}
        onConfirm={handleConfirm}
        isLoading={isActionPending}
        {...getConfirmDialogProps()}
      />
    </div>
  )
}
