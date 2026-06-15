import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Trash2, Plus } from 'lucide-react'
import { toast } from 'sonner'
import { Input, Button, Checkbox, Select, MoneyInput, QuantityInput } from '@/components/atoms'
import { tokens, textColors, borderColors } from '@/lib/designTokens'
import { useCompanyConfig } from '@/contexts'
import type { CompositeItemVariantData, PriceAdjustmentType } from '../types/compositeItem'
import { useCreateVariant, useUpdateVariant, useDeleteVariant } from '../hooks/useRecipes'

/** Controlled MoneyInput that only fires onCommit on blur — prevents per-keystroke API calls in table rows. */
function BlurMoneyInput({
  initialValue,
  currency,
  onCommit,
  className,
}: {
  initialValue: string
  currency: string
  onCommit: (value: string) => void
  className?: string
}) {
  const [draft, setDraft] = useState(initialValue)
  return (
    <MoneyInput
      currency={currency}
      min="-999999"
      value={draft}
      onChange={setDraft}
      onBlur={() => { if (draft !== initialValue) onCommit(draft) }}
      className={className}
    />
  )
}

/** Controlled QuantityInput that only fires onCommit on blur — prevents per-keystroke API calls in table rows. */
function BlurQuantityInput({
  initialValue,
  decimalPlaces,
  onCommit,
  className,
  min,
}: {
  initialValue: string
  decimalPlaces: number
  onCommit: (value: string) => void
  className?: string
  min?: string
}) {
  const [draft, setDraft] = useState(initialValue)
  return (
    <QuantityInput
      decimalPlaces={decimalPlaces}
      value={draft}
      onChange={setDraft}
      onBlur={() => { if (draft !== initialValue) onCommit(draft) }}
      className={className}
      {...(min !== undefined ? { min } : {})}
    />
  )
}

interface VariantEditorProps {
  compositeItemId: string
  variants: CompositeItemVariantData[]
}

export function VariantEditor({ compositeItemId, variants }: VariantEditorProps) {
  const { t } = useTranslation(['catalog', 'common'])
  const { config } = useCompanyConfig()
  const currency = config?.currency ?? 'TND'
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
          price_adjustment: newVariant.price_adjustment,
          recipe_multiplier: newVariant.recipe_multiplier,
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
    updateMutation.mutate({ id, data: { [field]: value } })
  }

  const handleDelete = (id: string) => {
    deleteMutation.mutate(id, {
      onSuccess: () => toast.success(t('common:deleted')),
    })
  }

  return (
    <div className="space-y-4">
      <div className="overflow-x-auto">
        <table className={`min-w-full divide-y ${borderColors.default}`}>
          <thead>
            <tr>
              <th className={`px-3 py-3.5 text-left text-sm font-semibold ${textColors.primary}`}>{t('catalog:code')}</th>
              <th className={`px-3 py-3.5 text-left text-sm font-semibold ${textColors.primary}`}>{t('catalog:name')}</th>
              <th className={`px-3 py-3.5 text-left text-sm font-semibold ${textColors.primary}`}>{t('catalog:priceAdjustmentType')}</th>
              <th className={`px-3 py-3.5 text-left text-sm font-semibold ${textColors.primary}`}>{t('catalog:priceAdjustment')}</th>
              <th className={`px-3 py-3.5 text-left text-sm font-semibold ${textColors.primary}`}>{t('catalog:recipeMultiplier')}</th>
              <th className={`px-3 py-3.5 text-left text-sm font-semibold ${textColors.primary}`}>{t('catalog:isDefault')}</th>
              <th className="px-3 py-3.5"></th>
            </tr>
          </thead>
          <tbody className={`divide-y ${borderColors.divideDefault}`}>
            {variants.map((variant) => (
              <tr key={variant.id}>
                <td className={`whitespace-nowrap px-3 py-4 text-sm ${textColors.primary}`}>{variant.code}</td>
                <td className="whitespace-nowrap px-3 py-4 text-sm">
                  <Input
                    type="text"
                    defaultValue={variant.name}
                    onBlur={(e) => { handleUpdate(variant.id, 'name', e.target.value); }}
                    className="!mt-0 w-32"
                  />
                </td>
                <td className="whitespace-nowrap px-3 py-4 text-sm">
                  <Select
                    defaultValue={variant.price_adjustment_type}
                    onChange={(e) => { handleUpdate(variant.id, 'price_adjustment_type', e.target.value); }}
                    className="!mt-0"
                  >
                    <option value="absolute">{t('catalog:absolute')}</option>
                    <option value="percentage">{t('catalog:percentage')}</option>
                    <option value="override">{t('catalog:override')}</option>
                  </Select>
                </td>
                <td className="whitespace-nowrap px-3 py-4 text-sm">
                  <BlurMoneyInput
                    initialValue={variant.price_adjustment}
                    currency={currency}
                    onCommit={(v) => { handleUpdate(variant.id, 'price_adjustment', v) }}
                    className="!mt-0 w-24"
                  />
                </td>
                <td className="whitespace-nowrap px-3 py-4 text-sm">
                  <BlurQuantityInput
                    initialValue={variant.recipe_multiplier}
                    decimalPlaces={2}
                    onCommit={(v) => { handleUpdate(variant.id, 'recipe_multiplier', v) }}
                    className="!mt-0 w-20"
                    min="0.01"
                  />
                </td>
                <td className="whitespace-nowrap px-3 py-4 text-sm">
                  <input
                    type="radio"
                    name="default_variant"
                    checked={variant.is_default}
                    onChange={() => { handleUpdate(variant.id, 'is_default', true); }}
                    className={tokens.radio.base}
                  />
                </td>
                <td className="whitespace-nowrap px-3 py-4 text-sm">
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => { handleDelete(variant.id); }}
                    disabled={deleteMutation.isPending}
                    className={`!p-1 ${textColors.error} ${textColors.hoverError}`}
                  >
                    <Trash2 className="h-4 w-4" />
                  </Button>
                </td>
              </tr>
            ))}
            {/* Add new variant row */}
            <tr className={tokens.table.header}>
              <td className="px-3 py-4">
                <Input
                  type="text"
                  placeholder={t('catalog:code')}
                  value={newVariant.code}
                  onChange={(e) => { setNewVariant({ ...newVariant, code: e.target.value }); }}
                  className="!mt-0 w-24"
                />
              </td>
              <td className="px-3 py-4">
                <Input
                  type="text"
                  placeholder={t('catalog:name')}
                  value={newVariant.name}
                  onChange={(e) => { setNewVariant({ ...newVariant, name: e.target.value }); }}
                  className="!mt-0 w-32"
                />
              </td>
              <td className="px-3 py-4">
                <Select
                  value={newVariant.price_adjustment_type}
                  onChange={(e) => { setNewVariant({ ...newVariant, price_adjustment_type: e.target.value as PriceAdjustmentType }); }}
                  className="!mt-0"
                >
                  <option value="absolute">{t('catalog:absolute')}</option>
                  <option value="percentage">{t('catalog:percentage')}</option>
                  <option value="override">{t('catalog:override')}</option>
                </Select>
              </td>
              <td className="px-3 py-4">
                <MoneyInput
                  currency={currency}
                  min="-999999"
                  value={newVariant.price_adjustment}
                  onChange={(v) => { setNewVariant({ ...newVariant, price_adjustment: v }); }}
                  className="!mt-0 w-24"
                />
              </td>
              <td className="px-3 py-4">
                <QuantityInput
                  decimalPlaces={2}
                  min="0.01"
                  value={newVariant.recipe_multiplier}
                  onChange={(v) => { setNewVariant({ ...newVariant, recipe_multiplier: v }); }}
                  className="!mt-0 w-20"
                />
              </td>
              <td className="px-3 py-4">
                <Checkbox
                  checked={newVariant.is_default}
                  onChange={(e) => { setNewVariant({ ...newVariant, is_default: e.target.checked }); }}
                />
              </td>
              <td className="px-3 py-4">
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={handleAdd}
                  disabled={!newVariant.code || !newVariant.name || createMutation.isPending}
                  className={`!p-1 ${textColors.brand}`}
                >
                  <Plus className="h-4 w-4" />
                </Button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  )
}
