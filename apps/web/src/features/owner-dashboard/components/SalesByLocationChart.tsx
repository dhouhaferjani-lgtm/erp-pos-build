import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import type { EChartsOption } from 'echarts'
import { chartCategoricalKeys, chartColors } from '@/lib/designTokens'
import { toChartNumber } from '../lib/rollupSalesByPeriod'
import { OwnerChart } from './OwnerChart'
import type { SalesByLocationReport } from '../api/ownerReportsApi'

interface SalesByLocationChartProps {
  data: SalesByLocationReport[]
  isLoading?: boolean
  isError?: boolean
}

export function SalesByLocationChart({ data, isLoading = false, isError = false }: SalesByLocationChartProps) {
  const { t } = useTranslation(['reports'])

  const periods = useMemo(() => Array.from(new Set(data.map((row) => row.period))), [data])
  const locations = useMemo(() => Array.from(new Set(data.map((row) => row.location_name))), [data])

  const option: EChartsOption = {
    color: chartCategoricalKeys.map((key) => chartColors[key]),
    tooltip: { trigger: 'axis' as const },
    legend: { top: 0 },
    grid: { left: '3%', right: '4%', bottom: '3%', containLabel: true },
    xAxis: { type: 'category' as const, data: periods },
    yAxis: { type: 'value' as const, name: t('reports:ownerDashboard.salesByLocation.axis') },
    series: locations.map((location) => ({
      name: location,
      type: 'bar' as const,
      data: periods.map((period) => toChartNumber(data.find((row) => row.period === period && row.location_name === location)?.gross_sales ?? '0')),
    })),
  }

  return (
    <OwnerChart
      title={t('reports:ownerDashboard.salesByLocation.title')}
      option={option}
      isLoading={isLoading}
      isError={isError}
      isEmpty={data.length === 0}
    />
  )
}
