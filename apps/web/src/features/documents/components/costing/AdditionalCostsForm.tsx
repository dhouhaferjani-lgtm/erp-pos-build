import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Trash2, DollarSign } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useCurrency } from '@/hooks/useCurrency'
import { api } from '../../../../lib/api'
import { tenantScopedKey } from '../../../../lib/tenantScopedKey'
import { useAuthStore } from '../../../../stores/authStore'
import { useCompanyStore } from '../../../../stores/companyStore'
import { Button } from '../../../../components/atoms/Button/Button'
import { colorClasses } from '@/lib/designTokens'

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

export function AdditionalCostsForm({ documentId, readonly = false, onUpdate }: AdditionalCostsFormProps) {
  const { t } = useTranslation(['documents'])
  const queryClient = useQueryClient()
  const { decimals } = useCurrency()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const [newCost, setNewCost] = useState<Partial<AdditionalCost>>({
    cost_type: 'shipping',
    amount: 0,
  })

  // Fetch existing costs
  const { data: costsData, isLoading } = useQuery({
    queryKey: tenantScopedKey(['document-additional-costs', documentId]),
    queryFn: async () => {
      const response = await api.get(`/documents/${documentId}/additional-costs`)
      return response.data
    },
    enabled: tenantId !== null && companyId !== null,
  })

  const costs: AdditionalCost[] = costsData?.data ?? []

  // Calculate total
  const totalCosts = costs.reduce((sum, cost) => sum + Number(cost.amount), 0)

  // Create cost mutation
  const createMutation = useMutation({
    mutationFn: async (cost: Partial<AdditionalCost>) => {
      await api.post(`/documents/${documentId}/additional-costs`, cost)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: tenantScopedKey(['document-additional-costs', documentId]) })
      setNewCost({ cost_type: 'shipping', amount: 0 })
      onUpdate?.()
    },
  })

  // Delete cost mutation
  const deleteMutation = useMutation({
    mutationFn: async (costId: string) => {
      await api.delete(`/documents/${documentId}/additional-costs/${costId}`)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: tenantScopedKey(['document-additional-costs', documentId]) })
      onUpdate?.()
    },
  })

  const handleAdd = () => {
    if (newCost.amount && newCost.amount > 0) {
      createMutation.mutate(newCost)
    }
  }

  const handleDelete = (costId: string) => {
    if (confirm(t('documents:costing.additionalCosts.deleteConfirm'))) {
      deleteMutation.mutate(costId)
    }
  }

  if (isLoading) {
    return <div className={`text-sm ${colorClasses.textGray500}`}>{t('documents:costing.additionalCosts.loading')}</div>
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h3 className={`text-sm font-medium ${colorClasses.textGray900}`}>{t('documents:costing.additionalCosts.title')}</h3>
        {totalCosts > 0 && (
          <div className={`text-sm font-semibold ${colorClasses.textGray900}`}>
            {t('documents:costing.additionalCosts.total', { amount: `$${totalCosts.toFixed(decimals)}` })}
          </div>
        )}
      </div>

      {/* Existing Costs */}
      {costs.length > 0 && (
        <div className="space-y-2">
          {costs.map((cost) => (
            <div
              key={cost.id}
              className={`flex items-center justify-between rounded-lg border ${colorClasses.borderGray200} ${colorClasses.bgGray50} p-3`}
            >
              <div className="flex-1">
                <div className="flex items-center gap-2">
                  <DollarSign className={`h-4 w-4 ${colorClasses.textGray400}`} />
                  <span className={`text-sm font-medium ${colorClasses.textGray900}`}>
                    {t(`documents:costing.additionalCosts.costTypes.${cost.cost_type}`)}
                  </span>
                  <span className={`text-sm font-semibold ${colorClasses.textGray900}`}>
                    ${Number(cost.amount).toFixed(decimals)}
                  </span>
                </div>
                {cost.description && (
                  <p className={`mt-1 text-xs ${colorClasses.textGray500}`}>{cost.description}</p>
                )}
              </div>
              {!readonly && (
                <button
                  onClick={() => { handleDelete(cost.id!); }}
                  className={`${colorClasses.textRed600} ${colorClasses.hoverTextRed700}`}
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
        <div className={`space-y-3 rounded-lg border ${colorClasses.borderGray200} bg-white p-4`}>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className={`mb-1 block text-xs font-medium ${colorClasses.textGray700}`}>
                {t('documents:costing.additionalCosts.costTypeLabel')}
              </label>
              <select
                value={newCost.cost_type}
                onChange={(e) => { setNewCost({ ...newCost, cost_type: e.target.value as AdditionalCost['cost_type'] }); }}
                className={`w-full rounded-md border ${colorClasses.borderGray300} px-3 py-2 text-sm ${colorClasses.focusBorderBlue500} focus:outline-none focus:ring-1 ${colorClasses.focusRingBlue500}`}
              >
                {(['transport', 'shipping', 'insurance', 'customs', 'handling', 'other'] as const).map((value) => (
                  <option key={value} value={value}>
                    {t(`documents:costing.additionalCosts.costTypes.${value}`)}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label className={`mb-1 block text-xs font-medium ${colorClasses.textGray700}`}>
                {t('documents:costing.additionalCosts.amountLabel')}
              </label>
              <input
                type="number"
                step="0.01"
                min="0"
                value={newCost.amount || ''}
                onChange={(e) => { setNewCost({ ...newCost, amount: parseFloat(e.target.value) || 0 }); }}
                className={`w-full rounded-md border ${colorClasses.borderGray300} px-3 py-2 text-sm ${colorClasses.focusBorderBlue500} focus:outline-none focus:ring-1 ${colorClasses.focusRingBlue500}`}
                placeholder="0.00"
              />
            </div>
          </div>
          <div>
            <label className={`mb-1 block text-xs font-medium ${colorClasses.textGray700}`}>
              {t('documents:costing.additionalCosts.descriptionOptional')}
            </label>
            <input
              type="text"
              value={newCost.description || ''}
              onChange={(e) => { setNewCost({ ...newCost, description: e.target.value }); }}
              className={`w-full rounded-md border ${colorClasses.borderGray300} px-3 py-2 text-sm ${colorClasses.focusBorderBlue500} focus:outline-none focus:ring-1 ${colorClasses.focusRingBlue500}`}
              placeholder={t('documents:costing.additionalCosts.descriptionPlaceholder')}
            />
          </div>
          <Button
            onClick={handleAdd}
            disabled={!newCost.amount || newCost.amount <= 0 || createMutation.isPending}
            className="w-full"
            size="sm"
          >
            <Plus className="mr-1 h-4 w-4" />
            {t('documents:costing.additionalCosts.addButton')}
          </Button>
        </div>
      )}

      {costs.length === 0 && (
        <p className={`text-center text-sm ${colorClasses.textGray500}`}>
          {t('documents:costing.additionalCosts.empty')}
        </p>
      )}
    </div>
  )
}
