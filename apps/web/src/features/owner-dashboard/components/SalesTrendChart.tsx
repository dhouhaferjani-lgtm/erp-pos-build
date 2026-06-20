import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import type { EChartsOption } from 'echarts'
import { chartColors } from '@/lib/designTokens'
import { OwnerChart } from './OwnerChart'
import { rollupSalesByPeriod } from '../lib/rollupSalesByPeriod'
import type { SalesByLocationReport } from '../api/ownerReportsApi'

interface SalesTrendChartProps {
  data: SalesByLocationReport[]
  isLoading?: boolean
  isError?: boolean
}

export function SalesTrendChart({ data, isLoading = false, isError = false }: SalesTrendChartProps) {
  const { t } = useTranslation(['reports'])
  const series = useMemo(() => rollupSalesByPeriod(data), [data])

  const option: EChartsOption = {
    color: [chartColors.primary],
    tooltip: { trigger: 'axis' as const },
    grid: { left: '3%', right: '4%', bottom: '3%', containLabel: true },
    xAxis: { type: 'category' as const, data: series.map((p) => p.period) },
    yAxis: { type: 'value' as const },
    series: [{ type: 'line' as const, smooth: true, data: series.map((p) => p.total) }],
  }

  return (
    <OwnerChart
      title={t('reports:ownerDashboard.salesTrend.title')}
      option={option}
      isLoading={isLoading}
      isError={isError}
      isEmpty={series.length === 0}
    />
  )
}
