import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { apiGet, apiPost } from '@/lib/api'
import { Receipt as ReceiptIcon, Loader2, Search, Ban, Printer } from 'lucide-react'
import { cn } from '@/lib/utils'
import { toast } from 'sonner'
import { useReceiptPrint } from '../../hooks/useReceiptPrint'
import { Modal, ModalContent, ModalFooter } from '@/components/organisms/Modal/Modal'

interface Terminal {
  id: string
  code: string
  name: string
}

interface ReceiptItem {
  id: string
  receipt_number: string
  terminal_id: string
  terminal_code: string
  cashier_name: string
  subtotal: string
  tax_amount: string
  total: string
  currency: string
  posted_at: string
  is_voided: boolean
  void_reason: string | null
}

interface ReceiptSearchFilters {
  terminal_id?: string
  receipt_number?: string
  is_voided?: boolean
  from_date?: string
  to_date?: string
  page?: number
  per_page?: number
}

interface PaginatedReceipts {
  data: ReceiptItem[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

export function ReceiptSearchPage() {
  const { t } = useTranslation(['pos', 'common'])
  const queryClient = useQueryClient()
  const { handlePrint } = useReceiptPrint()
  const [filters, setFilters] = useState<ReceiptSearchFilters>({ page: 1, per_page: 20 })
  const [searchInput, setSearchInput] = useState('')
  const [voidTarget, setVoidTarget] = useState<ReceiptItem | null>(null)
  const [voidReason, setVoidReason] = useState('')

  const { data: terminals = [] } = useQuery({
    queryKey: ['pos', 'terminals'],
    queryFn: () => apiGet<Terminal[]>('/pos/terminals'),
  })

  const { data, isLoading } = useQuery({
    queryKey: ['pos', 'receipts', filters],
    queryFn: () => {
      const params = new URLSearchParams()
      if (filters.terminal_id) params.set('terminal_id', filters.terminal_id)
      if (filters.receipt_number) params.set('receipt_number', filters.receipt_number)
      if (filters.is_voided !== undefined) params.set('is_voided', String(filters.is_voided))
      if (filters.from_date) params.set('from_date', filters.from_date)
      if (filters.to_date) params.set('to_date', filters.to_date)
      if (filters.page) params.set('page', String(filters.page))
      if (filters.per_page) params.set('per_page', String(filters.per_page))
      const query = params.toString()
      return apiGet<PaginatedReceipts>(`/pos/receipts${query ? `?${query}` : ''}`)
    },
  })

  const voidMutation = useMutation({
    mutationFn: (data: { id: string; reason: string }) =>
      apiPost(`/pos/receipts/${data.id}/void`, { reason: data.reason }),
    onSuccess: () => {
      toast.success(t('pos:receiptSearch.voidSuccess'))
      setVoidTarget(null)
      setVoidReason('')
      void queryClient.invalidateQueries({ queryKey: ['pos', 'receipts'] })
    },
    onError: () => {
      toast.error(t('pos:receiptSearch.voidError'))
    },
  })

  const receipts = data?.data ?? []
  const meta = data?.meta

  const handleSearch = () => {
    setFilters((prev) => ({ ...prev, receipt_number: searchInput || undefined, page: 1 }))
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
          <ReceiptIcon className="h-6 w-6 text-gray-400" />
          {t('pos:receiptSearch.title')}
        </h1>
        <p className="text-gray-500">{t('pos:receiptSearch.description')}</p>
      </div>

      {/* Filters */}
      <div className="flex flex-wrap gap-4 rounded-lg border border-gray-200 bg-white p-4">
        {/* Search */}
        <div className="flex-1 min-w-[200px]">
          <label className="block text-sm font-medium text-gray-700 mb-1">
            {t('pos:receiptSearch.receiptNumber')}
          </label>
          <div className="flex gap-2">
            <input
              type="text"
              className="flex-1 rounded-md border-gray-300 text-sm"
              placeholder={t('pos:receiptSearch.searchPlaceholder')}
              value={searchInput}
              onChange={(e) => setSearchInput(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter') handleSearch()
              }}
            />
            <button
              type="button"
              onClick={handleSearch}
              className="inline-flex items-center rounded-md bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700"
            >
              <Search className="h-4 w-4" />
            </button>
          </div>
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">
            {t('pos:receiptSearch.filters.terminal')}
          </label>
          <select
            className="rounded-md border-gray-300 text-sm"
            value={filters.terminal_id ?? ''}
            onChange={(e) =>
              setFilters((prev) => ({ ...prev, terminal_id: e.target.value || undefined, page: 1 }))
            }
          >
            <option value="">{t('pos:receiptSearch.filters.allTerminals')}</option>
            {terminals.map((term) => (
              <option key={term.id} value={term.id}>
                {term.name} ({term.code})
              </option>
            ))}
          </select>
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">
            {t('pos:receiptSearch.filters.status')}
          </label>
          <select
            className="rounded-md border-gray-300 text-sm"
            value={filters.is_voided === undefined ? '' : String(filters.is_voided)}
            onChange={(e) =>
              setFilters((prev) => ({
                ...prev,
                is_voided: e.target.value === '' ? undefined : e.target.value === 'true',
                page: 1,
              }))
            }
          >
            <option value="">{t('pos:receiptSearch.filters.allStatuses')}</option>
            <option value="false">{t('pos:receiptSearch.active')}</option>
            <option value="true">{t('pos:receiptSearch.voided')}</option>
          </select>
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">
            {t('pos:receiptSearch.filters.from')}
          </label>
          <input
            type="date"
            className="rounded-md border-gray-300 text-sm"
            value={filters.from_date ?? ''}
            onChange={(e) =>
              setFilters((prev) => ({ ...prev, from_date: e.target.value || undefined, page: 1 }))
            }
          />
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">
            {t('pos:receiptSearch.filters.to')}
          </label>
          <input
            type="date"
            className="rounded-md border-gray-300 text-sm"
            value={filters.to_date ?? ''}
            onChange={(e) =>
              setFilters((prev) => ({ ...prev, to_date: e.target.value || undefined, page: 1 }))
            }
          />
        </div>
      </div>

      {/* Table */}
      <div className="rounded-lg border border-gray-200 bg-white overflow-hidden">
        {isLoading ? (
          <div className="flex items-center justify-center py-12">
            <Loader2 className="h-8 w-8 animate-spin text-blue-600" />
          </div>
        ) : receipts.length === 0 ? (
          <div className="text-center py-12">
            <ReceiptIcon className="h-12 w-12 text-gray-300 mx-auto mb-3" />
            <p className="text-gray-500 font-medium">{t('pos:receiptSearch.noReceipts')}</p>
            <p className="text-gray-400 text-sm mt-1">{t('pos:receiptSearch.noReceiptsDescription')}</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase">{t('pos:receiptSearch.receiptNumber')}</th>
                  <th className="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase">{t('pos:receiptSearch.terminal')}</th>
                  <th className="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase">{t('pos:receiptSearch.cashier')}</th>
                  <th className="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase">{t('pos:receiptSearch.date')}</th>
                  <th className="px-4 py-3 text-end text-xs font-medium text-gray-500 uppercase">{t('pos:receiptSearch.total')}</th>
                  <th className="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase">{t('pos:receiptSearch.status')}</th>
                  <th className="px-4 py-3 text-end text-xs font-medium text-gray-500 uppercase">{t('common:table.actionsColumn')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200">
                {receipts.map((receipt) => (
                  <tr
                    key={receipt.id}
                    className={cn('hover:bg-gray-50', receipt.is_voided && 'opacity-60')}
                  >
                    <td className={cn(
                      'px-4 py-3 text-sm font-mono font-medium',
                      receipt.is_voided ? 'text-gray-400 line-through' : 'text-gray-900'
                    )}>
                      {receipt.receipt_number}
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-600">
                      {receipt.terminal_code}
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-600">
                      {receipt.cashier_name}
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-600">
                      {new Date(receipt.posted_at).toLocaleString()}
                    </td>
                    <td className="px-4 py-3 text-sm text-end font-mono text-gray-900">
                      {receipt.total} {receipt.currency}
                    </td>
                    <td className="px-4 py-3">
                      <span className={cn(
                        'inline-flex items-center rounded-full px-2 py-1 text-xs font-medium',
                        receipt.is_voided
                          ? 'bg-red-100 text-red-700'
                          : 'bg-green-100 text-green-700'
                      )}>
                        {receipt.is_voided ? t('pos:receiptSearch.voided') : t('pos:receiptSearch.active')}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-end">
                      <div className="flex items-center justify-end gap-2">
                        <button
                          type="button"
                          onClick={() => handlePrint(receipt.id)}
                          className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-blue-600 hover:bg-blue-50"
                          title={t('pos:receipt.print')}
                        >
                          <Printer className="h-3.5 w-3.5" />
                        </button>
                        {!receipt.is_voided && (
                          <button
                            type="button"
                            onClick={() => setVoidTarget(receipt)}
                            className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-red-600 hover:bg-red-50"
                            title={t('pos:receiptSearch.void')}
                          >
                            <Ban className="h-3.5 w-3.5" />
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {/* Pagination */}
        {meta && meta.last_page > 1 && (
          <div className="flex items-center justify-between border-t border-gray-200 px-4 py-3 bg-gray-50">
            <p className="text-sm text-gray-500">
              {t('common:pagination.showing', {
                from: (meta.current_page - 1) * meta.per_page + 1,
                to: Math.min(meta.current_page * meta.per_page, meta.total),
                total: meta.total,
              })}
            </p>
            <div className="flex gap-2">
              <button
                type="button"
                disabled={meta.current_page <= 1}
                onClick={() => setFilters((prev) => ({ ...prev, page: (prev.page ?? 1) - 1 }))}
                className="rounded-md border border-gray-300 bg-white px-3 py-1 text-sm disabled:opacity-50"
              >
                {t('common:pagination.previous')}
              </button>
              <button
                type="button"
                disabled={meta.current_page >= meta.last_page}
                onClick={() => setFilters((prev) => ({ ...prev, page: (prev.page ?? 1) + 1 }))}
                className="rounded-md border border-gray-300 bg-white px-3 py-1 text-sm disabled:opacity-50"
              >
                {t('common:pagination.next')}
              </button>
            </div>
          </div>
        )}
      </div>

      {/* Void Confirmation Modal */}
      {voidTarget && (
        <Modal
          isOpen={!!voidTarget}
          onClose={() => {
            setVoidTarget(null)
            setVoidReason('')
          }}
          title={t('pos:receiptSearch.voidReceipt')}
          size="md"
        >
          <ModalContent>
            <div className="space-y-4">
              <p className="text-sm text-gray-600">
                {t('pos:receiptSearch.voidConfirm')}
              </p>
              <p className="text-sm font-mono font-medium text-gray-900">
                {voidTarget.receipt_number} — {voidTarget.total} {voidTarget.currency}
              </p>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">
                  {t('pos:receiptSearch.voidReason')}
                </label>
                <input
                  type="text"
                  className="w-full rounded-md border-gray-300 text-sm"
                  placeholder={t('pos:receiptSearch.voidReasonPlaceholder')}
                  value={voidReason}
                  onChange={(e) => setVoidReason(e.target.value)}
                />
              </div>
            </div>
          </ModalContent>
          <ModalFooter>
            <button
              type="button"
              onClick={() => {
                setVoidTarget(null)
                setVoidReason('')
              }}
              className="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
            >
              {t('common:actions.cancel')}
            </button>
            <button
              type="button"
              onClick={() =>
                voidMutation.mutate({ id: voidTarget.id, reason: voidReason })
              }
              disabled={!voidReason.trim() || voidMutation.isPending}
              className="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50"
            >
              {voidMutation.isPending ? t('common:status.processing') : t('pos:receiptSearch.void')}
            </button>
          </ModalFooter>
        </Modal>
      )}
    </div>
  )
}
