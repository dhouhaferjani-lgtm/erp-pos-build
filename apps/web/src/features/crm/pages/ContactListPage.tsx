import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, Link } from 'react-router-dom'
import { Search, UserPlus } from 'lucide-react'
import { useQuery } from '@tanstack/react-query'
import { fetchContacts, contactKeys } from '../api/contactApi'
import type { ContactFilters } from '../api/contactApi'

export function ContactListPage() {
  const { t } = useTranslation(['crm', 'common'])
  const navigate = useNavigate()
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [filters] = useState<ContactFilters>({ per_page: 25 })

  const { data, isLoading } = useQuery({
    queryKey: contactKeys.list({ ...filters, search, page }),
    queryFn: () => fetchContacts({ ...filters, search, page }),
  })

  const contacts = data?.data ?? []
  const meta = data?.meta

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-gray-900">{t('crm:contacts.title')}</h1>
        <Link
          to="/crm/contacts/new"
          className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
        >
          <UserPlus className="h-4 w-4" />
          {t('crm:contacts.newContact')}
        </Link>
      </div>

      {/* Search */}
      <div className="flex gap-4">
        <div className="relative flex-1">
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
          <input
            type="text"
            value={search}
            onChange={(e) => { setSearch(e.target.value); setPage(1) }}
            placeholder={t('common:actions.search')}
            className="w-full rounded-lg border border-gray-300 py-2 pl-10 pr-4 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
          />
        </div>
      </div>

      {/* Table */}
      <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
        <table className="min-w-full divide-y divide-gray-200">
          <thead className="bg-gray-50">
            <tr>
              <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('crm:contacts.fullName')}
              </th>
              <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('crm:contacts.phone')}
              </th>
              <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('crm:contacts.email')}
              </th>
              <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('crm:contacts.company')}
              </th>
              <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('crm:contacts.isActive')}
              </th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-200">
            {isLoading ? (
              <tr>
                <td colSpan={5} className="px-6 py-12 text-center text-sm text-gray-500">
                  {t('common:common.loading')}
                </td>
              </tr>
            ) : contacts.length === 0 ? (
              <tr>
                <td colSpan={5} className="px-6 py-12 text-center text-sm text-gray-500">
                  {t('crm:contacts.noContacts')}
                </td>
              </tr>
            ) : (
              contacts.map((contact) => (
                <tr
                  key={contact.id}
                  className="cursor-pointer hover:bg-gray-50"
                  onClick={() => { navigate(`/crm/contacts/${contact.id}`) }}
                >
                  <td className="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">
                    {contact.full_name}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                    {contact.phone ?? '—'}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                    {contact.email ?? '—'}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                    {contact.parties?.[0]?.name ?? '—'}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm">
                    <span
                      className={`inline-flex rounded-full px-2 text-xs font-semibold leading-5 ${
                        contact.is_active
                          ? 'bg-green-100 text-green-800'
                          : 'bg-gray-100 text-gray-800'
                      }`}
                    >
                      {contact.is_active ? t('crm:contacts.filters.active') : t('crm:contacts.filters.inactive')}
                    </span>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {/* Pagination */}
      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between">
          <p className="text-sm text-gray-700">
            {t('common:pagination.showing', {
              from: ((meta.current_page - 1) * meta.per_page) + 1,
              to: Math.min(meta.current_page * meta.per_page, meta.total),
              total: meta.total,
            })}
          </p>
          <div className="flex gap-2">
            <button
              type="button"
              onClick={() => { setPage(Math.max(1, page - 1)) }}
              disabled={page === 1}
              className="rounded-lg border border-gray-300 px-3 py-1 text-sm disabled:opacity-50"
            >
              {t('common:actions.previous')}
            </button>
            <button
              type="button"
              onClick={() => { setPage(page + 1) }}
              disabled={page >= meta.last_page}
              className="rounded-lg border border-gray-300 px-3 py-1 text-sm disabled:opacity-50"
            >
              {t('common:actions.next')}
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
