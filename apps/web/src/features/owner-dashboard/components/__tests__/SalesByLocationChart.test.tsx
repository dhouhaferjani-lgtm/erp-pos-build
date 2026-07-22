import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { SalesByLocationChart } from '../SalesByLocationChart'
import type { SalesByLocationReport } from '../../api/ownerReportsApi'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('../OwnerChart', () => ({
  OwnerChart: ({ option, isEmpty }: { option: unknown; isEmpty: boolean }) => (
    <div data-testid="sales-by-location-chart" data-option={JSON.stringify(option)} data-empty={String(isEmpty)} />
  ),
}))

describe('SalesByLocationChart', () => {
  it('renders one grouped bar series per location and preserves decimal money at the chart boundary', () => {
    const rows: SalesByLocationReport[] = [
      { period: '2026-07-01', company_id: 'company', company_name: 'Company', location_id: 'l1', location_name: 'L1', gross_sales: '1234567.899', receipt_count: 1 },
      { period: '2026-07-02', company_id: 'company', company_name: 'Company', location_id: 'l1', location_name: 'L1', gross_sales: '2.000', receipt_count: 1 },
      { period: '2026-07-01', company_id: 'company', company_name: 'Company', location_id: 'l2', location_name: 'L2', gross_sales: '3.500', receipt_count: 1 },
    ]

    render(<SalesByLocationChart data={rows} />)

    const option = JSON.parse(screen.getByTestId('sales-by-location-chart').getAttribute('data-option') ?? '{}') as {
      series: Array<{ name: string; data: number[] }>
    }
    expect(option.series.map((series) => series.name)).toEqual(['L1', 'L2'])
    expect(option.series[0]?.data).toEqual([1234567.899, 2])
    expect(option.series[1]?.data).toEqual([3.5, 0])
  })

  it('marks an empty dataset for the chart empty state', () => {
    render(<SalesByLocationChart data={[]} />)

    expect(screen.getByTestId('sales-by-location-chart')).toHaveAttribute('data-empty', 'true')
  })
})
