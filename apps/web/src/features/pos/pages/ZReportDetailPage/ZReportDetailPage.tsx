import { useTranslation } from 'react-i18next'
import { useParams, useSearchParams, useNavigate } from 'react-router-dom'
import { useQuery, useMutation } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { fetchZReport, verifyZReportChain, downloadZReportPdf, type ZReportItem } from '../../api/reportApi'
import {
  ArrowLeft,
  Download,
  FileCheck,
  Loader2,
  Printer,
  ShieldCheck,
  ShieldAlert,
  AlertTriangle,
  Hash,
} from 'lucide-react'
import { toast } from 'sonner'
import { tokens, textColors, borderColors, colors } from '@/lib/designTokens'
import { POSButton } from '../../atoms/POSButton'
import { useCurrency } from '@/hooks/useCurrency'
import { usePosTenantScope } from '../../hooks/usePosTenantScope'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

export function ZReportDetailPage() {
  const { t } = useTranslation(['pos', 'common'])
  const { zNumber } = useParams<{ zNumber: string }>()
  const [searchParams] = useSearchParams()
  const navigate = useNavigate()
  const terminalId = searchParams.get('terminal_id') ?? ''
  const { hasTenantScope } = usePosTenantScope()

  const { data: report, isLoading, error } = useQuery({
    queryKey: tenantScopedKey(['pos', 'z-report', zNumber, terminalId]),
    queryFn: () => fetchZReport(zNumber!, terminalId),
    enabled: !!zNumber && !!terminalId && hasTenantScope,
  })

  const downloadPdfMutation = useMutation({
    mutationFn: () => downloadZReportPdf(zNumber!, terminalId),
    onError: () => {
      toast.error(t('common:errorMessages.generic'))
    },
  })

  const verifyChainMutation = useMutation({
    mutationFn: (tId: string) => verifyZReportChain(tId),
    onSuccess: (result) => {
      if (result.is_valid) {
        toast.success(t('pos:zReports.chainValid'))
      } else {
        toast.error(t('pos:zReports.chainInvalid'))
      }
    },
    onError: () => {
      toast.error(t('common:errorMessages.generic'))
    },
  })

  if (!terminalId) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className="text-center">
          <AlertTriangle className={`h-12 w-12 ${textColors.warningDark} mx-auto mb-3`} />
          <p className={textColors.tertiary}>{t('pos:zReports.selectTerminal')}</p>
        </div>
      </div>
    )
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <Loader2 className={`h-8 w-8 animate-spin ${textColors.brand}`} />
      </div>
    )
  }

  if (error || !report) {
    return (
      <div className="space-y-6">
        <button
          type="button"
          onClick={() => navigate('/pos/z-reports')}
          className={`inline-flex items-center gap-2 text-sm ${textColors.tertiary} ${textColors.hoverPrimary}`}
        >
          <ArrowLeft className="h-4 w-4" />
          {t('common:back')}
        </button>
        <div className={`rounded-lg border ${borderColors.error} ${tokens.alert.error} p-6 text-center`}>
          <AlertTriangle className={`h-12 w-12 ${textColors.error} mx-auto mb-3`} />
          <p className={textColors.error}>{t('pos:zReports.loadError')}</p>
        </div>
      </div>
    )
  }

  const reportData = report.report_data

  return (
    <div className="space-y-6">
      {/* Back + Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <button
            type="button"
            onClick={() => navigate('/pos/z-reports')}
            className={`inline-flex items-center gap-2 text-sm ${textColors.tertiary} ${textColors.hoverPrimary}`}
          >
            <ArrowLeft className="h-4 w-4" />
            {t('common:back')}
          </button>
          <div>
            <PageHeaderTitle className={`text-2xl font-bold ${textColors.primary} flex items-center gap-2`}>
              <FileCheck className={`h-6 w-6 ${textColors.disabled}`} />
              {t('pos:zReports.detailTitle', { zNumber: report.formatted_z_number })}
            </PageHeaderTitle>
            <p className={textColors.tertiary}>
              {new Date(report.generated_at).toLocaleString()}
              {report.generated_by_user && ` — ${report.generated_by_user.name}`}
            </p>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <POSButton
            variant="secondary"
            size="sm"
            onClick={() => { downloadPdfMutation.mutate(); }}
            disabled={downloadPdfMutation.isPending}
            icon={
              downloadPdfMutation.isPending ? (
                <Loader2 className="h-4 w-4 animate-spin" />
              ) : (
                <Download className="h-4 w-4" />
              )
            }
          >
            {t('pos:zReports.downloadPdf')}
          </POSButton>
          <POSButton
            variant="secondary"
            size="sm"
            onClick={() => { window.print(); }}
            icon={<Printer className="h-4 w-4" />}
          >
            {t('pos:zReports.print')}
          </POSButton>
          <POSButton
            size="sm"
            onClick={() => { verifyChainMutation.mutate(terminalId); }}
            disabled={verifyChainMutation.isPending}
            icon={
              verifyChainMutation.isPending ? (
                <Loader2 className="h-4 w-4 animate-spin" />
              ) : (
                <ShieldCheck className="h-4 w-4" />
              )
            }
          >
            {verifyChainMutation.isPending ? t('pos:zReports.verifying') : t('pos:zReports.verifyChain')}
          </POSButton>
        </div>
      </div>

      {/* Chain verification result */}
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
              <p className="text-sm">{t('pos:zReports.chainInvalid')}</p>
            </>
          )}
        </div>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Sales Summary */}
        <SummaryCard report={report} reportData={reportData} />

        {/* Cash Summary */}
        <CashSummaryCard report={report} />

        {/* VAT Breakdown */}
        {reportData?.vat_breakdown && reportData.vat_breakdown.length > 0 && (
          <VatBreakdownCard vatBreakdown={reportData.vat_breakdown} />
        )}

        {/* Payment Methods */}
        {reportData?.payment_methods && reportData.payment_methods.length > 0 && (
          <PaymentMethodsCard paymentMethods={reportData.payment_methods} />
        )}
      </div>

      {/* Fiscal Hash */}
      <HashInfoCard report={report} />
    </div>
  )
}

function SummaryCard({
  report,
  reportData,
}: {
  report: ZReportItem
  reportData: ZReportItem['report_data']
}) {
  const { t } = useTranslation(['pos'])
  const { decimals } = useCurrency()

  const averageTicket =
    report.sales_count > 0
      ? (parseFloat(report.gross_sales) / report.sales_count).toFixed(decimals)
      : (0).toFixed(decimals)

  return (
    <div className={tokens.card.base}>
      <h3 className={`text-lg font-semibold ${textColors.primary} mb-4`}>{t('pos:zReports.detail.salesSummary')}</h3>
      <div className={`divide-y ${borderColors.divideLight}`}>
        <SummaryRow label={t('pos:zReports.detail.receiptCount')} value={String(report.sales_count)} />
        <SummaryRow label={t('pos:zReports.detail.averageTicket')} value={averageTicket} mono />
        <SummaryRow label={t('pos:zReports.detail.grossSales')} value={report.gross_sales} mono />
        <SummaryRow label={t('pos:zReports.detail.netSales')} value={reportData?.net_sales ?? '--'} mono />
        <SummaryRow label={t('pos:zReports.detail.taxAmount')} value={reportData?.tax_amount ?? '--'} mono />
        <SummaryRow label={t('pos:zReports.detail.refundsCount')} value={String(reportData?.refunds_count ?? 0)} />
        <SummaryRow label={t('pos:zReports.detail.refundsAmount')} value={reportData?.refunds_amount ?? '0.00'} mono />
        <SummaryRow label={t('pos:zReports.detail.voidedCount')} value={String(reportData?.voided_count ?? 0)} />
      </div>
    </div>
  )
}

function CashSummaryCard({ report }: { report: ZReportItem }) {
  const { t } = useTranslation(['pos'])
  const varianceValue = parseFloat(report.variance)

  return (
    <div className={tokens.card.base}>
      <h3 className={`text-lg font-semibold ${textColors.primary} mb-4`}>{t('pos:zReports.detail.cashSummary')}</h3>
      <div className={`divide-y ${borderColors.divideLight}`}>
        <SummaryRow label={t('pos:zReports.detail.openingCash')} value={report.opening_cash} mono />
        <SummaryRow label={t('pos:zReports.detail.expectedCash')} value={report.expected_cash} mono />
        <SummaryRow label={t('pos:zReports.detail.actualCash')} value={report.actual_cash} mono />
        <div className="flex justify-between py-3">
          <span className={`text-sm ${textColors.tertiary}`}>{t('pos:zReports.detail.variance')}</span>
          <span
            className={`text-sm font-mono font-medium tabular-nums ${
              varianceValue === 0
                ? textColors.success
                : varianceValue > 0
                  ? textColors.brand
                  : textColors.error
            }`}
          >
            {report.variance}
          </span>
        </div>
      </div>
    </div>
  )
}

function VatBreakdownCard({
  vatBreakdown,
}: {
  vatBreakdown: ZReportItem['report_data']['vat_breakdown']
}) {
  const { t } = useTranslation(['pos'])

  return (
    <div className={tokens.card.base}>
      <h3 className={`text-lg font-semibold ${textColors.primary} mb-4`}>{t('pos:zReports.detail.vatBreakdown')}</h3>
      <DataTable className="w-full text-sm">
        <thead>
          <tr className={`text-left ${textColors.tertiary} border-b`}>
            <th className="pb-2 font-medium">{t('pos:xReport.rate')}</th>
            <th className="pb-2 font-medium text-right">{t('pos:xReport.net')}</th>
            <th className="pb-2 font-medium text-right">{t('pos:xReport.vat')}</th>
            <th className="pb-2 font-medium text-right">{t('pos:xReport.gross')}</th>
          </tr>
        </thead>
        <tbody>
          {vatBreakdown.map((entry) => (
            <tr key={entry.rate} className={`border-b ${borderColors.light}`}>
              <td className="py-2">{entry.rate}%</td>
              <td className="py-2 text-right font-mono tabular-nums">{entry.net}</td>
              <td className="py-2 text-right font-mono tabular-nums">{entry.vat}</td>
              <td className="py-2 text-right font-mono tabular-nums">{entry.gross}</td>
            </tr>
          ))}
        </tbody>
      </DataTable>
    </div>
  )
}

function PaymentMethodsCard({
  paymentMethods,
}: {
  paymentMethods: ZReportItem['report_data']['payment_methods']
}) {
  const { t } = useTranslation(['pos'])

  return (
    <div className={tokens.card.base}>
      <h3 className={`text-lg font-semibold ${textColors.primary} mb-4`}>{t('pos:zReports.detail.paymentMethods')}</h3>
      <DataTable className="w-full text-sm">
        <thead>
          <tr className={`text-left ${textColors.tertiary} border-b`}>
            <th className="pb-2 font-medium">{t('pos:xReport.method')}</th>
            <th className="pb-2 font-medium text-right">{t('pos:xReport.count')}</th>
            <th className="pb-2 font-medium text-right">{t('pos:xReport.amount')}</th>
          </tr>
        </thead>
        <tbody>
          {paymentMethods.map((entry) => (
            <tr key={entry.type} className={`border-b ${borderColors.light}`}>
              <td className="py-2">{entry.type}</td>
              <td className="py-2 text-right font-mono tabular-nums">{entry.count}</td>
              <td className="py-2 text-right font-mono tabular-nums">{entry.amount}</td>
            </tr>
          ))}
        </tbody>
      </DataTable>
    </div>
  )
}

function HashInfoCard({ report }: { report: ZReportItem }) {
  const { t } = useTranslation(['pos'])

  return (
    <div className={tokens.card.base}>
      <h3 className={`text-lg font-semibold ${textColors.primary} mb-4 flex items-center gap-2`}>
        <Hash className={`h-5 w-5 ${textColors.disabled}`} />
        {t('pos:zReports.detail.fiscalHash')}
      </h3>
      <div className="space-y-3">
        <div>
          <p className={`text-xs font-medium ${textColors.tertiary} uppercase mb-1`}>{t('pos:zReports.detail.currentHash')}</p>
          <p className={`text-xs font-mono ${textColors.secondary} ${colors.neutral[50]} rounded px-3 py-2 break-all`}>
            {report.fiscal_hash}
          </p>
        </div>
        {report.previous_z_hash && (
          <div>
            <p className={`text-xs font-medium ${textColors.tertiary} uppercase mb-1`}>{t('pos:zReports.detail.previousHash')}</p>
            <p className={`text-xs font-mono ${textColors.secondary} ${colors.neutral[50]} rounded px-3 py-2 break-all`}>
              {report.previous_z_hash}
            </p>
          </div>
        )}
        {report.is_first_z_report && (
          <p className={`text-xs ${textColors.brand} font-medium`}>{t('pos:zReports.detail.genesisReport')}</p>
        )}
      </div>
    </div>
  )
}

function SummaryRow({
  label,
  value,
  mono = false,
}: {
  label: string
  value: string
  mono?: boolean
}) {
  return (
    <div className="flex justify-between py-3">
      <span className={`text-sm ${textColors.tertiary}`}>{label}</span>
      <span className={`text-sm font-medium ${textColors.primary} ${mono ? 'font-mono tabular-nums' : ''}`}>{value}</span>
    </div>
  )
}
