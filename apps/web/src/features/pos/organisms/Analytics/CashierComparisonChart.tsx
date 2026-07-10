import ReactECharts from 'echarts-for-react'
import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import { colors, textColors, borderColors } from '@/lib/designTokens'
import type { CashierPerformance } from '../../api/analyticsApi'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

interface CashierComparisonChartProps {
  data: CashierPerformance[]
}

export function CashierComparisonChart({ data }: CashierComparisonChartProps) {
  const { t } = useTranslation(['pos'])

  const sorted = [...data].sort((a, b) => Number(b.total_sales) - Number(a.total_sales))

  const option = {
    tooltip: {
      trigger: 'axis' as const,
      axisPointer: { type: 'shadow' as const },
    },
    legend: {},
    grid: { left: '3%', right: '4%', bottom: '3%', containLabel: true },
    xAxis: { type: 'value' as const },
    yAxis: {
      type: 'category' as const,
      data: sorted.map((d) => d.cashier_name),
      inverse: true,
    },
    series: [
      {
        name: t('pos:analytics.totalSales'),
        type: 'bar',
        data: sorted.map((d) => Number(d.total_sales)),
        label: { show: true, position: 'right' as const },
      },
    ],
  }

  return (
    <div className={cn('rounded-lg border p-4', borderColors.light, colors.white)}>
      <h3 className={cn('mb-4 text-lg font-medium', textColors.primary)}>{t('pos:analytics.cashierPerformance')}</h3>
      {data.length === 0 ? (
        <p className={cn('py-8 text-center', textColors.tertiary)}>{t('pos:analytics.noData')}</p>
      ) : (
        <>
          <ReactECharts option={option} style={{ height: Math.max(250, sorted.length * 50) }} />
          <div className="mt-4 overflow-x-auto">
            <DataTable className="w-full text-sm">
              <thead>
                <tr className={cn('border-b text-left', borderColors.light, textColors.tertiary)}>
                  <th className="py-2 pr-4">{t('pos:analytics.cashier')}</th>
                  <th className="py-2 pr-4 text-right">{t('pos:analytics.receipts')}</th>
                  <th className="py-2 pr-4 text-right">{t('pos:analytics.totalSales')}</th>
                  <th className="py-2 text-right">{t('pos:analytics.averageTicket')}</th>
                </tr>
              </thead>
              <tbody>
                {sorted.map((c) => (
                  <tr key={c.cashier_id} className={cn('border-b last:border-0', borderColors.light)}>
                    <td className={cn('py-2 pr-4 font-medium', textColors.primary)}>{c.cashier_name}</td>
                    <td className={cn('py-2 pr-4 text-right', textColors.primary)}>{c.receipt_count}</td>
                    <td className={cn('py-2 pr-4 text-right', textColors.primary)}>{Number(c.total_sales).toLocaleString(undefined, { minimumFractionDigits: 2 })}</td>
                    <td className={cn('py-2 text-right', textColors.primary)}>{Number(c.average_ticket).toLocaleString(undefined, { minimumFractionDigits: 2 })}</td>
                  </tr>
                ))}
              </tbody>
            </DataTable>
          </div>
        </>
      )}
    </div>
  )
}
