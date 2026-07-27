import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { EChartsOption } from 'echarts'
import { Button } from '@/components/atoms'
import { borderColors, chartCategoricalKeys, chartColors, colors, textColors } from '@/lib/designTokens'
import { OwnerChart } from './OwnerChart'
import { rollupSalesByPeriod } from '../lib/rollupSalesByPeriod'
import type { SalesByLocationReport } from '../api/ownerReportsApi'

interface SalesTrendChartProps {
  data: SalesByLocationReport[]
  comparisonData?: SalesByLocationReport[]
  granularity?: 'hour' | 'day' | 'week' | 'month'
  isLoading?: boolean
  isError?: boolean
  seriesMode?: 'rollup' | 'per-location'
}

interface TrendSeries {
  periods: string[]
  current: number[]
  comparison: number[]
  perLocation?: Array<{ name: string; data: number[] }>
}

export function SalesTrendChart({
  data,
  comparisonData = [],
  granularity = 'day',
  isLoading = false,
  isError = false,
  seriesMode = 'rollup',
}: SalesTrendChartProps) {
  const { t } = useTranslation(['reports'])
  const [mode, setMode] = useState<'rollup' | 'per-location'>(seriesMode)
  const series = useMemo<TrendSeries>(() => {
    if (mode === 'per-location') {
      return buildPerLocationSeries(data, granularity)
    }
    if (granularity !== 'hour') {
      return {
        periods: rollupSalesByPeriod(data).map((point) => point.period),
        current: rollupSalesByPeriod(data).map((point) => point.total),
        comparison: [],
      }
    }

    return buildHourlySeries(data, comparisonData)
  }, [comparisonData, data, granularity, mode])

  const option: EChartsOption = {
    color: mode === 'per-location'
      ? chartCategoricalKeys.map((key) => chartColors[key])
      : [chartColors.primary, chartColors.neutral],
    tooltip: { trigger: 'axis' as const },
    legend: { top: 0 },
    grid: { left: '3%', right: '4%', bottom: '3%', containLabel: true },
    xAxis: { type: 'category' as const, data: series.periods },
    yAxis: { type: 'value' as const },
    series: mode === 'per-location'
      ? (series.perLocation ?? []).map((line) => ({
          name: line.name,
          type: 'line' as const,
          smooth: true,
          data: line.data,
        }))
      : [
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
      headerAction={(
        <div className={`flex rounded-md border ${borderColors.light} p-0.5`} role="group" aria-label={t('reports:ownerDashboard.salesTrend.seriesMode.label')}>
          {(['rollup', 'per-location'] as const).map((value) => (
            <Button
              key={value}
              type="button"
              size="xs"
              variant={mode === value ? 'secondary' : 'ghost'}
              onClick={() => setMode(value)}
              className={`rounded px-2 py-1 text-xs ${mode === value ? `${textColors.primary} ${colors.white}` : textColors.tertiary}`}
              aria-pressed={mode === value}
            >
              {t(`reports:ownerDashboard.salesTrend.seriesMode.${value}`)}
            </Button>
          ))}
        </div>
      )}
    />
  )
}

function buildPerLocationSeries(data: SalesByLocationReport[], granularity: SalesTrendChartProps['granularity']): {
  periods: string[]
  current: number[]
  comparison: number[]
  perLocation: Array<{ name: string; data: number[] }>
} {
  const locations = [...new Map(data.map((row) => [row.location_id, row.location_name])).entries()]
  const groups = locations.map(([locationId, locationName]) => ({
    name: locationName,
    points: rollupSalesByPeriod(data.filter((row) => row.location_id === locationId), { granularity: granularity ?? 'day' }),
  }))
  const periods = [...new Set(groups.flatMap((group) => group.points.map((point) => point.period)))].sort()

  return {
    periods,
    current: [],
    comparison: [],
    perLocation: groups.map((group) => ({
      name: group.name,
      data: periods.map((period) => group.points.find((point) => point.period === period)?.total ?? 0),
    })),
  }
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
