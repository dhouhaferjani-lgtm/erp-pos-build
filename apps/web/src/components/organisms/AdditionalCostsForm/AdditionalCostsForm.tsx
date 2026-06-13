import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Plus, Trash2 } from 'lucide-react'
import { useCompany } from '../../../hooks/useCompany'

export type AdditionalCostType = 'shipping' | 'customs' | 'insurance' | 'handling' | 'other'

export interface AdditionalCost {
  id: string
  type: AdditionalCostType
  description: string
  amount: number
}

export interface AdditionalCostsFormProps {
  costs: AdditionalCost[]
  onChange: (costs: AdditionalCost[]) => void
  disabled?: boolean
  currency?: string
}

const costTypes: AdditionalCostType[] = ['shipping', 'customs', 'insurance', 'handling', 'other']

function generateId(): string {
  return `cost-${String(Date.now())}-${Math.random().toString(36).slice(2, 9)}`
}

export function AdditionalCostsForm({
  costs,
  onChange,
  disabled = false,
  currency,
}: AdditionalCostsFormProps) {
  const { t } = useTranslation(['inventory'])
  const { currentCompany } = useCompany()
  const effectiveCurrency = currency ?? currentCompany?.currency ?? 'USD'
  const [newCost, setNewCost] = useState<Omit<AdditionalCost, 'id'>>({
    type: 'shipping',
    description: '',
    amount: 0,
  })

  const handleAddCost = () => {
    if (newCost.amount <= 0) return

    const cost: AdditionalCost = {
      id: generateId(),
      ...newCost,
    }

    onChange([...costs, cost])
    setNewCost({
      type: 'shipping',
      description: '',
      amount: 0,
    })
  }

  const handleRemoveCost = (id: string) => {
    onChange(costs.filter((c) => c.id !== id))
  }

  const handleUpdateCost = (id: string, updates: Partial<Omit<AdditionalCost, 'id'>>) => {
    onChange(
      costs.map((c) => (c.id === id ? { ...c, ...updates } : c))
    )
  }

  const total = costs.reduce((sum, c) => sum + c.amount, 0)

  const formatCurrency = (value: number): string => {
    return new Intl.NumberFormat('fr-TN', {
      style: 'currency',
      currency: effectiveCurrency,
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(value)
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h3 className="text-sm font-medium text-gray-900 dark:text-gray-100">
          {t('inventory:additionalCosts.title')}
        </h3>
        <span className="text-sm font-semibold text-gray-700 dark:text-gray-300">
          {t('inventory:additionalCosts.total')}: {formatCurrency(total)}
        </span>
      </div>

      {costs.length === 0 ? (
        <p className="py-2 text-sm text-gray-500 dark:text-gray-400">
          {t('inventory:additionalCosts.empty')}
        </p>
      ) : (
        <div className="divide-y divide-gray-200 rounded-lg border border-gray-200 dark:divide-gray-700 dark:border-gray-700">
          {costs.map((cost) => (
            <div
              key={cost.id}
              className="flex items-center gap-3 px-3 py-2"
            >
              <select
                value={cost.type}
                onChange={(e) => { handleUpdateCost(cost.id, { type: e.target.value as AdditionalCostType }) }}
                disabled={disabled}
                className="w-32 rounded-md border border-gray-300 px-2 py-1.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
              >
                {costTypes.map((type) => (
                  <option key={type} value={type}>
                    {t(`inventory:additionalCosts.types.${type}`)}
                  </option>
                ))}
              </select>

              <input
                type="text"
                value={cost.description}
                onChange={(e) => { handleUpdateCost(cost.id, { description: e.target.value }) }}
                placeholder={t('inventory:additionalCosts.description')}
                disabled={disabled}
                className="flex-1 rounded-md border border-gray-300 px-2 py-1.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
              />

              <div className="flex items-center gap-1">
                <input
                  type="number"
                  value={cost.amount}
                  onChange={(e) => { handleUpdateCost(cost.id, { amount: parseFloat(e.target.value) || 0 }) }}
                  disabled={disabled}
                  step="0.01"
                  min="0"
                  className="w-24 rounded-md border border-gray-300 px-2 py-1.5 text-end text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                />
                <span className="text-xs text-gray-500 dark:text-gray-400">{effectiveCurrency}</span>
              </div>

              <button
                type="button"
                onClick={() => { handleRemoveCost(cost.id) }}
                disabled={disabled}
                className="rounded p-1 text-gray-400 hover:bg-red-50 hover:text-red-600 disabled:opacity-50 dark:hover:bg-red-900/20 dark:hover:text-red-400"
                aria-label={t('inventory:additionalCosts.removeCost')}
              >
                <Trash2 className="h-4 w-4" />
              </button>
            </div>
          ))}
        </div>
      )}

      {!disabled && (
        <div className="flex items-end gap-3 rounded-lg bg-gray-50 p-3 dark:bg-gray-800/50">
          <div className="w-32">
            <label className="mb-1 block text-xs text-gray-500 dark:text-gray-400">
              {t('inventory:additionalCosts.type')}
            </label>
            <select
              value={newCost.type}
              onChange={(e) => { setNewCost((prev) => ({ ...prev, type: e.target.value as AdditionalCostType })) }}
              className="w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
            >
              {costTypes.map((type) => (
                <option key={type} value={type}>
                  {t(`inventory:additionalCosts.types.${type}`)}
                </option>
              ))}
            </select>
          </div>

          <div className="flex-1">
            <label className="mb-1 block text-xs text-gray-500 dark:text-gray-400">
              {t('inventory:additionalCosts.description')}
            </label>
            <input
              type="text"
              value={newCost.description}
              onChange={(e) => { setNewCost((prev) => ({ ...prev, description: e.target.value })) }}
              placeholder={t('inventory:additionalCosts.description')}
              className="w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
            />
          </div>

          <div className="w-28">
            <label className="mb-1 block text-xs text-gray-500 dark:text-gray-400">
              {t('inventory:additionalCosts.amount')}
            </label>
            <input
              type="number"
              value={newCost.amount || ''}
              onChange={(e) => { setNewCost((prev) => ({ ...prev, amount: parseFloat(e.target.value) || 0 })) }}
              placeholder="0.00"
              step="0.01"
              min="0"
              className="w-full rounded-md border border-gray-300 px-2 py-1.5 text-end text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
            />
          </div>

          <button
            type="button"
            onClick={handleAddCost}
            disabled={newCost.amount <= 0}
            className="flex items-center gap-1.5 rounded-md bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
          >
            <Plus className="h-4 w-4" />
            {t('inventory:additionalCosts.add')}
          </button>
        </div>
      )}
    </div>
  )
}

export default AdditionalCostsForm
