import { useTranslation } from 'react-i18next'

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
    <div className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
      <p className="text-sm font-medium text-gray-500">{label}</p>
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
        colorClass="text-red-600"
      />
      <StatCard
        label={t('finance:vatReporting.summary.inputVat')}
        amount={inputVat}
        colorClass="text-green-600"
      />
      <StatCard
        label={t('finance:vatReporting.summary.creditBroughtForward')}
        amount={creditBroughtForward}
        colorClass="text-blue-600"
      />
      <StatCard
        label={t('finance:vatReporting.summary.amountPayable')}
        amount={amountPayable}
        colorClass={parseFloat(amountPayable) > 0 ? 'text-red-600' : 'text-green-600'}
      />
    </div>
  )
}
