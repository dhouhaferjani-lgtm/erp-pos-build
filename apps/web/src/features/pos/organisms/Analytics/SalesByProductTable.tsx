import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import { colors, textColors, borderColors } from '@/lib/designTokens'
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
    <div className={cn('rounded-lg border p-4', borderColors.light, colors.white)}>
      <h3 className={cn('mb-4 text-lg font-medium', textColors.primary)}>{t('pos:analytics.topProducts')}</h3>
      {data.length === 0 ? (
        <p className={cn('py-8 text-center', textColors.tertiary)}>{t('pos:analytics.noData')}</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className={cn('border-b text-left', borderColors.light, textColors.tertiary)}>
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
                  <tr key={product.product_name} className={cn('border-b last:border-0', borderColors.light)}>
                    <td className={cn('py-2 pr-4', textColors.tertiary)}>{index + 1}</td>
                    <td className={cn('py-2 pr-4 font-medium', textColors.primary)}>{product.product_name}</td>
                    <td className={cn('py-2 pr-4 text-right', textColors.primary)}>{Number(product.quantity).toLocaleString()}</td>
                    <td className={cn('py-2 pr-4 text-right font-medium', textColors.primary)}>{formatCurrency(product.total)}</td>
                    <td className="py-2">
                      <div className={cn('h-2 w-full rounded-full', colors.neutral[100])}>
                        <div
                          className={cn('h-2 rounded-full', colors.primary[600])}
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
