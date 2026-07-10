import { useTranslation } from 'react-i18next'
import { useCompany } from '../../../hooks/useCompany'
import {
  LandedCostBreakdown,
  type LineAllocation,
} from '../../../components/organisms/LandedCostBreakdown/LandedCostBreakdown'
import { useLandedCostBreakdown } from '../hooks/useAdditionalCosts'
import { colorClasses } from '@/lib/designTokens'

interface PurchaseOrderLandedCostBreakdownProps {
  documentId: string
  currency?: string
}

/**
 * Container component that fetches landed cost breakdown data and displays it
 * Shows how additional costs (shipping, customs, etc.) are allocated across PO lines
 */
export function PurchaseOrderLandedCostBreakdown({
  documentId,
  currency,
}: PurchaseOrderLandedCostBreakdownProps) {
  const { t } = useTranslation(['inventory'])
  const { currentCompany } = useCompany()
  const effectiveCurrency = currency ?? currentCompany?.currency ?? 'USD'
  const { data, isLoading, isError } = useLandedCostBreakdown(documentId)

  if (isLoading) {
    return (
      <div className={`rounded-lg border ${colorClasses.borderGray200} bg-white p-4`}>
        <div className="animate-pulse space-y-3">
          <div className={`h-4 w-48 rounded ${colorClasses.bgGray200}`} />
          <div className={`h-32 rounded ${colorClasses.bgGray200}`} />
        </div>
      </div>
    )
  }

  // Don't show anything if there's an error or no data
  // This is a supplementary display component, errors shouldn't block the main page
  if (isError) {
    return null
  }

  // No additional costs allocated or no allocations
  if (!data || data.allocations.length === 0 || data.total_additional_costs === 0) {
    return null
  }

  // Transform API data to component format
  const lines: LineAllocation[] = data.allocations.map((allocation) => ({
    lineId: allocation.line_id,
    productName: allocation.product_name,
    description: allocation.description,
    quantity: allocation.quantity,
    unitPrice: allocation.unit_price,
    lineTotal: allocation.line_total,
    allocatedCosts: allocation.allocated_costs,
    landedUnitCost: allocation.landed_unit_cost,
    proportion: allocation.proportion,
  }))

  return (
    <div className={`rounded-lg border ${colorClasses.borderGray200} bg-white p-6`}>
      <h2 className={`mb-4 text-lg font-semibold ${colorClasses.textGray900}`}>
        {t('inventory:landedCost.title')}
      </h2>
      <LandedCostBreakdown lines={lines} currency={effectiveCurrency} showProportion />
    </div>
  )
}

export default PurchaseOrderLandedCostBreakdown
