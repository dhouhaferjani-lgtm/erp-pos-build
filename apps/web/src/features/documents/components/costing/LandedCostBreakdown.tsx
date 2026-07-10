import { Package, DollarSign, TrendingUp } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useCurrency } from '@/hooks/useCurrency'
import { colorClasses } from '@/lib/designTokens'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

interface DocumentLine {
  id: string
  description: string
  quantity: number
  unit_price: number
  total: number
  allocated_costs?: number
  landed_unit_cost?: number
}

interface LandedCostBreakdownProps {
  lines: DocumentLine[]
  totalAdditionalCosts: number
}

export function LandedCostBreakdown({ lines, totalAdditionalCosts }: LandedCostBreakdownProps) {
  const { t } = useTranslation(['documents'])
  const { decimals } = useCurrency()
  const subtotal = lines.reduce((sum, line) => sum + Number(line.total), 0)
  const grandTotal = subtotal + totalAdditionalCosts

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h3 className={`text-sm font-medium ${colorClasses.textGray900}`}>{t('documents:costing.landedCost.title')}</h3>
        <div className={`text-xs ${colorClasses.textGray500}`}>
          {t('documents:costing.landedCost.allocationMethod')}
        </div>
      </div>

      {/* Summary Cards */}
      <div className="grid grid-cols-3 gap-4">
        <div className={`rounded-lg border ${colorClasses.borderGray200} bg-white p-4`}>
          <div className="flex items-center gap-2">
            <Package className={`h-4 w-4 ${colorClasses.textBlue600}`} />
            <span className={`text-xs font-medium ${colorClasses.textGray600}`}>{t('documents:costing.landedCost.productsSubtotal')}</span>
          </div>
          <p className={`mt-2 text-lg font-semibold ${colorClasses.textGray900}`}>
            ${subtotal.toFixed(decimals)}
          </p>
        </div>

        <div className={`rounded-lg border ${colorClasses.borderGray200} bg-white p-4`}>
          <div className="flex items-center gap-2">
            <DollarSign className={`h-4 w-4 ${colorClasses.textOrange600}`} />
            <span className={`text-xs font-medium ${colorClasses.textGray600}`}>{t('documents:costing.additionalCosts.title')}</span>
          </div>
          <p className={`mt-2 text-lg font-semibold ${colorClasses.textGray900}`}>
            ${totalAdditionalCosts.toFixed(decimals)}
          </p>
        </div>

        <div className={`rounded-lg border ${colorClasses.borderBlue200} ${colorClasses.bgBlue50} p-4`}>
          <div className="flex items-center gap-2">
            <TrendingUp className={`h-4 w-4 ${colorClasses.textBlue600}`} />
            <span className={`text-xs font-medium ${colorClasses.textBlue700}`}>{t('documents:costing.landedCost.totalLandedCost')}</span>
          </div>
          <p className={`mt-2 text-lg font-semibold ${colorClasses.textBlue900}`}>
            ${grandTotal.toFixed(decimals)}
          </p>
        </div>
      </div>

      {/* Line-by-Line Breakdown */}
      <div className={`overflow-hidden rounded-lg border ${colorClasses.borderGray200}`}>
        <DataTable className={`min-w-full divide-y ${colorClasses.divideGray200}`}>
          <thead className={`${colorClasses.bgGray50}`}>
            <tr>
              <th className={`px-4 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                {t('documents:costing.landedCost.columns.product')}
              </th>
              <th className={`px-4 py-3 text-end text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                {t('documents:costing.landedCost.columns.qty')}
              </th>
              <th className={`px-4 py-3 text-end text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                {t('documents:costing.landedCost.columns.unitPrice')}
              </th>
              <th className={`px-4 py-3 text-end text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                {t('documents:costing.landedCost.columns.lineTotal')}
              </th>
              <th className={`px-4 py-3 text-end text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                {t('documents:costing.landedCost.columns.percentOfTotal')}
              </th>
              <th className={`px-4 py-3 text-end text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                {t('documents:costing.landedCost.columns.allocatedCost')}
              </th>
              <th className={`px-4 py-3 text-end text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                {t('documents:costing.landedCost.columns.landedUnitCost')}
              </th>
            </tr>
          </thead>
          <tbody className={`divide-y ${colorClasses.divideGray200} bg-white`}>
            {lines.map((line) => {
              const percentage = subtotal > 0 ? (Number(line.total) / subtotal) * 100 : 0
              const allocatedCost = Number(line.allocated_costs || 0)
              const landedUnitCost = Number(line.landed_unit_cost || line.unit_price)

              return (
                <tr key={line.id} className={`${colorClasses.hoverBgGray50}`}>
                  <td className={`px-4 py-3 text-sm ${colorClasses.textGray900}`}>{line.description}</td>
                  <td className={`px-4 py-3 text-end text-sm ${colorClasses.textGray900}`}>
                    {line.quantity}
                  </td>
                  <td className={`px-4 py-3 text-end text-sm ${colorClasses.textGray900}`}>
                    ${Number(line.unit_price).toFixed(decimals)}
                  </td>
                  <td className={`px-4 py-3 text-end text-sm ${colorClasses.textGray900}`}>
                    ${Number(line.total).toFixed(decimals)}
                  </td>
                  <td className={`px-4 py-3 text-end text-sm ${colorClasses.textGray600}`}>
                    {percentage.toFixed(1)}%
                  </td>
                  <td className={`px-4 py-3 text-end text-sm font-medium ${colorClasses.textOrange600}`}>
                    ${allocatedCost.toFixed(decimals)}
                  </td>
                  <td className={`px-4 py-3 text-end text-sm font-semibold ${colorClasses.textBlue600}`}>
                    ${landedUnitCost.toFixed(decimals)}
                  </td>
                </tr>
              )
            })}
          </tbody>
          <tfoot className={`${colorClasses.bgGray50}`}>
            <tr>
              <td colSpan={3} className={`px-4 py-3 text-end text-sm font-medium ${colorClasses.textGray900}`}>
                {t('documents:costing.landedCost.totals')}
              </td>
              <td className={`px-4 py-3 text-end text-sm font-semibold ${colorClasses.textGray900}`}>
                ${subtotal.toFixed(decimals)}
              </td>
              <td className={`px-4 py-3 text-end text-sm ${colorClasses.textGray600}`}>
                100.0%
              </td>
              <td className={`px-4 py-3 text-end text-sm font-semibold ${colorClasses.textOrange600}`}>
                ${totalAdditionalCosts.toFixed(decimals)}
              </td>
              <td className={`px-4 py-3 text-end text-sm font-bold ${colorClasses.textBlue600}`}>
                ${grandTotal.toFixed(decimals)}
              </td>
            </tr>
          </tfoot>
        </DataTable>
      </div>

      <div className={`rounded-lg ${colorClasses.bgBlue50} p-4`}>
        <p className={`text-xs ${colorClasses.textBlue800}`}>
          <strong>{t('documents:costing.landedCost.noteLabel')}</strong>{' '}
          {t('documents:costing.landedCost.noteText')}
        </p>
      </div>
    </div>
  )
}
