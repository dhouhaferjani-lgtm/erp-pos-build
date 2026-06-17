import ReactECharts from 'echarts-for-react'
import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import { colors, textColors, borderColors } from '@/lib/designTokens'
import type { PeriodSales } from '../../api/analyticsApi'

interface SalesTimeSeriesChartProps {
  data: PeriodSales[]
}

export function SalesTimeSeriesChart({ data }: SalesTimeSeriesChartProps) {
  const { t } = useTranslation(['pos'])

  const option = {
    tooltip: {
      trigger: 'axis' as const,
    },
    xAxis: {
      type: 'category' as const,
      data: data.map((d) => {
        const date = new Date(d.period)
        return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
      }),
    },
    yAxis: [
      { type: 'value' as const, name: t('pos:analytics.sales') },
      { type: 'value' as const, name: t('pos:analytics.count'), position: 'right' as const },
    ],
    series: [
      {
        name: t('pos:analytics.sales'),
        type: 'line',
        smooth: true,
        areaStyle: { opacity: 0.15 },
        data: data.map((d) => Number(d.total)),
      },
      {
        name: t('pos:analytics.receiptCount'),
        type: 'bar',
        yAxisIndex: 1,
        barWidth: '40%',
        itemStyle: { opacity: 0.4 },
        data: data.map((d) => d.count),
      },
    ],
    grid: { left: '3%', right: '4%', bottom: '3%', containLabel: true },
  }

  return (
    <div className={cn('rounded-lg border p-4', borderColors.light, colors.white)}>
      <h3 className={cn('mb-4 text-lg font-medium', textColors.primary)}>{t('pos:analytics.salesOverTime')}</h3>
      {data.length === 0 ? (
        <p className={cn('py-8 text-center', textColors.tertiary)}>{t('pos:analytics.noData')}</p>
      ) : (
        <ReactECharts option={option} style={{ height: 350 }} />
      )}
    </div>
  )
}
