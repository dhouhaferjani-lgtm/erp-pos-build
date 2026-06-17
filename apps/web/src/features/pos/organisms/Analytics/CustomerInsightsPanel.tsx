import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import { colors, textColors, borderColors } from '@/lib/designTokens'
import type { CustomerAnalytics } from '../../api/analyticsApi'

interface CustomerInsightsPanelProps {
  data: CustomerAnalytics
}

const cardSurface = cn('rounded-lg border p-4', borderColors.light, colors.white)

function formatCurrency(value: string): string {
  return Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

export function CustomerInsightsPanel({ data }: CustomerInsightsPanelProps) {
  const { t } = useTranslation(['pos'])

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-3 gap-4">
        <div className={cardSurface}>
          <p className={cn('text-sm', textColors.tertiary)}>{t('pos:analytics.uniqueCustomers')}</p>
          <p className={cn('mt-1 text-2xl font-semibold', textColors.primary)}>{data.unique_customers}</p>
        </div>
        <div className={cardSurface}>
          <p className={cn('text-sm', textColors.tertiary)}>{t('pos:analytics.returningCustomers')}</p>
          <p className={cn('mt-1 text-2xl font-semibold', textColors.primary)}>{data.returning_count}</p>
        </div>
        <div className={cardSurface}>
          <p className={cn('text-sm', textColors.tertiary)}>{t('pos:analytics.returningRate')}</p>
          <p className={cn('mt-1 text-2xl font-semibold', textColors.primary)}>{data.returning_rate}%</p>
        </div>
      </div>

      {data.top_customers.length > 0 && (
        <div className={cardSurface}>
          <h3 className={cn('mb-4 text-lg font-medium', textColors.primary)}>{t('pos:analytics.topCustomers')}</h3>
          <table className="w-full text-sm">
            <thead>
              <tr className={cn('border-b text-left', borderColors.light, textColors.tertiary)}>
                <th className="py-2 pr-4">#</th>
                <th className="py-2 pr-4">{t('pos:analytics.customer')}</th>
                <th className="py-2 pr-4 text-right">{t('pos:analytics.receipts')}</th>
                <th className="py-2 text-right">{t('pos:analytics.totalSpent')}</th>
              </tr>
            </thead>
            <tbody>
              {data.top_customers.map((c, i) => (
                <tr key={c.partner_id} className={cn('border-b last:border-0', borderColors.light)}>
                  <td className={cn('py-2 pr-4', textColors.tertiary)}>{i + 1}</td>
                  <td className={cn('py-2 pr-4 font-medium', textColors.primary)}>{c.customer_name}</td>
                  <td className={cn('py-2 pr-4 text-right', textColors.primary)}>{c.receipt_count}</td>
                  <td className={cn('py-2 text-right', textColors.primary)}>{formatCurrency(c.total_spent)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
