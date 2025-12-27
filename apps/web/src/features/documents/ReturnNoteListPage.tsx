/**
 * Return Note List Page
 * Displays all return notes with filtering and search capabilities
 */

import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Plus, Search, Filter, FileText } from 'lucide-react'
import { api } from '@/lib/api'
import type { ReturnNote } from '@/types/returnNote'

interface ReturnNotesResponse {
  data: ReturnNote[]
}

export function ReturnNoteListPage() {
  const { t } = useTranslation(['sales', 'common'])
  const [searchQuery, setSearchQuery] = useState('')
  const [statusFilter, setStatusFilter] = useState<string>('')
  const [reasonFilter, setReasonFilter] = useState<string>('')

  // Fetch return notes
  const { data, isLoading } = useQuery({
    queryKey: ['return-notes', searchQuery, statusFilter, reasonFilter],
    queryFn: async () => {
      const params = new URLSearchParams()
      if (searchQuery) params.append('search', searchQuery)
      if (statusFilter) params.append('status', statusFilter)
      if (reasonFilter) params.append('return_reason', reasonFilter)

      const response = await api.get<ReturnNotesResponse>(`/return-notes?${params.toString()}`)
      return response.data
    },
  })

  const returnNotes = data?.data || []

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-gray-900">
            {t('sales:returnNotes.title')}
          </h1>
          <p className="mt-1 text-sm text-gray-500">
            {t('common:list.showing', { count: returnNotes.length })}
          </p>
        </div>
        <Link
          to="/sales/return-notes/create"
          className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 transition-colors"
        >
          <Plus className="h-4 w-4" />
          {t('sales:returnNotes.new')}
        </Link>
      </div>

      {/* Filters */}
      <div className="rounded-lg border border-gray-200 bg-white p-4">
        <div className="flex items-center gap-2 mb-4">
          <Filter className="h-4 w-4 text-gray-400" />
          <span className="text-sm font-medium text-gray-700">
            {t('common:filters.title')}
          </span>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          {/* Search */}
          <div className="md:col-span-2">
            <div className="relative">
              <Search className="absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
              <input
                type="text"
                placeholder={t('common:search.placeholder')}
                value={searchQuery}
                onChange={(e) => { setSearchQuery(e.target.value) }}
                className="w-full rounded-md border border-gray-300 ps-9 pe-3 py-2 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
              />
            </div>
          </div>

          {/* Status Filter */}
          <div>
            <select
              value={statusFilter}
              onChange={(e) => { setStatusFilter(e.target.value) }}
              className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
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
              className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
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
      <div className="rounded-lg border border-gray-200 bg-white overflow-hidden">
        {isLoading ? (
          <div className="px-6 py-12 text-center">
            <div className="inline-block h-8 w-8 animate-spin rounded-full border-4 border-solid border-blue-600 border-e-transparent"></div>
            <p className="mt-4 text-sm text-gray-500">{t('common:status.loading')}</p>
          </div>
        ) : returnNotes.length === 0 ? (
          <div className="px-6 py-12 text-center">
            <FileText className="mx-auto h-12 w-12 text-gray-400" />
            <h3 className="mt-4 text-sm font-medium text-gray-900">
              {t('sales:returnNotes.empty.title', 'No return notes yet')}
            </h3>
            <p className="mt-2 text-sm text-gray-500">
              {t('sales:returnNotes.empty.description', 'Get started by creating your first return note.')}
            </p>
            <div className="mt-6">
              <Link
                to="/sales/return-notes/create"
                className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
              >
                <Plus className="h-4 w-4" />
                {t('sales:returnNotes.new')}
              </Link>
            </div>
          </div>
        ) : (
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('sales:documents.number')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('sales:documents.date')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('sales:documents.partner')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('sales:returnNotes.reason.title')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('sales:documents.status')}
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('sales:documents.total')}
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 bg-white">
              {returnNotes.map((returnNote) => (
                <tr
                  key={returnNote.id}
                  className="hover:bg-gray-50 transition-colors"
                >
                  <td className="whitespace-nowrap px-6 py-4">
                    <Link
                      to={`/sales/return-notes/${returnNote.id}`}
                      className="font-medium text-blue-600 hover:text-blue-800"
                    >
                      {returnNote.document_number}
                    </Link>
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                    {new Date(returnNote.document_date).toLocaleDateString()}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                    {returnNote.partner?.name || '-'}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    <span className="inline-flex rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-800">
                      {t(`sales:returnNotes.reason.${returnNote.metadata.return_reason}`)}
                    </span>
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    <span
                      className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${
                        returnNote.status === 'confirmed'
                          ? 'bg-green-100 text-green-800'
                          : returnNote.status === 'cancelled'
                            ? 'bg-red-100 text-red-800'
                            : 'bg-gray-100 text-gray-800'
                      }`}
                    >
                      {t(`sales:returnNotes.status.${returnNote.status}`)}
                    </span>
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-end text-sm font-medium text-gray-900">
                    {parseFloat(returnNote.total).toFixed(2)} {returnNote.currency}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  )
}
