import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Trash2, Plus } from 'lucide-react'
import { toast } from 'sonner'
import type { CompositeItemVariantData, PriceAdjustmentType } from '../types/compositeItem'
import { useCreateVariant, useUpdateVariant, useDeleteVariant } from '../hooks/useRecipes'

interface VariantEditorProps {
  compositeItemId: string
  variants: CompositeItemVariantData[]
}

export function VariantEditor({ compositeItemId, variants }: VariantEditorProps) {
  const { t } = useTranslation(['catalog', 'common'])
  const [newVariant, setNewVariant] = useState({
    code: '',
    name: '',
    price_adjustment_type: 'absolute' as PriceAdjustmentType,
    price_adjustment: '0',
    recipe_multiplier: '1',
    is_default: false,
  })

  const createMutation = useCreateVariant()
  const updateMutation = useUpdateVariant()
  const deleteMutation = useDeleteVariant()

  const handleAdd = () => {
    if (!newVariant.code || !newVariant.name) return
    createMutation.mutate(
      {
        compositeItemId,
        data: {
          code: newVariant.code,
          name: newVariant.name,
          price_adjustment_type: newVariant.price_adjustment_type,
          price_adjustment: Number(newVariant.price_adjustment),
          recipe_multiplier: Number(newVariant.recipe_multiplier),
          is_default: newVariant.is_default,
        },
      },
      {
        onSuccess: () => {
          setNewVariant({ code: '', name: '', price_adjustment_type: 'absolute', price_adjustment: '0', recipe_multiplier: '1', is_default: false })
          toast.success(t('common:saved'))
        },
      }
    )
  }

  const handleUpdate = (id: string, field: string, value: string | boolean) => {
    const data: Record<string, unknown> = {}
    if (typeof value === 'boolean') {
      data[field] = value
    } else if (field === 'price_adjustment' || field === 'recipe_multiplier') {
      data[field] = Number(value)
    } else {
      data[field] = value
    }
    updateMutation.mutate({ id, data })
  }

  const handleDelete = (id: string) => {
    deleteMutation.mutate(id, {
      onSuccess: () => toast.success(t('common:deleted')),
    })
  }

  return (
    <div className="space-y-4">
      <div className="overflow-x-auto">
        <table className="min-w-full divide-y divide-gray-300">
          <thead>
            <tr>
              <th className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">{t('catalog:code')}</th>
              <th className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">{t('catalog:name')}</th>
              <th className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">{t('catalog:priceAdjustmentType')}</th>
              <th className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">{t('catalog:priceAdjustment')}</th>
              <th className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">{t('catalog:recipeMultiplier')}</th>
              <th className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">{t('catalog:isDefault')}</th>
              <th className="px-3 py-3.5"></th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-200">
            {variants.map((variant) => (
              <tr key={variant.id}>
                <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-900">{variant.code}</td>
                <td className="whitespace-nowrap px-3 py-4 text-sm">
                  <input
                    type="text"
                    defaultValue={variant.name}
                    onBlur={(e) => handleUpdate(variant.id, 'name', e.target.value)}
                    className="w-32 rounded-md border-gray-300 text-sm"
                  />
                </td>
                <td className="whitespace-nowrap px-3 py-4 text-sm">
                  <select
                    defaultValue={variant.price_adjustment_type}
                    onChange={(e) => handleUpdate(variant.id, 'price_adjustment_type', e.target.value)}
                    className="rounded-md border-gray-300 text-sm"
                  >
                    <option value="absolute">{t('catalog:absolute')}</option>
                    <option value="percentage">{t('catalog:percentage')}</option>
                    <option value="override">{t('catalog:override')}</option>
                  </select>
                </td>
                <td className="whitespace-nowrap px-3 py-4 text-sm">
                  <input
                    type="number"
                    defaultValue={variant.price_adjustment}
                    onBlur={(e) => handleUpdate(variant.id, 'price_adjustment', e.target.value)}
                    className="w-24 rounded-md border-gray-300 text-sm"
                    step="0.01"
                  />
                </td>
                <td className="whitespace-nowrap px-3 py-4 text-sm">
                  <input
                    type="number"
                    defaultValue={variant.recipe_multiplier}
                    onBlur={(e) => handleUpdate(variant.id, 'recipe_multiplier', e.target.value)}
                    className="w-20 rounded-md border-gray-300 text-sm"
                    step="0.01"
                    min="0.01"
                  />
                </td>
                <td className="whitespace-nowrap px-3 py-4 text-sm">
                  <input
                    type="radio"
                    name="default_variant"
                    checked={variant.is_default}
                    onChange={() => handleUpdate(variant.id, 'is_default', true)}
                    className="border-gray-300"
                  />
                </td>
                <td className="whitespace-nowrap px-3 py-4 text-sm">
                  <button
                    onClick={() => handleDelete(variant.id)}
                    className="text-red-600 hover:text-red-900"
                    disabled={deleteMutation.isPending}
                  >
                    <Trash2 className="h-4 w-4" />
                  </button>
                </td>
              </tr>
            ))}
            {/* Add new variant row */}
            <tr className="bg-gray-50">
              <td className="px-3 py-4">
                <input
                  type="text"
                  placeholder={t('catalog:code')}
                  value={newVariant.code}
                  onChange={(e) => setNewVariant({ ...newVariant, code: e.target.value })}
                  className="w-24 rounded-md border-gray-300 text-sm"
                />
              </td>
              <td className="px-3 py-4">
                <input
                  type="text"
                  placeholder={t('catalog:name')}
                  value={newVariant.name}
                  onChange={(e) => setNewVariant({ ...newVariant, name: e.target.value })}
                  className="w-32 rounded-md border-gray-300 text-sm"
                />
              </td>
              <td className="px-3 py-4">
                <select
                  value={newVariant.price_adjustment_type}
                  onChange={(e) => setNewVariant({ ...newVariant, price_adjustment_type: e.target.value as PriceAdjustmentType })}
                  className="rounded-md border-gray-300 text-sm"
                >
                  <option value="absolute">{t('catalog:absolute')}</option>
                  <option value="percentage">{t('catalog:percentage')}</option>
                  <option value="override">{t('catalog:override')}</option>
                </select>
              </td>
              <td className="px-3 py-4">
                <input
                  type="number"
                  value={newVariant.price_adjustment}
                  onChange={(e) => setNewVariant({ ...newVariant, price_adjustment: e.target.value })}
                  className="w-24 rounded-md border-gray-300 text-sm"
                  step="0.01"
                />
              </td>
              <td className="px-3 py-4">
                <input
                  type="number"
                  value={newVariant.recipe_multiplier}
                  onChange={(e) => setNewVariant({ ...newVariant, recipe_multiplier: e.target.value })}
                  className="w-20 rounded-md border-gray-300 text-sm"
                  step="0.01"
                  min="0.01"
                />
              </td>
              <td className="px-3 py-4">
                <input
                  type="checkbox"
                  checked={newVariant.is_default}
                  onChange={(e) => setNewVariant({ ...newVariant, is_default: e.target.checked })}
                  className="rounded border-gray-300"
                />
              </td>
              <td className="px-3 py-4">
                <button
                  onClick={handleAdd}
                  disabled={!newVariant.code || !newVariant.name || createMutation.isPending}
                  className="text-indigo-600 hover:text-indigo-900 disabled:opacity-50"
                >
                  <Plus className="h-4 w-4" />
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  )
}
