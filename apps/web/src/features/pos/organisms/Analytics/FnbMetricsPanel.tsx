import ReactECharts from 'echarts-for-react'
import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import { colors, textColors, borderColors } from '@/lib/designTokens'
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
        <div className={cn('rounded-lg border p-4', borderColors.light, colors.white)}>
          <p className={cn('text-sm', textColors.tertiary)}>{t('pos:analytics.avgTableTime')}</p>
          <p className={cn('mt-1 text-2xl font-semibold', textColors.primary)}>
            {data.avg_table_time_minutes} {t('pos:analytics.minutes')}
          </p>
        </div>
        <div className={cn('rounded-lg border p-4', borderColors.light, colors.white)}>
          <p className={cn('text-sm', textColors.tertiary)}>{t('pos:analytics.avgItemsPerOrder')}</p>
          <p className={cn('mt-1 text-2xl font-semibold', textColors.primary)}>{data.avg_items_per_order}</p>
        </div>
      </div>

      {sortedHours.length > 0 && (
        <div className={cn('rounded-lg border p-4', borderColors.light, colors.white)}>
          <h3 className={cn('mb-4 text-lg font-medium', textColors.primary)}>{t('pos:analytics.peakHours')}</h3>
          <ReactECharts option={peakHoursOption} style={{ height: 300 }} />
        </div>
      )}

      {data.orders_by_mode.length > 0 && (
        <div className={cn('rounded-lg border p-4', borderColors.light, colors.white)}>
          <h3 className={cn('mb-4 text-lg font-medium', textColors.primary)}>{t('pos:analytics.ordersByMode')}</h3>
          <div className="flex gap-4">
            {data.orders_by_mode.map((m) => (
              <div key={m.mode} className={cn('rounded-md px-4 py-2', colors.neutral[100])}>
                <span className={cn('font-medium', textColors.primary)}>{m.mode}</span>
                <span className={cn('ml-2', textColors.tertiary)}>{m.count}</span>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
