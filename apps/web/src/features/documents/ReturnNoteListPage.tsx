/**
 * Return Note List Page
 * Displays all return notes with filtering and search capabilities
 */

import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Plus, Search, Filter, FileText } from 'lucide-react'
import { useCurrency } from '@/hooks/useCurrency'
import { api } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import type { ReturnNote } from '@/types/returnNote'
import { colorClasses } from '@/lib/designTokens'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

interface ReturnNotesResponse {
  data: ReturnNote[]
}

export function ReturnNoteListPage() {
  const { t } = useTranslation(['sales', 'common'])
  const { decimals } = useCurrency()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const [searchQuery, setSearchQuery] = useState('')
  const [statusFilter, setStatusFilter] = useState<string>('')
  const [reasonFilter, setReasonFilter] = useState<string>('')

  // Fetch return notes
  const { data, isLoading } = useQuery({
    queryKey: tenantScopedKey(['return-notes', searchQuery, statusFilter, reasonFilter]),
    queryFn: async () => {
      const params = new URLSearchParams()
      if (searchQuery) params.append('search', searchQuery)
      if (statusFilter) params.append('status', statusFilter)
      if (reasonFilter) params.append('return_reason', reasonFilter)

      const response = await api.get<ReturnNotesResponse>(`/return-notes?${params.toString()}`)
      return response.data
    },
    enabled: tenantId !== null && companyId !== null,
  })

  const returnNotes = data?.data || []

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className={`text-[1.5rem] leading-8 font-semibold ${colorClasses.textGray900}`}>
            {t('sales:returnNotes.title')}
          </h1>
          <p className={`mt-1 text-sm ${colorClasses.textGray500}`}>
            {t('common:list.showing', { count: returnNotes.length })}
          </p>
        </div>
        <Link
          to="/sales/return-notes/create"
          className={`inline-flex items-center gap-2 rounded-lg ${colorClasses.bgBlue600} px-4 py-2 text-sm font-medium text-white ${colorClasses.hoverBgBlue700} transition-colors`}
        >
          <Plus className="h-4 w-4" />
          {t('sales:returnNotes.new')}
        </Link>
      </div>

      {/* Filters */}
      <div className={`rounded-lg border ${colorClasses.borderGray200} bg-white p-4`}>
        <div className="flex items-center gap-2 mb-4">
          <Filter className={`h-4 w-4 ${colorClasses.textGray400}`} />
          <span className={`text-sm font-medium ${colorClasses.textGray700}`}>
            {t('common:filters.title')}
          </span>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          {/* Search */}
          <div className="md:col-span-2">
            <div className="relative">
              <Search className={`absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 ${colorClasses.textGray400}`} />
              <input
                type="text"
                placeholder={t('common:search.placeholder')}
                value={searchQuery}
                onChange={(e) => { setSearchQuery(e.target.value) }}
                className={`w-full rounded-md border ${colorClasses.borderGray300} ps-9 pe-3 py-2 text-sm ${colorClasses.focusBorderBlue500} focus:ring-1 ${colorClasses.focusRingBlue500}`}
              />
            </div>
          </div>

          {/* Status Filter */}
          <div>
            <select
              value={statusFilter}
              onChange={(e) => { setStatusFilter(e.target.value) }}
              className={`w-full rounded-md border ${colorClasses.borderGray300} px-3 py-2 text-sm ${colorClasses.focusBorderBlue500} focus:ring-1 ${colorClasses.focusRingBlue500}`}
            >
              <option value="">{t('common:filters.allStatuses')}</option>
              <option value="draft">{t('sales:returnNotes.status.draft')}</option>
              <option value="confirmed">{t('sales:returnNotes.status.confirmed')}</option>
              <option value="cancelled">{t('sales:returnNotes.status.cancelled')}</option>
            </select>
          </div>

          {/* Return Reason Filter */}
          <div>
            <select
              value={reasonFilter}
              onChange={(e) => { setReasonFilter(e.target.value) }}
              className={`w-full rounded-md border ${colorClasses.borderGray300} px-3 py-2 text-sm ${colorClasses.focusBorderBlue500} focus:ring-1 ${colorClasses.focusRingBlue500}`}
            >
              <option value="">{t('common:filters.allReasons')}</option>
              <option value="defective">{t('sales:returnNotes.reason.defective')}</option>
              <option value="wrong_item">{t('sales:returnNotes.reason.wrongItem')}</option>
              <option value="customer_regret">{t('sales:returnNotes.reason.customerRegret')}</option>
              <option value="damaged_in_transit">{t('sales:returnNotes.reason.damagedInTransit')}</option>
              <option value="warranty">{t('sales:returnNotes.reason.warranty')}</option>
              <option value="exchange">{t('sales:returnNotes.reason.exchange')}</option>
              <option value="other">{t('sales:returnNotes.reason.other')}</option>
            </select>
          </div>
        </div>
      </div>

      {/* Table */}
      <div className={`rounded-lg border ${colorClasses.borderGray200} bg-white overflow-hidden`}>
        {isLoading ? (
          <div className="px-6 py-12 text-center">
            <div className={`inline-block h-8 w-8 animate-spin rounded-full border-4 border-solid ${colorClasses.borderBlue600} border-e-transparent`}></div>
            <p className={`mt-4 text-sm ${colorClasses.textGray500}`}>{t('common:status.loading')}</p>
          </div>
        ) : returnNotes.length === 0 ? (
          <div className="px-6 py-12 text-center">
            <FileText className={`mx-auto h-12 w-12 ${colorClasses.textGray400}`} />
            <h3 className={`mt-4 text-sm font-medium ${colorClasses.textGray900}`}>
              {t('sales:returnNotes.empty.title', 'No return notes yet')}
            </h3>
            <p className={`mt-2 text-sm ${colorClasses.textGray500}`}>
              {t('sales:returnNotes.empty.description', 'Get started by creating your first return note.')}
            </p>
            <div className="mt-6">
              <Link
                to="/sales/return-notes/create"
                className={`inline-flex items-center gap-2 rounded-lg ${colorClasses.bgBlue600} px-4 py-2 text-sm font-medium text-white ${colorClasses.hoverBgBlue700}`}
              >
                <Plus className="h-4 w-4" />
                {t('sales:returnNotes.new')}
              </Link>
            </div>
          </div>
        ) : (
          <DataTable className={`min-w-full divide-y ${colorClasses.divideGray200}`}>
            <thead className={`${colorClasses.bgGray50}`}>
              <tr>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                  {t('sales:documents.number')}
                </th>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                  {t('sales:documents.date')}
                </th>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                  {t('sales:documents.partner')}
                </th>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                  {t('sales:returnNotes.reason.title')}
                </th>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                  {t('sales:documents.status')}
                </th>
                <th className={`px-6 py-3 text-end text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                  {t('sales:documents.total')}
                </th>
              </tr>
            </thead>
            <tbody className={`divide-y ${colorClasses.divideGray200} bg-white`}>
              {returnNotes.map((returnNote) => (
                <tr
                  key={returnNote.id}
                  className={`${colorClasses.hoverBgGray50} transition-colors`}
                >
                  <td className="whitespace-nowrap px-6 py-4">
                    <Link
                      to={`/sales/return-notes/${returnNote.id}`}
                      className={`font-medium ${colorClasses.textBlue600} ${colorClasses.hoverTextBlue800}`}
                    >
                      {returnNote.document_number ?? t('sales:documents.draftNumberPlaceholder')}
                    </Link>
                  </td>
                  <td className={`whitespace-nowrap px-6 py-4 text-sm ${colorClasses.textGray900}`}>
                    {new Date(returnNote.document_date).toLocaleDateString()}
                  </td>
                  <td className={`whitespace-nowrap px-6 py-4 text-sm ${colorClasses.textGray900}`}>
                    {returnNote.partner?.name || '-'}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    <span className={`inline-flex rounded-full ${colorClasses.bgGray100} px-2.5 py-0.5 text-xs font-medium ${colorClasses.textGray800}`}>
                      {returnNote.payload?.return_reason
                        ? t(`sales:returnNotes.reason.${returnNote.payload.return_reason}`)
                        : '-'}
                    </span>
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    <span
                      className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${
                        returnNote.status === 'confirmed'
                          ? `${colorClasses.bgGreen100} ${colorClasses.textGreen800}`
                          : returnNote.status === 'cancelled'
                            ? `${colorClasses.bgRed100} ${colorClasses.textRed800}`
                            : `${colorClasses.bgGray100} ${colorClasses.textGray800}`
                      }`}
                    >
                      {t(`sales:returnNotes.status.${returnNote.status}`)}
                    </span>
                  </td>
                  <td className={`whitespace-nowrap px-6 py-4 text-end text-sm font-medium ${colorClasses.textGray900}`}>
                    {parseFloat(returnNote.total).toFixed(decimals)} {returnNote.currency}
                  </td>
                </tr>
              ))}
            </tbody>
          </DataTable>
        )}
      </div>
    </div>
  )
}
