import { useTranslation } from 'react-i18next'
import type { SalesSummary } from '../../api/analyticsApi'

interface SalesSummaryCardsProps {
  data: SalesSummary
}

function formatCurrency(value: string): string {
  return Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

export function SalesSummaryCards({ data }: SalesSummaryCardsProps) {
  const { t } = useTranslation(['pos'])

  const cards = [
    { label: t('pos:analytics.totalReceipts'), value: String(data.receipt_count), isCurrency: false },
    { label: t('pos:analytics.grossSales'), value: formatCurrency(data.gross_sales), isCurrency: true },
    { label: t('pos:analytics.netSales'), value: formatCurrency(data.net_sales), isCurrency: true },
    { label: t('pos:analytics.taxTotal'), value: formatCurrency(data.tax_total), isCurrency: true },
    { label: t('pos:analytics.averageTicket'), value: formatCurrency(data.average_ticket), isCurrency: true },
    { label: t('pos:analytics.refunds'), value: `${data.refund_count} (${formatCurrency(data.refund_total)})`, isCurrency: false },
    { label: t('pos:analytics.voided'), value: String(data.voided_count), isCurrency: false },
  ]

  return (
    <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
      {cards.map((card) => (
        <div key={card.label} className="rounded-lg border bg-card p-4">
          <p className="text-sm text-muted-foreground">{card.label}</p>
          <p className="mt-1 text-2xl font-semibold">{card.value}</p>
        </div>
      ))}
    </div>
  )
}
