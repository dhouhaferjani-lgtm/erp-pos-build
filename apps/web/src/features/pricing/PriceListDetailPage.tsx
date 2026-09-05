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
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

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
      await queryClient.invalidateQueries({ queryKey: ['price-list', id] })
    },
  })

  const removePartnerMutation = useMutation({
    mutationFn: (partnerId: string) => removePriceListFromPartner(id!, partnerId),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['price-list', id] })
    },
  })

  // `fetchPriceList` resolves the PriceListDetail itself — apiGet already
  // unwraps `response.data.data` (gate r1 F-3).
  const priceList = data

  const formatDate = (dateString: string | null | undefined) => {
    if (!dateString) return '-'
    return new Date(dateString).toLocaleDateString('en-US', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    })
  }

  const formatCurrency = (amount: string | number) =>
    formatAmount(amount, priceList?.currency ?? 'TND', { locale: 'en-US' })

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
        <div className={`${colorTokens.text.subtle}`}>{t('common:status.loading')}</div>
      </div>
    )
  }

  if (error || !priceList) {
    return (
      <div className={`rounded-lg ${colorTokens.intent.danger.bgSubtle} p-4 ${colorTokens.intent.danger.textStrong}`}>
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
            className={`inline-flex items-center gap-2 text-sm ${colorTokens.text.muted} ${colorTokens.variants.hoverTextGray900}`}
          >
            <ArrowLeft className="h-4 w-4" />
            {t('common:actions.back')}
          </Link>
          <div>
            <div className="flex items-center gap-2">
              <PageHeaderTitle className={`text-2xl font-bold ${colorTokens.text.primary}`}>{priceList.name}</PageHeaderTitle>
              {priceList.is_default && (
                <span className={`inline-flex rounded-full ${colorTokens.intent.primary.bgSoft} px-2 py-0.5 text-xs font-medium ${colorTokens.intent.primary.textStronger}`}>
                  {t('pricing:priceLists.fields.default')}
                </span>
              )}
              <span
                className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${
                  priceList.is_active
                    ? `${colorTokens.intent.success.bgSoft} ${colorTokens.intent.success.textStronger}`
                    : `${colorTokens.surface.muted} ${colorTokens.text.strong}`
                }`}
              >
                {priceList.is_active ? t('common:filters.active') : t('common:filters.inactive')}
              </span>
            </div>
            <p className={`${colorTokens.text.subtle} font-mono`}>{priceList.code}</p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <Link
            to={`/pricing/price-lists/${id}/edit`}
            className={`inline-flex items-center gap-2 rounded-lg border ${colorTokens.border.default} bg-white px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.variants.hoverBgGray50}`}
          >
            <Edit className="h-4 w-4" />
            {t('common:actions.edit')}
          </Link>
          <button
            onClick={handleDelete}
            disabled={deleteMutation.isPending}
            className={`inline-flex items-center gap-2 rounded-lg border ${colorTokens.intent.danger.border} bg-white px-4 py-2 text-sm font-medium ${colorTokens.intent.danger.textStrong} ${colorTokens.variants.hoverBgRed50} disabled:opacity-50`}
          >
            <Trash2 className="h-4 w-4" />
            {t('common:actions.delete')}
          </button>
        </div>
      </div>

      {/* Details Card */}
      <div className={`rounded-lg border ${colorTokens.border.subtle} bg-white p-6`}>
        <h2 className={`text-lg font-semibold ${colorTokens.text.primary} mb-4`}>
          {t('pricing:priceLists.details', 'Details')}
        </h2>
        <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <dt className={`text-sm ${colorTokens.text.subtle}`}>{t('pricing:priceLists.fields.currency', 'Currency')}</dt>
            <dd className={`mt-1 text-sm font-medium ${colorTokens.text.primary}`}>{priceList.currency}</dd>
          </div>
          <div>
            <dt className={`text-sm ${colorTokens.text.subtle}`}>{t('pricing:priceLists.fields.validFrom', 'Valid From')}</dt>
            <dd className={`mt-1 text-sm font-medium ${colorTokens.text.primary}`}>{formatDate(priceList.valid_from)}</dd>
          </div>
          <div>
            <dt className={`text-sm ${colorTokens.text.subtle}`}>{t('pricing:priceLists.fields.validUntil', 'Valid Until')}</dt>
            <dd className={`mt-1 text-sm font-medium ${colorTokens.text.primary}`}>{formatDate(priceList.valid_until)}</dd>
          </div>
          <div>
            <dt className={`text-sm ${colorTokens.text.subtle}`}>{t('common:fields.created', 'Created')}</dt>
            <dd className={`mt-1 text-sm font-medium ${colorTokens.text.primary}`}>{formatDate(priceList.created_at)}</dd>
          </div>
        </dl>
        {priceList.description && (
          <div className={`mt-4 pt-4 border-t ${colorTokens.border.subtle}`}>
            <dt className={`text-sm ${colorTokens.text.subtle}`}>{t('pricing:priceLists.fields.description', 'Description')}</dt>
            <dd className={`mt-1 text-sm ${colorTokens.text.primary}`}>{priceList.description}</dd>
          </div>
        )}
      </div>

      {/* Items Section */}
      <div className={`rounded-lg border ${colorTokens.border.subtle} bg-white`}>
        <div className={`flex items-center justify-between px-6 py-4 border-b ${colorTokens.border.subtle}`}>
          <div className="flex items-center gap-2">
            <Package className={`h-5 w-5 ${colorTokens.text.disabled}`} />
            <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>
              {t('pricing:priceLists.items', 'Price List Items')}
            </h2>
            <span className={`text-sm ${colorTokens.text.subtle}`}>({priceList.items.length})</span>
          </div>
          <button
            onClick={() => { setShowAddItemModal(true); }}
            className={`inline-flex items-center gap-2 rounded-lg ${colorTokens.intent.primary.bgStrong} px-3 py-1.5 text-sm font-medium text-white ${colorTokens.intent.primary.bgStrongHover}`}
          >
            <Plus className="h-4 w-4" />
            {t('pricing:priceLists.addItem', 'Add Item')}
          </button>
        </div>

        {priceList.items.length === 0 ? (
          <div className="p-12 text-center">
            <Package className={`mx-auto h-12 w-12 ${colorTokens.text.disabled}`} />
            <h3 className={`mt-2 text-sm font-semibold ${colorTokens.text.primary}`}>
              {t('pricing:priceLists.noItems', 'No items')}
            </h3>
            <p className={`mt-1 text-sm ${colorTokens.text.subtle}`}>
              {t('pricing:priceLists.noItemsDescription', 'Add products to this price list.')}
            </p>
          </div>
        ) : (
          <DataTable className={`min-w-full divide-y ${colorTokens.border.divider}`}>
            <thead className={`${colorTokens.surface.page}`}>
              <tr>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                  {t('pricing:priceLists.fields.product', 'Product')}
                </th>
                <th className={`px-6 py-3 text-end text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                  {t('pricing:priceLists.fields.price', 'Price')}
                </th>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                  {t('pricing:priceLists.fields.quantityRange', 'Quantity Range')}
                </th>
                <th className={`px-6 py-3 text-end text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                  {t('common:table.actionsColumn')}
                </th>
              </tr>
            </thead>
            <tbody className={`divide-y ${colorTokens.border.divider} bg-white`}>
              {Object.entries(itemsByProduct).map(([_productId, items]) =>
                items.map((item, index) => (
                  <tr key={item.id} className={`${colorTokens.variants.hoverBgGray50}`}>
                    {index === 0 && (
                      <td
                        className="whitespace-nowrap px-6 py-4"
                        rowSpan={items.length}
                      >
                        <div>
                          <p className={`font-medium ${colorTokens.text.primary}`}>{item.product_name}</p>
                          <p className={`text-sm ${colorTokens.text.subtle} font-mono`}>{item.product_sku}</p>
                        </div>
                      </td>
                    )}
                    <td className="whitespace-nowrap px-6 py-4 text-end">
                      <span className={`font-medium ${colorTokens.text.primary}`}>
                        {formatCurrency(item.price)}
                      </span>
                    </td>
                    <td className={`whitespace-nowrap px-6 py-4 text-sm ${colorTokens.text.subtle}`}>
                      {item.min_quantity}
                      {item.max_quantity ? ` - ${item.max_quantity}` : '+'}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-end">
                      <button
                        onClick={() => { handleRemoveItem(item); }}
                        disabled={removeItemMutation.isPending}
                        className={`${colorTokens.intent.danger.text} ${colorTokens.variants.hoverTextRed800} disabled:opacity-50`}
                        title={t('common:actions.delete')}
                      >
                        <X className="h-4 w-4" />
                      </button>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </DataTable>
        )}
      </div>

      {/* Partners Section */}
      <div className={`rounded-lg border ${colorTokens.border.subtle} bg-white`}>
        <div className={`flex items-center justify-between px-6 py-4 border-b ${colorTokens.border.subtle}`}>
          <div className="flex items-center gap-2">
            <Users className={`h-5 w-5 ${colorTokens.text.disabled}`} />
            <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>
              {t('pricing:priceLists.assignedPartners', 'Assigned Partners')}
            </h2>
            <span className={`text-sm ${colorTokens.text.subtle}`}>({priceList.partners.length})</span>
          </div>
          <button
            onClick={() => { setShowAssignPartnerModal(true); }}
            className={`inline-flex items-center gap-2 rounded-lg ${colorTokens.intent.primary.bgStrong} px-3 py-1.5 text-sm font-medium text-white ${colorTokens.intent.primary.bgStrongHover}`}
          >
            <Plus className="h-4 w-4" />
            {t('pricing:priceLists.assignPartner', 'Assign Partner')}
          </button>
        </div>

        {priceList.partners.length === 0 ? (
          <div className="p-12 text-center">
            <Users className={`mx-auto h-12 w-12 ${colorTokens.text.disabled}`} />
            <h3 className={`mt-2 text-sm font-semibold ${colorTokens.text.primary}`}>
              {t('pricing:priceLists.noPartners', 'No partners assigned')}
            </h3>
            <p className={`mt-1 text-sm ${colorTokens.text.subtle}`}>
              {t('pricing:priceLists.noPartnersDescription', 'Assign customers or suppliers to use this price list.')}
            </p>
          </div>
        ) : (
          <ul className={`divide-y ${colorTokens.border.divider}`}>
            {priceList.partners.map((partner) => (
              <li key={partner.id} className={`flex items-center justify-between px-6 py-4 ${colorTokens.variants.hoverBgGray50}`}>
                <div>
                  <p className={`font-medium ${colorTokens.text.primary}`}>{partner.name}</p>
                  {(partner.valid_from || partner.valid_until) && (
                    <p className={`text-sm ${colorTokens.text.subtle}`}>
                      {formatDate(partner.valid_from)} - {formatDate(partner.valid_until)}
                    </p>
                  )}
                </div>
                <button
                  onClick={() => { handleRemovePartner(partner); }}
                  disabled={removePartnerMutation.isPending}
                  className={`${colorTokens.intent.danger.text} ${colorTokens.variants.hoverTextRed800} disabled:opacity-50`}
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
            <h3 className={`text-lg font-semibold ${colorTokens.text.primary} mb-4`}>
              {t('pricing:priceLists.addItem', 'Add Item')}
            </h3>
            <p className={`${colorTokens.text.subtle} mb-4`}>{t('pricing:priceLists.addItemComingSoon')}</p>
            <button
              onClick={() => { setShowAddItemModal(false); }}
              className={`rounded-lg ${colorTokens.surface.muted} px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.variants.hoverBgGray200}`}
            >
              {t('common:actions.close')}
            </button>
          </div>
        </div>
      )}

      {showAssignPartnerModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
          <div className="w-full max-w-md rounded-lg bg-white p-6">
            <h3 className={`text-lg font-semibold ${colorTokens.text.primary} mb-4`}>
              {t('pricing:priceLists.assignPartner', 'Assign Partner')}
            </h3>
            <p className={`${colorTokens.text.subtle} mb-4`}>{t('pricing:priceLists.assignPartnerComingSoon')}</p>
            <button
              onClick={() => { setShowAssignPartnerModal(false); }}
              className={`rounded-lg ${colorTokens.surface.muted} px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.variants.hoverBgGray200}`}
            >
              {t('common:actions.close')}
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
