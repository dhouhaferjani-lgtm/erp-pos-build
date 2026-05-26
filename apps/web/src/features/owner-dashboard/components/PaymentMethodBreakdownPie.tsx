import { useTranslation } from 'react-i18next'
import type { EChartsOption } from 'echarts'
import { borderColors, chartColors, colors, textColors } from '@/lib/designTokens'
import { OwnerChart } from './OwnerChart'
import type { PaymentMethodBreakdownReport } from '../api/ownerReportsApi'

interface PaymentMethodBreakdownPieProps {
  data: PaymentMethodBreakdownReport[]
  mode: 'amount' | 'percentage'
  onModeChange: (mode: 'amount' | 'percentage') => void
  isLoading?: boolean
  isError?: boolean
}

export function PaymentMethodBreakdownPie({ data, mode, onModeChange, isLoading = false, isError = false }: PaymentMethodBreakdownPieProps) {
  const { t } = useTranslation(['reports'])

  const option: EChartsOption = {
    color: [chartColors.success, chartColors.primary, chartColors.warning, chartColors.violet, chartColors.cyan],
    tooltip: { trigger: 'item' as const, formatter: mode === 'percentage' ? '{b}: {c}%' : '{b}: {c} ({d}%)' },
    legend: { bottom: 0 },
    series: [
      {
        name: t('reports:ownerDashboard.paymentMethods.title'),
        type: 'pie' as const,
        radius: '68%',
        data: data.map((row) => ({ name: row.payment_method_name, value: Number(mode === 'percentage' ? row.percentage : row.amount) })),
      },
    ],
  }

  return (
    <div>
      <div className={`mb-3 inline-flex rounded-md border ${borderColors.default}`}>
        <button
          type="button"
          onClick={() => {
            onModeChange('amount')
          }}
          className={`px-3 py-1 text-sm ${mode === 'amount' ? `${colors.primary[600]} ${textColors.inverse}` : `${colors.white} ${textColors.secondary}`}`}
        >
          {t('reports:ownerDashboard.paymentMethods.amount')}
        </button>
        <button
          type="button"
          onClick={() => {
            onModeChange('percentage')
          }}
          className={`px-3 py-1 text-sm ${mode === 'percentage' ? `${colors.primary[600]} ${textColors.inverse}` : `${colors.white} ${textColors.secondary}`}`}
        >
          {t('reports:ownerDashboard.paymentMethods.percentage')}
        </button>
      </div>
      <OwnerChart
        title={t('reports:ownerDashboard.paymentMethods.title')}
        option={option}
        isLoading={isLoading}
        isError={isError}
        isEmpty={data.length === 0}
      />
    </div>
  )
}
