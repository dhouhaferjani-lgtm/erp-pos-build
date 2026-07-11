import { useState, useEffect, useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { useCompanyStore } from '../../../stores/companyStore'
import { getReprintLog, type ReprintLogEntry, type ReprintLogResponse } from '../api/complianceApi'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

function PrintTypeBadge({ printType }: { printType: ReprintLogEntry['print_type'] }) {
  const { t } = useTranslation('compliance')

  const colorMap: Record<ReprintLogEntry['print_type'], string> = {
    original: `${colorTokens.intent.success.bgSoft} ${colorTokens.intent.success.textStronger}`,
    duplicate: `${colorTokens.intent.warning.bgSoft} ${colorTokens.intent.warning.textStronger}`,
    reprint: `${colorTokens.intent.primary.bgSoft} ${colorTokens.intent.primary.textStronger}`,
  }

  const labelMap: Record<ReprintLogEntry['print_type'], string> = {
    original: t('reprintLog.original'),
    duplicate: t('reprintLog.duplicate'),
    reprint: t('reprintLog.reprint'),
  }

  return (
    <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${colorMap[printType]}`}>
      {labelMap[printType]}
    </span>
  )
}

function PrintMethodBadge({ method }: { method: ReprintLogEntry['print_method'] }) {
  const { t } = useTranslation('compliance')

  const labelMap: Record<ReprintLogEntry['print_method'], string> = {
    pdf: t('reprintLog.pdf'),
    thermal: t('reprintLog.thermal'),
    escpos: t('reprintLog.escpos'),
  }

  return (
    <span className={`inline-flex items-center rounded-full ${colorTokens.surface.muted} px-2.5 py-0.5 text-xs font-medium ${colorTokens.text.strong}`}>
      {labelMap[method]}
    </span>
  )
}

export function ReprintLogTable() {
  const { t } = useTranslation('compliance')
  const currentCompanyId = useCompanyStore((state) => state.currentCompanyId)
  const [data, setData] = useState<ReprintLogResponse | null>(null)
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const fetchLog = useCallback(async (pageNum: number) => {
    if (!currentCompanyId) return
    setLoading(true)
    setError(null)
    try {
      const result = await getReprintLog({
        company_id: currentCompanyId,
        page: pageNum,
        per_page: 20,
      })
      setData(result)
    } catch {
      setError(t('exportError'))
    } finally {
      setLoading(false)
    }
  }, [currentCompanyId, t])

  useEffect(() => {
    void fetchLog(page)
  }, [page, fetchLog])

  const handlePrev = () => {
    if (page > 1) setPage((p) => p - 1)
  }

  const handleNext = () => {
    if (data && page < data.meta.last_page) setPage((p) => p + 1)
  }

  return (
    <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-6`}>
      <h3 className={`text-lg font-semibold ${colorTokens.text.primary} mb-2`}>
        {t('reprintLog.title')}
      </h3>
      <p className={`text-sm ${colorTokens.text.muted} mb-4`}>
        {t('reprintLog.description')}
      </p>

      {error && <p className={`mb-4 text-sm ${colorTokens.intent.danger.text}`}>{error}</p>}

      {loading && !data && (
        <div className="flex justify-center py-8">
          <div className={`h-8 w-8 animate-spin rounded-full border-4 ${colorTokens.intent.primary.borderStrong} border-t-transparent`} />
        </div>
      )}

      {data && data.data.length === 0 && (
        <p className={`text-sm ${colorTokens.text.subtle} py-4`}>{t('reprintLog.noRecords')}</p>
      )}

      {data && data.data.length > 0 && (
        <>
          <div className="overflow-x-auto">
            <DataTable className={`min-w-full divide-y ${colorTokens.border.divider}`}>
              <thead className={colorTokens.surface.page}>
                <tr>
                  <th className={`px-4 py-3 text-start text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                    {t('reprintLog.receiptNumber')}
                  </th>
                  <th className={`px-4 py-3 text-start text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                    {t('reprintLog.terminal')}
                  </th>
                  <th className={`px-4 py-3 text-start text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                    {t('reprintLog.user')}
                  </th>
                  <th className={`px-4 py-3 text-start text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                    {t('reprintLog.printType')}
                  </th>
                  <th className={`px-4 py-3 text-start text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                    {t('reprintLog.copyNumber')}
                  </th>
                  <th className={`px-4 py-3 text-start text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                    {t('reprintLog.method')}
                  </th>
                  <th className={`px-4 py-3 text-start text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                    {t('reprintLog.printedAt')}
                  </th>
                </tr>
              </thead>
              <tbody className={`${colorTokens.surface.base} divide-y ${colorTokens.border.divider}`}>
                {data.data.map((entry) => (
                  <tr key={entry.id}>
                    <td className={`px-4 py-3 whitespace-nowrap text-sm font-medium ${colorTokens.text.primary}`}>
                      {entry.receipt_number}
                    </td>
                    <td className={`px-4 py-3 whitespace-nowrap text-sm ${colorTokens.text.subtle}`}>
                      {entry.terminal_code}
                    </td>
                    <td className={`px-4 py-3 whitespace-nowrap text-sm ${colorTokens.text.subtle}`}>
                      {entry.user_name}
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap text-sm">
                      <PrintTypeBadge printType={entry.print_type} />
                    </td>
                    <td className={`px-4 py-3 whitespace-nowrap text-sm ${colorTokens.text.subtle}`}>
                      {entry.copy_number}
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap text-sm">
                      <PrintMethodBadge method={entry.print_method} />
                    </td>
                    <td className={`px-4 py-3 whitespace-nowrap text-sm ${colorTokens.text.subtle}`}>
                      {new Date(entry.printed_at).toLocaleString()}
                    </td>
                  </tr>
                ))}
              </tbody>
            </DataTable>
          </div>

          {/* Pagination */}
          <div className="mt-4 flex items-center justify-between">
            <p className={`text-sm ${colorTokens.text.subtle}`}>
              {t('reprintLog.receiptNumber')} {((page - 1) * data.meta.per_page) + 1}
              {' - '}
              {Math.min(page * data.meta.per_page, data.meta.total)}
              {' / '}
              {data.meta.total}
            </p>
            <div className="flex gap-2">
              <button
                type="button"
                onClick={handlePrev}
                disabled={page <= 1 || loading}
                className={`inline-flex items-center px-3 py-1.5 border ${colorTokens.border.default} text-sm font-medium rounded-md ${colorTokens.text.secondary} ${colorTokens.surface.base} ${colorTokens.intent.neutral.bgHover} disabled:opacity-50 disabled:cursor-not-allowed`}
              >
                &laquo;
              </button>
              <span className={`inline-flex items-center px-3 py-1.5 text-sm ${colorTokens.text.secondary}`}>
                {page} / {data.meta.last_page}
              </span>
              <button
                type="button"
                onClick={handleNext}
                disabled={page >= data.meta.last_page || loading}
                className={`inline-flex items-center px-3 py-1.5 border ${colorTokens.border.default} text-sm font-medium rounded-md ${colorTokens.text.secondary} ${colorTokens.surface.base} ${colorTokens.intent.neutral.bgHover} disabled:opacity-50 disabled:cursor-not-allowed`}
              >
                &raquo;
              </button>
            </div>
          </div>
        </>
      )}
    </div>
  )
}
