import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { Plus, Users } from 'lucide-react'
import { Button, Select } from '@/components/atoms'
import { SearchInput } from '@/components/molecules/SearchInput/SearchInput'
import { Spinner } from '@/components/atoms/Spinner/Spinner'
import { OffsetPagination } from '@/components/ui/OffsetPagination'

import { useMembers } from '../hooks/useMembers'
import { MemberStatusBadge } from '../components/MemberStatusBadge'
import type { MemberStatus } from '../types/loyalty'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { DataTable } from '@/components/molecules/DataTable/DataTable'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'

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
          <PageHeaderTitle className={`text-2xl font-bold ${colorTokens.text.primary}`}>{t('loyalty:membersTitle')}</PageHeaderTitle>
          <p className={`text-sm mt-1 ${colorTokens.text.subtle}`}>{t('loyalty:membersSubtitle')}</p>
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
          <Users className={`w-12 h-12 mx-auto ${colorTokens.text.faint} mb-4`} />
          <p className={`${colorTokens.text.subtle} font-medium`}>{t('loyalty:members.noMembers')}</p>
          <p className={`${colorTokens.text.disabled} text-sm mt-1`}>{t('loyalty:members.noMembersDescription')}</p>
        </div>
      ) : (
        <>
          <div className={`overflow-x-auto rounded-lg border ${colorTokens.border.subtle}`}>
            <DataTable className={`min-w-full divide-y ${colorTokens.border.divider}`}>
              <thead className={`${colorTokens.surface.page}`}>
                <tr>
                  <th className={`px-4 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase`}>
                    {t('loyalty:fields.name')}
                  </th>
                  <th className={`px-4 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase`}>
                    {t('loyalty:fields.phone')}
                  </th>
                  <th className={`px-4 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase`}>
                    {t('loyalty:fields.email')}
                  </th>
                  <th className={`px-4 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase`}>
                    {t('loyalty:fields.status')}
                  </th>
                  <th className={`px-4 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase`}>
                    {t('loyalty:fields.enrolledAt')}
                  </th>
                </tr>
              </thead>
              <tbody className={`${colorTokens.surface.base} divide-y ${colorTokens.border.divider}`}>
                {members.map((member) => (
                  <tr
                    key={member.id}
                    className={`${colorTokens.intent.neutral.bgHover} cursor-pointer`}
                    onClick={() => navigate(`/pos/loyalty/members/${member.id}`)}
                  >
                    <td className={`px-4 py-3 font-medium ${colorTokens.text.primary}`}>
                      {[member.first_name, member.last_name].filter(Boolean).join(' ') || '-'}
                    </td>
                    <td className={`px-4 py-3 text-sm ${colorTokens.text.secondary}`}>{member.phone}</td>
                    <td className={`px-4 py-3 text-sm ${colorTokens.text.secondary}`}>{member.email ?? '-'}</td>
                    <td className="px-4 py-3">
                      <MemberStatusBadge status={member.status} />
                    </td>
                    <td className={`px-4 py-3 text-sm ${colorTokens.text.subtle}`}>
                      {new Date(member.enrollment_date).toLocaleDateString()}
                    </td>
                  </tr>
                ))}
              </tbody>
            </DataTable>
          </div>
          {meta ? (
            <OffsetPagination
              currentPage={meta.current_page}
              lastPage={meta.last_page}
              total={meta.total}
              perPage={meta.per_page}
              from={null}
              to={null}
              onPageChange={(newPage) => { setPage(newPage); }}
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
