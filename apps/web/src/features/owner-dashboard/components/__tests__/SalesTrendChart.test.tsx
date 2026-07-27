import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { SalesTrendChart } from '../SalesTrendChart'
import type { SalesByLocationReport } from '../../api/ownerReportsApi'

interface CapturedChartOption {
  xAxis: { data: string[] }
  series: {
    name: string
    data: number[]
    lineStyle?: { type?: string }
  }[]
}

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

vi.mock('../OwnerChart', () => ({
  OwnerChart: ({ option }: { option: unknown }) => (
    <div data-testid="trend-chart-option" data-option={JSON.stringify(option)} />
  ),
}))

const todayRows: SalesByLocationReport[] = [
  { period: '2026-07-03T09:00:00Z', company_id: 'c', company_name: 'C', location_id: 'loc-1', location_name: 'Lac 2', gross_sales: '120.000', receipt_count: 2 },
]

const lastWeekRows: SalesByLocationReport[] = [
  { period: '2026-06-26T10:00:00Z', company_id: 'c', company_name: 'C', location_id: 'loc-1', location_name: 'Lac 2', gross_sales: '80.000', receipt_count: 1 },
]

describe('SalesTrendChart', () => {
  it('renders today and same-weekday-last-week hourly series with a dashed comparison line', () => {
    render(
      <SalesTrendChart
        data={todayRows}
        comparisonData={lastWeekRows}
        granularity="hour"
        isLoading={false}
        isError={false}
      />,
    )

    const option = readCapturedOption()

    expect(option.xAxis.data).toEqual(['09:00', '10:00'])
    expect(option.series).toEqual([
      expect.objectContaining({
        name: 'reports:ownerDashboard.salesTrend.today',
        data: [120, 0],
      }),
      expect.objectContaining({
        name: 'reports:ownerDashboard.salesTrend.sameWeekdayLastWeek',
        data: [0, 80],
        lineStyle: { type: 'dashed' },
      }),
    ])
  })

  it('renders one line per location when requested', () => {
    render(
      <SalesTrendChart
        data={[...todayRows, { ...todayRows[0], location_id: 'loc-2', location_name: 'Lac 1', period: '2026-07-03T10:00:00Z', gross_sales: '80.000' }]}
        granularity="day"
        seriesMode="per-location"
      />,
    )

    const option = readCapturedOption()
    expect(option.series.map((item) => item.name)).toEqual(['Lac 2', 'Lac 1'])
  })
})

function readCapturedOption(): CapturedChartOption {
  const parsed: unknown = JSON.parse(screen.getByTestId('trend-chart-option').getAttribute('data-option') ?? '{}')
  if (!isCapturedChartOption(parsed)) {
    throw new Error('Unexpected chart option shape')
  }

  return parsed
}

function isCapturedChartOption(value: unknown): value is CapturedChartOption {
  if (!isRecord(value) || !isRecord(value['xAxis']) || !Array.isArray(value['xAxis']['data']) || !Array.isArray(value['series'])) {
    return false
  }

  return value['xAxis']['data'].every((item) => typeof item === 'string')
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}
