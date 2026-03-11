import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { Plus, Users } from 'lucide-react'
import { Button, Select } from '@/components/atoms'
import { SearchInput } from '@/components/molecules/SearchInput/SearchInput'
import { Spinner } from '@/components/atoms/Spinner/Spinner'
import { Pagination } from '@/components/ui/Pagination'

import { useMembers } from '../hooks/useMembers'
import { MemberStatusBadge } from '../components/MemberStatusBadge'
import type { MemberStatus } from '../types/loyalty'

export function MemberListPage() {
  const { t } = useTranslation(['loyalty', 'common'])
  const navigate = useNavigate()
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState<string>('')
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(20)

  const { data, isLoading } = useMembers({
    page,
    per_page: perPage,
    ...(search ? { search } : {}),
    ...(statusFilter ? { status: statusFilter } : {}),
  })

  const members = data?.data ?? []
  const meta = data?.meta

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">{t('loyalty:membersTitle')}</h1>
          <p className="text-sm mt-1 text-gray-500">{t('loyalty:membersSubtitle')}</p>
        </div>
        <Button onClick={() => navigate('/pos/loyalty/members/new')}>
          <Plus className="w-4 h-4 mr-2" />
          {t('loyalty:members.create')}
        </Button>
      </div>

      <div className="flex gap-4">
        <SearchInput
          value={search}
          onChange={(val) => {
            setSearch(val)
            setPage(1)
          }}
          placeholder={t('common:search')}
          className="max-w-xs"
        />
        <Select
          value={statusFilter}
          onChange={(e) => {
            setStatusFilter(e.target.value)
            setPage(1)
          }}
          className="w-auto"
        >
          <option value="">{t('common:all')}</option>
          {(['active', 'inactive', 'suspended'] as MemberStatus[]).map((s) => (
            <option key={s} value={s}>{t(`loyalty:statuses.${s}`)}</option>
          ))}
        </Select>
      </div>

      {isLoading ? (
        <div className="flex justify-center py-12">
          <Spinner />
        </div>
      ) : members.length === 0 ? (
        <div className="text-center py-12">
          <Users className="w-12 h-12 mx-auto text-gray-300 mb-4" />
          <p className="text-gray-500 font-medium">{t('loyalty:members.noMembers')}</p>
          <p className="text-gray-400 text-sm mt-1">{t('loyalty:members.noMembersDescription')}</p>
        </div>
      ) : (
        <>
          <div className="overflow-x-auto rounded-lg border border-gray-200">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                    {t('loyalty:fields.name')}
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                    {t('loyalty:fields.phone')}
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                    {t('loyalty:fields.email')}
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                    {t('loyalty:fields.status')}
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                    {t('loyalty:fields.enrolledAt')}
                  </th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {members.map((member) => (
                  <tr
                    key={member.id}
                    className="hover:bg-gray-50 cursor-pointer"
                    onClick={() => navigate(`/pos/loyalty/members/${member.id}`)}
                  >
                    <td className="px-4 py-3 font-medium text-gray-900">
                      {[member.first_name, member.last_name].filter(Boolean).join(' ') || '-'}
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-700">{member.phone}</td>
                    <td className="px-4 py-3 text-sm text-gray-700">{member.email ?? '-'}</td>
                    <td className="px-4 py-3">
                      <MemberStatusBadge status={member.status} />
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-500">
                      {new Date(member.enrollment_date).toLocaleDateString()}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {meta ? (
            <Pagination
              hasPrev={meta.current_page > 1}
              hasNext={meta.current_page < meta.last_page}
              onPrev={() => setPage((p) => Math.max(1, p - 1))}
              onNext={() => setPage((p) => p + 1)}
              perPage={perPage}
              onPerPageChange={(newPerPage) => {
                setPerPage(newPerPage)
                setPage(1)
              }}
            />
          ) : null}
        </>
      )}
    </div>
  )
}
