import ReactECharts from 'echarts-for-react'
import type { EChartsOption } from 'echarts'
import { useTranslation } from 'react-i18next'
import { borderColors, colors, spacing, textColors } from '@/lib/designTokens'
import type { ReactNode } from 'react'

interface OwnerChartProps {
  title: string
  option: EChartsOption
  isLoading?: boolean
  isError?: boolean
  isEmpty?: boolean
  height?: number
  headerAction?: ReactNode
}

export function OwnerChart({ title, option, isLoading = false, isError = false, isEmpty = false, height = 320, headerAction }: OwnerChartProps) {
  const { t } = useTranslation(['reports'])

  return (
    <section className={`rounded-lg border ${borderColors.light} ${colors.white} ${spacing.md}`}>
      <div className="mb-4 flex items-center justify-between gap-3">
        <h3 className={`text-lg font-medium ${textColors.primary}`}>{title}</h3>
        {headerAction}
      </div>
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
