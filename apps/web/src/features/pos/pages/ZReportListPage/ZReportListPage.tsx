import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery, useMutation } from '@tanstack/react-query'
import { apiGet } from '@/lib/api'
import { fetchZReports, verifyZReportChain, type ZReportListFilters } from '../../api/reportApi'
import { FileCheck, Loader2, ShieldCheck, ShieldAlert } from 'lucide-react'
import { toast } from 'sonner'

interface Terminal {
  id: string
  code: string
  name: string
}

export function ZReportListPage() {
  const { t } = useTranslation(['pos', 'common'])
  const [filters, setFilters] = useState<ZReportListFilters>({ page: 1, per_page: 20 })

  const { data: terminals = [] } = useQuery({
    queryKey: ['pos', 'terminals'],
    queryFn: () => apiGet<Terminal[]>('/pos/terminals'),
  })

  const { data, isLoading } = useQuery({
    queryKey: ['pos', 'z-reports', filters],
    queryFn: () => fetchZReports(filters),
    enabled: !!filters.terminal_id,
  })

  const verifyChainMutation = useMutation({
    mutationFn: (terminalId: string) => verifyZReportChain(terminalId),
    onSuccess: (result) => {
      if (result.valid) {
        toast.success(t('pos:zReports.chainValid'))
      } else {
        toast.error(t('pos:zReports.chainInvalid'))
      }
    },
    onError: () => {
      toast.error(t('common:error.generic'))
    },
  })

  const reports = data?.data ?? []
  const meta = data?.meta

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
            <FileCheck className="h-6 w-6 text-gray-400" />
            {t('pos:zReports.title')}
          </h1>
          <p className="text-gray-500">{t('pos:zReports.description')}</p>
        </div>

        {filters.terminal_id && (
          <button
            type="button"
            onClick={() => verifyChainMutation.mutate(filters.terminal_id!)}
            disabled={verifyChainMutation.isPending}
            className="inline-flex items-center gap-2 rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
          >
            {verifyChainMutation.isPending ? (
              <Loader2 className="h-4 w-4 animate-spin" />
            ) : (
              <ShieldCheck className="h-4 w-4" />
            )}
            {verifyChainMutation.isPending ? t('pos:zReports.verifying') : t('pos:zReports.verifyChain')}
          </button>
        )}
      </div>

      {/* Terminal Selector */}
      <div className="rounded-lg border border-gray-200 bg-white p-4">
        <label className="block text-sm font-medium text-gray-700 mb-1">
          {t('pos:zReports.selectTerminal')}
        </label>
        <select
          className="rounded-md border-gray-300 text-sm w-full max-w-xs"
          value={filters.terminal_id ?? ''}
          onChange={(e) =>
            setFilters({
              terminal_id: e.target.value || undefined,
              page: 1,
              per_page: 20,
            })
          }
        >
          <option value="">— {t('pos:shiftHistory.filters.allTerminals')} —</option>
          {terminals.map((term) => (
            <option key={term.id} value={term.id}>
              {term.name} ({term.code})
            </option>
          ))}
        </select>
      </div>

      {/* Table */}
      {!filters.terminal_id ? (
        <div className="rounded-lg border border-gray-200 bg-white text-center py-12">
          <FileCheck className="h-12 w-12 text-gray-300 mx-auto mb-3" />
          <p className="text-gray-500">{t('pos:zReports.selectTerminal')}</p>
        </div>
      ) : (
        <div className="rounded-lg border border-gray-200 bg-white overflow-hidden">
          {isLoading ? (
            <div className="flex items-center justify-center py-12">
              <Loader2 className="h-8 w-8 animate-spin text-blue-600" />
            </div>
          ) : reports.length === 0 ? (
            <div className="text-center py-12">
              <FileCheck className="h-12 w-12 text-gray-300 mx-auto mb-3" />
              <p className="text-gray-500 font-medium">{t('pos:zReports.noReports')}</p>
              <p className="text-gray-400 text-sm mt-1">{t('pos:zReports.noReportsDescription')}</p>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase">{t('pos:zReports.zNumber')}</th>
                    <th className="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase">{t('pos:zReports.date')}</th>
                    <th className="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase">{t('pos:zReports.generatedBy')}</th>
                    <th className="px-4 py-3 text-end text-xs font-medium text-gray-500 uppercase">{t('pos:zReports.totalSales')}</th>
                    <th className="px-4 py-3 text-end text-xs font-medium text-gray-500 uppercase">{t('pos:zReports.taxCollected')}</th>
                    <th className="px-4 py-3 text-end text-xs font-medium text-gray-500 uppercase">{t('pos:zReports.receiptCount')}</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200">
                  {reports.map((report) => (
                    <tr key={report.id} className="hover:bg-gray-50">
                      <td className="px-4 py-3 text-sm font-mono font-medium text-gray-900">
                        Z-{report.z_number}
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-600">
                        {new Date(report.generated_at).toLocaleString()}
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-600">
                        {report.generated_by}
                      </td>
                      <td className="px-4 py-3 text-sm text-end font-mono text-gray-900">
                        {report.total_sales}
                      </td>
                      <td className="px-4 py-3 text-sm text-end font-mono text-gray-600">
                        {report.total_tax}
                      </td>
                      <td className="px-4 py-3 text-sm text-end font-mono text-gray-600">
                        {report.receipt_count}
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
      )}
    </div>
  )
}
