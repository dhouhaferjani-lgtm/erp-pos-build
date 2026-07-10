import { useTranslation } from 'react-i18next'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface VatSummaryCardsProps {
  outputVat: string
  inputVat: string
  creditBroughtForward: string
  amountPayable: string
}

function formatAmount(value: string): string {
  return new Intl.NumberFormat('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(parseFloat(value))
}

interface StatCardProps {
  label: string
  amount: string
  colorClass: string
}

function StatCard({ label, amount, colorClass }: StatCardProps) {
  return (
    <div className={`rounded-lg border ${colorTokens.border.subtle} bg-white p-4 shadow-sm`}>
      <p className={`text-sm font-medium ${colorTokens.text.subtle}`}>{label}</p>
      <p className={`mt-1 text-2xl font-semibold ${colorClass}`}>
        {formatAmount(amount)}
      </p>
    </div>
  )
}

export function VatSummaryCards({
  outputVat,
  inputVat,
  creditBroughtForward,
  amountPayable,
}: VatSummaryCardsProps) {
  const { t } = useTranslation('finance')

  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <StatCard
        label={t('finance:vatReporting.summary.outputVat')}
        amount={outputVat}
        colorClass={colorTokens.intent.danger.text}
      />
      <StatCard
        label={t('finance:vatReporting.summary.inputVat')}
        amount={inputVat}
        colorClass={colorTokens.intent.success.text}
      />
      <StatCard
        label={t('finance:vatReporting.summary.creditBroughtForward')}
        amount={creditBroughtForward}
        colorClass={colorTokens.intent.primary.text}
      />
      <StatCard
        label={t('finance:vatReporting.summary.amountPayable')}
        amount={amountPayable}
        colorClass={parseFloat(amountPayable) > 0 ? `${colorTokens.intent.danger.text}` : `${colorTokens.intent.success.text}`}
      />
    </div>
  )
}
