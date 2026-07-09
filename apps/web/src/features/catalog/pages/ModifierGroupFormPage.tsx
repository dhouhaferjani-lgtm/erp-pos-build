import { useState, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router-dom'
import { ArrowLeft, Trash2, Plus } from 'lucide-react'
import { toast } from 'sonner'
import { Input, FormField, Button, Select, MoneyInput, Checkbox, DraftMoneyInput, DraftQuantityInput } from '@/components/atoms'
import { PageHeader } from '@/components/molecules'
import { StickyFormFooter } from '@/components/molecules/StickyFormFooter/StickyFormFooter'
import { ProductLineSelect } from '@/components/molecules/line-items'
import { cn } from '@/lib/utils'
import { tokens, textColors } from '@/lib/designTokens'
import { useCompanyConfig } from '@/contexts'

import {
  useModifierGroup,
  useCreateModifierGroup,
  useUpdateModifierGroup,
  useCreateModifier,
  useUpdateModifier,
  useDeleteModifier,
} from '../hooks/useModifierGroups'
import type { SelectionType, ModifierData } from '../types/compositeItem'
import { useCompanyVerticalLabels } from '../hooks/useVerticalLabels'

export function ModifierGroupFormPage() {
  const { t } = useTranslation(['catalog', 'common'])
  const getLabel = useCompanyVerticalLabels()
  const { config } = useCompanyConfig()
  const currency = config?.currency ?? 'TND'
  const navigate = useNavigate()
  const { id } = useParams<{ id: string }>()
  const isEdit = !!id && id !== 'new'

  const { data: group, isLoading } = useModifierGroup(isEdit ? id : '')
  const createMutation = useCreateModifierGroup()
  const updateMutation = useUpdateModifierGroup()
  const createModifierMutation = useCreateModifier()
  const updateModifierMutation = useUpdateModifier()
  const deleteModifierMutation = useDeleteModifier()

  const [form, setForm] = useState({
    code: '',
    name: '',
    selection_type: 'single' as SelectionType,
    min_selections: 0,
    max_selections: 1,
    is_required: false,
    is_active: true,
  })

  const [newModifier, setNewModifier] = useState({
    code: '',
    name: '',
    price_adjustment: '0',
  })

  useEffect(() => {
    if (group) {
      setForm({
        code: group.code,
        name: group.name,
        selection_type: group.selection_type,
        min_selections: group.min_selections,
        max_selections: group.max_selections,
        is_required: group.is_required,
        is_active: group.is_active,
      })
    }
  }, [group])

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (isEdit) {
      updateMutation.mutate(
        { id, data: form },
        {
          onSuccess: () => toast.success(t('common:saved')),
          onError: () => toast.error(t('common:error')),
        }
      )
    } else {
      createMutation.mutate(form, {
        onSuccess: (created) => {
          toast.success(t('common:saved'))
          navigate(`/catalog/modifier-groups/${created.id}/edit`)
        },
        onError: () => toast.error(t('common:error')),
      })
    }
  }

  const handleAddModifier = () => {
    if (!newModifier.code || !newModifier.name || !isEdit) return
    createModifierMutation.mutate(
      {
        groupId: id,
        data: {
          code: newModifier.code,
          name: newModifier.name,
          price_adjustment: newModifier.price_adjustment,
        },
      },
      {
        onSuccess: () => {
          setNewModifier({ code: '', name: '', price_adjustment: '0' })
          toast.success(t('common:saved'))
        },
      }
    )
  }

  const handleDeleteModifier = (modifierId: string) => {
    deleteModifierMutation.mutate(modifierId, {
      onSuccess: () => toast.success(t('common:deleted')),
    })
  }

  const handleUpdateModifier = (modifierId: string, field: string, value: string | boolean) => {
    updateModifierMutation.mutate(
      {
        id: modifierId,
        data: { [field]: value },
      },
      {
        onSuccess: () => toast.success(t('common:saved')),
      }
    )
  }

  if (isEdit && isLoading) {
    return <div className={cn('text-center py-8', textColors.tertiary)}>{t('common:loading')}</div>
  }

  const modifiers = group?.modifiers ?? []

  const pageTitle = isEdit
    ? `${t('common:actions.edit')} ${getLabel('modifierGroup')}`
    : `${t('common:actions.create')} ${getLabel('modifierGroup')}`

  return (
    <div className="space-y-6">
      <PageHeader
        title={pageTitle}
        breadcrumb={
          <Button
            type="button"
            variant="secondary"
            size="sm"
            onClick={() => navigate('/catalog/modifier-groups')}
            aria-label={t('common:back')}
          >
            <ArrowLeft className="h-4 w-4" />
          </Button>
        }
      />

      <form onSubmit={handleSubmit} className="space-y-6">
        <div className={tokens.card.base}>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <FormField label={t('catalog:code')} htmlFor="mg-code" required>
              <Input
                id="mg-code"
                required
                value={form.code}
                onChange={(e) => { setForm({ ...form, code: e.target.value }); }}
              />
            </FormField>
            <FormField label={t('catalog:name')} htmlFor="mg-name" required>
              <Input
                id="mg-name"
                required
                value={form.name}
                onChange={(e) => { setForm({ ...form, name: e.target.value }); }}
              />
            </FormField>
            <FormField label={t('catalog:selectionType')} htmlFor="mg-selection">
              <Select
                id="mg-selection"
                value={form.selection_type}
                onChange={(e) => { setForm({ ...form, selection_type: e.target.value as SelectionType }); }}
              >
                <option value="single">{t('catalog:single')}</option>
                <option value="multiple">{t('catalog:multiple')}</option>
              </Select>
            </FormField>
            <div className="grid grid-cols-2 gap-3">
              <FormField label={t('catalog:minSelections')} htmlFor="mg-min">
                <Input
                  id="mg-min"
                  type="number"
                  min="0"
                  value={form.min_selections}
                  onChange={(e) => { setForm({ ...form, min_selections: Number(e.target.value) }); }}
                />
              </FormField>
              <FormField label={t('catalog:maxSelections')} htmlFor="mg-max">
                <Input
                  id="mg-max"
                  type="number"
                  min="1"
                  value={form.max_selections}
                  onChange={(e) => { setForm({ ...form, max_selections: Number(e.target.value) }); }}
                />
              </FormField>
            </div>
            <div className="flex items-center gap-6 pt-6">
              <label className="flex items-center gap-2">
                <Checkbox
                  checked={form.is_required}
                  onChange={(e) => { setForm({ ...form, is_required: e.target.checked }); }}
                />
                <span className={cn('text-sm', textColors.secondary)}>{t('catalog:isRequired')}</span>
              </label>
              <label className="flex items-center gap-2">
                <Checkbox
                  checked={form.is_active}
                  onChange={(e) => { setForm({ ...form, is_active: e.target.checked }); }}
                />
                <span className={cn('text-sm', textColors.secondary)}>{t('catalog:isActive')}</span>
              </label>
            </div>
          </div>
        </div>

        <StickyFormFooter>
          <Button
            type="button"
            variant="secondary"
            onClick={() => navigate('/catalog/modifier-groups')}
          >
            {t('common:cancel')}
          </Button>
          <Button
            type="submit"
            variant="primary"
            disabled={createMutation.isPending || updateMutation.isPending}
          >
            {t('common:save')}
          </Button>
        </StickyFormFooter>
      </form>

      {/* Modifiers section (edit mode only) */}
      {isEdit && (
        <div className={tokens.card.base}>
          <h3 className={cn(tokens.heading.section, 'mb-4')}>{t('catalog:modifiers')}</h3>
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-300">
              <thead>
                <tr>
                  <th className={cn('px-3 py-3.5 text-left text-sm font-semibold', textColors.primary)}>{t('catalog:code')}</th>
                  <th className={cn('px-3 py-3.5 text-left text-sm font-semibold', textColors.primary)}>{t('catalog:name')}</th>
                  <th className={cn('px-3 py-3.5 text-left text-sm font-semibold', textColors.primary)}>{t('catalog:priceAdjustment')}</th>
                  <th className={cn('px-3 py-3.5 text-left text-sm font-semibold', textColors.primary)}>{t('catalog:isDefault')}</th>
                  <th className={cn('px-3 py-3.5 text-left text-sm font-semibold', textColors.primary)}>{t('catalog:inventoryLink')}</th>
                  <th className="px-3 py-3.5"></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200">
                {modifiers.map((mod: ModifierData) => (
                  <tr key={mod.id}>
                    <td className={cn('whitespace-nowrap px-3 py-4 text-sm', textColors.primary)}>{mod.code}</td>
                    <td className="whitespace-nowrap px-3 py-4 text-sm">
                      <Input
                        type="text"
                        defaultValue={mod.name}
                        onBlur={(e) => {
                          if (e.target.value !== mod.name) {
                            handleUpdateModifier(mod.id, 'name', e.target.value)
                          }
                        }}
                        className="!mt-0 w-32"
                      />
                    </td>
                    <td className="whitespace-nowrap px-3 py-4 text-sm">
                      <DraftMoneyInput
                        initialValue={mod.price_adjustment}
                        currency={currency}
                        onCommit={(v) => { handleUpdateModifier(mod.id, 'price_adjustment', v) }}
                        className="!mt-0 w-24"
                        min="-999999"
                      />
                    </td>
                    <td className="whitespace-nowrap px-3 py-4 text-sm">
                      <Checkbox
                        defaultChecked={mod.is_default}
                        onChange={(e) => { handleUpdateModifier(mod.id, 'is_default', e.target.checked); }}
                      />
                    </td>
                    <td className="px-3 py-4 text-sm">
                      <div className="flex items-center gap-2">
                        <ProductLineSelect
                          value={mod.component_id ?? ''}
                          onChange={(productId) => { handleUpdateModifier(mod.id, 'component_id', productId); }}
                          placeholder={t('catalog:searchComponent')}
                          className="min-w-[160px]"
                        />
                        {mod.component_id && (
                            <DraftQuantityInput
                              initialValue={mod.component_quantity ?? ''}
                              decimalPlaces={4}
                              min="0"
                              onCommit={(v) => { handleUpdateModifier(mod.id, 'component_quantity', v) }}
                              className="!mt-0 w-20"
                              placeholder={t('catalog:quantity')}
                          />
                        )}
                      </div>
                    </td>
                    <td className="whitespace-nowrap px-3 py-4 text-sm">
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => { handleDeleteModifier(mod.id); }}
                        aria-label={t('common:delete')}
                        className={cn('!p-1', textColors.error, textColors.hoverError)}
                      >
                        <Trash2 className="h-4 w-4" />
                      </Button>
                    </td>
                  </tr>
                ))}
                <tr className={tokens.table.header}>
                  <td className="px-3 py-4">
                    <Input
                      type="text"
                      placeholder={t('catalog:code')}
                      value={newModifier.code}
                      onChange={(e) => { setNewModifier({ ...newModifier, code: e.target.value }); }}
                      className="!mt-0 w-24"
                    />
                  </td>
                  <td className="px-3 py-4">
                    <Input
                      type="text"
                      placeholder={t('catalog:name')}
                      value={newModifier.name}
                      onChange={(e) => { setNewModifier({ ...newModifier, name: e.target.value }); }}
                      className="!mt-0 w-32"
                    />
                  </td>
                  <td className="px-3 py-4">
                    <MoneyInput
                      currency={currency}
                      min="-999999"
                      value={newModifier.price_adjustment}
                      onChange={(v) => { setNewModifier({ ...newModifier, price_adjustment: v }); }}
                      className="!mt-0 w-24"
                    />
                  </td>
                  <td className="px-3 py-4">-</td>
                  <td className="px-3 py-4">-</td>
                  <td className="px-3 py-4">
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={handleAddModifier}
                      disabled={!newModifier.code || !newModifier.name || createModifierMutation.isPending}
                      aria-label={t('catalog:createModifierGroup')}
                      className={cn('!p-1', textColors.brand)}
                    >
                      <Plus className="h-4 w-4" />
                    </Button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  )
}
