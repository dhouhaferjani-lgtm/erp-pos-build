import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import type { EChartsOption } from 'echarts'
import { chartColors } from '@/lib/designTokens'
import { OwnerChart } from './OwnerChart'
import { rollupSalesByPeriod } from '../lib/rollupSalesByPeriod'
import type { SalesByLocationReport } from '../api/ownerReportsApi'

interface SalesTrendChartProps {
  data: SalesByLocationReport[]
  comparisonData?: SalesByLocationReport[]
  granularity?: 'hour' | 'day' | 'week' | 'month'
  isLoading?: boolean
  isError?: boolean
}

export function SalesTrendChart({
  data,
  comparisonData = [],
  granularity = 'day',
  isLoading = false,
  isError = false,
}: SalesTrendChartProps) {
  const { t } = useTranslation(['reports'])
  const series = useMemo(() => {
    if (granularity !== 'hour') {
      return {
        periods: rollupSalesByPeriod(data).map((point) => point.period),
        current: rollupSalesByPeriod(data).map((point) => point.total),
        comparison: [],
      }
    }

    return buildHourlySeries(data, comparisonData)
  }, [comparisonData, data, granularity])

  const option: EChartsOption = {
    color: [chartColors.primary, chartColors.neutral],
    tooltip: { trigger: 'axis' as const },
    legend: { top: 0 },
    grid: { left: '3%', right: '4%', bottom: '3%', containLabel: true },
    xAxis: { type: 'category' as const, data: series.periods },
    yAxis: { type: 'value' as const },
    series: [
      {
        name: t('reports:ownerDashboard.salesTrend.today'),
        type: 'line' as const,
        smooth: true,
        data: series.current,
        z: 2,
      },
      ...(granularity === 'hour'
        ? [{
            name: t('reports:ownerDashboard.salesTrend.sameWeekdayLastWeek'),
            type: 'line' as const,
            smooth: true,
            data: series.comparison,
            lineStyle: { type: 'dashed' as const },
            z: 1,
          }]
        : []),
    ],
  }

  return (
    <OwnerChart
      title={t('reports:ownerDashboard.salesTrend.title')}
      option={option}
      isLoading={isLoading}
      isError={isError}
      isEmpty={data.length === 0 && comparisonData.length === 0}
    />
  )
}

function buildHourlySeries(data: SalesByLocationReport[], comparisonData: SalesByLocationReport[]) {
  const currentPoints = rollupSalesByPeriod(data, { granularity: 'hour' })
  const comparisonPoints = rollupSalesByPeriod(comparisonData, { granularity: 'hour' })
  const firstNonEmptyIndex = currentPoints.findIndex((point, index) => point.total !== 0 || comparisonPoints[index]?.total !== 0)

  if (firstNonEmptyIndex === -1) {
    return {
      periods: currentPoints.map((point) => point.period),
      current: currentPoints.map((point) => point.total),
      comparison: comparisonPoints.map((point) => point.total),
    }
  }

  let lastNonEmptyIndex = firstNonEmptyIndex
  for (let index = currentPoints.length - 1; index >= firstNonEmptyIndex; index -= 1) {
    if (currentPoints[index]?.total !== 0 || comparisonPoints[index]?.total !== 0) {
      lastNonEmptyIndex = index
      break
    }
  }
  const slicedCurrent = currentPoints.slice(firstNonEmptyIndex, lastNonEmptyIndex + 1)
  const slicedComparison = comparisonPoints.slice(firstNonEmptyIndex, lastNonEmptyIndex + 1)

  return {
    periods: slicedCurrent.map((point) => point.period),
    current: slicedCurrent.map((point) => point.total),
    comparison: slicedComparison.map((point) => point.total),
  }
}
