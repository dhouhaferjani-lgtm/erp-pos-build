import { useTranslation } from 'react-i18next'
import type { ProductSales } from '../../api/analyticsApi'

interface SalesByProductTableProps {
  data: ProductSales[]
}

function formatCurrency(value: string): string {
  return Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

export function SalesByProductTable({ data }: SalesByProductTableProps) {
  const { t } = useTranslation(['pos'])

  const maxTotal = Math.max(...data.map((d) => Number(d.total)), 1)

  return (
    <div className="rounded-lg border bg-card p-4">
      <h3 className="mb-4 text-lg font-medium">{t('pos:analytics.topProducts')}</h3>
      {data.length === 0 ? (
        <p className="py-8 text-center text-muted-foreground">{t('pos:analytics.noData')}</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b text-left text-muted-foreground">
                <th className="py-2 pr-4">#</th>
                <th className="py-2 pr-4">{t('pos:analytics.product')}</th>
                <th className="py-2 pr-4 text-right">{t('pos:analytics.quantity')}</th>
                <th className="py-2 pr-4 text-right">{t('pos:analytics.total')}</th>
                <th className="w-40 py-2" />
              </tr>
            </thead>
            <tbody>
              {data.map((product, index) => {
                const pct = (Number(product.total) / maxTotal) * 100
                return (
                  <tr key={product.product_name} className="border-b last:border-0">
                    <td className="py-2 pr-4 text-muted-foreground">{index + 1}</td>
                    <td className="py-2 pr-4 font-medium">{product.product_name}</td>
                    <td className="py-2 pr-4 text-right">{Number(product.quantity).toLocaleString()}</td>
                    <td className="py-2 pr-4 text-right font-medium">{formatCurrency(product.total)}</td>
                    <td className="py-2">
                      <div className="h-2 w-full rounded-full bg-muted">
                        <div
                          className="bg-primary h-2 rounded-full"
                          style={{ width: `${pct}%` }}
                        />
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
