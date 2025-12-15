import { useCallback } from 'react'
import { AdditionalCostsForm, type AdditionalCost } from '../../../components/organisms/AdditionalCostsForm'
import { useCompany } from '../../../hooks/useCompany'
import {
  useAdditionalCosts,
  useCreateAdditionalCost,
  useUpdateAdditionalCost,
  useDeleteAdditionalCost,
  type AdditionalCost as ApiAdditionalCost,
} from '../hooks/useAdditionalCosts'

interface PurchaseOrderAdditionalCostsProps {
  documentId: string
  disabled?: boolean
  currency?: string
}

/**
 * Container component that connects the AdditionalCostsForm to the API
 */
export function PurchaseOrderAdditionalCosts({
  documentId,
  disabled = false,
  currency,
}: PurchaseOrderAdditionalCostsProps) {
  const { currentCompany } = useCompany()
  const effectiveCurrency = currency ?? currentCompany?.currency ?? 'USD'
  const { data, isLoading } = useAdditionalCosts(documentId)
  const createMutation = useCreateAdditionalCost(documentId)
  const updateMutation = useUpdateAdditionalCost(documentId)
  const deleteMutation = useDeleteAdditionalCost(documentId)

  // Transform API data to component format
  const costs: AdditionalCost[] = (data?.data ?? []).map((cost: ApiAdditionalCost) => ({
    id: cost.id,
    type: cost.cost_type,
    description: cost.description,
    amount: parseFloat(cost.amount),
  }))

  const handleChange = useCallback(
    (newCosts: AdditionalCost[]) => {
      // Find added costs (have temp IDs starting with 'cost-')
      const addedCosts = newCosts.filter(
        (c) => c.id.startsWith('cost-') && !costs.some((existing) => existing.id === c.id)
      )

      // Find removed costs
      const removedCostIds = costs
        .filter((existing) => !newCosts.some((c) => c.id === existing.id))
        .map((c) => c.id)

      // Find updated costs
      const updatedCosts = newCosts.filter((c) => {
        if (c.id.startsWith('cost-')) return false // Skip new costs
        const existing = costs.find((e) => e.id === c.id)
        if (!existing) return false
        return (
          existing.type !== c.type ||
          existing.description !== c.description ||
          existing.amount !== c.amount
        )
      })

      // Process additions
      for (const cost of addedCosts) {
        createMutation.mutate({
          cost_type: cost.type,
          description: cost.description,
          amount: cost.amount,
        })
      }

      // Process removals
      for (const costId of removedCostIds) {
        deleteMutation.mutate(costId)
      }

      // Process updates
      for (const cost of updatedCosts) {
        updateMutation.mutate({
          costId: cost.id,
          data: {
            cost_type: cost.type,
            description: cost.description,
            amount: cost.amount,
          },
        })
      }
    },
    [costs, createMutation, updateMutation, deleteMutation]
  )

  if (isLoading) {
    return (
      <div className="rounded-lg border border-gray-200 bg-white p-4">
        <div className="animate-pulse space-y-3">
          <div className="h-4 w-32 rounded bg-gray-200" />
          <div className="h-10 rounded bg-gray-200" />
        </div>
      </div>
    )
  }

  const isMutating =
    createMutation.isPending || updateMutation.isPending || deleteMutation.isPending

  return (
    <div className="rounded-lg border border-gray-200 bg-white p-4">
      <AdditionalCostsForm
        costs={costs}
        onChange={handleChange}
        disabled={disabled || isMutating}
        currency={effectiveCurrency}
      />
    </div>
  )
}

export default PurchaseOrderAdditionalCosts
