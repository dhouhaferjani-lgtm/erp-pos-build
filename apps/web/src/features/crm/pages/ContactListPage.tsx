import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, Link } from 'react-router-dom'
import { Search, UserPlus } from 'lucide-react'
import { useQuery } from '@tanstack/react-query'
import { fetchContacts, contactKeys } from '../api/contactApi'
import type { ContactFilters } from '../api/contactApi'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { Input } from '@/components/atoms/Input/Input'
import { Button } from '@/components/atoms/Button/Button'
import { Badge } from '@/components/atoms/Badge/Badge'

export function ContactListPage() {
  const { t } = useTranslation(['crm', 'common'])
  const navigate = useNavigate()
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [filters] = useState<ContactFilters>({ per_page: 25 })
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  const { data, isLoading } = useQuery({
    queryKey: tenantScopedKey([...contactKeys.list({ ...filters, search, page })]),
    queryFn: () => fetchContacts({ ...filters, search, page }),
    enabled: !!tenantId && !!companyId,
  })

  const contacts = data?.data ?? []
  const meta = data?.meta

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-gray-900">{t('crm:contacts.title')}</h1>
        <Link to="/crm/contacts/new">
          <Button variant="primary">
            <UserPlus className="h-4 w-4 me-2" />
            {t('crm:contacts.newContact')}
          </Button>
        </Link>
      </div>

      {/* Search */}
      <div className="flex gap-4">
        <div className="relative flex-1">
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
          <Input
            type="text"
            value={search}
            onChange={(e) => { setSearch(e.target.value); setPage(1) }}
            placeholder={t('common:actions.search')}
            className="pl-10"
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
                  onClick={() => { void navigate(`/crm/contacts/${contact.id}`) }}
                >
                  <td className="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">
                    {contact.full_name}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                    {contact.phone ?? '\u2014'}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                    {contact.email ?? '\u2014'}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                    {contact.parties?.[0]?.name ?? '\u2014'}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm">
                    <Badge variant={contact.is_active ? 'success' : 'default'}>
                      {contact.is_active ? t('crm:contacts.filters.active') : t('crm:contacts.filters.inactive')}
                    </Badge>
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
            <Button
              variant="secondary"
              size="sm"
              onClick={() => { setPage(Math.max(1, page - 1)) }}
              disabled={page === 1}
            >
              {t('common:actions.previous')}
            </Button>
            <Button
              variant="secondary"
              size="sm"
              onClick={() => { setPage(page + 1) }}
              disabled={page >= meta.last_page}
            >
              {t('common:actions.next')}
            </Button>
          </div>
        </div>
      )}
    </div>
  )
}
