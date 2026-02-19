import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Trash2, Plus } from 'lucide-react'
import { toast } from 'sonner'
import type { RecipeData, RecipeLineData, RecipeCostData } from '../types/compositeItem'
import {
  useCreateRecipeLine,
  useUpdateRecipeLine,
  useDeleteRecipeLine,
  useCalculateRecipeCost,
  useActivateRecipe,
} from '../hooks/useRecipes'

interface RecipeLineEditorProps {
  recipe: RecipeData | null
  compositeItemId: string
  onRecipeCreated?: () => void
}

export function RecipeLineEditor({ recipe, compositeItemId }: RecipeLineEditorProps) {
  const { t } = useTranslation(['catalog', 'common'])
  const [costData, setCostData] = useState<RecipeCostData | null>(null)
  const [newLine, setNewLine] = useState({
    component_id: '',
    quantity: '',
    wastage_percent: '0',
    is_optional: false,
  })

  const createLineMutation = useCreateRecipeLine()
  const updateLineMutation = useUpdateRecipeLine()
  const deleteLineMutation = useDeleteRecipeLine()
  const calculateCostMutation = useCalculateRecipeCost()
  const activateRecipeMutation = useActivateRecipe()

  if (!recipe) {
    return (
      <div className="text-center py-8 text-gray-500">
        {t('catalog:createRecipe')}
      </div>
    )
  }

  const lines = recipe.lines ?? []

  const handleAddLine = () => {
    if (!newLine.component_id || !newLine.quantity) return
    createLineMutation.mutate(
      {
        recipeId: recipe.id,
        data: {
          component_id: newLine.component_id,
          quantity: Number(newLine.quantity),
          wastage_percent: Number(newLine.wastage_percent),
          is_optional: newLine.is_optional,
        },
      },
      {
        onSuccess: () => {
          setNewLine({ component_id: '', quantity: '', wastage_percent: '0', is_optional: false })
          toast.success(t('common:saved'))
        },
      }
    )
  }

  const handleDeleteLine = (lineId: string) => {
    deleteLineMutation.mutate(
      { recipeId: recipe.id, lineId },
      { onSuccess: () => toast.success(t('common:deleted')) }
    )
  }

  const handleUpdateLine = (lineId: string, field: string, value: string | boolean) => {
    updateLineMutation.mutate({
      recipeId: recipe.id,
      lineId,
      data: { [field]: typeof value === 'string' ? Number(value) : value },
    })
  }

  const handleCalculateCost = () => {
    calculateCostMutation.mutate(recipe.id, {
      onSuccess: (data) => {
        setCostData(data)
        toast.success(t('catalog:costCalculated'))
      },
    })
  }

  const handleActivate = () => {
    activateRecipeMutation.mutate(recipe.id, {
      onSuccess: () => toast.success(t('catalog:recipeActivated')),
    })
  }

  return (
    <div className="space-y-6">
      {/* Recipe info */}
      <div className="flex items-center justify-between">
        <div>
          <span className="text-sm text-gray-500">
            {t('catalog:version')}: {recipe.version}
            {recipe.version_name && ` - ${recipe.version_name}`}
          </span>
          {recipe.is_active && (
            <span className="ml-2 inline-flex items-center rounded-full bg-green-50 px-2 py-1 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20">
              {t('catalog:isActive')}
            </span>
          )}
        </div>
        <div className="flex gap-2">
          {!recipe.is_active && (
            <button
              onClick={handleActivate}
              disabled={activateRecipeMutation.isPending}
              className="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
            >
              {t('catalog:activateRecipe')}
            </button>
          )}
          <button
            onClick={handleCalculateCost}
            disabled={calculateCostMutation.isPending}
            className="inline-flex items-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50"
          >
            {t('catalog:calculateCost')}
          </button>
        </div>
      </div>

      {/* Lines table */}
      <div className="overflow-x-auto">
        <table className="min-w-full divide-y divide-gray-300">
          <thead>
            <tr>
              <th className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">{t('catalog:component')}</th>
              <th className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">{t('catalog:quantity')}</th>
              <th className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">{t('catalog:unit')}</th>
              <th className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">{t('catalog:wastagePercent')}</th>
              <th className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">{t('catalog:optional')}</th>
              <th className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">{t('catalog:unitCost')}</th>
              <th className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">{t('catalog:lineCost')}</th>
              <th className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900"></th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-200">
            {lines.map((line: RecipeLineData) => (
              <tr key={line.id}>
                <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-900">
                  {line.component_name ?? line.component_id}
                  {line.component_sku && <span className="text-gray-500 ml-1">({line.component_sku})</span>}
                </td>
                <td className="whitespace-nowrap px-3 py-4 text-sm">
                  <input
                    type="number"
                    defaultValue={line.quantity}
                    onBlur={(e) => handleUpdateLine(line.id, 'quantity', e.target.value)}
                    className="w-20 rounded-md border-gray-300 text-sm"
                    step="0.01"
                  />
                </td>
                <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">{line.unit_name ?? '-'}</td>
                <td className="whitespace-nowrap px-3 py-4 text-sm">
                  <input
                    type="number"
                    defaultValue={line.wastage_percent}
                    onBlur={(e) => handleUpdateLine(line.id, 'wastage_percent', e.target.value)}
                    className="w-16 rounded-md border-gray-300 text-sm"
                    step="0.1"
                    min="0"
                    max="100"
                  />
                </td>
                <td className="whitespace-nowrap px-3 py-4 text-sm">
                  <input
                    type="checkbox"
                    defaultChecked={line.is_optional}
                    onChange={(e) => handleUpdateLine(line.id, 'is_optional', e.target.checked)}
                    className="rounded border-gray-300"
                  />
                </td>
                <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">{line.unit_cost ?? '-'}</td>
                <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">{line.line_cost ?? '-'}</td>
                <td className="whitespace-nowrap px-3 py-4 text-sm">
                  <button
                    onClick={() => handleDeleteLine(line.id)}
                    className="text-red-600 hover:text-red-900"
                    disabled={deleteLineMutation.isPending}
                  >
                    <Trash2 className="h-4 w-4" />
                  </button>
                </td>
              </tr>
            ))}
            {/* Add new line row */}
            <tr className="bg-gray-50">
              <td className="px-3 py-4">
                <input
                  type="text"
                  placeholder={t('catalog:component') + ' ID'}
                  value={newLine.component_id}
                  onChange={(e) => setNewLine({ ...newLine, component_id: e.target.value })}
                  className="w-full rounded-md border-gray-300 text-sm"
                />
              </td>
              <td className="px-3 py-4">
                <input
                  type="number"
                  placeholder={t('catalog:quantity')}
                  value={newLine.quantity}
                  onChange={(e) => setNewLine({ ...newLine, quantity: e.target.value })}
                  className="w-20 rounded-md border-gray-300 text-sm"
                  step="0.01"
                />
              </td>
              <td className="px-3 py-4">-</td>
              <td className="px-3 py-4">
                <input
                  type="number"
                  value={newLine.wastage_percent}
                  onChange={(e) => setNewLine({ ...newLine, wastage_percent: e.target.value })}
                  className="w-16 rounded-md border-gray-300 text-sm"
                  step="0.1"
                />
              </td>
              <td className="px-3 py-4">
                <input
                  type="checkbox"
                  checked={newLine.is_optional}
                  onChange={(e) => setNewLine({ ...newLine, is_optional: e.target.checked })}
                  className="rounded border-gray-300"
                />
              </td>
              <td className="px-3 py-4">-</td>
              <td className="px-3 py-4">-</td>
              <td className="px-3 py-4">
                <button
                  onClick={handleAddLine}
                  disabled={!newLine.component_id || !newLine.quantity || createLineMutation.isPending}
                  className="text-indigo-600 hover:text-indigo-900 disabled:opacity-50"
                >
                  <Plus className="h-4 w-4" />
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      {/* Cost breakdown */}
      {costData && (
        <div className="mt-6 rounded-lg bg-gray-50 p-4">
          <h4 className="text-sm font-medium text-gray-900 mb-3">{t('catalog:costBreakdown')}</h4>
          <table className="min-w-full text-sm">
            <thead>
              <tr>
                <th className="text-left py-1">{t('catalog:component')}</th>
                <th className="text-right py-1">{t('catalog:quantity')}</th>
                <th className="text-right py-1">{t('catalog:unitCost')}</th>
                <th className="text-right py-1">{t('catalog:lineCost')}</th>
                <th className="text-right py-1">{t('catalog:percentOfTotal')}</th>
              </tr>
            </thead>
            <tbody>
              {costData.lines.map((line, idx) => (
                <tr key={idx}>
                  <td className="py-1">{line.component_name}</td>
                  <td className="text-right py-1">{line.quantity}</td>
                  <td className="text-right py-1">{line.unit_cost}</td>
                  <td className="text-right py-1">{line.line_cost}</td>
                  <td className="text-right py-1">{line.percent_of_total}%</td>
                </tr>
              ))}
            </tbody>
            <tfoot>
              <tr className="font-semibold border-t border-gray-300">
                <td className="py-2">{t('catalog:totalCost')}</td>
                <td colSpan={3} className="text-right py-2">{costData.total_cost}</td>
                <td className="text-right py-2">100%</td>
              </tr>
            </tfoot>
          </table>
        </div>
      )}
    </div>
  )
}
