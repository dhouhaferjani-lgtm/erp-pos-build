import { useTranslation } from 'react-i18next'
import type { EChartsOption } from 'echarts'
import { chartColors } from '@/lib/designTokens'
import { OwnerChart } from './OwnerChart'
import type { CategoryRevenueReport } from '../api/ownerReportsApi'

interface RevenueByCategoryDonutProps {
  data: CategoryRevenueReport[]
  isLoading?: boolean
  isError?: boolean
}

export function RevenueByCategoryDonut({ data, isLoading = false, isError = false }: RevenueByCategoryDonutProps) {
  const { t } = useTranslation(['reports'])

  const option: EChartsOption = {
    color: [chartColors.primary, chartColors.success, chartColors.warning, chartColors.cyan, chartColors.violet, chartColors.neutral],
    tooltip: { trigger: 'item' as const, formatter: '{b}: {c} ({d}%)' },
    legend: { orient: 'vertical' as const, right: 10, top: 'center' },
    series: [
      {
        type: 'pie' as const,
        radius: ['42%', '70%'],
        avoidLabelOverlap: false,
        label: { show: false },
        emphasis: { label: { show: true, fontSize: 14, fontWeight: 'bold' } },
        data: data.map((row) => ({ name: row.category_name, value: Number(row.revenue) })),
      },
    ],
  }

  return (
    <OwnerChart
      title={t('reports:ownerDashboard.revenueByCategory.title')}
      option={option}
      isLoading={isLoading}
      isError={isError}
      isEmpty={data.length === 0}
    />
  )
}
