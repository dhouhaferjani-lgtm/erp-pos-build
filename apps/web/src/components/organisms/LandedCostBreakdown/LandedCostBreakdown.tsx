import { useTranslation } from 'react-i18next'
import { useCompany } from '../../../hooks/useCompany'

export interface LineAllocation {
  lineId: string
  productName: string
  description?: string
  quantity: number
  unitPrice: number
  lineTotal: number
  allocatedCosts: number
  landedUnitCost: number
  proportion: number
}

export interface LandedCostBreakdownProps {
  lines: LineAllocation[]
  currency?: string
  showProportion?: boolean
}

export function LandedCostBreakdown({
  lines,
  currency,
  showProportion = true,
}: LandedCostBreakdownProps) {
  const { t } = useTranslation(['inventory'])
  const { currentCompany } = useCompany()
  const effectiveCurrency = currency ?? currentCompany?.currency ?? 'USD'

  const formatCurrency = (value: number): string => {
    return new Intl.NumberFormat('fr-TN', {
      style: 'currency',
      currency: effectiveCurrency,
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(value)
  }

  const formatPercent = (value: number): string => {
    return `${(value * 100).toFixed(1)}%`
  }

  const totalAllocated = lines.reduce((sum, l) => sum + l.allocatedCosts, 0)
  const totalLineValue = lines.reduce((sum, l) => sum + l.lineTotal, 0)

  if (lines.length === 0) {
    return (
      <div className="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800/50">
        <p className="text-center text-sm text-gray-500 dark:text-gray-400">
          {t('inventory:landedCost.noAdditionalCosts')}
        </p>
      </div>
    )
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h3 className="text-sm font-medium text-gray-900 dark:text-gray-100">
          {t('inventory:landedCost.breakdown')}
        </h3>
        <span className="text-sm text-gray-500 dark:text-gray-400">
          {t('inventory:landedCost.additionalCosts')}: {formatCurrency(totalAllocated)}
        </span>
      </div>

      <div className="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
        <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
          <thead className="bg-gray-50 dark:bg-gray-800">
            <tr>
              <th className="px-4 py-2 text-start text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                {t('inventory:products.name')}
              </th>
              <th className="px-4 py-2 text-end text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                {t('inventory:stock.quantity')}
              </th>
              <th className="px-4 py-2 text-end text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                {t('inventory:landedCost.purchasePrice')}
              </th>
              {showProportion && (
                <th className="px-4 py-2 text-end text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                  {t('inventory:landedCost.proportion')}
                </th>
              )}
              <th className="px-4 py-2 text-end text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                {t('inventory:landedCost.allocatedCosts')}
              </th>
              <th className="px-4 py-2 text-end text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                {t('inventory:landedCost.landedUnitCost')}
              </th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-900">
            {lines.map((line) => (
              <tr key={line.lineId} className="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                <td className="px-4 py-3">
                  <div className="text-sm font-medium text-gray-900 dark:text-gray-100">
                    {line.productName}
                  </div>
                  {line.description && (
                    <div className="text-xs text-gray-500 dark:text-gray-400">
                      {line.description}
                    </div>
                  )}
                </td>
                <td className="px-4 py-3 text-end text-sm text-gray-600 dark:text-gray-400">
                  {line.quantity}
                </td>
                <td className="px-4 py-3 text-end text-sm text-gray-600 dark:text-gray-400">
                  {formatCurrency(line.unitPrice)}
                </td>
                {showProportion && (
                  <td className="px-4 py-3 text-end text-sm text-gray-500 dark:text-gray-400">
                    {formatPercent(line.proportion)}
                  </td>
                )}
                <td className="px-4 py-3 text-end text-sm text-blue-600 dark:text-blue-400">
                  +{formatCurrency(line.allocatedCosts)}
                </td>
                <td className="px-4 py-3 text-end text-sm font-semibold text-gray-900 dark:text-gray-100">
                  {formatCurrency(line.landedUnitCost)}
                </td>
              </tr>
            ))}
          </tbody>
          <tfoot className="bg-gray-50 dark:bg-gray-800">
            <tr>
              <td className="px-4 py-2 text-sm font-medium text-gray-900 dark:text-gray-100">
                Total
              </td>
              <td className="px-4 py-2 text-end text-sm text-gray-600 dark:text-gray-400">
                {lines.reduce((sum, l) => sum + l.quantity, 0)}
              </td>
              <td className="px-4 py-2 text-end text-sm text-gray-600 dark:text-gray-400">
                {formatCurrency(totalLineValue)}
              </td>
              {showProportion && (
                <td className="px-4 py-2 text-end text-sm text-gray-500 dark:text-gray-400">
                  100%
                </td>
              )}
              <td className="px-4 py-2 text-end text-sm font-semibold text-blue-600 dark:text-blue-400">
                +{formatCurrency(totalAllocated)}
              </td>
              <td className="px-4 py-2 text-end text-sm font-semibold text-gray-900 dark:text-gray-100">
                {formatCurrency(totalLineValue + totalAllocated)}
              </td>
            </tr>
          </tfoot>
        </table>
      </div>

      <div className="rounded-lg bg-blue-50 p-3 dark:bg-blue-900/20">
        <div className="flex items-center justify-between text-sm">
          <span className="text-blue-700 dark:text-blue-300">
            {t('inventory:landedCost.title')}
          </span>
          <span className="font-semibold text-blue-900 dark:text-blue-100">
            {formatCurrency(totalLineValue + totalAllocated)}
          </span>
        </div>
      </div>
    </div>
  )
}

export default LandedCostBreakdown
