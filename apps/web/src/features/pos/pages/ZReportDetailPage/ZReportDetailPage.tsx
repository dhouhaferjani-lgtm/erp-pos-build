import { useTranslation } from 'react-i18next'
import { useParams, useSearchParams, useNavigate } from 'react-router-dom'
import { useQuery, useMutation } from '@tanstack/react-query'
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
import { useCurrency } from '@/hooks/useCurrency'

export function ZReportDetailPage() {
  const { t } = useTranslation(['pos', 'common'])
  const { zNumber } = useParams<{ zNumber: string }>()
  const [searchParams] = useSearchParams()
  const navigate = useNavigate()
  const terminalId = searchParams.get('terminal_id') ?? ''

  const { data: report, isLoading, error } = useQuery({
    queryKey: ['pos', 'z-report', zNumber, terminalId],
    queryFn: () => fetchZReport(zNumber!, terminalId),
    enabled: !!zNumber && !!terminalId,
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
          <AlertTriangle className="h-12 w-12 text-yellow-400 mx-auto mb-3" />
          <p className="text-gray-500">{t('pos:zReports.selectTerminal')}</p>
        </div>
      </div>
    )
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <Loader2 className="h-8 w-8 animate-spin text-blue-600" />
      </div>
    )
  }

  if (error || !report) {
    return (
      <div className="space-y-6">
        <button
          type="button"
          onClick={() => navigate('/pos/z-reports')}
          className="inline-flex items-center gap-2 text-sm text-gray-600 hover:text-gray-900"
        >
          <ArrowLeft className="h-4 w-4" />
          {t('common:back')}
        </button>
        <div className="rounded-lg border border-red-200 bg-red-50 p-6 text-center">
          <AlertTriangle className="h-12 w-12 text-red-400 mx-auto mb-3" />
          <p className="text-red-700">{t('pos:zReports.loadError')}</p>
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
            className="inline-flex items-center gap-2 text-sm text-gray-600 hover:text-gray-900"
          >
            <ArrowLeft className="h-4 w-4" />
            {t('common:back')}
          </button>
          <div>
            <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
              <FileCheck className="h-6 w-6 text-gray-400" />
              {t('pos:zReports.detailTitle', { zNumber: report.formatted_z_number })}
            </h1>
            <p className="text-gray-500">
              {new Date(report.generated_at).toLocaleString()}
              {report.generated_by_user && ` — ${report.generated_by_user.name}`}
            </p>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={() => { downloadPdfMutation.mutate(); }}
            disabled={downloadPdfMutation.isPending}
            className="inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
          >
            {downloadPdfMutation.isPending ? (
              <Loader2 className="h-4 w-4 animate-spin" />
            ) : (
              <Download className="h-4 w-4" />
            )}
            {t('pos:zReports.downloadPdf')}
          </button>
          <button
            type="button"
            onClick={() => { window.print(); }}
            className="inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
          >
            <Printer className="h-4 w-4" />
            {t('pos:zReports.print')}
          </button>
          <button
            type="button"
            onClick={() => { verifyChainMutation.mutate(terminalId); }}
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
        </div>
      </div>

      {/* Chain verification result */}
      {verifyChainMutation.isSuccess && (
        <div
          className={`rounded-lg p-4 flex items-center gap-3 ${
            verifyChainMutation.data.is_valid
              ? 'bg-green-50 border border-green-200'
              : 'bg-red-50 border border-red-200'
          }`}
        >
          {verifyChainMutation.data.is_valid ? (
            <>
              <ShieldCheck className="h-5 w-5 text-green-600 shrink-0" />
              <p className="text-sm text-green-800">{t('pos:zReports.chainValid')}</p>
            </>
          ) : (
            <>
              <ShieldAlert className="h-5 w-5 text-red-600 shrink-0" />
              <p className="text-sm text-red-800">{t('pos:zReports.chainInvalid')}</p>
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
    <div className="rounded-lg border border-gray-200 bg-white p-6">
      <h3 className="text-lg font-semibold text-gray-900 mb-4">{t('pos:zReports.detail.salesSummary')}</h3>
      <div className="divide-y divide-gray-100">
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
    <div className="rounded-lg border border-gray-200 bg-white p-6">
      <h3 className="text-lg font-semibold text-gray-900 mb-4">{t('pos:zReports.detail.cashSummary')}</h3>
      <div className="divide-y divide-gray-100">
        <SummaryRow label={t('pos:zReports.detail.openingCash')} value={report.opening_cash} mono />
        <SummaryRow label={t('pos:zReports.detail.expectedCash')} value={report.expected_cash} mono />
        <SummaryRow label={t('pos:zReports.detail.actualCash')} value={report.actual_cash} mono />
        <div className="flex justify-between py-3">
          <span className="text-sm text-gray-600">{t('pos:zReports.detail.variance')}</span>
          <span
            className={`text-sm font-mono font-medium ${
              varianceValue === 0
                ? 'text-green-600'
                : varianceValue > 0
                  ? 'text-blue-600'
                  : 'text-red-600'
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
    <div className="rounded-lg border border-gray-200 bg-white p-6">
      <h3 className="text-lg font-semibold text-gray-900 mb-4">{t('pos:zReports.detail.vatBreakdown')}</h3>
      <table className="w-full text-sm">
        <thead>
          <tr className="text-left text-gray-500 border-b">
            <th className="pb-2 font-medium">{t('pos:xReport.rate')}</th>
            <th className="pb-2 font-medium text-right">{t('pos:xReport.net')}</th>
            <th className="pb-2 font-medium text-right">{t('pos:xReport.vat')}</th>
            <th className="pb-2 font-medium text-right">{t('pos:xReport.gross')}</th>
          </tr>
        </thead>
        <tbody>
          {vatBreakdown.map((entry) => (
            <tr key={entry.rate} className="border-b border-gray-100">
              <td className="py-2">{entry.rate}%</td>
              <td className="py-2 text-right font-mono">{entry.net}</td>
              <td className="py-2 text-right font-mono">{entry.vat}</td>
              <td className="py-2 text-right font-mono">{entry.gross}</td>
            </tr>
          ))}
        </tbody>
      </table>
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
    <div className="rounded-lg border border-gray-200 bg-white p-6">
      <h3 className="text-lg font-semibold text-gray-900 mb-4">{t('pos:zReports.detail.paymentMethods')}</h3>
      <table className="w-full text-sm">
        <thead>
          <tr className="text-left text-gray-500 border-b">
            <th className="pb-2 font-medium">{t('pos:xReport.method')}</th>
            <th className="pb-2 font-medium text-right">{t('pos:xReport.count')}</th>
            <th className="pb-2 font-medium text-right">{t('pos:xReport.amount')}</th>
          </tr>
        </thead>
        <tbody>
          {paymentMethods.map((entry) => (
            <tr key={entry.type} className="border-b border-gray-100">
              <td className="py-2">{entry.type}</td>
              <td className="py-2 text-right font-mono">{entry.count}</td>
              <td className="py-2 text-right font-mono">{entry.amount}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function HashInfoCard({ report }: { report: ZReportItem }) {
  const { t } = useTranslation(['pos'])

  return (
    <div className="rounded-lg border border-gray-200 bg-white p-6">
      <h3 className="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
        <Hash className="h-5 w-5 text-gray-400" />
        {t('pos:zReports.detail.fiscalHash')}
      </h3>
      <div className="space-y-3">
        <div>
          <p className="text-xs font-medium text-gray-500 uppercase mb-1">{t('pos:zReports.detail.currentHash')}</p>
          <p className="text-xs font-mono text-gray-700 bg-gray-50 rounded px-3 py-2 break-all">
            {report.fiscal_hash}
          </p>
        </div>
        {report.previous_z_hash && (
          <div>
            <p className="text-xs font-medium text-gray-500 uppercase mb-1">{t('pos:zReports.detail.previousHash')}</p>
            <p className="text-xs font-mono text-gray-700 bg-gray-50 rounded px-3 py-2 break-all">
              {report.previous_z_hash}
            </p>
          </div>
        )}
        {report.is_first_z_report && (
          <p className="text-xs text-blue-600 font-medium">{t('pos:zReports.detail.genesisReport')}</p>
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
      <span className="text-sm text-gray-600">{label}</span>
      <span className={`text-sm font-medium text-gray-900 ${mono ? 'font-mono' : ''}`}>{value}</span>
    </div>
  )
}
