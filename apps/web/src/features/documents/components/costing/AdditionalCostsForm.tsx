import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Trash2, DollarSign } from 'lucide-react'
import { api } from '../../../../lib/api'
import { Button } from '../../../../components/atoms/Button/Button'

interface AdditionalCost {
  id?: string
  cost_type: 'transport' | 'shipping' | 'insurance' | 'customs' | 'handling' | 'other'
  description?: string
  amount: number
}

interface AdditionalCostsFormProps {
  documentId: string
  readonly?: boolean
  onUpdate?: () => void
}

const COST_TYPE_LABELS = {
  transport: 'Transport',
  shipping: 'Shipping',
  insurance: 'Insurance',
  customs: 'Customs',
  handling: 'Handling',
  other: 'Other',
}

export function AdditionalCostsForm({ documentId, readonly = false, onUpdate }: AdditionalCostsFormProps) {
  const queryClient = useQueryClient()
  const [newCost, setNewCost] = useState<Partial<AdditionalCost>>({
    cost_type: 'shipping',
    amount: 0,
  })

  // Fetch existing costs
  const { data: costsData, isLoading } = useQuery({
    queryKey: ['document-additional-costs', documentId],
    queryFn: async () => {
      const response = await api.get(`/documents/${documentId}/additional-costs`)
      return response.data
    },
  })

  const costs: AdditionalCost[] = costsData?.data ?? []

  // Calculate total
  const totalCosts = costs.reduce((sum, cost) => sum + Number(cost.amount), 0)

  // Create cost mutation
  const createMutation = useMutation({
    mutationFn: async (cost: Partial<AdditionalCost>) => {
      await api.post(`/documents/${documentId}/additional-costs`, cost)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['document-additional-costs', documentId] })
      setNewCost({ cost_type: 'shipping', amount: 0 })
      onUpdate?.()
    },
  })

  // Delete cost mutation
  const deleteMutation = useMutation({
    mutationFn: async (costId: string) => {
      await api.delete(`/documents/${documentId}/additional-costs/${costId}`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['document-additional-costs', documentId] })
      onUpdate?.()
    },
  })

  const handleAdd = () => {
    if (newCost.amount && newCost.amount > 0) {
      createMutation.mutate(newCost)
    }
  }

  const handleDelete = (costId: string) => {
    if (confirm('Are you sure you want to delete this cost?')) {
      deleteMutation.mutate(costId)
    }
  }

  if (isLoading) {
    return <div className="text-sm text-gray-500">Loading costs...</div>
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h3 className="text-sm font-medium text-gray-900">Additional Costs</h3>
        {totalCosts > 0 && (
          <div className="text-sm font-semibold text-gray-900">
            Total: ${totalCosts.toFixed(2)}
          </div>
        )}
      </div>

      {/* Existing Costs */}
      {costs.length > 0 && (
        <div className="space-y-2">
          {costs.map((cost) => (
            <div
              key={cost.id}
              className="flex items-center justify-between rounded-lg border border-gray-200 bg-gray-50 p-3"
            >
              <div className="flex-1">
                <div className="flex items-center gap-2">
                  <DollarSign className="h-4 w-4 text-gray-400" />
                  <span className="text-sm font-medium text-gray-900">
                    {COST_TYPE_LABELS[cost.cost_type]}
                  </span>
                  <span className="text-sm font-semibold text-gray-900">
                    ${Number(cost.amount).toFixed(2)}
                  </span>
                </div>
                {cost.description && (
                  <p className="mt-1 text-xs text-gray-500">{cost.description}</p>
                )}
              </div>
              {!readonly && (
                <button
                  onClick={() => { handleDelete(cost.id!); }}
                  className="text-red-600 hover:text-red-700"
                  disabled={deleteMutation.isPending}
                >
                  <Trash2 className="h-4 w-4" />
                </button>
              )}
            </div>
          ))}
        </div>
      )}

      {/* Add New Cost Form */}
      {!readonly && (
        <div className="space-y-3 rounded-lg border border-gray-200 bg-white p-4">
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="mb-1 block text-xs font-medium text-gray-700">
                Cost Type
              </label>
              <select
                value={newCost.cost_type}
                onChange={(e) => { setNewCost({ ...newCost, cost_type: e.target.value as AdditionalCost['cost_type'] }); }}
                className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              >
                {Object.entries(COST_TYPE_LABELS).map(([value, label]) => (
                  <option key={value} value={value}>
                    {label}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label className="mb-1 block text-xs font-medium text-gray-700">
                Amount
              </label>
              <input
                type="number"
                step="0.01"
                min="0"
                value={newCost.amount || ''}
                onChange={(e) => { setNewCost({ ...newCost, amount: parseFloat(e.target.value) || 0 }); }}
                className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                placeholder="0.00"
              />
            </div>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-gray-700">
              Description (Optional)
            </label>
            <input
              type="text"
              value={newCost.description || ''}
              onChange={(e) => { setNewCost({ ...newCost, description: e.target.value }); }}
              className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              placeholder="e.g., International freight"
            />
          </div>
          <Button
            onClick={handleAdd}
            disabled={!newCost.amount || newCost.amount <= 0 || createMutation.isPending}
            className="w-full"
            size="sm"
          >
            <Plus className="mr-1 h-4 w-4" />
            Add Cost
          </Button>
        </div>
      )}

      {costs.length === 0 && (
        <p className="text-center text-sm text-gray-500">
          No additional costs added yet.
        </p>
      )}
    </div>
  )
}
