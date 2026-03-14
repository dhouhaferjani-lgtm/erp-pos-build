import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useCompanyStore } from '../../../stores/companyStore'
import { verifyChains, type ChainVerificationResult } from '../api/complianceApi'

function StatusBadge({ isValid }: { isValid: boolean }) {
  const { t } = useTranslation('compliance')
  return (
    <span
      className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${
        isValid
          ? 'bg-green-100 text-green-800'
          : 'bg-red-100 text-red-800'
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
    <div className="rounded-lg border border-gray-200 bg-white p-6">
      <div className="flex items-center justify-between mb-4">
        <div>
          <h3 className="text-lg font-semibold text-gray-900">
            {t('chainVerification.title')}
          </h3>
          <p className="text-sm text-gray-600 mt-1">
            {t('chainVerification.description')}
          </p>
        </div>
        <button
          type="button"
          onClick={() => { void handleVerify() }}
          disabled={loading}
          className="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {loading ? t('chainVerification.verifying') : t('chainVerification.verify')}
        </button>
      </div>

      {error && <p className="mt-2 text-sm text-red-600">{error}</p>}

      {results !== null && results.length === 0 && (
        <p className="text-sm text-gray-500">{t('chainVerification.noTerminals')}</p>
      )}

      {results !== null && results.length > 0 && (
        <>
          {allValid && (
            <div className="mb-4 rounded-md bg-green-50 p-3">
              <p className="text-sm font-medium text-green-800">
                {t('chainVerification.allValid')}
              </p>
            </div>
          )}
          {hasBroken && (
            <div className="mb-4 rounded-md bg-red-50 p-3">
              <p className="text-sm font-medium text-red-800">
                {t('chainVerification.hasBroken')}
              </p>
            </div>
          )}

          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t('chainVerification.terminal')}
                  </th>
                  <th className="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t('chainVerification.receiptChain')}
                  </th>
                  <th className="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t('chainVerification.chainLength')}
                  </th>
                  <th className="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t('chainVerification.zReportChain')}
                  </th>
                  <th className="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t('chainVerification.chainLength')}
                  </th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {results.map((result) => (
                  <tr key={result.terminal_id}>
                    <td className="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900">
                      {result.terminal_code}
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap text-sm">
                      <StatusBadge isValid={result.receipt_chain.is_valid} />
                      {!result.receipt_chain.is_valid && result.receipt_chain.broken_at_sequence !== null && (
                        <span className="ms-2 text-xs text-red-600">
                          {t('chainVerification.brokenAt')} #{result.receipt_chain.broken_at_sequence}
                        </span>
                      )}
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                      {result.receipt_chain.chain_length}
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap text-sm">
                      <StatusBadge isValid={result.z_report_chain.is_valid} />
                      {!result.z_report_chain.is_valid && result.z_report_chain.broken_at_z_number !== null && (
                        <span className="ms-2 text-xs text-red-600">
                          {t('chainVerification.brokenAt')} Z-{result.z_report_chain.broken_at_z_number}
                        </span>
                      )}
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                      {result.z_report_chain.chain_length}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </div>
  )
}
