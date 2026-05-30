import { useState } from 'react'
import { Link, useParams, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import {
  ArrowLeft,
  Edit,
  Trash2,
  Plus,
  Users,
  Package,
  X,
} from 'lucide-react'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { formatAmount } from '@/hooks/useCurrency'
import { fetchPriceList, deletePriceList, removePriceListItem, removePriceListFromPartner } from './api'
import { priceListsInvalidationPredicate } from './_invalidation'
import type { PriceListItem, PriceListPartner } from './types'

export function PriceListDetailPage() {
  const { t } = useTranslation(['common', 'pricing'])
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const [showAddItemModal, setShowAddItemModal] = useState(false)
  const [showAssignPartnerModal, setShowAssignPartnerModal] = useState(false)

  const { data, isLoading, error } = useQuery({
    queryKey: tenantScopedKey(['price-list', id]),
    queryFn: () => fetchPriceList(id!),
    enabled: Boolean(id) && !!tenantId && !!companyId,
  })

  const deleteMutation = useMutation({
    mutationFn: () => deletePriceList(id!),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: priceListsInvalidationPredicate(tenantId, companyId),
      })
      navigate('/pricing/price-lists')
    },
  })

  const removeItemMutation = useMutation({
    mutationFn: (itemId: string) => removePriceListItem(id!, itemId),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: tenantScopedKey(['price-list', id]) })
    },
  })

  const removePartnerMutation = useMutation({
    mutationFn: (partnerId: string) => removePriceListFromPartner(id!, partnerId),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: tenantScopedKey(['price-list', id]) })
    },
  })

  const priceList = data?.data

  const formatDate = (dateString: string | null | undefined) => {
    if (!dateString) return '-'
    return new Date(dateString).toLocaleDateString('en-US', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    })
  }

  const formatCurrency = (amount: string | number) =>
    formatAmount(amount, priceList?.currency ?? 'EUR', { locale: 'en-US' })

  const handleDelete = () => {
    if (window.confirm(t('common:confirmation.delete'))) {
      deleteMutation.mutate()
    }
  }

  const handleRemoveItem = (item: PriceListItem) => {
    if (window.confirm(t('pricing:priceLists.confirmRemoveItem', 'Are you sure you want to remove this item?'))) {
      removeItemMutation.mutate(item.id)
    }
  }

  const handleRemovePartner = (partner: PriceListPartner) => {
    if (window.confirm(t('pricing:priceLists.confirmRemovePartner', 'Are you sure you want to remove this partner?'))) {
      removePartnerMutation.mutate(partner.id)
    }
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className="text-gray-500">{t('common:status.loading')}</div>
      </div>
    )
  }

  if (error || !priceList) {
    return (
      <div className="rounded-lg bg-red-50 p-4 text-red-700">
        {t('common:errors.loadingFailed')}
      </div>
    )
  }

  // Group items by product for quantity breaks display
  const itemsByProduct = priceList.items.reduce<Record<string, PriceListItem[]>>((acc, item) => {
    const key = item.product_id
    if (!acc[key]) {
      acc[key] = []
    }
    acc[key].push(item)
    return acc
  }, {})

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Link
            to="/pricing/price-lists"
            className="inline-flex items-center gap-2 text-sm text-gray-600 hover:text-gray-900"
          >
            <ArrowLeft className="h-4 w-4" />
            {t('common:actions.back')}
          </Link>
          <div>
            <div className="flex items-center gap-2">
              <h1 className="text-2xl font-bold text-gray-900">{priceList.name}</h1>
              {priceList.is_default && (
                <span className="inline-flex rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800">
                  Default
                </span>
              )}
              <span
                className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${
                  priceList.is_active
                    ? 'bg-green-100 text-green-800'
                    : 'bg-gray-100 text-gray-800'
                }`}
              >
                {priceList.is_active ? t('common:filters.active') : t('common:filters.inactive')}
              </span>
            </div>
            <p className="text-gray-500 font-mono">{priceList.code}</p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <Link
            to={`/pricing/price-lists/${id}/edit`}
            className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
          >
            <Edit className="h-4 w-4" />
            {t('common:actions.edit')}
          </Link>
          <button
            onClick={handleDelete}
            disabled={deleteMutation.isPending}
            className="inline-flex items-center gap-2 rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50 disabled:opacity-50"
          >
            <Trash2 className="h-4 w-4" />
            {t('common:actions.delete')}
          </button>
        </div>
      </div>

      {/* Details Card */}
      <div className="rounded-lg border border-gray-200 bg-white p-6">
        <h2 className="text-lg font-semibold text-gray-900 mb-4">
          {t('pricing:priceLists.details', 'Details')}
        </h2>
        <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <dt className="text-sm text-gray-500">{t('pricing:priceLists.fields.currency', 'Currency')}</dt>
            <dd className="mt-1 text-sm font-medium text-gray-900">{priceList.currency}</dd>
          </div>
          <div>
            <dt className="text-sm text-gray-500">{t('pricing:priceLists.fields.validFrom', 'Valid From')}</dt>
            <dd className="mt-1 text-sm font-medium text-gray-900">{formatDate(priceList.valid_from)}</dd>
          </div>
          <div>
            <dt className="text-sm text-gray-500">{t('pricing:priceLists.fields.validUntil', 'Valid Until')}</dt>
            <dd className="mt-1 text-sm font-medium text-gray-900">{formatDate(priceList.valid_until)}</dd>
          </div>
          <div>
            <dt className="text-sm text-gray-500">{t('common:fields.created', 'Created')}</dt>
            <dd className="mt-1 text-sm font-medium text-gray-900">{formatDate(priceList.created_at)}</dd>
          </div>
        </dl>
        {priceList.description && (
          <div className="mt-4 pt-4 border-t border-gray-200">
            <dt className="text-sm text-gray-500">{t('pricing:priceLists.fields.description', 'Description')}</dt>
            <dd className="mt-1 text-sm text-gray-900">{priceList.description}</dd>
          </div>
        )}
      </div>

      {/* Items Section */}
      <div className="rounded-lg border border-gray-200 bg-white">
        <div className="flex items-center justify-between px-6 py-4 border-b border-gray-200">
          <div className="flex items-center gap-2">
            <Package className="h-5 w-5 text-gray-400" />
            <h2 className="text-lg font-semibold text-gray-900">
              {t('pricing:priceLists.items', 'Price List Items')}
            </h2>
            <span className="text-sm text-gray-500">({priceList.items.length})</span>
          </div>
          <button
            onClick={() => { setShowAddItemModal(true); }}
            className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700"
          >
            <Plus className="h-4 w-4" />
            {t('pricing:priceLists.addItem', 'Add Item')}
          </button>
        </div>

        {priceList.items.length === 0 ? (
          <div className="p-12 text-center">
            <Package className="mx-auto h-12 w-12 text-gray-400" />
            <h3 className="mt-2 text-sm font-semibold text-gray-900">
              {t('pricing:priceLists.noItems', 'No items')}
            </h3>
            <p className="mt-1 text-sm text-gray-500">
              {t('pricing:priceLists.noItemsDescription', 'Add products to this price list.')}
            </p>
          </div>
        ) : (
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('pricing:priceLists.fields.product', 'Product')}
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('pricing:priceLists.fields.price', 'Price')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('pricing:priceLists.fields.quantityRange', 'Quantity Range')}
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('common:table.actions')}
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 bg-white">
              {Object.entries(itemsByProduct).map(([_productId, items]) =>
                items.map((item, index) => (
                  <tr key={item.id} className="hover:bg-gray-50">
                    {index === 0 && (
                      <td
                        className="whitespace-nowrap px-6 py-4"
                        rowSpan={items.length}
                      >
                        <div>
                          <p className="font-medium text-gray-900">{item.product_name}</p>
                          <p className="text-sm text-gray-500 font-mono">{item.product_sku}</p>
                        </div>
                      </td>
                    )}
                    <td className="whitespace-nowrap px-6 py-4 text-end">
                      <span className="font-medium text-gray-900">
                        {formatCurrency(item.price)}
                      </span>
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                      {item.min_quantity}
                      {item.max_quantity ? ` - ${item.max_quantity}` : '+'}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-end">
                      <button
                        onClick={() => { handleRemoveItem(item); }}
                        disabled={removeItemMutation.isPending}
                        className="text-red-600 hover:text-red-800 disabled:opacity-50"
                        title={t('common:actions.delete')}
                      >
                        <X className="h-4 w-4" />
                      </button>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        )}
      </div>

      {/* Partners Section */}
      <div className="rounded-lg border border-gray-200 bg-white">
        <div className="flex items-center justify-between px-6 py-4 border-b border-gray-200">
          <div className="flex items-center gap-2">
            <Users className="h-5 w-5 text-gray-400" />
            <h2 className="text-lg font-semibold text-gray-900">
              {t('pricing:priceLists.assignedPartners', 'Assigned Partners')}
            </h2>
            <span className="text-sm text-gray-500">({priceList.partners.length})</span>
          </div>
          <button
            onClick={() => { setShowAssignPartnerModal(true); }}
            className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700"
          >
            <Plus className="h-4 w-4" />
            {t('pricing:priceLists.assignPartner', 'Assign Partner')}
          </button>
        </div>

        {priceList.partners.length === 0 ? (
          <div className="p-12 text-center">
            <Users className="mx-auto h-12 w-12 text-gray-400" />
            <h3 className="mt-2 text-sm font-semibold text-gray-900">
              {t('pricing:priceLists.noPartners', 'No partners assigned')}
            </h3>
            <p className="mt-1 text-sm text-gray-500">
              {t('pricing:priceLists.noPartnersDescription', 'Assign customers or suppliers to use this price list.')}
            </p>
          </div>
        ) : (
          <ul className="divide-y divide-gray-200">
            {priceList.partners.map((partner) => (
              <li key={partner.id} className="flex items-center justify-between px-6 py-4 hover:bg-gray-50">
                <div>
                  <p className="font-medium text-gray-900">{partner.name}</p>
                  {(partner.valid_from || partner.valid_until) && (
                    <p className="text-sm text-gray-500">
                      {formatDate(partner.valid_from)} - {formatDate(partner.valid_until)}
                    </p>
                  )}
                </div>
                <button
                  onClick={() => { handleRemovePartner(partner); }}
                  disabled={removePartnerMutation.isPending}
                  className="text-red-600 hover:text-red-800 disabled:opacity-50"
                  title={t('common:actions.delete')}
                >
                  <X className="h-4 w-4" />
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>

      {/* Placeholder modals - will be implemented later */}
      {showAddItemModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
          <div className="w-full max-w-md rounded-lg bg-white p-6">
            <h3 className="text-lg font-semibold text-gray-900 mb-4">
              {t('pricing:priceLists.addItem', 'Add Item')}
            </h3>
            <p className="text-gray-500 mb-4">Item addition modal - coming soon</p>
            <button
              onClick={() => { setShowAddItemModal(false); }}
              className="rounded-lg bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200"
            >
              {t('common:actions.close')}
            </button>
          </div>
        </div>
      )}

      {showAssignPartnerModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
          <div className="w-full max-w-md rounded-lg bg-white p-6">
            <h3 className="text-lg font-semibold text-gray-900 mb-4">
              {t('pricing:priceLists.assignPartner', 'Assign Partner')}
            </h3>
            <p className="text-gray-500 mb-4">Partner assignment modal - coming soon</p>
            <button
              onClick={() => { setShowAssignPartnerModal(false); }}
              className="rounded-lg bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200"
            >
              {t('common:actions.close')}
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
