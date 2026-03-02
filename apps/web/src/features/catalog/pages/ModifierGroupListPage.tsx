import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Plus, Search } from 'lucide-react'
import { useModifierGroups } from '../hooks/useModifierGroups'
import { useCompanyVerticalLabels } from '../hooks/useVerticalLabels'
import type { ModifierGroupData } from '../types/compositeItem'

export function ModifierGroupListPage() {
  const { t } = useTranslation(['catalog', 'common'])
  const getLabel = useCompanyVerticalLabels()
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)

  const { data, isLoading } = useModifierGroups({ search: search || undefined, page, per_page: 25 })
  const groups = data?.data ?? []
  const meta = data?.meta

  return (
    <div className="space-y-6">
      <div className="sm:flex sm:items-center sm:justify-between">
        <h1 className="text-2xl font-semibold text-gray-900">{getLabel('modifierGroups')}</h1>
        <Link
          to="/catalog/modifier-groups/new"
          className="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-500"
        >
          <Plus className="mr-1.5 h-4 w-4" />
          {t('catalog:createModifierGroup')}
        </Link>
      </div>

      <div className="relative">
        <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
          <Search className="h-5 w-5 text-gray-400" />
        </div>
        <input
          type="text"
          placeholder={t('common:search')}
          value={search}
          onChange={(e) => { setSearch(e.target.value); setPage(1) }}
          className="block w-full rounded-md border-gray-300 pl-10 text-sm focus:border-indigo-500 focus:ring-indigo-500"
        />
      </div>

      {isLoading ? (
        <div className="text-center py-8 text-gray-500">{t('common:loading')}</div>
      ) : groups.length === 0 ? (
        <div className="text-center py-8 text-gray-500">{t('catalog:noModifierGroups')}</div>
      ) : (
        <div className="overflow-hidden shadow ring-1 ring-black ring-opacity-5 rounded-lg">
          <table className="min-w-full divide-y divide-gray-300">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">{t('catalog:code')}</th>
                <th className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">{t('catalog:name')}</th>
                <th className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">{t('catalog:selectionType')}</th>
                <th className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">{t('catalog:modifiers')}</th>
                <th className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">{t('catalog:isRequired')}</th>
                <th className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">{t('catalog:isActive')}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 bg-white">
              {groups.map((group: ModifierGroupData) => (
                <tr key={group.id} className="hover:bg-gray-50">
                  <td className="whitespace-nowrap px-3 py-4 text-sm">
                    <Link to={`/catalog/modifier-groups/${group.id}/edit`} className="text-indigo-600 hover:text-indigo-900">
                      {group.code}
                    </Link>
                  </td>
                  <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-900">{group.name}</td>
                  <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">
                    {group.selection_type === 'single' ? t('catalog:single') : t('catalog:multiple')}
                  </td>
                  <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">
                    {group.modifiers?.length ?? 0}
                  </td>
                  <td className="whitespace-nowrap px-3 py-4 text-sm">
                    {group.is_required ? t('common:yes') : t('common:no')}
                  </td>
                  <td className="whitespace-nowrap px-3 py-4 text-sm">
                    <span className={`inline-flex items-center rounded-full px-2 py-1 text-xs font-medium ${
                      group.is_active
                        ? 'bg-green-50 text-green-700 ring-1 ring-inset ring-green-600/20'
                        : 'bg-red-50 text-red-700 ring-1 ring-inset ring-red-600/20'
                    }`}>
                      {group.is_active ? t('common:active') : t('common:inactive')}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between">
          <p className="text-sm text-gray-700">
            {t('common:page')} {meta.current_page} / {meta.last_page}
          </p>
          <div className="flex gap-2">
            <button onClick={() => setPage(Math.max(1, page - 1))} disabled={page <= 1} className="rounded-md border border-gray-300 px-3 py-1 text-sm disabled:opacity-50">{t('common:previous')}</button>
            <button onClick={() => setPage(Math.min(meta.last_page, page + 1))} disabled={page >= meta.last_page} className="rounded-md border border-gray-300 px-3 py-1 text-sm disabled:opacity-50">{t('common:next')}</button>
          </div>
        </div>
      )}
    </div>
  )
}
