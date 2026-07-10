import { useTranslation } from 'react-i18next'
import { AlertTriangle } from 'lucide-react'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

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
          ? `${colorTokens.intent.danger.borderSubtle} ${colorTokens.intent.danger.bgSubtle} ${colorTokens.intent.danger.textStronger}`
          : `${colorTokens.intent.caution.borderSubtle} ${colorTokens.intent.caution.bgSubtle} ${colorTokens.intent.caution.textStronger}`
      }`}
      role="alert"
    >
      <AlertTriangle
        className={`h-5 w-5 flex-shrink-0 ${
          severity === 'error' ? colorTokens.intent.danger.textSubtle : colorTokens.intent.caution.textSubtle
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
