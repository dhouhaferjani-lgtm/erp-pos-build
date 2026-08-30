import { useTranslation } from 'react-i18next'
import { AlertTriangle } from 'lucide-react'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { bcadd, bccomp, bcdiv, bcmul, formatCurrency } from '@/lib/decimal'

interface CreditLimitWarningProps {
  creditLimit: string | null
  outstandingBalance: string | null
  currency: string
  thresholdPercentage?: number
}

export function CreditLimitWarning({
  creditLimit,
  outstandingBalance,
  currency,
  thresholdPercentage = 80,
}: CreditLimitWarningProps) {
  const { t } = useTranslation('sales')

  if (!creditLimit || !outstandingBalance) {
    return null
  }

  if (bccomp(creditLimit, '0') <= 0 || bccomp(outstandingBalance, '0') <= 0) {
    return null
  }

  const usagePercentage = bcmul(bcdiv(outstandingBalance, creditLimit, 6), '100', 6)
  const thresholdComparisonLeft = bcmul(outstandingBalance, '100', 6)
  const thresholdComparisonRight = bcmul(creditLimit, String(thresholdPercentage), 6)
  const isExceeded = bccomp(outstandingBalance, creditLimit) >= 0
  const isApproaching = bccomp(thresholdComparisonLeft, thresholdComparisonRight) >= 0

  // Round half-up (bcadd inherits Big.RM = 1) so a usage of 79.5 reads as
  // "80" beside an 80% threshold instead of truncating to "79". A balance
  // that is still strictly below the limit is capped at 99 so it can never
  // read as "100% used" while the copy says "approaching".
  const roundedPercentage = bcadd(usagePercentage, '0', 0)
  const displayedPercentage =
    !isExceeded && bccomp(roundedPercentage, '100') >= 0 ? '99' : roundedPercentage

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
            outstanding: formatCurrency(outstandingBalance, true, currency),
            limit: formatCurrency(creditLimit, true, currency),
            percentage: displayedPercentage,
          })}
        </p>
      </div>
    </div>
  )
}
