import ReactECharts from 'echarts-for-react'
import { useTranslation } from 'react-i18next'
import type { FnbMetrics } from '../../api/analyticsApi'

interface FnbMetricsPanelProps {
  data: FnbMetrics
}

export function FnbMetricsPanel({ data }: FnbMetricsPanelProps) {
  const { t } = useTranslation(['pos'])

  const sortedHours = [...data.peak_hours].sort((a, b) => a.hour - b.hour)

  const peakHoursOption = {
    tooltip: { trigger: 'axis' as const },
    xAxis: {
      type: 'category' as const,
      data: sortedHours.map((h) => `${String(h.hour).padStart(2, '0')}:00`),
    },
    yAxis: { type: 'value' as const },
    series: [
      {
        type: 'bar',
        data: sortedHours.map((h) => h.order_count),
        itemStyle: { color: '#8b5cf6' },
      },
    ],
    grid: { left: '3%', right: '4%', bottom: '3%', containLabel: true },
  }

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-4">
        <div className="rounded-lg border bg-card p-4">
          <p className="text-sm text-muted-foreground">{t('pos:analytics.avgTableTime')}</p>
          <p className="mt-1 text-2xl font-semibold">
            {data.avg_table_time_minutes} {t('pos:analytics.minutes')}
          </p>
        </div>
        <div className="rounded-lg border bg-card p-4">
          <p className="text-sm text-muted-foreground">{t('pos:analytics.avgItemsPerOrder')}</p>
          <p className="mt-1 text-2xl font-semibold">{data.avg_items_per_order}</p>
        </div>
      </div>

      {sortedHours.length > 0 && (
        <div className="rounded-lg border bg-card p-4">
          <h3 className="mb-4 text-lg font-medium">{t('pos:analytics.peakHours')}</h3>
          <ReactECharts option={peakHoursOption} style={{ height: 300 }} />
        </div>
      )}

      {data.orders_by_mode.length > 0 && (
        <div className="rounded-lg border bg-card p-4">
          <h3 className="mb-4 text-lg font-medium">{t('pos:analytics.ordersByMode')}</h3>
          <div className="flex gap-4">
            {data.orders_by_mode.map((m) => (
              <div key={m.mode} className="rounded-md bg-muted px-4 py-2">
                <span className="font-medium">{m.mode}</span>
                <span className="ml-2 text-muted-foreground">{m.count}</span>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
