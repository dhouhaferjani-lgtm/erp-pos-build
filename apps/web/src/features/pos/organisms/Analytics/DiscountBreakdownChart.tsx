import ReactECharts from 'echarts-for-react'
import { useTranslation } from 'react-i18next'
import type { DiscountAnalysis } from '../../api/analyticsApi'

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
        <div className="rounded-lg border bg-card p-4">
          <p className="text-sm text-muted-foreground">{t('pos:analytics.totalDiscounts')}</p>
          <p className="mt-1 text-2xl font-semibold">{formatCurrency(data.total_discount_amount)}</p>
        </div>
        <div className="rounded-lg border bg-card p-4">
          <p className="text-sm text-muted-foreground">{t('pos:analytics.discountedLines')}</p>
          <p className="mt-1 text-2xl font-semibold">{data.discount_count}</p>
        </div>
      </div>

      {data.by_reason.length > 0 && (
        <div className="rounded-lg border bg-card p-4">
          <h3 className="mb-4 text-lg font-medium">{t('pos:analytics.discountsByReason')}</h3>
          <ReactECharts option={option} style={{ height: 300 }} />
        </div>
      )}

      {data.top_discounted_products.length > 0 && (
        <div className="rounded-lg border bg-card p-4">
          <h3 className="mb-4 text-lg font-medium">{t('pos:analytics.topDiscountedProducts')}</h3>
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b text-left text-muted-foreground">
                <th className="py-2 pr-4">{t('pos:analytics.product')}</th>
                <th className="py-2 pr-4 text-right">{t('pos:analytics.quantity')}</th>
                <th className="py-2 text-right">{t('pos:analytics.discountAmount')}</th>
              </tr>
            </thead>
            <tbody>
              {data.top_discounted_products.map((p) => (
                <tr key={p.product_name} className="border-b last:border-0">
                  <td className="py-2 pr-4">{p.product_name}</td>
                  <td className="py-2 pr-4 text-right">{p.quantity}</td>
                  <td className="py-2 text-right">{formatCurrency(p.discount_amount)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
