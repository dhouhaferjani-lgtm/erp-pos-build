import Big from 'big.js'
import type { SalesByLocationReport } from '../api/ownerReportsApi'

interface RollupSalesByPeriodOptions {
  granularity?: 'hour' | 'day' | 'week' | 'month'
}

interface SalesPeriodTotal {
  period: string
  total: number
}

const businessHourLabels = Array.from({ length: 13 }, (_, index) => `${String(index + 8).padStart(2, '0')}:00`)

export function rollupSalesByPeriod(rows: SalesByLocationReport[], options: RollupSalesByPeriodOptions = {}): SalesPeriodTotal[] {
  if (options.granularity === 'hour') {
    return rollupHourlySales(rows)
  }

  const byPeriod = new Map<string, Big>()
  for (const row of rows) {
    byPeriod.set(row.period, (byPeriod.get(row.period) ?? new Big(0)).plus(toBig(row.gross_sales)))
  }
  return [...byPeriod.entries()]
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([period, total]) => ({ period, total: total.toNumber() }))
}

function rollupHourlySales(rows: SalesByLocationReport[]): SalesPeriodTotal[] {
  const byHour = new Map<string, Big>(businessHourLabels.map((label) => [label, new Big(0)]))

  for (const row of rows) {
    const hour = extractHourLabel(row.period)
    if (hour === null || !byHour.has(hour)) {
      continue
    }
    byHour.set(hour, (byHour.get(hour) ?? new Big(0)).plus(toBig(row.gross_sales)))
  }

  return businessHourLabels.map((period) => ({ period, total: (byHour.get(period) ?? new Big(0)).toNumber() }))
}

function extractHourLabel(period: string): string | null {
  const match = /(?:T|\s|^)(\d{2}):/.exec(period)
  if (!match) {
    return null
  }

  return `${match[1]}:00`
}

function toBig(value: string): Big {
  try {
    return new Big(value)
  } catch {
    return new Big(0)
  }
}

export function toChartNumber(value: string): number {
  return toBig(value).toNumber()
}
