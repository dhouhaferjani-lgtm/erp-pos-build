import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Save, Info } from 'lucide-react'
import { api } from '../../../lib/api'
import { Button } from '../../../components/atoms/Button/Button'

interface Company {
  id: string
  name: string
  inventory_costing_method?: 'FIFO' | 'WAC' | 'LIFO'
  default_target_margin?: string
  default_minimum_margin?: string
  allow_below_cost_sales?: boolean
}

interface CompanyResponse {
  data: Company
}

export function InventorySettings() {
  const queryClient = useQueryClient()

  // Fetch current company settings
  const { data: companyData, isLoading } = useQuery({
    queryKey: ['company-settings'],
    queryFn: async () => {
      const response = await api.get<CompanyResponse>('/company')
      return response.data
    },
  })

  const company = companyData?.data

  const [settings, setSettings] = useState({
    inventory_costing_method: company?.inventory_costing_method || 'WAC',
    default_target_margin: company?.default_target_margin || '30.00',
    default_minimum_margin: company?.default_minimum_margin || '15.00',
    allow_below_cost_sales: company?.allow_below_cost_sales || false,
  })

  // Update settings when company data loads
  useState(() => {
    if (company) {
      setSettings({
        inventory_costing_method: company.inventory_costing_method || 'WAC',
        default_target_margin: company.default_target_margin || '30.00',
        default_minimum_margin: company.default_minimum_margin || '15.00',
        allow_below_cost_sales: company.allow_below_cost_sales || false,
      })
    }
  })

  // Save mutation
  const saveMutation = useMutation({
    mutationFn: async () => {
      await api.patch('/company', settings)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['company-settings'] })
      alert('Settings saved successfully!')
    },
    onError: () => {
      alert('Failed to save settings. Please try again.')
    },
  })

  const handleSave = () => {
    saveMutation.mutate()
  }

  const hasChanges = company && (
    settings.inventory_costing_method !== (company.inventory_costing_method || 'WAC') ||
    settings.default_target_margin !== (company.default_target_margin || '30.00') ||
    settings.default_minimum_margin !== (company.default_minimum_margin || '15.00') ||
    settings.allow_below_cost_sales !== (company.allow_below_cost_sales || false)
  )

  if (isLoading) {
    return (
      <div className="flex items-center justify-center p-8">
        <div className="h-8 w-8 animate-spin rounded-full border-4 border-gray-300 border-t-blue-600" />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-lg font-semibold text-gray-900">Inventory & Pricing Settings</h2>
        <p className="mt-1 text-sm text-gray-600">
          Configure inventory costing methods and default margin policies for your company.
        </p>
      </div>

      {/* Inventory Costing Method */}
      <div className="rounded-lg border border-gray-200 bg-white p-6">
        <h3 className="text-sm font-medium text-gray-900">Inventory Costing Method</h3>
        <p className="mt-1 text-xs text-gray-600">
          Choose how product costs are calculated when inventory is received.
        </p>

        <div className="mt-4 space-y-3">
          <label className="flex items-start gap-3 rounded-lg border border-gray-200 p-4 hover:bg-gray-50">
            <input
              type="radio"
              name="costing_method"
              value="FIFO"
              checked={settings.inventory_costing_method === 'FIFO'}
              onChange={(e) => { setSettings({ ...settings, inventory_costing_method: e.target.value as 'FIFO' | 'WAC' | 'LIFO' }); }}
              className="mt-0.5"
            />
            <div className="flex-1">
              <div className="text-sm font-medium text-gray-900">FIFO (First In, First Out)</div>
              <p className="text-xs text-gray-600">
                Oldest inventory is sold first. Good for perishable goods.
              </p>
            </div>
          </label>

          <label className="flex items-start gap-3 rounded-lg border-2 border-blue-500 bg-blue-50 p-4">
            <input
              type="radio"
              name="costing_method"
              value="WAC"
              checked={settings.inventory_costing_method === 'WAC'}
              onChange={(e) => { setSettings({ ...settings, inventory_costing_method: e.target.value as 'FIFO' | 'WAC' | 'LIFO' }); }}
              className="mt-0.5"
            />
            <div className="flex-1">
              <div className="flex items-center gap-2">
                <div className="text-sm font-medium text-gray-900">WAC (Weighted Average Cost)</div>
                <span className="rounded-full bg-blue-600 px-2 py-0.5 text-xs font-medium text-white">
                  Recommended
                </span>
              </div>
              <p className="text-xs text-gray-600">
                Average cost of all inventory. Best for most businesses. Automatically recalculates when goods are received.
              </p>
            </div>
          </label>

          <label className="flex items-start gap-3 rounded-lg border border-gray-200 p-4 hover:bg-gray-50">
            <input
              type="radio"
              name="costing_method"
              value="LIFO"
              checked={settings.inventory_costing_method === 'LIFO'}
              onChange={(e) => { setSettings({ ...settings, inventory_costing_method: e.target.value as 'FIFO' | 'WAC' | 'LIFO' }); }}
              className="mt-0.5"
            />
            <div className="flex-1">
              <div className="text-sm font-medium text-gray-900">LIFO (Last In, First Out)</div>
              <p className="text-xs text-gray-600">
                Newest inventory is sold first. Rarely used (not allowed in some countries).
              </p>
            </div>
          </label>
        </div>
      </div>

      {/* Margin Policies */}
      <div className="rounded-lg border border-gray-200 bg-white p-6">
        <h3 className="text-sm font-medium text-gray-900">Default Margin Policies</h3>
        <p className="mt-1 text-xs text-gray-600">
          Set company-wide default margins. Individual products can override these settings.
        </p>

        <div className="mt-4 grid grid-cols-2 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700">
              Target Margin (%)
            </label>
            <input
              type="number"
              step="0.01"
              min="0"
              max="100"
              value={settings.default_target_margin}
              onChange={(e) => { setSettings({ ...settings, default_target_margin: e.target.value }); }}
              className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
            <p className="mt-1 text-xs text-gray-500">
              Recommended profit margin for products
            </p>
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700">
              Minimum Margin (%)
            </label>
            <input
              type="number"
              step="0.01"
              min="0"
              max="100"
              value={settings.default_minimum_margin}
              onChange={(e) => { setSettings({ ...settings, default_minimum_margin: e.target.value }); }}
              className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
            <p className="mt-1 text-xs text-gray-500">
              Lowest acceptable margin before warning
            </p>
          </div>
        </div>

        <div className="mt-4 rounded-lg bg-blue-50 p-3">
          <div className="flex items-start gap-2">
            <Info className="h-4 w-4 shrink-0 text-blue-600" />
            <div className="text-xs text-blue-800">
              <strong>Margin Calculation:</strong> Margin % = (Sell Price - Cost) / Cost × 100
              <br />
              Example: If cost is $100 and target margin is 30%, suggested price = $100 / (1 - 0.30) = $142.86
            </div>
          </div>
        </div>
      </div>

      {/* Sales Restrictions */}
      <div className="rounded-lg border border-gray-200 bg-white p-6">
        <h3 className="text-sm font-medium text-gray-900">Sales Restrictions</h3>
        <p className="mt-1 text-xs text-gray-600">
          Control whether users can sell products below cost.
        </p>

        <div className="mt-4">
          <label className="flex items-start gap-3">
            <input
              type="checkbox"
              checked={settings.allow_below_cost_sales}
              onChange={(e) => { setSettings({ ...settings, allow_below_cost_sales: e.target.checked }); }}
              className="mt-0.5"
            />
            <div className="flex-1">
              <div className="text-sm font-medium text-gray-900">
                Allow Sales Below Cost
              </div>
              <p className="text-xs text-gray-600">
                When disabled, users must have special permission to sell products at a loss.
              </p>
            </div>
          </label>
        </div>
      </div>

      {/* Save Button */}
      <div className="flex items-center justify-end gap-3 border-t border-gray-200 pt-4">
        {hasChanges && (
          <span className="text-sm text-gray-600">You have unsaved changes</span>
        )}
        <Button
          onClick={handleSave}
          disabled={!hasChanges || saveMutation.isPending}
          size="md"
        >
          <Save className="mr-2 h-4 w-4" />
          {saveMutation.isPending ? 'Saving...' : 'Save Settings'}
        </Button>
      </div>
    </div>
  )
}
