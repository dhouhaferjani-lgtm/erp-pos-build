import { Package, DollarSign, TrendingUp } from 'lucide-react'
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
  const { decimals } = useCurrency()
  const subtotal = lines.reduce((sum, line) => sum + Number(line.total), 0)
  const grandTotal = subtotal + totalAdditionalCosts

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h3 className="text-sm font-medium text-gray-900">Landed Cost Breakdown</h3>
        <div className="text-xs text-gray-500">
          Cost Allocation Method: Proportional
        </div>
      </div>

      {/* Summary Cards */}
      <div className="grid grid-cols-3 gap-4">
        <div className="rounded-lg border border-gray-200 bg-white p-4">
          <div className="flex items-center gap-2">
            <Package className="h-4 w-4 text-blue-600" />
            <span className="text-xs font-medium text-gray-600">Products Subtotal</span>
          </div>
          <p className="mt-2 text-lg font-semibold text-gray-900">
            ${subtotal.toFixed(decimals)}
          </p>
        </div>

        <div className="rounded-lg border border-gray-200 bg-white p-4">
          <div className="flex items-center gap-2">
            <DollarSign className="h-4 w-4 text-orange-600" />
            <span className="text-xs font-medium text-gray-600">Additional Costs</span>
          </div>
          <p className="mt-2 text-lg font-semibold text-gray-900">
            ${totalAdditionalCosts.toFixed(decimals)}
          </p>
        </div>

        <div className="rounded-lg border border-blue-200 bg-blue-50 p-4">
          <div className="flex items-center gap-2">
            <TrendingUp className="h-4 w-4 text-blue-600" />
            <span className="text-xs font-medium text-blue-700">Total Landed Cost</span>
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
                Product
              </th>
              <th className="px-4 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                Qty
              </th>
              <th className="px-4 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                Unit Price
              </th>
              <th className="px-4 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                Line Total
              </th>
              <th className="px-4 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                % of Total
              </th>
              <th className="px-4 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                Allocated Cost
              </th>
              <th className="px-4 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                Landed Unit Cost
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
                Totals:
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
          <strong>Note:</strong> Additional costs are allocated proportionally based on each line's percentage of the subtotal.
          Landed unit cost = (Line Total + Allocated Costs) / Quantity
        </p>
      </div>
    </div>
  )
}
