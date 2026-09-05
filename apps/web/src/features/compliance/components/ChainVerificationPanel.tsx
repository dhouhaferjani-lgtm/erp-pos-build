import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useCompanyStore } from '../../../stores/companyStore'
import { formatDateTime } from '../../../lib/format'
import { verifyChains, type ChainVerificationResponse } from '../api/complianceApi'
import { resolveChainDiagnosticKey } from '../lib/chainDiagnostics'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

type BadgeTone = 'valid' | 'broken' | 'notCovered'

const TONE_CLASSES: Record<BadgeTone, string> = {
  valid: `${colorTokens.intent.success.bgSoft} ${colorTokens.intent.success.textStronger}`,
  broken: `${colorTokens.intent.danger.bgSoft} ${colorTokens.intent.danger.textStronger}`,
  notCovered: `${colorTokens.intent.caution.bgSoft} ${colorTokens.intent.caution.textStronger}`,
}

const TONE_LABEL_KEYS: Record<BadgeTone, string> = {
  valid: 'chainVerification.valid',
  broken: 'chainVerification.broken',
  notCovered: 'chainVerification.notCovered',
}

function StatusBadge({ tone }: { tone: BadgeTone }) {
  const { t } = useTranslation('compliance')

  return (
    <span
      className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${TONE_CLASSES[tone]}`}
    >
      {t(TONE_LABEL_KEYS[tone])}
    </span>
  )
}

/**
 * Renders a backend chain diagnostic.
 *
 * Known diagnostics are shown as translated operator copy; anything this build
 * does not recognise is shown as an explicitly labelled monospace TECHNICAL
 * DETAIL rather than passed off as operator copy (CLAUDE.md rule 11 — the
 * backend strings are hardcoded English developer prose).
 */
function ChainDiagnostic({ error }: { error: string }) {
  const { t } = useTranslation('compliance')
  const key = resolveChainDiagnosticKey(error)

  if (key !== null) {
    return <span className={`block text-xs ${colorTokens.intent.danger.text}`}>{t(key)}</span>
  }

  return (
    <span className={`block text-xs ${colorTokens.text.subtle}`}>
      <span className="font-medium">{t('chainVerification.technicalDetail')}: </span>
      <code className="font-mono break-all">{error}</code>
    </span>
  )
}

/** Receipt-chain verdict cell: never a bare green badge over an unverified arm. */
function ReceiptChainStatus({
  isValid,
  totalReceipts,
  failedAtSequence,
  error,
}: {
  isValid: boolean
  totalReceipts: number
  failedAtSequence: number | null
  error: string | null
}) {
  const { t } = useTranslation('compliance')

  if (!isValid) {
    return (
      <>
        <StatusBadge tone="broken" />
        <span className={`ms-2 text-xs ${colorTokens.intent.danger.text}`}>
          {failedAtSequence !== null && (
            <>
              {t('chainVerification.brokenAt')} #{failedAtSequence}
            </>
          )}
        </span>
        {error !== null && <ChainDiagnostic error={error} />}
      </>
    )
  }

  // `total_receipts` is the LEGACY-arm row count only (Nf525DataProvider.php:382-395
  // scopes it to `whereNull('fiscal_event_id')`, and :410-418 early-returns
  // isValid/totalRows 0 when that set is empty). On a Phase-1 terminal every
  // receipt is event-chained, so a green "Valid" here would claim an integrity
  // pass over rows this endpoint never looked at.
  if (totalReceipts === 0) {
    return (
      <>
        <StatusBadge tone="notCovered" />
        <span className={`block text-xs ${colorTokens.text.subtle}`}>
          {t('chainVerification.legacyArmOnlyNote')}
        </span>
      </>
    )
  }

  return <StatusBadge tone="valid" />
}

export function ChainVerificationPanel() {
  const { t } = useTranslation('compliance')
  const currentCompanyId = useCompanyStore((state) => state.currentCompanyId)
  const [loading, setLoading] = useState(false)
  const [report, setReport] = useState<ChainVerificationResponse | null>(null)
  const [error, setError] = useState<string | null>(null)

  const handleVerify = async () => {
    if (!currentCompanyId) return
    setLoading(true)
    setError(null)
    try {
      const data = await verifyChains()
      setReport(data)
    } catch {
      setError(t('exportError'))
    } finally {
      setLoading(false)
    }
  }

  const terminals = report?.terminals ?? []
  // The backend already computed the fleet verdict (`all_chains_valid`); the
  // panel renders it rather than deriving a second, competing one.
  const allValid = report?.all_chains_valid === true
  // ...but a green fleet verdict is only honest when every receipt arm this
  // check can see actually had rows to verify.
  const hasUncoveredReceiptArm = terminals.some(
    (row) => row.receipt_chain.is_valid && row.receipt_chain.total_receipts === 0
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

      {report !== null && terminals.length === 0 && (
        <p className={`text-sm ${colorTokens.text.subtle}`}>{t('chainVerification.noTerminals')}</p>
      )}

      {report !== null && terminals.length > 0 && (
        <>
          {report.verified_at !== '' && (
            <p className={`mb-3 text-xs ${colorTokens.text.subtle}`}>
              {t('chainVerification.verifiedAt', {
                timestamp: formatDateTime(report.verified_at),
              })}
            </p>
          )}
          {allValid && !hasUncoveredReceiptArm && (
            <div className={`mb-4 rounded-md ${colorTokens.intent.success.bgSubtle} p-3`}>
              <p className={`text-sm font-medium ${colorTokens.intent.success.textStronger}`}>
                {t('chainVerification.allValid')}
              </p>
            </div>
          )}
          {allValid && hasUncoveredReceiptArm && (
            <div className={`mb-4 rounded-md ${colorTokens.intent.caution.bgSubtle} p-3`}>
              <p className={`text-sm font-medium ${colorTokens.intent.caution.textStronger}`}>
                {t('chainVerification.allValidWithLegacyGap')}
              </p>
            </div>
          )}
          {!allValid && (
            <div className={`mb-4 rounded-md ${colorTokens.intent.danger.bgSubtle} p-3`}>
              <p className={`text-sm font-medium ${colorTokens.intent.danger.textStronger}`}>
                {t('chainVerification.hasBroken')}
              </p>
            </div>
          )}

          <div className="overflow-x-auto">
            <DataTable className={`min-w-full divide-y ${colorTokens.border.divider}`}>
              <thead className={colorTokens.surface.page}>
                <tr>
                  <th className={`px-4 py-3 text-start text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                    {t('chainVerification.terminal')}
                  </th>
                  <th className={`px-4 py-3 text-start text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                    {t('chainVerification.receiptChain')}
                  </th>
                  <th className={`px-4 py-3 text-start text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                    {t('chainVerification.legacyRowsVerified')}
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
                {terminals.map((result) => (
                  <tr key={result.terminal_id}>
                    <td className={`px-4 py-3 whitespace-nowrap text-sm font-medium ${colorTokens.text.primary}`}>
                      {result.terminal_code}
                    </td>
                    <td className="px-4 py-3 text-sm">
                      <ReceiptChainStatus
                        isValid={result.receipt_chain.is_valid}
                        totalReceipts={result.receipt_chain.total_receipts}
                        failedAtSequence={result.receipt_chain.failed_at_sequence}
                        error={result.receipt_chain.error}
                      />
                    </td>
                    <td className={`px-4 py-3 whitespace-nowrap text-sm ${colorTokens.text.subtle}`}>
                      {result.receipt_chain.total_receipts === 0
                        ? t('chainVerification.noLegacyRows')
                        : `${String(result.receipt_chain.verified)} / ${String(result.receipt_chain.total_receipts)}`}
                    </td>
                    <td className="px-4 py-3 text-sm">
                      <StatusBadge tone={result.z_report_chain.is_valid ? 'valid' : 'broken'} />
                      {!result.z_report_chain.is_valid && (
                        <>
                          <span className={`ms-2 text-xs ${colorTokens.intent.danger.text}`}>
                            {result.z_report_chain.failed_at_z_number !== null && (
                              <>
                                {t('chainVerification.brokenAt')} Z-
                                {result.z_report_chain.failed_at_z_number}
                              </>
                            )}
                          </span>
                          {result.z_report_chain.error !== null && (
                            <ChainDiagnostic error={result.z_report_chain.error} />
                          )}
                        </>
                      )}
                    </td>
                    <td className={`px-4 py-3 whitespace-nowrap text-sm ${colorTokens.text.subtle}`}>
                      {result.z_report_chain.total_reports}
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
