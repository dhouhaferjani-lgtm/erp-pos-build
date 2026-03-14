import { useTranslation } from 'react-i18next'
import type { CustomerAnalytics } from '../../api/analyticsApi'

interface CustomerInsightsPanelProps {
  data: CustomerAnalytics
}

function formatCurrency(value: string): string {
  return Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

export function CustomerInsightsPanel({ data }: CustomerInsightsPanelProps) {
  const { t } = useTranslation(['pos'])

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-3 gap-4">
        <div className="rounded-lg border bg-card p-4">
          <p className="text-sm text-muted-foreground">{t('pos:analytics.uniqueCustomers')}</p>
          <p className="mt-1 text-2xl font-semibold">{data.unique_customers}</p>
        </div>
        <div className="rounded-lg border bg-card p-4">
          <p className="text-sm text-muted-foreground">{t('pos:analytics.returningCustomers')}</p>
          <p className="mt-1 text-2xl font-semibold">{data.returning_count}</p>
        </div>
        <div className="rounded-lg border bg-card p-4">
          <p className="text-sm text-muted-foreground">{t('pos:analytics.returningRate')}</p>
          <p className="mt-1 text-2xl font-semibold">{data.returning_rate}%</p>
        </div>
      </div>

      {data.top_customers.length > 0 && (
        <div className="rounded-lg border bg-card p-4">
          <h3 className="mb-4 text-lg font-medium">{t('pos:analytics.topCustomers')}</h3>
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b text-left text-muted-foreground">
                <th className="py-2 pr-4">#</th>
                <th className="py-2 pr-4">{t('pos:analytics.customer')}</th>
                <th className="py-2 pr-4 text-right">{t('pos:analytics.receipts')}</th>
                <th className="py-2 text-right">{t('pos:analytics.totalSpent')}</th>
              </tr>
            </thead>
            <tbody>
              {data.top_customers.map((c, i) => (
                <tr key={c.partner_id} className="border-b last:border-0">
                  <td className="py-2 pr-4 text-muted-foreground">{i + 1}</td>
                  <td className="py-2 pr-4 font-medium">{c.customer_name}</td>
                  <td className="py-2 pr-4 text-right">{c.receipt_count}</td>
                  <td className="py-2 text-right">{formatCurrency(c.total_spent)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
