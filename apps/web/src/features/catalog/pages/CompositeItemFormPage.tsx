import { useState, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router-dom'
import { ArrowLeft } from 'lucide-react'
import { toast } from 'sonner'
import { Input, FormField, Button, Select } from '@/components/atoms'
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/molecules/Tabs/Tabs'
import { StickyFormFooter } from '@/components/molecules/StickyFormFooter/StickyFormFooter'
import { tokens } from '@/lib/designTokens'
import { useQuery } from '@tanstack/react-query'
import { fetchLocations } from '@/features/location/api'
import { useCompositeItem, useCreateCompositeItem, useUpdateCompositeItem, useCompositeItemAvailability } from '../hooks/useCompositeItems'
import { useCreateRecipe } from '../hooks/useRecipes'
import { RecipeLineEditor } from '../components/RecipeLineEditor'
import { VariantEditor } from '../components/VariantEditor'
import { ModifierGroupAssigner } from '../components/ModifierGroupAssigner'
import { useVerticalLabels, companyVerticalToCatalog } from '../hooks/useVerticalLabels'
import { useCompanyConfig } from '@/contexts'
import type { ProductionType, PricingMode } from '../types/compositeItem'

type TabValue = 'details' | 'recipeTab' | 'sizesTab' | 'modifiersTab'

export function CompositeItemFormPage() {
  const { t } = useTranslation(['catalog', 'common'])
  const navigate = useNavigate()
  const { id } = useParams<{ id: string }>()
  const isEdit = !!id && id !== 'new'
  const { config, hasModule } = useCompanyConfig()
  const hasInventory = hasModule('Inventory')
  const companyVerticalType = companyVerticalToCatalog(config?.vertical)

  const { data: item, isLoading } = useCompositeItem(isEdit ? id : '')
  const getLabel = useVerticalLabels(item?.vertical_type ?? companyVerticalType)
  const createMutation = useCreateCompositeItem()
  const updateMutation = useUpdateCompositeItem()
  const createRecipeMutation = useCreateRecipe()

  const [activeTab, setActiveTab] = useState<TabValue>('details')
  const [selectedLocationId, setSelectedLocationId] = useState('')

  const { data: locations } = useQuery({
    queryKey: ['locations'],
    queryFn: fetchLocations,
    enabled: isEdit,
    staleTime: 60000,
  })

  const { data: availability, isLoading: isCheckingAvailability } = useCompositeItemAvailability(
    isEdit ? id : '',
    selectedLocationId,
  )

  const [form, setForm] = useState({
    code: '',
    name: '',
    category_id: '',
    vertical_type: companyVerticalType,
    base_price: '',
    manual_cost: '',
    production_type: 'made_to_order' as ProductionType,
    pricing_mode: 'standard' as PricingMode,
    tax_rate: '',
    is_active: true,
    is_available: true,
    image_url: '',
  })

  // Sync vertical_type from company config when it loads (for new items)
  useEffect(() => {
    if (!isEdit && companyVerticalType !== 'generic') {
      setForm((prev) => ({ ...prev, vertical_type: companyVerticalType }))
    }
  }, [isEdit, companyVerticalType])

  useEffect(() => {
    if (item) {
      setForm({
        code: item.code,
        name: item.name,
        category_id: item.category_id ?? '',
        vertical_type: item.vertical_type,
        base_price: item.base_price,
        manual_cost: item.manual_cost ?? '',
        production_type: item.production_type,
        pricing_mode: item.pricing_mode ?? 'standard',
        tax_rate: item.tax_rate ?? '',
        is_active: item.is_active,
        is_available: item.is_available,
        image_url: item.image_url ?? '',
      })
    }
  }, [item])

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()

    const data = {
      code: form.code,
      name: form.name,
      category_id: form.category_id || null,
      vertical_type: form.vertical_type,
      base_price: Number(form.base_price),
      manual_cost: form.manual_cost ? Number(form.manual_cost) : null,
      production_type: form.production_type,
      pricing_mode: form.pricing_mode,
      tax_rate: form.tax_rate ? Number(form.tax_rate) : null,
      is_active: form.is_active,
      is_available: form.is_available,
      image_url: form.image_url || null,
    }

    if (isEdit) {
      updateMutation.mutate(
        { id, data },
        {
          onSuccess: () => {
            toast.success(t('common:saved'))
          },
          onError: () => toast.error(t('common:error')),
        }
      )
    } else {
      createMutation.mutate(data, {
        onSuccess: (created) => {
          toast.success(t('common:saved'))
          navigate(`/catalog/composite-items/${created.id}/edit`)
        },
        onError: () => toast.error(t('common:error')),
      })
    }
  }

  const handleCreateRecipe = () => {
    if (!isEdit || !id) return
    createRecipeMutation.mutate(
      { compositeItemId: id, data: {} },
      {
        onSuccess: () => {
          toast.success(t('common:created'))
        },
      }
    )
  }

  if (isEdit && isLoading) {
    return <div className="text-center py-8 text-gray-500">{t('common:loading')}</div>
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center gap-4">
        <button
          type="button"
          onClick={() => navigate('/catalog/composite-items')}
          className="rounded-lg p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
        >
          <ArrowLeft className="h-5 w-5" />
        </button>
        <h1 className="text-2xl font-semibold text-gray-900">
          {isEdit
            ? `${t('common:actions.edit')} ${getLabel('compositeItem')}`
            : `${t('common:actions.create')} ${getLabel('compositeItem')}`}
        </h1>
      </div>

      {/* Tabs (edit mode) / Details form (create mode) */}
      {isEdit ? (
        <Tabs defaultValue="details" value={activeTab} onChange={(v) => { setActiveTab(v as TabValue); }}>
          <TabsList>
            <TabsTrigger value="details">{t('catalog:details')}</TabsTrigger>
            {hasInventory && (
              <TabsTrigger value="recipeTab">{getLabel('recipe')}</TabsTrigger>
            )}
            <TabsTrigger value="sizesTab">{getLabel('variant')}</TabsTrigger>
            <TabsTrigger value="modifiersTab">{getLabel('modifierGroup')}</TabsTrigger>
          </TabsList>

          <TabsContent value="details" className="mt-6">
            <form onSubmit={handleSubmit} className="space-y-6">
              <div className={tokens.card.base}>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <FormField label={t('catalog:code')} htmlFor="ci-code" required>
                    <Input
                      id="ci-code"
                      required
                      value={form.code}
                      onChange={(e) => { setForm({ ...form, code: e.target.value }); }}
                    />
                  </FormField>
                  <FormField label={t('catalog:name')} htmlFor="ci-name" required>
                    <Input
                      id="ci-name"
                      required
                      value={form.name}
                      onChange={(e) => { setForm({ ...form, name: e.target.value }); }}
                    />
                  </FormField>
                  <FormField label={t('catalog:basePrice')} htmlFor="ci-price" required>
                    <Input
                      id="ci-price"
                      type="number"
                      required
                      step="0.01"
                      min="0"
                      value={form.base_price}
                      onChange={(e) => { setForm({ ...form, base_price: e.target.value }); }}
                    />
                  </FormField>
                  <FormField label={t('catalog:manualCost')} htmlFor="ci-manual-cost">
                    <Input
                      id="ci-manual-cost"
                      type="number"
                      step="0.01"
                      min="0"
                      value={form.manual_cost}
                      onChange={(e) => { setForm({ ...form, manual_cost: e.target.value }); }}
                      placeholder={t('catalog:manualCostPlaceholder')}
                    />
                    {form.manual_cost && parseFloat(form.base_price) > 0 && (
                      <p className="mt-1 text-sm text-gray-500">
                        {t('catalog:margin')}: {(((parseFloat(form.base_price) - parseFloat(form.manual_cost)) / parseFloat(form.base_price)) * 100).toFixed(1)}%
                      </p>
                    )}
                  </FormField>
                  <input type="hidden" name="vertical_type" value={form.vertical_type} />
                  <FormField label={t('catalog:productionType')} htmlFor="ci-production">
                    <Select
                      id="ci-production"
                      value={form.production_type}
                      onChange={(e) => { setForm({ ...form, production_type: e.target.value as ProductionType }); }}
                    >
                      <option value="made_to_order">{t('catalog:productionTypes.made_to_order')}</option>
                      <option value="batch">{t('catalog:productionTypes.batch')}</option>
                      <option value="stock">{t('catalog:productionTypes.stock')}</option>
                    </Select>
                  </FormField>
                  {(companyVerticalType === 'fnb' || companyVerticalType === 'bakery' || form.vertical_type === 'fnb' || form.vertical_type === 'bakery') && (
                    <FormField label={t('catalog:pricingMode')} htmlFor="ci-pricing-mode">
                      <Select
                        id="ci-pricing-mode"
                        value={form.pricing_mode}
                        onChange={(e) => { setForm({ ...form, pricing_mode: e.target.value as PricingMode }); }}
                      >
                        <option value="standard">{t('catalog:pricingModes.standard')}</option>
                        <option value="fixed_bundle">{t('catalog:pricingModes.fixed_bundle')}</option>
                      </Select>
                    </FormField>
                  )}
                  <FormField label={t('catalog:taxRate')} htmlFor="ci-tax">
                    <Input
                      id="ci-tax"
                      type="number"
                      step="0.01"
                      min="0"
                      max="100"
                      value={form.tax_rate}
                      onChange={(e) => { setForm({ ...form, tax_rate: e.target.value }); }}
                    />
                  </FormField>
                  <div className="flex items-center gap-6 pt-6">
                    <label className="flex items-center gap-2">
                      <input
                        type="checkbox"
                        checked={form.is_active}
                        onChange={(e) => { setForm({ ...form, is_active: e.target.checked }); }}
                        className={tokens.checkbox.base}
                      />
                      <span className="text-sm text-gray-700">{t('catalog:isActive')}</span>
                    </label>
                    <label className="flex items-center gap-2">
                      <input
                        type="checkbox"
                        checked={form.is_available}
                        onChange={(e) => { setForm({ ...form, is_available: e.target.checked }); }}
                        className={tokens.checkbox.base}
                      />
                      <span className="text-sm text-gray-700">{t('catalog:isAvailable')}</span>
                    </label>
                  </div>
                </div>
              </div>

              <StickyFormFooter>
                <Button
                  type="button"
                  variant="secondary"
                  onClick={() => navigate('/catalog/composite-items')}
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

            {/* Availability section — only shown when Inventory module is enabled */}
            {hasInventory && item?.active_recipe && (
              <div className={`${tokens.card.base} mt-6`}>
                <h3 className="text-lg font-medium text-gray-900 mb-4">{t('catalog:availability')}</h3>
                <div className="flex items-end gap-3 mb-4">
                  <FormField label={t('common:location')} htmlFor="avail-location" className="flex-1">
                    <Select
                      id="avail-location"
                      value={selectedLocationId}
                      onChange={(e) => { setSelectedLocationId(e.target.value); }}
                    >
                      <option value="">{t('common:select')}</option>
                      {(locations ?? []).map((loc) => (
                        <option key={loc.id} value={loc.id}>{loc.name}</option>
                      ))}
                    </Select>
                  </FormField>
                </div>
                {isCheckingAvailability && (
                  <p className="text-sm text-gray-500">{t('common:loading')}</p>
                )}
                {availability && selectedLocationId && (
                  <div className="space-y-3">
                    <div className="flex items-center gap-4">
                      <div className="text-2xl font-bold text-gray-900">{availability.available_quantity}</div>
                      <div className="text-sm text-gray-600">{t('catalog:maxProducible')}</div>
                      {availability.limiting_component && (
                        <div className="text-sm text-amber-600">
                          {t('catalog:limitingIngredient')}: {availability.limiting_component}
                        </div>
                      )}
                    </div>
                    {availability.components.length > 0 && (
                      <table className="min-w-full text-sm">
                        <thead>
                          <tr>
                            <th className="text-left py-1 font-medium text-gray-700">{getLabel('recipeLine')}</th>
                            <th className="text-right py-1 font-medium text-gray-700">{t('catalog:required')}</th>
                            <th className="text-right py-1 font-medium text-gray-700">{t('catalog:available')}</th>
                            <th className="text-right py-1 font-medium text-gray-700">{t('catalog:maxProducible')}</th>
                          </tr>
                        </thead>
                        <tbody>
                          {availability.components.map((comp) => (
                            <tr key={comp.product_id}>
                              <td className="py-1">{comp.product_name}</td>
                              <td className="text-right py-1">{comp.required_quantity}</td>
                              <td className="text-right py-1">{comp.available_quantity}</td>
                              <td className="text-right py-1">{comp.max_produces}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    )}
                  </div>
                )}
              </div>
            )}
          </TabsContent>

          {hasInventory && (
            <TabsContent value="recipeTab" className="mt-6">
              {item?.active_recipe ? (
                <RecipeLineEditor
                  recipe={item.active_recipe}
                  compositeItemId={id}
                  verticalType={item.vertical_type}
                />
              ) : (
                <div className="text-center py-8">
                  <p className="text-gray-500 mb-4">{t('catalog:createRecipe')}</p>
                  <Button
                    onClick={handleCreateRecipe}
                    disabled={createRecipeMutation.isPending}
                  >
                    {t('catalog:createRecipe')}
                  </Button>
                </div>
              )}
            </TabsContent>
          )}

          <TabsContent value="sizesTab" className="mt-6">
            <VariantEditor
              compositeItemId={id}
              variants={item?.variants ?? []}
            />
          </TabsContent>

          <TabsContent value="modifiersTab" className="mt-6">
            <ModifierGroupAssigner
              compositeItemId={id}
              assignedGroups={item?.modifier_groups ?? []}
            />
          </TabsContent>
        </Tabs>
      ) : (
        <form onSubmit={handleSubmit} className="space-y-6">
          <div className={tokens.card.base}>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <FormField label={t('catalog:code')} htmlFor="ci-code" required>
                <Input
                  id="ci-code"
                  required
                  value={form.code}
                  onChange={(e) => { setForm({ ...form, code: e.target.value }); }}
                />
              </FormField>
              <FormField label={t('catalog:name')} htmlFor="ci-name" required>
                <Input
                  id="ci-name"
                  required
                  value={form.name}
                  onChange={(e) => { setForm({ ...form, name: e.target.value }); }}
                />
              </FormField>
              <FormField label={t('catalog:basePrice')} htmlFor="ci-price" required>
                <Input
                  id="ci-price"
                  type="number"
                  required
                  step="0.01"
                  min="0"
                  value={form.base_price}
                  onChange={(e) => { setForm({ ...form, base_price: e.target.value }); }}
                />
              </FormField>
              <FormField label={t('catalog:manualCost')} htmlFor="ci-manual-cost">
                <Input
                  id="ci-manual-cost"
                  type="number"
                  step="0.01"
                  min="0"
                  value={form.manual_cost}
                  onChange={(e) => { setForm({ ...form, manual_cost: e.target.value }); }}
                  placeholder={t('catalog:manualCostPlaceholder')}
                />
                {form.manual_cost && parseFloat(form.base_price) > 0 && (
                  <p className="mt-1 text-sm text-gray-500">
                    {t('catalog:margin')}: {(((parseFloat(form.base_price) - parseFloat(form.manual_cost)) / parseFloat(form.base_price)) * 100).toFixed(1)}%
                  </p>
                )}
              </FormField>
              <input type="hidden" name="vertical_type" value={form.vertical_type} />
              <FormField label={t('catalog:productionType')} htmlFor="ci-production">
                <Select
                  id="ci-production"
                  value={form.production_type}
                  onChange={(e) => { setForm({ ...form, production_type: e.target.value as ProductionType }); }}
                >
                  <option value="made_to_order">{t('catalog:productionTypes.made_to_order')}</option>
                  <option value="batch">{t('catalog:productionTypes.batch')}</option>
                  <option value="stock">{t('catalog:productionTypes.stock')}</option>
                </Select>
              </FormField>
              <FormField label={t('catalog:taxRate')} htmlFor="ci-tax">
                <Input
                  id="ci-tax"
                  type="number"
                  step="0.01"
                  min="0"
                  max="100"
                  value={form.tax_rate}
                  onChange={(e) => { setForm({ ...form, tax_rate: e.target.value }); }}
                />
              </FormField>
              <div className="flex items-center gap-6 pt-6">
                <label className="flex items-center gap-2">
                  <input
                    type="checkbox"
                    checked={form.is_active}
                    onChange={(e) => { setForm({ ...form, is_active: e.target.checked }); }}
                    className={tokens.checkbox.base}
                  />
                  <span className="text-sm text-gray-700">{t('catalog:isActive')}</span>
                </label>
                <label className="flex items-center gap-2">
                  <input
                    type="checkbox"
                    checked={form.is_available}
                    onChange={(e) => { setForm({ ...form, is_available: e.target.checked }); }}
                    className={tokens.checkbox.base}
                  />
                  <span className="text-sm text-gray-700">{t('catalog:isAvailable')}</span>
                </label>
              </div>
            </div>
          </div>

          <StickyFormFooter>
            <Button
              type="button"
              variant="secondary"
              onClick={() => navigate('/catalog/composite-items')}
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
      )}
    </div>
  )
}
