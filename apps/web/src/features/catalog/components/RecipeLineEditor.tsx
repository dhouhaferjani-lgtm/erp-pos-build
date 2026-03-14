import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Trash2, Plus } from 'lucide-react'
import { toast } from 'sonner'
import { Input, Button, FormField, Select } from '@/components/atoms'
import { Textarea } from '@/components/atoms/Textarea/Textarea'
import { Badge } from '@/components/atoms/Badge/Badge'
import { ProductSearchSelect } from '@/components/ui/ProductSearchSelect'
import { CompositeItemSearchSelect } from './CompositeItemSearchSelect'
import { tokens } from '@/lib/designTokens'
import type { RecipeData, RecipeLineData, RecipeCostData, VerticalType, ComponentType } from '../types/compositeItem'
import { useVerticalLabels } from '../hooks/useVerticalLabels'
import {
  useCreateRecipeLine,
  useUpdateRecipeLine,
  useDeleteRecipeLine,
  useCalculateRecipeCost,
  useActivateRecipe,
  useUpdateRecipe,
} from '../hooks/useRecipes'

interface RecipeLineEditorProps {
  recipe: RecipeData | null
  compositeItemId: string
  verticalType?: VerticalType
  onRecipeCreated?: () => void
}

export function RecipeLineEditor({ recipe, compositeItemId: _compositeItemId, verticalType }: RecipeLineEditorProps) {
  const { t } = useTranslation(['catalog', 'common'])
  const getLabel = useVerticalLabels(verticalType)
  const [costData, setCostData] = useState<RecipeCostData | null>(null)
  const [newLine, setNewLine] = useState({
    component_type: 'product' as ComponentType,
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
  const updateRecipeMutation = useUpdateRecipe()

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
          component_type: newLine.component_type,
          component_id: newLine.component_id,
          quantity: Number(newLine.quantity),
          wastage_percent: Number(newLine.wastage_percent),
          is_optional: newLine.is_optional,
        },
      },
      {
        onSuccess: () => {
          setNewLine({ component_type: 'product', component_id: '', quantity: '', wastage_percent: '0', is_optional: false })
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

  const handleUpdateRecipeField = (field: string, value: string) => {
    const numValue = value === '' ? null : Number(value)
    updateRecipeMutation.mutate({
      id: recipe.id,
      data: { [field]: numValue },
    })
  }

  const handleUpdateInstructions = (value: string) => {
    updateRecipeMutation.mutate({
      id: recipe.id,
      data: { instructions: value || null },
    })
  }

  const totalTime = (recipe.prep_time_minutes ?? 0) + (recipe.cook_time_minutes ?? 0)

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
            <Badge variant="success" className="ml-2">
              {t('catalog:isActive')}
            </Badge>
          )}
        </div>
        <div className="flex gap-2">
          {!recipe.is_active && (
            <Button
              variant="primary"
              size="sm"
              onClick={handleActivate}
              disabled={activateRecipeMutation.isPending}
            >
              {t('catalog:activateRecipe')}
            </Button>
          )}
          <Button
            variant="secondary"
            size="sm"
            onClick={handleCalculateCost}
            disabled={calculateCostMutation.isPending}
          >
            {t('catalog:calculateCost')}
          </Button>
        </div>
      </div>

      {/* Recipe metadata */}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3 rounded-lg border border-gray-200 bg-gray-50 p-4">
        <FormField label={t('catalog:prepTime')}>
          <Input
            type="number"
            min="0"
            defaultValue={recipe.prep_time_minutes ?? ''}
            onBlur={(e) => handleUpdateRecipeField('prep_time_minutes', e.target.value)}
            placeholder="0"
          />
        </FormField>
        <FormField label={t('catalog:cookTime')}>
          <Input
            type="number"
            min="0"
            defaultValue={recipe.cook_time_minutes ?? ''}
            onBlur={(e) => handleUpdateRecipeField('cook_time_minutes', e.target.value)}
            placeholder="0"
          />
        </FormField>
        <FormField label={t('catalog:totalTime')}>
          <div className="mt-1 flex h-[38px] items-center rounded-md bg-gray-100 px-3 text-sm text-gray-600">
            {totalTime > 0 ? totalTime : '-'}
          </div>
        </FormField>
        <div className="sm:col-span-3">
          <FormField label={t('catalog:instructions')}>
            <Textarea
              defaultValue={recipe.instructions ?? ''}
              onBlur={(e) => handleUpdateInstructions(e.target.value)}
              rows={3}
              placeholder={t('catalog:instructions')}
            />
          </FormField>
        </div>
      </div>

      {/* Lines table */}
      <div className="overflow-x-auto">
        <table className="min-w-full divide-y divide-gray-300">
          <thead>
            <tr>
              <th className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">{getLabel('recipeLine')}</th>
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
                  <div className="flex items-center gap-1.5">
                    {line.component_type === 'composite_item' && (
                      <Badge variant="info" className="text-[10px] !px-1 !py-0">{t('catalog:combo')}</Badge>
                    )}
                    {line.component_name ?? line.component_id}
                  </div>
                  {line.component_sku && <span className="text-gray-500 ml-1 text-xs">({line.component_sku})</span>}
                </td>
                <td className="whitespace-nowrap px-3 py-4 text-sm">
                  <Input
                    type="number"
                    defaultValue={line.quantity}
                    onBlur={(e) => handleUpdateLine(line.id, 'quantity', e.target.value)}
                    className="!mt-0 w-20"
                    step="0.01"
                  />
                </td>
                <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">{line.unit_name ?? '-'}</td>
                <td className="whitespace-nowrap px-3 py-4 text-sm">
                  <Input
                    type="number"
                    defaultValue={line.wastage_percent}
                    onBlur={(e) => handleUpdateLine(line.id, 'wastage_percent', e.target.value)}
                    className="!mt-0 w-16"
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
                    className={tokens.checkbox.base}
                  />
                </td>
                <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">{line.unit_cost ?? '-'}</td>
                <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">{line.line_cost ?? '-'}</td>
                <td className="whitespace-nowrap px-3 py-4 text-sm">
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => handleDeleteLine(line.id)}
                    disabled={deleteLineMutation.isPending}
                    className="!p-1 text-red-600 hover:text-red-900 hover:bg-red-50"
                  >
                    <Trash2 className="h-4 w-4" />
                  </Button>
                </td>
              </tr>
            ))}
            {/* Add new line row */}
            <tr className="bg-gray-50">
              <td className="px-3 py-4">
                <div className="space-y-2">
                  <Select
                    value={newLine.component_type}
                    onChange={(e) => setNewLine({ ...newLine, component_type: e.target.value as ComponentType, component_id: '' })}
                    className="!mt-0 text-xs"
                  >
                    <option value="product">{t('catalog:componentTypes.product')}</option>
                    <option value="composite_item">{t('catalog:componentTypes.composite_item')}</option>
                  </Select>
                  {newLine.component_type === 'product' ? (
                    <ProductSearchSelect
                      value={newLine.component_id}
                      onChange={(id) => setNewLine({ ...newLine, component_id: id })}
                      placeholder={t(verticalType === 'fnb' || verticalType === 'bakery' ? 'catalog:searchIngredient' : 'catalog:searchComponent')}
                      className="min-w-[200px]"
                    />
                  ) : (
                    <CompositeItemSearchSelect
                      value={newLine.component_id}
                      onChange={(id) => setNewLine({ ...newLine, component_id: id })}
                      placeholder={t('catalog:searchCompositeItem')}
                      className="min-w-[200px]"
                    />
                  )}
                </div>
              </td>
              <td className="px-3 py-4">
                <Input
                  type="number"
                  placeholder={t('catalog:quantity')}
                  value={newLine.quantity}
                  onChange={(e) => setNewLine({ ...newLine, quantity: e.target.value })}
                  className="!mt-0 w-20"
                  step="0.01"
                />
              </td>
              <td className="px-3 py-4">-</td>
              <td className="px-3 py-4">
                <Input
                  type="number"
                  value={newLine.wastage_percent}
                  onChange={(e) => setNewLine({ ...newLine, wastage_percent: e.target.value })}
                  className="!mt-0 w-16"
                  step="0.1"
                />
              </td>
              <td className="px-3 py-4">
                <input
                  type="checkbox"
                  checked={newLine.is_optional}
                  onChange={(e) => setNewLine({ ...newLine, is_optional: e.target.checked })}
                  className={tokens.checkbox.base}
                />
              </td>
              <td className="px-3 py-4">-</td>
              <td className="px-3 py-4">-</td>
              <td className="px-3 py-4">
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={handleAddLine}
                  disabled={!newLine.component_id || !newLine.quantity || createLineMutation.isPending}
                  className="!p-1 text-blue-600 hover:text-blue-700"
                >
                  <Plus className="h-4 w-4" />
                </Button>
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
                <th className="text-left py-1">{getLabel('recipeLine')}</th>
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
