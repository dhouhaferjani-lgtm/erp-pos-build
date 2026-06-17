import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery, useMutation } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { apiGet } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { fetchZReports, verifyZReportChain, type ZReportListFilters } from '../../api/reportApi'
import { FileCheck, Loader2, ShieldCheck, ShieldAlert, ChevronRight, Calendar } from 'lucide-react'
import { toast } from 'sonner'
import { tokens, textColors, borderColors } from '@/lib/designTokens'
import { POSButton } from '../../atoms/POSButton'
import { usePosTenantScope } from '../../hooks/usePosTenantScope'

interface Terminal {
  id: string
  code: string
  name: string
}

export function ZReportListPage() {
  const { t } = useTranslation(['pos', 'common'])
  const navigate = useNavigate()
  const [filters, setFilters] = useState<ZReportListFilters>({ page: 1, per_page: 20 })
  const { hasTenantScope } = usePosTenantScope()

  const { data: terminals = [] } = useQuery({
    queryKey: tenantScopedKey(['pos', 'terminals']),
    queryFn: () => apiGet<Terminal[]>('/pos/terminals'),
    enabled: hasTenantScope,
  })

  const { data, isLoading } = useQuery({
    queryKey: tenantScopedKey(['pos', 'z-reports', filters]),
    queryFn: () => fetchZReports(filters),
    enabled: !!filters.terminal_id && hasTenantScope,
  })

  const verifyChainMutation = useMutation({
    mutationFn: (terminalId: string) => verifyZReportChain(terminalId),
    onSuccess: (result) => {
      if (result.is_valid) {
        toast.success(t('pos:zReports.chainValid'))
      } else {
        toast.error(
          result.broken_at_z_number
            ? t('pos:zReports.chainBrokenAt', { zNumber: result.broken_at_z_number })
            : t('pos:zReports.chainInvalid')
        )
      }
    },
    onError: () => {
      toast.error(t('common:errorMessages.generic'))
    },
  })

  const reports = data?.data ?? []
  const meta = data?.meta

  const handleRowClick = (report: (typeof reports)[0]) => {
    if (filters.terminal_id) {
      navigate(`/pos/z-reports/${String(report.z_number)}?terminal_id=${filters.terminal_id}`)
    }
  }

  const updateFilter = (key: 'from_date' | 'to_date', value: string) => {
    setFilters((prev) => {
      const next: ZReportListFilters = { page: 1, per_page: prev.per_page ?? 20 }
      if (prev.terminal_id) next.terminal_id = prev.terminal_id
      if (key === 'from_date') {
        if (value) next.from_date = value
        if (prev.to_date) next.to_date = prev.to_date
      } else {
        if (prev.from_date) next.from_date = prev.from_date
        if (value) next.to_date = value
      }
      return next
    })
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className={`text-2xl font-bold ${textColors.primary} flex items-center gap-2`}>
            <FileCheck className={`h-6 w-6 ${textColors.disabled}`} />
            {t('pos:zReports.title')}
          </h1>
          <p className={textColors.tertiary}>{t('pos:zReports.description')}</p>
        </div>

        {filters.terminal_id && (
          <POSButton
            onClick={() => { verifyChainMutation.mutate(filters.terminal_id!); }}
            disabled={verifyChainMutation.isPending}
            size="sm"
            icon={
              verifyChainMutation.isPending ? (
                <Loader2 className="h-4 w-4 animate-spin" />
              ) : verifyChainMutation.isSuccess && !verifyChainMutation.data.is_valid ? (
                <ShieldAlert className="h-4 w-4" />
              ) : (
                <ShieldCheck className="h-4 w-4" />
              )
            }
          >
            {verifyChainMutation.isPending ? t('pos:zReports.verifying') : t('pos:zReports.verifyChain')}
          </POSButton>
        )}
      </div>

      {/* Chain verification result banner */}
      {verifyChainMutation.isSuccess && (
        <div
          className={`rounded-lg p-4 flex items-center gap-3 ${
            verifyChainMutation.data.is_valid ? tokens.alert.success : tokens.alert.error
          }`}
        >
          {verifyChainMutation.data.is_valid ? (
            <>
              <ShieldCheck className={`h-5 w-5 ${textColors.success} shrink-0`} />
              <p className="text-sm">{t('pos:zReports.chainValid')}</p>
            </>
          ) : (
            <>
              <ShieldAlert className={`h-5 w-5 ${textColors.error} shrink-0`} />
              <p className="text-sm">
                {verifyChainMutation.data.broken_at_z_number
                  ? t('pos:zReports.chainBrokenAt', { zNumber: verifyChainMutation.data.broken_at_z_number })
                  : t('pos:zReports.chainInvalid')}
              </p>
            </>
          )}
        </div>
      )}

      {/* Filters */}
      <div className={`rounded-lg border ${borderColors.light} bg-white p-4`}>
        <div className="flex flex-wrap gap-4 items-end">
          {/* Terminal selector */}
          <div className="min-w-[200px]">
            <label className={`block text-sm font-medium ${textColors.secondary} mb-1`}>
              {t('pos:zReports.terminal')}
            </label>
            <select
              className={`rounded-md ${borderColors.default} text-sm w-full`}
              value={filters.terminal_id ?? ''}
              onChange={(e) => {
                const value = e.target.value
                setFilters((prev) => {
                  const next: ZReportListFilters = { page: 1, per_page: 20 }
                  if (value) next.terminal_id = value
                  if (prev.from_date) next.from_date = prev.from_date
                  if (prev.to_date) next.to_date = prev.to_date
                  return next
                })
                verifyChainMutation.reset()
              }}
            >
              <option value="">-- {t('pos:shiftHistory.filters.allTerminals')} --</option>
              {terminals.map((term) => (
                <option key={term.id} value={term.id}>
                  {term.name} ({term.code})
                </option>
              ))}
            </select>
          </div>

          {/* Date range */}
          <div className="min-w-[160px]">
            <label className={`block text-sm font-medium ${textColors.secondary} mb-1`}>
              <span className="flex items-center gap-1">
                <Calendar className="h-3.5 w-3.5" />
                {t('pos:zReports.filters.from')}
              </span>
            </label>
            <input
              type="date"
              className={`rounded-md ${borderColors.default} text-sm w-full`}
              value={filters.from_date ?? ''}
              onChange={(e) => { updateFilter('from_date', e.target.value); }}
            />
          </div>
          <div className="min-w-[160px]">
            <label className={`block text-sm font-medium ${textColors.secondary} mb-1`}>
              <span className="flex items-center gap-1">
                <Calendar className="h-3.5 w-3.5" />
                {t('pos:zReports.filters.to')}
              </span>
            </label>
            <input
              type="date"
              className={`rounded-md ${borderColors.default} text-sm w-full`}
              value={filters.to_date ?? ''}
              onChange={(e) => { updateFilter('to_date', e.target.value); }}
            />
          </div>
        </div>
      </div>

      {/* Table */}
      {!filters.terminal_id ? (
        <div className={`rounded-lg border ${borderColors.light} bg-white text-center py-12`}>
          <FileCheck className={`h-12 w-12 ${textColors.disabled} mx-auto mb-3`} />
          <p className={textColors.tertiary}>{t('pos:zReports.selectTerminal')}</p>
        </div>
      ) : (
        <div className={`rounded-lg border ${borderColors.light} bg-white overflow-hidden`}>
          {isLoading ? (
            <div className="flex items-center justify-center py-12">
              <Loader2 className={`h-8 w-8 animate-spin ${textColors.brand}`} />
            </div>
          ) : reports.length === 0 ? (
            <div className="text-center py-12">
              <FileCheck className={`h-12 w-12 ${textColors.disabled} mx-auto mb-3`} />
              <p className={`${textColors.tertiary} font-medium`}>{t('pos:zReports.noReports')}</p>
              <p className={`${textColors.disabled} text-sm mt-1`}>{t('pos:zReports.noReportsDescription')}</p>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className={`min-w-full divide-y ${borderColors.divideDefault}`}>
                <thead className={tokens.table.header}>
                  <tr>
                    <th className={`px-4 py-3 text-start text-xs font-medium ${textColors.tertiary} uppercase`}>{t('pos:zReports.zNumber')}</th>
                    <th className={`px-4 py-3 text-start text-xs font-medium ${textColors.tertiary} uppercase`}>{t('pos:zReports.date')}</th>
                    <th className={`px-4 py-3 text-start text-xs font-medium ${textColors.tertiary} uppercase`}>{t('pos:zReports.generatedBy')}</th>
                    <th className={`px-4 py-3 text-end text-xs font-medium ${textColors.tertiary} uppercase`}>{t('pos:zReports.grossSales')}</th>
                    <th className={`px-4 py-3 text-end text-xs font-medium ${textColors.tertiary} uppercase`}>{t('pos:zReports.netSales')}</th>
                    <th className={`px-4 py-3 text-end text-xs font-medium ${textColors.tertiary} uppercase`}>{t('pos:zReports.taxCollected')}</th>
                    <th className={`px-4 py-3 text-end text-xs font-medium ${textColors.tertiary} uppercase`}>{t('pos:zReports.receiptCount')}</th>
                    <th className={`px-4 py-3 text-end text-xs font-medium ${textColors.tertiary} uppercase`}>{t('pos:zReports.variance')}</th>
                    <th className="px-4 py-3 w-8"></th>
                  </tr>
                </thead>
                <tbody className={`divide-y ${borderColors.divideDefault}`}>
                  {reports.map((report) => (
                    <tr
                      key={report.id}
                      onClick={() => { handleRowClick(report); }}
                      className={`${tokens.table.rowHover} cursor-pointer`}
                    >
                      <td className={`px-4 py-3 text-sm font-mono font-medium ${textColors.primary}`}>
                        {report.formatted_z_number}
                      </td>
                      <td className={`px-4 py-3 text-sm ${textColors.tertiary}`}>
                        {new Date(report.generated_at).toLocaleString()}
                      </td>
                      <td className={`px-4 py-3 text-sm ${textColors.tertiary}`}>
                        {report.generated_by_user?.name ?? report.generated_by}
                      </td>
                      <td className={`px-4 py-3 text-sm text-end font-mono tabular-nums ${textColors.primary}`}>
                        {report.gross_sales}
                      </td>
                      <td className={`px-4 py-3 text-sm text-end font-mono tabular-nums ${textColors.tertiary}`}>
                        {report.report_data?.net_sales ?? '--'}
                      </td>
                      <td className={`px-4 py-3 text-sm text-end font-mono tabular-nums ${textColors.tertiary}`}>
                        {report.report_data?.tax_amount ?? '--'}
                      </td>
                      <td className={`px-4 py-3 text-sm text-end font-mono tabular-nums ${textColors.tertiary}`}>
                        {report.sales_count}
                      </td>
                      <td className="px-4 py-3 text-sm text-end font-mono tabular-nums">
                        <span
                          className={
                            report.has_variance
                              ? parseFloat(report.variance) < 0
                                ? textColors.error
                                : textColors.brand
                              : textColors.success
                          }
                        >
                          {report.variance}
                        </span>
                      </td>
                      <td className="px-4 py-3">
                        <ChevronRight className={`h-4 w-4 ${textColors.disabled}`} />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {/* Pagination */}
          {meta && meta.last_page > 1 && (
            <div className={`flex items-center justify-between border-t ${borderColors.light} px-4 py-3 ${tokens.table.header}`}>
              <p className={`text-sm ${textColors.tertiary}`}>
                {t('common:pagination.showing', {
                  from: (meta.current_page - 1) * meta.per_page + 1,
                  to: Math.min(meta.current_page * meta.per_page, meta.total),
                  total: meta.total,
                })}
              </p>
              <div className="flex gap-2">
                <POSButton
                  variant="secondary"
                  size="sm"
                  disabled={meta.current_page <= 1}
                  onClick={() => { setFilters((prev) => ({ ...prev, page: (prev.page ?? 1) - 1 })); }}
                >
                  {t('common:pagination.previous')}
                </POSButton>
                <POSButton
                  variant="secondary"
                  size="sm"
                  disabled={meta.current_page >= meta.last_page}
                  onClick={() => { setFilters((prev) => ({ ...prev, page: (prev.page ?? 1) + 1 })); }}
                >
                  {t('common:pagination.next')}
                </POSButton>
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  )
}
