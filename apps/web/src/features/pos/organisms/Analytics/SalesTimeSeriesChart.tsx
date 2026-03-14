import ReactECharts from 'echarts-for-react'
import { useTranslation } from 'react-i18next'
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
    <div className="rounded-lg border bg-card p-4">
      <h3 className="mb-4 text-lg font-medium">{t('pos:analytics.salesOverTime')}</h3>
      {data.length === 0 ? (
        <p className="py-8 text-center text-muted-foreground">{t('pos:analytics.noData')}</p>
      ) : (
        <ReactECharts option={option} style={{ height: 350 }} />
      )}
    </div>
  )
}
