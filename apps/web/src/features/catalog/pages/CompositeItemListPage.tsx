import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Plus, Search } from 'lucide-react'
import { useCompositeItems } from '../hooks/useCompositeItems'
import { useCompanyVerticalLabels } from '../hooks/useVerticalLabels'
import type { CompositeItemData } from '../types/compositeItem'

export function CompositeItemListPage() {
  const { t } = useTranslation(['catalog', 'common'])
  const getLabel = useCompanyVerticalLabels()
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)

  const { data, isLoading } = useCompositeItems({ search: search || undefined, page, per_page: 25 })
  const items = data?.data ?? []
  const meta = data?.meta

  return (
    <div className="space-y-6">
      <div className="sm:flex sm:items-center sm:justify-between">
        <h1 className="text-2xl font-semibold text-gray-900">{getLabel('compositeItems')}</h1>
        <Link
          to="/catalog/composite-items/new"
          className="inline-flex items-center rounded-md bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-700"
        >
          <Plus className="mr-1.5 h-4 w-4" />
          {t('catalog:createCompositeItem')}
        </Link>
      </div>

      {/* Search */}
      <div className="relative">
        <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
          <Search className="h-5 w-5 text-gray-400" />
        </div>
        <input
          type="text"
          placeholder={t('common:search')}
          value={search}
          onChange={(e) => { setSearch(e.target.value); setPage(1) }}
          className="block w-full rounded-md border-gray-300 pl-10 text-sm focus:border-blue-500 focus:ring-blue-500"
        />
      </div>

      {/* Table */}
      {isLoading ? (
        <div className="text-center py-8 text-gray-500">{t('common:loading')}</div>
      ) : items.length === 0 ? (
        <div className="text-center py-8 text-gray-500">{t('catalog:noCompositeItems')}</div>
      ) : (
        <div className="overflow-hidden shadow ring-1 ring-black ring-opacity-5 rounded-lg">
          <table className="min-w-full divide-y divide-gray-300">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">{t('catalog:code')}</th>
                <th className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">{t('catalog:name')}</th>
                <th className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">{t('catalog:category')}</th>
                <th className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">{t('catalog:basePrice')}</th>
                <th className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">{t('catalog:productionType')}</th>
                <th className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">{t('catalog:isActive')}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 bg-white">
              {items.map((item: CompositeItemData) => (
                <tr key={item.id} className="hover:bg-gray-50">
                  <td className="whitespace-nowrap px-3 py-4 text-sm">
                    <Link to={`/catalog/composite-items/${item.id}/edit`} className="text-blue-600 hover:text-blue-700">
                      {item.code}
                    </Link>
                  </td>
                  <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-900">{item.name}</td>
                  <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">{item.category_name ?? '-'}</td>
                  <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-900">{item.base_price}</td>
                  <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">
                    {t(`catalog:productionTypes.${item.production_type}`)}
                  </td>
                  <td className="whitespace-nowrap px-3 py-4 text-sm">
                    <span className={`inline-flex items-center rounded-full px-2 py-1 text-xs font-medium ${
                      item.is_active
                        ? 'bg-green-50 text-green-700 ring-1 ring-inset ring-green-600/20'
                        : 'bg-red-50 text-red-700 ring-1 ring-inset ring-red-600/20'
                    }`}>
                      {item.is_active ? t('common:active') : t('common:inactive')}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* Pagination */}
      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between">
          <p className="text-sm text-gray-700">
            {t('common:page')} {meta.current_page} / {meta.last_page} ({meta.total} {t('common:total')})
          </p>
          <div className="flex gap-2">
            <button
              onClick={() => setPage(Math.max(1, page - 1))}
              disabled={page <= 1}
              className="rounded-md border border-gray-300 px-3 py-1 text-sm disabled:opacity-50"
            >
              {t('common:previous')}
            </button>
            <button
              onClick={() => setPage(Math.min(meta.last_page, page + 1))}
              disabled={page >= meta.last_page}
              className="rounded-md border border-gray-300 px-3 py-1 text-sm disabled:opacity-50"
            >
              {t('common:next')}
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
