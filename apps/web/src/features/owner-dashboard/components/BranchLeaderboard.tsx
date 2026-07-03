import { useMemo } from 'react'
import Big from 'big.js'
import { useTranslation } from 'react-i18next'
import { borderColors, colors, spacing, textColors } from '@/lib/designTokens'
import { formatCurrency } from '@/lib/decimal'
import { useLiveSales, useSalesByLocation } from '../hooks/useOwnerReports'
import type { SalesByLocationReport } from '../api/ownerReportsApi'

interface BranchLeaderboardProps {
  canFetch?: boolean
  currency?: string
}

interface BranchLeaderboardEntry {
  locationId: string
  locationName: string
  total: Big
  delta: BranchDelta | null
  isActive: boolean
}

interface BranchDelta {
  direction: 'up' | 'down'
  label: string
}

export function BranchLeaderboard({ canFetch = true, currency = 'TND' }: BranchLeaderboardProps) {
  const { t } = useTranslation(['reports'])
  const today = formatDateInput(new Date())
  const comparisonDay = shiftDateInput(today, -7)
  const todaySales = useSalesByLocation({ from: today, to: today, granularity: 'day' }, canFetch)
  const comparisonSales = useSalesByLocation({ from: comparisonDay, to: comparisonDay, granularity: 'day' }, canFetch)
  const liveSales = useLiveSales(canFetch)

  const entries = useMemo(
    () => buildLeaderboardEntries(
      todaySales.data ?? [],
      comparisonSales.data ?? [],
      liveSales.data?.open_shifts_by_location ?? {},
    ),
    [comparisonSales.data, liveSales.data?.open_shifts_by_location, todaySales.data],
  )

  return (
    <section className={`rounded-lg border ${borderColors.light} ${colors.white} ${spacing.md}`}>
      <h3 className={`mb-4 text-lg font-medium ${textColors.primary}`}>{t('reports:ownerDashboard.branchLeaderboard.title')}</h3>
      {todaySales.isLoading ? (
        <p className={`py-8 text-center ${textColors.tertiary}`}>{t('reports:ownerDashboard.loading')}</p>
      ) : todaySales.isError ? (
        <p className={`py-8 text-center ${textColors.error}`}>{t('reports:ownerDashboard.error')}</p>
      ) : entries.length === 0 ? (
        <p className={`py-8 text-center ${textColors.tertiary}`}>{t('reports:ownerDashboard.noData')}</p>
      ) : (
        <ol className="space-y-3">
          {entries.map((entry, index) => (
            <li
              key={entry.locationId}
              data-testid="branch-leaderboard-row"
              className={`flex items-center justify-between gap-3 rounded-md border ${borderColors.light} ${colors.neutral[50]} p-3`}
            >
              <div className="min-w-0">
                <div className="flex items-center gap-2">
                  <span className={`text-xs font-semibold ${textColors.tertiary}`}>#{index + 1}</span>
                  <span
                    aria-label={entry.isActive
                      ? t('reports:ownerDashboard.branchLeaderboard.active')
                      : t('reports:ownerDashboard.branchLeaderboard.inactive')}
                    className={`h-2.5 w-2.5 shrink-0 rounded-full ${entry.isActive ? colors.success[600] : colors.neutral[300]}`}
                  />
                  <p className={`truncate text-sm font-medium ${textColors.primary}`}>{entry.locationName}</p>
                </div>
                <p className={`mt-1 text-xs ${entry.delta?.direction === 'down' ? textColors.error : textColors.success}`}>
                  {formatDelta(entry.delta)}
                </p>
              </div>
              <p className={`shrink-0 text-sm font-semibold ${textColors.primary}`}>
                {formatCurrency(entry.total.toFixed(3), true, currency)}
              </p>
            </li>
          ))}
        </ol>
      )}
    </section>
  )
}

function buildLeaderboardEntries(
  todayRows: SalesByLocationReport[],
  comparisonRows: SalesByLocationReport[],
  openShiftsByLocation: Record<string, number>,
): BranchLeaderboardEntry[] {
  const todayTotals = sumByLocation(todayRows)
  const comparisonTotals = sumByLocation(comparisonRows)

  return [...todayTotals.values()]
    .map((entry) => {
      const comparisonTotal = comparisonTotals.get(entry.locationId)?.total ?? new Big(0)
      return {
        ...entry,
        delta: calculateDelta(entry.total, comparisonTotal),
        isActive: (openShiftsByLocation[entry.locationId] ?? 0) > 0,
      }
    })
    .sort((a, b) => b.total.cmp(a.total))
}

function sumByLocation(rows: SalesByLocationReport[]): Map<string, { locationId: string; locationName: string; total: Big }> {
  const byLocation = new Map<string, { locationId: string; locationName: string; total: Big }>()

  for (const row of rows) {
    const existing = byLocation.get(row.location_id)
    byLocation.set(row.location_id, {
      locationId: row.location_id,
      locationName: row.location_name,
      total: (existing?.total ?? new Big(0)).plus(toBig(row.gross_sales)),
    })
  }

  return byLocation
}

function calculateDelta(today: Big, baseline: Big): BranchDelta | null {
  if (baseline.eq(0)) {
    return null
  }

  const percent = today.minus(baseline).div(baseline).times(100)
  const direction = percent.gte(0) ? 'up' : 'down'

  return {
    direction,
    label: percent.abs().toFixed(1),
  }
}

function formatDelta(delta: BranchDelta | null): string {
  if (delta === null) {
    return '—'
  }

  return `${delta.direction === 'up' ? '▲' : '▼'} ${delta.label}%`
}

function toBig(value: string): Big {
  try {
    return new Big(value)
  } catch {
    return new Big(0)
  }
}

function shiftDateInput(value: string, days: number): string {
  const date = new Date(`${value}T00:00:00`)
  date.setDate(date.getDate() + days)

  return formatDateInput(date)
}

function formatDateInput(date: Date): string {
  const year = String(date.getFullYear())
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')

  return `${year}-${month}-${day}`
}
