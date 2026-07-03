import { describe, expect, it } from 'vitest'
import { rollupSalesByPeriod } from '../rollupSalesByPeriod'
import type { SalesByLocationReport } from '../../api/ownerReportsApi'

const rows: SalesByLocationReport[] = [
  { period: '2026-06-01', company_id: 'c', company_name: 'C', location_id: 'a', location_name: 'A', gross_sales: '100', receipt_count: 1 },
  { period: '2026-06-01', company_id: 'c', company_name: 'C', location_id: 'b', location_name: 'B', gross_sales: '50', receipt_count: 1 },
  { period: '2026-06-02', company_id: 'c', company_name: 'C', location_id: 'a', location_name: 'A', gross_sales: '200', receipt_count: 1 },
]

describe('rollupSalesByPeriod', () => {
  it('sums gross sales per period across locations', () => {
    expect(rollupSalesByPeriod(rows)).toEqual([
      { period: '2026-06-01', total: 150 },
      { period: '2026-06-02', total: 200 },
    ])
  })

  it('sums gross sales into business-hour buckets', () => {
    const hourlyRows: SalesByLocationReport[] = [
      { period: '2026-07-03T08:00:00Z', company_id: 'c', company_name: 'C', location_id: 'a', location_name: 'A', gross_sales: '75.500', receipt_count: 1 },
      { period: '2026-07-03 08:00', company_id: 'c', company_name: 'C', location_id: 'b', location_name: 'B', gross_sales: '24.500', receipt_count: 1 },
      { period: '2026-07-03T10:00:00Z', company_id: 'c', company_name: 'C', location_id: 'a', location_name: 'A', gross_sales: '10.000', receipt_count: 1 },
    ]

    expect(rollupSalesByPeriod(hourlyRows, { granularity: 'hour' })).toEqual([
      { period: '08:00', total: 100 },
      { period: '09:00', total: 0 },
      { period: '10:00', total: 10 },
      { period: '11:00', total: 0 },
      { period: '12:00', total: 0 },
      { period: '13:00', total: 0 },
      { period: '14:00', total: 0 },
      { period: '15:00', total: 0 },
      { period: '16:00', total: 0 },
      { period: '17:00', total: 0 },
      { period: '18:00', total: 0 },
      { period: '19:00', total: 0 },
      { period: '20:00', total: 0 },
    ])
  })
})
