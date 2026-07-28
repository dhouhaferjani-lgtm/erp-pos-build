import { useTranslation } from 'react-i18next'
import { useCompany } from '../../../hooks/useCompany'
import { formatQuantity } from '../../../lib/decimal'
import { formatPercent } from '../../../lib/format'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { getQuantityDecimals } from '@/lib/quantityScale'

export interface LineAllocation {
  lineId: string
  productName: string
  description?: string
  quantity: number
  quantity_decimals: number
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

  const totalAllocated = lines.reduce((sum, l) => sum + l.allocatedCosts, 0)
  const totalLineValue = lines.reduce((sum, l) => sum + l.lineTotal, 0)
  const totalQuantityDecimals = lines.reduce(
    (maximum, line) => Math.max(maximum, getQuantityDecimals(line)),
    0,
  )

  if (lines.length === 0) {
    return (
      <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.page} p-4 ${colorTokens.variants.darkBorderGray700} ${colorTokens.variants.darkBgGray800Alpha50}`}>
        <p className={`text-center text-sm ${colorTokens.text.subtle} ${colorTokens.variants.darkTextGray400}`}>
          {t('inventory:landedCost.noAdditionalCosts')}
        </p>
      </div>
    )
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h3 className={`text-sm font-medium ${colorTokens.text.primary} ${colorTokens.variants.darkTextGray100}`}>
          {t('inventory:landedCost.breakdown')}
        </h3>
        <span className={`text-sm ${colorTokens.text.subtle} ${colorTokens.variants.darkTextGray400}`}>
          {t('inventory:landedCost.additionalCosts')}: {formatCurrency(totalAllocated)}
        </span>
      </div>

      <div className={`overflow-hidden rounded-lg border ${colorTokens.border.subtle} ${colorTokens.variants.darkBorderGray700}`}>
        <table className={`min-w-full divide-y ${colorTokens.border.divider} ${colorTokens.variants.darkDivideGray700}`}>
          <thead className={`${colorTokens.surface.page} ${colorTokens.variants.darkBgGray800}`}>
            <tr>
              <th className={`px-4 py-2 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle} ${colorTokens.variants.darkTextGray400}`}>
                {t('inventory:products.name')}
              </th>
              <th className={`px-4 py-2 text-end text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle} ${colorTokens.variants.darkTextGray400}`}>
                {t('inventory:stock.quantity')}
              </th>
              <th className={`px-4 py-2 text-end text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle} ${colorTokens.variants.darkTextGray400}`}>
                {t('inventory:landedCost.purchasePrice')}
              </th>
              {showProportion && (
                <th className={`px-4 py-2 text-end text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle} ${colorTokens.variants.darkTextGray400}`}>
                  {t('inventory:landedCost.proportion')}
                </th>
              )}
              <th className={`px-4 py-2 text-end text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle} ${colorTokens.variants.darkTextGray400}`}>
                {t('inventory:landedCost.allocatedCosts')}
              </th>
              <th className={`px-4 py-2 text-end text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle} ${colorTokens.variants.darkTextGray400}`}>
                {t('inventory:landedCost.landedUnitCost')}
              </th>
            </tr>
          </thead>
          <tbody className={`divide-y ${colorTokens.border.divider} ${colorTokens.surface.base} ${colorTokens.variants.darkDivideGray700} ${colorTokens.variants.darkBgGray900}`}>
            {lines.map((line) => (
              <tr key={line.lineId} className={`${colorTokens.variants.hoverBgGray50} ${colorTokens.variants.darkHoverBgGray800Alpha50}`}>
                <td className="px-4 py-3">
                  <div className={`text-sm font-medium ${colorTokens.text.primary} ${colorTokens.variants.darkTextGray100}`}>
                    {line.productName}
                  </div>
                  {line.description && (
                    <div className={`text-xs ${colorTokens.text.subtle} ${colorTokens.variants.darkTextGray400}`}>
                      {line.description}
                    </div>
                  )}
                </td>
                <td className={`px-4 py-3 text-end text-sm ${colorTokens.text.muted} ${colorTokens.variants.darkTextGray400}`}>
                  {formatQuantity(line.quantity, getQuantityDecimals(line))}
                </td>
                <td className={`px-4 py-3 text-end text-sm ${colorTokens.text.muted} ${colorTokens.variants.darkTextGray400}`}>
                  {formatCurrency(line.unitPrice)}
                </td>
                {showProportion && (
                  <td className={`px-4 py-3 text-end text-sm ${colorTokens.text.subtle} ${colorTokens.variants.darkTextGray400}`}>
                    {formatPercent(line.proportion * 100)}
                  </td>
                )}
                <td className={`px-4 py-3 text-end text-sm ${colorTokens.intent.primary.text} ${colorTokens.variants.darkTextBlue400}`}>
                  +{formatCurrency(line.allocatedCosts)}
                </td>
                <td className={`px-4 py-3 text-end text-sm font-semibold ${colorTokens.text.primary} ${colorTokens.variants.darkTextGray100}`}>
                  {formatCurrency(line.landedUnitCost)}
                </td>
              </tr>
            ))}
          </tbody>
          <tfoot className={`${colorTokens.surface.page} ${colorTokens.variants.darkBgGray800}`}>
            <tr>
              <td className={`px-4 py-2 text-sm font-medium ${colorTokens.text.primary} ${colorTokens.variants.darkTextGray100}`}>
                {t('inventory:landedCost.total')}
              </td>
              <td className={`px-4 py-2 text-end text-sm ${colorTokens.text.muted} ${colorTokens.variants.darkTextGray400}`}>
                {formatQuantity(
                  lines.reduce((sum, l) => sum + l.quantity, 0),
                  totalQuantityDecimals,
                )}
              </td>
              <td className={`px-4 py-2 text-end text-sm ${colorTokens.text.muted} ${colorTokens.variants.darkTextGray400}`}>
                {formatCurrency(totalLineValue)}
              </td>
              {showProportion && (
                <td className={`px-4 py-2 text-end text-sm ${colorTokens.text.subtle} ${colorTokens.variants.darkTextGray400}`}>
                  100%
                </td>
              )}
              <td className={`px-4 py-2 text-end text-sm font-semibold ${colorTokens.intent.primary.text} ${colorTokens.variants.darkTextBlue400}`}>
                +{formatCurrency(totalAllocated)}
              </td>
              <td className={`px-4 py-2 text-end text-sm font-semibold ${colorTokens.text.primary} ${colorTokens.variants.darkTextGray100}`}>
                {formatCurrency(totalLineValue + totalAllocated)}
              </td>
            </tr>
          </tfoot>
        </table>
      </div>

      <div className={`rounded-lg ${colorTokens.intent.primary.bgSubtle} p-3 ${colorTokens.variants.darkBgBlue900Alpha20}`}>
        <div className="flex items-center justify-between text-sm">
          <span className={`${colorTokens.intent.primary.textStrong} ${colorTokens.variants.darkTextBlue300}`}>
            {t('inventory:landedCost.title')}
          </span>
          <span className={`font-semibold ${colorTokens.intent.primary.textStrongest} ${colorTokens.variants.darkTextBlue100}`}>
            {formatCurrency(totalLineValue + totalAllocated)}
          </span>
        </div>
      </div>
    </div>
  )
}

export default LandedCostBreakdown
