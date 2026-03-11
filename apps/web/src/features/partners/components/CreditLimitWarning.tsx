import { useTranslation } from 'react-i18next'
import { AlertTriangle } from 'lucide-react'

interface CreditLimitWarningProps {
  creditLimit: string | null
  outstandingBalance: string | null
  thresholdPercentage?: number
}

export function CreditLimitWarning({
  creditLimit,
  outstandingBalance,
  thresholdPercentage = 80,
}: CreditLimitWarningProps) {
  const { t } = useTranslation('sales')

  if (!creditLimit || !outstandingBalance) {
    return null
  }

  const limit = parseFloat(creditLimit)
  const outstanding = parseFloat(outstandingBalance)

  if (limit <= 0 || outstanding <= 0) {
    return null
  }

  const usagePercentage = (outstanding / limit) * 100
  const isExceeded = outstanding >= limit
  const isApproaching = usagePercentage >= thresholdPercentage

  if (!isApproaching && !isExceeded) {
    return null
  }

  const severity = isExceeded ? 'error' : 'warning'

  return (
    <div
      className={`flex items-start gap-3 rounded-lg border p-4 ${
        severity === 'error'
          ? 'border-red-200 bg-red-50 text-red-800'
          : 'border-amber-200 bg-amber-50 text-amber-800'
      }`}
      role="alert"
    >
      <AlertTriangle
        className={`h-5 w-5 flex-shrink-0 ${
          severity === 'error' ? 'text-red-500' : 'text-amber-500'
        }`}
      />
      <div>
        <p className="font-medium">
          {isExceeded
            ? t('partners.b2b.creditLimitExceeded')
            : t('partners.b2b.creditLimitApproaching')}
        </p>
        <p className="mt-1 text-sm opacity-90">
          {t('partners.b2b.creditLimitUsage', {
            outstanding: parseFloat(outstandingBalance).toLocaleString(undefined, {
              minimumFractionDigits: 2,
              maximumFractionDigits: 2,
            }),
            limit: parseFloat(creditLimit).toLocaleString(undefined, {
              minimumFractionDigits: 2,
              maximumFractionDigits: 2,
            }),
            percentage: Math.round(usagePercentage),
          })}
        </p>
      </div>
    </div>
  )
}
