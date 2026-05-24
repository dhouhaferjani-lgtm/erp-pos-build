import ReactECharts from 'echarts-for-react'
import type { EChartsOption } from 'echarts'
import { useTranslation } from 'react-i18next'
import { borderColors, colors, spacing, textColors } from '@/lib/designTokens'

interface OwnerChartProps {
  title: string
  option: EChartsOption
  isLoading?: boolean
  isError?: boolean
  isEmpty?: boolean
  height?: number
}

export function OwnerChart({ title, option, isLoading = false, isError = false, isEmpty = false, height = 320 }: OwnerChartProps) {
  const { t } = useTranslation(['reports'])

  return (
    <section className={`rounded-lg border ${borderColors.light} ${colors.white} ${spacing.md}`}>
      <h3 className={`mb-4 text-lg font-medium ${textColors.primary}`}>{title}</h3>
      {isLoading ? (
        <p className={`py-8 text-center ${textColors.tertiary}`}>{t('reports:ownerDashboard.loading')}</p>
      ) : isError ? (
        <p className={`py-8 text-center ${textColors.error}`}>{t('reports:ownerDashboard.error')}</p>
      ) : isEmpty ? (
        <p className={`py-8 text-center ${textColors.tertiary}`}>{t('reports:ownerDashboard.noData')}</p>
      ) : (
        <ReactECharts option={option} style={{ height }} />
      )}
    </section>
  )
}
