import { useTranslation } from 'react-i18next'
import { useParams, useNavigate } from 'react-router-dom'
import { ArrowLeft } from 'lucide-react'
import { QueryError } from '@/components/QueryError'
import { useVatReport } from '../hooks/useVatReport'
import { VatSummaryCards } from '../components/VatSummaryCards'
import { VatBreakdownTable } from '../components/VatBreakdownTable'
import { VatSpecialItems } from '../components/VatSpecialItems'
import { VatPeriodStatusBadge } from '../components/VatPeriodStatusBadge'
import { VatExportMenu } from '../components/VatExportMenu'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'

export function VatReportPage() {
  const { t } = useTranslation(['finance'])
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()

  const { data: report, isLoading, error, refetch } = useVatReport(id)

  return (
    <div className="p-6">
      {/* Back link */}
      <button
        type="button"
        onClick={() => { void navigate('/finance/vat-periods'); }}
        className={`mb-4 flex items-center gap-1.5 text-sm ${colorTokens.text.subtle} ${colorTokens.variants.hoverTextGray700}`}
      >
        <ArrowLeft className="h-4 w-4" />
        {t('finance:vatReporting.detail.backToList')}
      </button>

      {/* Loading */}
      {isLoading ? (
        <div className={`py-12 text-center ${colorTokens.text.subtle}`}>
          {t('finance:reports.common.loading')}
        </div>
      ) : error ? (
        <QueryError
          error={error}
          onRetry={() => { void refetch(); }}
          title={t('finance:vatReporting.title')}
        />
      ) : report ? (
        <div className="space-y-6">
          {/* Header */}
          <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex items-center gap-3">
              <PageHeaderTitle className="text-2xl font-bold">{report.period.label}</PageHeaderTitle>
              <VatPeriodStatusBadge status={report.period.status} />
            </div>
            <VatExportMenu periodId={report.period.id} />
          </div>

          {/* Summary Cards */}
          <VatSummaryCards
            outputVat={report.output_vat.total_vat}
            inputVat={report.input_vat.total_vat}
            creditBroughtForward={report.credit_brought_forward}
            amountPayable={report.amount_payable}
          />

          {/* Output VAT Breakdown */}
          {report.output_vat.breakdowns.length > 0 && (
            <div>
              <h2 className={`mb-3 text-lg font-semibold ${colorTokens.text.primary}`}>
                {t('finance:vatReporting.detail.outputBreakdown')}
              </h2>
              <p className={`mb-2 text-sm ${colorTokens.text.subtle}`}>
                {t('finance:vatReporting.detail.vatCollected')}
              </p>
              <VatBreakdownTable breakdowns={report.output_vat.breakdowns} />
            </div>
          )}

          {/* Input VAT Breakdown */}
          {report.input_vat.breakdowns.length > 0 && (
            <div>
              <h2 className={`mb-3 text-lg font-semibold ${colorTokens.text.primary}`}>
                {t('finance:vatReporting.detail.inputBreakdown')}
              </h2>
              <p className={`mb-2 text-sm ${colorTokens.text.subtle}`}>
                {t('finance:vatReporting.detail.vatOnPurchases')}
              </p>
              <VatBreakdownTable
                breakdowns={report.input_vat.breakdowns}
                showRecoverable
              />
            </div>
          )}

          {/* Special Items */}
          <VatSpecialItems
            specialItems={report.special_items}
            countryCode={typeof report.declaration['country_code'] === 'string' ? report.declaration['country_code'] : ''}
          />

          {/* Bottom summary */}
          <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.page} p-4`}>
            <div className="space-y-2">
              <div className="flex justify-between text-sm">
                <span className={`${colorTokens.text.muted}`}>{t('finance:vatReporting.detail.creditBroughtForward')}</span>
                <span className="font-medium">{formatAmount(report.credit_brought_forward)}</span>
              </div>
              <div className="flex justify-between text-sm">
                <span className={`${colorTokens.text.muted}`}>{t('finance:vatReporting.detail.fromPreviousPeriod')}</span>
                <span className="font-medium">{formatAmount(report.credit_carried_forward)}</span>
              </div>
              <div className={`flex justify-between border-t ${colorTokens.border.default} pt-2 text-base font-bold`}>
                <span>{t('finance:vatReporting.detail.amountPayable')}</span>
                <span className={parseFloat(report.amount_payable) > 0 ? `${colorTokens.intent.danger.text}` : `${colorTokens.intent.success.text}`}>
                  {formatAmount(report.amount_payable)}
                </span>
              </div>
            </div>
          </div>
        </div>
      ) : null}
    </div>
  )
}

function formatAmount(value: string): string {
  return new Intl.NumberFormat('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(parseFloat(value))
}
