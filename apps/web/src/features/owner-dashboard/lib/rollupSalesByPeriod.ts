import type { SalesByLocationReport } from '../api/ownerReportsApi'

// Number() here is the ECharts coordinate boundary only (mirrors SalesByLocationChart); displayed money stays server-string.
export function rollupSalesByPeriod(rows: SalesByLocationReport[]): { period: string; total: number }[] {
  const byPeriod = new Map<string, number>()
  for (const row of rows) {
    byPeriod.set(row.period, (byPeriod.get(row.period) ?? 0) + Number(row.gross_sales))
  }
  return [...byPeriod.entries()].sort(([a], [b]) => a.localeCompare(b)).map(([period, total]) => ({ period, total }))
}
