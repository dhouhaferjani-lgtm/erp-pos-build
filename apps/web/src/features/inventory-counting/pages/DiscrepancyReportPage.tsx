import { useParams, Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { format } from 'date-fns'
import {
  ArrowLeft,
  Download,
  TrendingUp,
  TrendingDown,
  CheckCircle,
  AlertTriangle,
  User,
} from 'lucide-react'
import { CountingStatusBadge } from '../components'
import { useDiscrepancyReport, useExportReport } from '../api/queries'
import { formatTND } from '@/lib/format'
import { cn } from '@/lib/utils'

export function DiscrepancyReportPage() {
  const { t } = useTranslation('inventory')
  const { id } = useParams<{ id: string }>()
  const countingId = id ?? ''

  const { data: report, isLoading, error } = useDiscrepancyReport(countingId)
  const exportReport = useExportReport()

  if (isLoading) {
    return (
      <div className="p-8 text-center text-gray-500">
        {t('loading')}...
      </div>
    )
  }

  if (error || !report) {
    return (
      <div className="p-8 text-center">
        <p className="text-red-600 mb-4">{t('error')}</p>
        <Link
          to={`/inventory/counting/${String(countingId)}`}
          className="text-blue-600 hover:text-blue-700"
        >
          {t('back')}
        </Link>
      </div>
    )
  }

  const handleExport = (format: 'pdf' | 'xlsx') => {
    exportReport.mutate({ id: countingId, format })
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Link
            to={`/inventory/counting/${String(countingId)}`}
            className="inline-flex items-center justify-center w-10 h-10 rounded-full hover:bg-gray-100"
          >
            <ArrowLeft className="w-5 h-5" />
          </Link>
          <div>
            <h1 className="text-2xl font-bold flex items-center gap-3">
              {t('counting.report.title')}
              <CountingStatusBadge status={report.counting.status} />
            </h1>
            <p className="text-gray-500">
              {t('counting.report.generatedAt', {
                date: format(new Date(report.generated_at), 'MMM d, yyyy h:mm a'),
              })}
            </p>
          </div>
        </div>

        <div className="flex gap-2">
          <button
            type="button"
            onClick={() => { handleExport('pdf'); }}
            disabled={exportReport.isPending}
            className="inline-flex items-center px-4 py-2 text-sm font-medium border border-gray-300 rounded-md hover:bg-gray-50 disabled:opacity-50"
          >
            <Download className="w-4 h-4 me-2" />
            {t('counting.report.exportPdf')}
          </button>
          <button
            type="button"
            onClick={() => { handleExport('xlsx'); }}
            disabled={exportReport.isPending}
            className="inline-flex items-center px-4 py-2 text-sm font-medium border border-gray-300 rounded-md hover:bg-gray-50 disabled:opacity-50"
          >
            <Download className="w-4 h-4 me-2" />
            {t('counting.report.exportExcel')}
          </button>
        </div>
      </div>

      {/* Summary Cards */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <SummaryCard
          icon={CheckCircle}
          label={t('counting.report.totalItemsCounted')}
          value={report.summary.total_items_counted.toString()}
          iconClassName="text-blue-600"
        />
        <SummaryCard
          icon={CheckCircle}
          label={t('counting.report.itemsNoVariance')}
          value={report.summary.items_no_variance.toString()}
          iconClassName="text-green-600"
        />
        <SummaryCard
          icon={AlertTriangle}
          label={t('counting.report.itemsWithVariance')}
          value={report.summary.items_with_variance.toString()}
          iconClassName="text-amber-600"
        />
        <SummaryCard
          icon={
            report.summary.total_variance_value.net >= 0 ? TrendingUp : TrendingDown
          }
          label={t('counting.report.netVarianceValue')}
          value={formatTND(report.summary.total_variance_value.net)}
          iconClassName={
            report.summary.total_variance_value.net >= 0
              ? 'text-green-600'
              : 'text-red-600'
          }
          highlight={report.summary.total_variance_value.net !== 0}
        />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Variance Breakdown */}
        <div className="lg:col-span-2 bg-white rounded-lg border p-6">
          <h2 className="text-lg font-semibold mb-4">
            {t('counting.report.varianceBreakdown')}
          </h2>

          <div className="grid grid-cols-2 gap-4 mb-6">
            <div className="bg-green-50 rounded-lg p-4">
              <div className="flex items-center gap-2 mb-2">
                <TrendingUp className="w-5 h-5 text-green-600" />
                <span className="text-sm text-gray-600">
                  {t('counting.report.positiveVariance')}
                </span>
              </div>
              <div className="text-2xl font-bold text-green-600">
                +{formatTND(report.summary.total_variance_value.positive)}
              </div>
            </div>
            <div className="bg-red-50 rounded-lg p-4">
              <div className="flex items-center gap-2 mb-2">
                <TrendingDown className="w-5 h-5 text-red-600" />
                <span className="text-sm text-gray-600">
                  {t('counting.report.negativeVariance')}
                </span>
              </div>
              <div className="text-2xl font-bold text-red-600">
                {formatTND(report.summary.total_variance_value.negative)}
              </div>
            </div>
          </div>

          <h3 className="font-medium mb-3">
            {t('counting.report.resolutionMethods')}
          </h3>
          <div className="space-y-2">
            <ResolutionMethodRow
              label={t('counting.reconciliation.allMatch')}
              count={report.summary.variance_breakdown.auto_all_match}
              total={report.summary.total_items_counted}
              color="bg-green-500"
            />
            <ResolutionMethodRow
              label={t('counting.reconciliation.variance')}
              count={report.summary.variance_breakdown.auto_counters_agree}
              total={report.summary.total_items_counted}
              color="bg-yellow-500"
            />
            <ResolutionMethodRow
              label={t('counting.reconciliation.thirdDecisive')}
              count={report.summary.variance_breakdown.third_count_decisive}
              total={report.summary.total_items_counted}
              color="bg-blue-500"
            />
            <ResolutionMethodRow
              label={t('counting.reconciliation.override')}
              count={report.summary.variance_breakdown.manual_override}
              total={report.summary.total_items_counted}
              color="bg-purple-500"
            />
          </div>
        </div>

        {/* Counter Performance */}
        <div className="bg-white rounded-lg border p-6">
          <h2 className="text-lg font-semibold mb-4 flex items-center gap-2">
            <User className="w-5 h-5" />
            {t('counting.report.counterPerformance')}
          </h2>

          <div className="space-y-4">
            {report.counter_performance.map((counter) => (
              <div
                key={counter.user.id}
                className="p-4 bg-gray-50 rounded-lg"
              >
                <div className="flex items-center gap-3 mb-3">
                  <div className="w-10 h-10 rounded-full bg-gray-300 flex items-center justify-center font-medium">
                    {counter.user.name.charAt(0)}
                  </div>
                  <div>
                    <div className="font-medium">{counter.user.name}</div>
                    <div className="text-sm text-gray-500">
                      {counter.items_counted} {t('counting.report.itemsCounted')}
                    </div>
                  </div>
                </div>

                <dl className="grid grid-cols-2 gap-2 text-sm">
                  <dt className="text-gray-500">
                    {t('counting.report.accuracyRate')}
                  </dt>
                  <dd
                    className={cn(
                      'font-medium text-end',
                      counter.accuracy_rate >= 95
                        ? 'text-green-600'
                        : counter.accuracy_rate >= 80
                          ? 'text-yellow-600'
                          : 'text-red-600'
                    )}
                  >
                    {counter.accuracy_rate.toFixed(1)}%
                  </dd>
                  <dt className="text-gray-500">
                    {t('counting.report.matchedOther')}
                  </dt>
                  <dd className="font-medium text-end">
                    {counter.matched_other_counter}
                  </dd>
                  <dt className="text-gray-500">
                    {t('counting.report.matchedTheoretical')}
                  </dt>
                  <dd className="font-medium text-end">
                    {counter.matched_theoretical}
                  </dd>
                  {counter.times_proven_wrong_by_3rd > 0 && (
                    <>
                      <dt className="text-gray-500">
                        {t('counting.report.provenWrong')}
                      </dt>
                      <dd className="font-medium text-end text-red-600">
                        {counter.times_proven_wrong_by_3rd}
                      </dd>
                    </>
                  )}
                </dl>
              </div>
            ))}
          </div>
        </div>
      </div>

      {/* Flagged Items */}
      {report.flagged_items.length > 0 && (
        <div className="bg-white rounded-lg border p-6">
          <h2 className="text-lg font-semibold mb-4 flex items-center gap-2">
            <AlertTriangle className="w-5 h-5 text-amber-600" />
            {t('counting.report.flaggedItems')}
          </h2>

          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead>
                <tr>
                  <th className="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase">
                    {t('counting.reconciliation.product')}
                  </th>
                  <th className="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase">
                    {t('counting.reconciliation.location')}
                  </th>
                  <th className="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">
                    {t('counting.reconciliation.theoretical')}
                  </th>
                  <th className="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">
                    {t('counting.reconciliation.final')}
                  </th>
                  <th className="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">
                    {t('counting.reconciliation.varianceShort')}
                  </th>
                  <th className="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase">
                    {t('counting.report.reason')}
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200">
                {report.flagged_items.map((item) => (
                  <tr key={item.id} className="bg-amber-50/50">
                    <td className="px-4 py-3">
                      <div className="font-medium">{item.product.name}</div>
                      <div className="text-sm text-gray-500">
                        {item.product.sku}
                      </div>
                    </td>
                    <td className="px-4 py-3 text-sm">{item.location.code}</td>
                    <td className="px-4 py-3 text-center font-mono">
                      {item.theoretical_qty}
                    </td>
                    <td className="px-4 py-3 text-center font-mono font-medium">
                      {item.final_qty ?? '-'}
                    </td>
                    <td className="px-4 py-3 text-center">
                      {item.variance !== null && (
                        <span
                          className={cn(
                            'font-mono font-medium',
                            item.variance > 0 && 'text-green-600',
                            item.variance < 0 && 'text-red-600'
                          )}
                        >
                          {item.variance > 0 ? '+' : ''}
                          {item.variance}
                        </span>
                      )}
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-600">
                      {item.flag_reason || item.resolution_notes || '-'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  )
}

interface SummaryCardProps {
  icon: React.ComponentType<{ className?: string }>
  label: string
  value: string
  iconClassName?: string
  highlight?: boolean
}

function SummaryCard({
  icon: Icon,
  label,
  value,
  iconClassName,
  highlight = false,
}: SummaryCardProps) {
  return (
    <div
      className={cn(
        'rounded-lg border bg-white p-6',
        highlight && 'border-amber-300 bg-amber-50'
      )}
    >
      <div className="flex items-center justify-between">
        <div>
          <p className="text-sm text-gray-500">{label}</p>
          <p className="text-2xl font-bold">{value}</p>
        </div>
        <Icon className={cn('w-8 h-8', iconClassName)} />
      </div>
    </div>
  )
}

interface ResolutionMethodRowProps {
  label: string
  count: number
  total: number
  color: string
}

function ResolutionMethodRow({
  label,
  count,
  total,
  color,
}: ResolutionMethodRowProps) {
  const percentage = total > 0 ? (count / total) * 100 : 0

  return (
    <div>
      <div className="flex justify-between text-sm mb-1">
        <span>{label}</span>
        <span className="font-medium">
          {count} ({percentage.toFixed(1)}%)
        </span>
      </div>
      <div className="w-full bg-gray-200 rounded-full h-2">
        <div
          className={cn('h-2 rounded-full', color)}
          style={{ width: `${String(percentage)}%` }}
        />
      </div>
    </div>
  )
}
