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
})
