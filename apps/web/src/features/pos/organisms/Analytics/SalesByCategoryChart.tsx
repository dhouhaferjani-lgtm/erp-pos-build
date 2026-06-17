import ReactECharts from 'echarts-for-react'
import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import { colors, textColors, borderColors } from '@/lib/designTokens'
import type { CategorySales } from '../../api/analyticsApi'

interface SalesByCategoryChartProps {
  data: CategorySales[]
}

export function SalesByCategoryChart({ data }: SalesByCategoryChartProps) {
  const { t } = useTranslation(['pos'])

  const option = {
    tooltip: {
      trigger: 'item' as const,
      formatter: '{b}: {c} ({d}%)',
    },
    legend: {
      orient: 'vertical' as const,
      right: 10,
      top: 'center',
    },
    series: [
      {
        type: 'pie',
        radius: ['40%', '70%'],
        avoidLabelOverlap: false,
        label: { show: false },
        emphasis: {
          label: { show: true, fontSize: 14, fontWeight: 'bold' },
        },
        data: data.map((d) => ({
          name: d.category_name,
          value: Number(d.total),
        })),
      },
    ],
  }

  return (
    <div className={cn('rounded-lg border p-4', borderColors.light, colors.white)}>
      <h3 className={cn('mb-4 text-lg font-medium', textColors.primary)}>{t('pos:analytics.salesByCategory')}</h3>
      {data.length === 0 ? (
        <p className={cn('py-8 text-center', textColors.tertiary)}>{t('pos:analytics.noData')}</p>
      ) : (
        <ReactECharts option={option} style={{ height: 350 }} />
      )}
    </div>
  )
}
