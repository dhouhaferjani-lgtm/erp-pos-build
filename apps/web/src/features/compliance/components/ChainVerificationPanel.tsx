import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useCompanyStore } from '../../../stores/companyStore'
import { verifyChains, type ChainVerificationResult } from '../api/complianceApi'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

function StatusBadge({ isValid }: { isValid: boolean }) {
  const { t } = useTranslation('compliance')
  return (
    <span
      className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${
        isValid
          ? `${colorTokens.intent.success.bgSoft} ${colorTokens.intent.success.textStronger}`
          : `${colorTokens.intent.danger.bgSoft} ${colorTokens.intent.danger.textStronger}`
      }`}
    >
      {isValid ? t('chainVerification.valid') : t('chainVerification.broken')}
    </span>
  )
}

export function ChainVerificationPanel() {
  const { t } = useTranslation('compliance')
  const currentCompanyId = useCompanyStore((state) => state.currentCompanyId)
  const [loading, setLoading] = useState(false)
  const [results, setResults] = useState<ChainVerificationResult[] | null>(null)
  const [error, setError] = useState<string | null>(null)

  const handleVerify = async () => {
    if (!currentCompanyId) return
    setLoading(true)
    setError(null)
    try {
      const data = await verifyChains(currentCompanyId)
      setResults(data)
    } catch {
      setError(t('exportError'))
    } finally {
      setLoading(false)
    }
  }

  const allValid = results !== null && results.every(
    (r) => r.receipt_chain.is_valid && r.z_report_chain.is_valid
  )
  const hasBroken = results !== null && results.some(
    (r) => !r.receipt_chain.is_valid || !r.z_report_chain.is_valid
  )

  return (
    <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-6`}>
      <div className="flex items-center justify-between mb-4">
        <div>
          <h3 className={`text-lg font-semibold ${colorTokens.text.primary}`}>
            {t('chainVerification.title')}
          </h3>
          <p className={`text-sm ${colorTokens.text.muted} mt-1`}>
            {t('chainVerification.description')}
          </p>
        </div>
        <button
          type="button"
          onClick={() => { void handleVerify() }}
          disabled={loading}
          className={`inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm ${colorTokens.text.inverse} ${colorTokens.intent.primary.bgStrong} ${colorTokens.intent.primary.bgStrongHover} disabled:opacity-50 disabled:cursor-not-allowed`}
        >
          {loading ? t('chainVerification.verifying') : t('chainVerification.verify')}
        </button>
      </div>

      {error && <p className={`mt-2 text-sm ${colorTokens.intent.danger.text}`}>{error}</p>}

      {results !== null && results.length === 0 && (
        <p className={`text-sm ${colorTokens.text.subtle}`}>{t('chainVerification.noTerminals')}</p>
      )}

      {results !== null && results.length > 0 && (
        <>
          {allValid && (
            <div className={`mb-4 rounded-md ${colorTokens.intent.success.bgSubtle} p-3`}>
              <p className={`text-sm font-medium ${colorTokens.intent.success.textStronger}`}>
                {t('chainVerification.allValid')}
              </p>
            </div>
          )}
          {hasBroken && (
            <div className={`mb-4 rounded-md ${colorTokens.intent.danger.bgSubtle} p-3`}>
              <p className={`text-sm font-medium ${colorTokens.intent.danger.textStronger}`}>
                {t('chainVerification.hasBroken')}
              </p>
            </div>
          )}

          <div className="overflow-x-auto">
            <DataTable className={`min-w-full divide-y ${colorTokens.border.divider}`}>
              <thead className={`${colorTokens.surface.page}`}>
                <tr>
                  <th className={`px-4 py-3 text-start text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                    {t('chainVerification.terminal')}
                  </th>
                  <th className={`px-4 py-3 text-start text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                    {t('chainVerification.receiptChain')}
                  </th>
                  <th className={`px-4 py-3 text-start text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                    {t('chainVerification.chainLength')}
                  </th>
                  <th className={`px-4 py-3 text-start text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                    {t('chainVerification.zReportChain')}
                  </th>
                  <th className={`px-4 py-3 text-start text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                    {t('chainVerification.chainLength')}
                  </th>
                </tr>
              </thead>
              <tbody className={`${colorTokens.surface.base} divide-y ${colorTokens.border.divider}`}>
                {results.map((result) => (
                  <tr key={result.terminal_id}>
                    <td className={`px-4 py-3 whitespace-nowrap text-sm font-medium ${colorTokens.text.primary}`}>
                      {result.terminal_code}
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap text-sm">
                      <StatusBadge isValid={result.receipt_chain.is_valid} />
                      {!result.receipt_chain.is_valid && result.receipt_chain.broken_at_sequence !== null && (
                        <span className={`ms-2 text-xs ${colorTokens.intent.danger.text}`}>
                          {t('chainVerification.brokenAt')} #{result.receipt_chain.broken_at_sequence}
                        </span>
                      )}
                    </td>
                    <td className={`px-4 py-3 whitespace-nowrap text-sm ${colorTokens.text.subtle}`}>
                      {result.receipt_chain.chain_length}
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap text-sm">
                      <StatusBadge isValid={result.z_report_chain.is_valid} />
                      {!result.z_report_chain.is_valid && result.z_report_chain.broken_at_z_number !== null && (
                        <span className={`ms-2 text-xs ${colorTokens.intent.danger.text}`}>
                          {t('chainVerification.brokenAt')} Z-{result.z_report_chain.broken_at_z_number}
                        </span>
                      )}
                    </td>
                    <td className={`px-4 py-3 whitespace-nowrap text-sm ${colorTokens.text.subtle}`}>
                      {result.z_report_chain.chain_length}
                    </td>
                  </tr>
                ))}
              </tbody>
            </DataTable>
          </div>
        </>
      )}
    </div>
  )
}
