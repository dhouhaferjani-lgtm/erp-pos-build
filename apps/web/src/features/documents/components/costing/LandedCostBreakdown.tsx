import { Package, DollarSign, TrendingUp } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useCurrency } from '@/hooks/useCurrency'

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
        <h3 className="text-sm font-medium text-gray-900">{t('documents:costing.landedCost.title')}</h3>
        <div className="text-xs text-gray-500">
          {t('documents:costing.landedCost.allocationMethod')}
        </div>
      </div>

      {/* Summary Cards */}
      <div className="grid grid-cols-3 gap-4">
        <div className="rounded-lg border border-gray-200 bg-white p-4">
          <div className="flex items-center gap-2">
            <Package className="h-4 w-4 text-blue-600" />
            <span className="text-xs font-medium text-gray-600">{t('documents:costing.landedCost.productsSubtotal')}</span>
          </div>
          <p className="mt-2 text-lg font-semibold text-gray-900">
            ${subtotal.toFixed(decimals)}
          </p>
        </div>

        <div className="rounded-lg border border-gray-200 bg-white p-4">
          <div className="flex items-center gap-2">
            <DollarSign className="h-4 w-4 text-orange-600" />
            <span className="text-xs font-medium text-gray-600">{t('documents:costing.additionalCosts.title')}</span>
          </div>
          <p className="mt-2 text-lg font-semibold text-gray-900">
            ${totalAdditionalCosts.toFixed(decimals)}
          </p>
        </div>

        <div className="rounded-lg border border-blue-200 bg-blue-50 p-4">
          <div className="flex items-center gap-2">
            <TrendingUp className="h-4 w-4 text-blue-600" />
            <span className="text-xs font-medium text-blue-700">{t('documents:costing.landedCost.totalLandedCost')}</span>
          </div>
          <p className="mt-2 text-lg font-semibold text-blue-900">
            ${grandTotal.toFixed(decimals)}
          </p>
        </div>
      </div>

      {/* Line-by-Line Breakdown */}
      <div className="overflow-hidden rounded-lg border border-gray-200">
        <table className="min-w-full divide-y divide-gray-200">
          <thead className="bg-gray-50">
            <tr>
              <th className="px-4 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('documents:costing.landedCost.columns.product')}
              </th>
              <th className="px-4 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('documents:costing.landedCost.columns.qty')}
              </th>
              <th className="px-4 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('documents:costing.landedCost.columns.unitPrice')}
              </th>
              <th className="px-4 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('documents:costing.landedCost.columns.lineTotal')}
              </th>
              <th className="px-4 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('documents:costing.landedCost.columns.percentOfTotal')}
              </th>
              <th className="px-4 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('documents:costing.landedCost.columns.allocatedCost')}
              </th>
              <th className="px-4 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('documents:costing.landedCost.columns.landedUnitCost')}
              </th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-200 bg-white">
            {lines.map((line) => {
              const percentage = subtotal > 0 ? (Number(line.total) / subtotal) * 100 : 0
              const allocatedCost = Number(line.allocated_costs || 0)
              const landedUnitCost = Number(line.landed_unit_cost || line.unit_price)

              return (
                <tr key={line.id} className="hover:bg-gray-50">
                  <td className="px-4 py-3 text-sm text-gray-900">{line.description}</td>
                  <td className="px-4 py-3 text-end text-sm text-gray-900">
                    {line.quantity}
                  </td>
                  <td className="px-4 py-3 text-end text-sm text-gray-900">
                    ${Number(line.unit_price).toFixed(decimals)}
                  </td>
                  <td className="px-4 py-3 text-end text-sm text-gray-900">
                    ${Number(line.total).toFixed(decimals)}
                  </td>
                  <td className="px-4 py-3 text-end text-sm text-gray-600">
                    {percentage.toFixed(1)}%
                  </td>
                  <td className="px-4 py-3 text-end text-sm font-medium text-orange-600">
                    ${allocatedCost.toFixed(decimals)}
                  </td>
                  <td className="px-4 py-3 text-end text-sm font-semibold text-blue-600">
                    ${landedUnitCost.toFixed(decimals)}
                  </td>
                </tr>
              )
            })}
          </tbody>
          <tfoot className="bg-gray-50">
            <tr>
              <td colSpan={3} className="px-4 py-3 text-end text-sm font-medium text-gray-900">
                {t('documents:costing.landedCost.totals')}
              </td>
              <td className="px-4 py-3 text-end text-sm font-semibold text-gray-900">
                ${subtotal.toFixed(decimals)}
              </td>
              <td className="px-4 py-3 text-end text-sm text-gray-600">
                100.0%
              </td>
              <td className="px-4 py-3 text-end text-sm font-semibold text-orange-600">
                ${totalAdditionalCosts.toFixed(decimals)}
              </td>
              <td className="px-4 py-3 text-end text-sm font-bold text-blue-600">
                ${grandTotal.toFixed(decimals)}
              </td>
            </tr>
          </tfoot>
        </table>
      </div>

      <div className="rounded-lg bg-blue-50 p-4">
        <p className="text-xs text-blue-800">
          <strong>{t('documents:costing.landedCost.noteLabel')}</strong>{' '}
          {t('documents:costing.landedCost.noteText')}
        </p>
      </div>
    </div>
  )
}
