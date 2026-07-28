import ReactECharts from 'echarts-for-react'
import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import { colors, textColors, borderColors } from '@/lib/designTokens'
import { formatQuantity } from '@/lib/decimal'
import { getQuantityDecimals } from '@/lib/quantityScale'
import type { DiscountAnalysis } from '../../api/analyticsApi'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

interface DiscountBreakdownChartProps {
  data: DiscountAnalysis
}

function formatCurrency(value: string): string {
  return Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

export function DiscountBreakdownChart({ data }: DiscountBreakdownChartProps) {
  const { t } = useTranslation(['pos'])

  const option = {
    tooltip: { trigger: 'axis' as const, axisPointer: { type: 'shadow' as const } },
    xAxis: {
      type: 'category' as const,
      data: data.by_reason.map((d) => d.reason),
      axisLabel: { rotate: 30 },
    },
    yAxis: { type: 'value' as const },
    series: [
      {
        name: t('pos:analytics.discountAmount'),
        type: 'bar',
        data: data.by_reason.map((d) => Number(d.total_amount)),
        itemStyle: { color: '#f59e0b' },
      },
    ],
    grid: { left: '3%', right: '4%', bottom: '15%', containLabel: true },
  }

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-4">
        <div className={cn('rounded-lg border p-4', borderColors.light, colors.white)}>
          <p className={cn('text-sm', textColors.tertiary)}>{t('pos:analytics.totalDiscounts')}</p>
          <p className={cn('mt-1 text-2xl font-semibold', textColors.primary)}>{formatCurrency(data.total_discount_amount)}</p>
        </div>
        <div className={cn('rounded-lg border p-4', borderColors.light, colors.white)}>
          <p className={cn('text-sm', textColors.tertiary)}>{t('pos:analytics.discountedLines')}</p>
          <p className={cn('mt-1 text-2xl font-semibold', textColors.primary)}>{data.discount_count}</p>
        </div>
      </div>

      {data.by_reason.length > 0 && (
        <div className={cn('rounded-lg border p-4', borderColors.light, colors.white)}>
          <h3 className={cn('mb-4 text-lg font-medium', textColors.primary)}>{t('pos:analytics.discountsByReason')}</h3>
          <ReactECharts option={option} style={{ height: 300 }} />
        </div>
      )}

      {data.top_discounted_products.length > 0 && (
        <div className={cn('rounded-lg border p-4', borderColors.light, colors.white)}>
          <h3 className={cn('mb-4 text-lg font-medium', textColors.primary)}>{t('pos:analytics.topDiscountedProducts')}</h3>
          <DataTable className="w-full text-sm">
            <thead>
              <tr className={cn('border-b text-left', borderColors.light, textColors.tertiary)}>
                <th className="py-2 pr-4">{t('pos:analytics.product')}</th>
                <th className="py-2 pr-4 text-right">{t('pos:analytics.quantity')}</th>
                <th className="py-2 text-right">{t('pos:analytics.discountAmount')}</th>
              </tr>
            </thead>
            <tbody>
              {data.top_discounted_products.map((p) => (
                <tr key={`${p.product_id ?? 'legacy'}:${p.product_name}`} className={cn('border-b last:border-0', borderColors.light)}>
                  <td className={cn('py-2 pr-4', textColors.primary)}>{p.product_name}</td>
                  <td className={cn('py-2 pr-4 text-right', textColors.primary)}>
                    {formatQuantity(p.quantity, getQuantityDecimals(p))}
                  </td>
                  <td className={cn('py-2 text-right', textColors.primary)}>{formatCurrency(p.discount_amount)}</td>
                </tr>
              ))}
            </tbody>
          </DataTable>
        </div>
      )}
    </div>
  )
}
