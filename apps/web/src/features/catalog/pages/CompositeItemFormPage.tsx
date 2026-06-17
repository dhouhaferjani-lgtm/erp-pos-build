import { useState, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router-dom'
import { ArrowLeft, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { usePermissions } from '@/hooks/usePermissions'
import { Input, FormField, Button, Select, Checkbox } from '@/components/atoms'
import { PageHeader } from '@/components/molecules'
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/molecules/Tabs/Tabs'
import { StickyFormFooter } from '@/components/molecules/StickyFormFooter/StickyFormFooter'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { cn } from '@/lib/utils'
import { tokens, textColors } from '@/lib/designTokens'
import { bcsub, bcdiv, bcmul, bccomp } from '@/lib/decimal'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useQuery } from '@tanstack/react-query'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { fetchLocations } from '@/features/location/api'
import { useCompositeItem, useCreateCompositeItem, useUpdateCompositeItem, useDeleteCompositeItem, useCompositeItemAvailability } from '../hooks/useCompositeItems'
import { useCreateRecipe } from '../hooks/useRecipes'
import { RecipeLineEditor } from '../components/RecipeLineEditor'
import { VariantEditor } from '../components/VariantEditor'
import { ModifierGroupAssigner } from '../components/ModifierGroupAssigner'
import { useVerticalLabels, companyVerticalToCatalog } from '../hooks/useVerticalLabels'
import { useCompanyConfig } from '@/contexts'
import { TaxConfigurationField } from '../../../components/molecules/TaxConfigurationField'
import { MoneyInput } from '@/components/atoms'
import type { ProductionType, PricingMode } from '../types/compositeItem'

type TabValue = 'details' | 'recipeTab' | 'sizesTab' | 'modifiersTab'

/**
 * Cost margin as a 1-dp percentage string, computed entirely from decimal
 * strings (precision contract rule 19 — never `parseFloat` a money value).
 * Returns `null` when base price is missing/zero (margin is undefined).
 */
function computeMarginPercent(basePrice: string, cost: string): string | null {
  if (!basePrice || bccomp(basePrice, '0') <= 0) return null
  // (base - cost) / base * 100, rounded half-up to 1dp via high intermediate scale.
  const fraction = bcdiv(bcsub(basePrice, cost, 4), basePrice, 6)
  return bcmul(fraction, '100', 1)
}

export function CompositeItemFormPage() {
  const { t } = useTranslation(['catalog', 'common'])
  const navigate = useNavigate()
  const { id } = useParams<{ id: string }>()
  const isEdit = !!id && id !== 'new'
  const { hasPermission } = usePermissions()
  const canDelete = hasPermission('composite-items.delete')
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  const { config, hasModule } = useCompanyConfig()
  const currency = config?.currency ?? 'TND'
  const hasInventory = hasModule('Inventory')
  const companyVerticalType = companyVerticalToCatalog(config?.vertical)

  const { data: item, isLoading } = useCompositeItem(isEdit ? id : '')
  const getLabel = useVerticalLabels(item?.vertical_type ?? companyVerticalType)
  const createMutation = useCreateCompositeItem()
  const updateMutation = useUpdateCompositeItem()
  const createRecipeMutation = useCreateRecipe()
  const deleteMutation = useDeleteCompositeItem()
  const [confirmDeleteOpen, setConfirmDeleteOpen] = useState(false)

  const handleDelete = async () => {
    if (!isEdit || !id) return
    try {
      await deleteMutation.mutateAsync(id)
      toast.success(t('catalog:deleteCompositeItemSuccess'))
      setConfirmDeleteOpen(false)
      void navigate('/catalog/composite-items')
    } catch (error) {
      const msg = error instanceof Error ? error.message : String(error)
      toast.error(t('catalog:deleteCompositeItemError', { error: msg }))
    }
  }

  const [activeTab, setActiveTab] = useState<TabValue>('details')
  const [selectedLocationId, setSelectedLocationId] = useState('')

  const { data: locations } = useQuery({
    queryKey: tenantScopedKey(['locations']),
    queryFn: fetchLocations,
    enabled: tenantId !== null && companyId !== null && isEdit,
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
    tax_configuration_id: null as string | null,
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
        tax_configuration_id: null,
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
      base_price: form.base_price,
      manual_cost: form.manual_cost || null,
      production_type: form.production_type,
      pricing_mode: form.pricing_mode,
      tax_rate: form.tax_rate || null,
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
    return <div className={cn('text-center py-8', textColors.tertiary)}>{t('common:loading')}</div>
  }

  const pageTitle = isEdit
    ? `${t('common:actions.edit')} ${getLabel('compositeItem')}`
    : `${t('common:actions.create')} ${getLabel('compositeItem')}`

  return (
    <div className="space-y-6">
      <PageHeader
        title={pageTitle}
        breadcrumb={
          <Button
            type="button"
            variant="secondary"
            size="sm"
            onClick={() => navigate('/catalog/composite-items')}
            aria-label={t('common:back')}
          >
            <ArrowLeft className="h-4 w-4" />
          </Button>
        }
      />

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
                    <MoneyInput
                      id="ci-price"
                      required
                      currency={currency}
                      min="0"
                      value={form.base_price}
                      onChange={(v) => { setForm({ ...form, base_price: v }); }}
                    />
                  </FormField>
                  <FormField label={t('catalog:manualCost')} htmlFor="ci-manual-cost">
                    <MoneyInput
                      id="ci-manual-cost"
                      currency={currency}
                      min="0"
                      value={form.manual_cost}
                      onChange={(v) => { setForm({ ...form, manual_cost: v }); }}
                      placeholder={t('catalog:manualCostPlaceholder')}
                    />
                    {item?.recipe_cost && (
                      <div className={cn(tokens.alert.base, tokens.alert.info, 'text-sm space-y-1 mt-2')}>
                        <div className="flex justify-between">
                          <span>{t('catalog:recipeCost')}</span>
                          <span className="font-medium">{item.recipe_cost}</span>
                        </div>
                        {item.margin_percentage !== null && (
                          <div className="flex justify-between">
                            <span>{t('catalog:margin')}</span>
                            <span className="font-medium">{item.margin_percentage}%</span>
                          </div>
                        )}
                      </div>
                    )}
                    {form.manual_cost && computeMarginPercent(form.base_price, form.manual_cost) !== null && (
                      <p className={cn('mt-1 text-sm', textColors.tertiary)}>
                        {t('catalog:margin')}: {computeMarginPercent(form.base_price, form.manual_cost)}%
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
                  <TaxConfigurationField
                    label={t('catalog:taxRate')}
                    value={form.tax_configuration_id}
                    onChange={(configId, taxRate) => {
                      setForm({ ...form, tax_configuration_id: configId, tax_rate: taxRate })
                    }}
                  />
                  <div className="flex items-center gap-6 pt-6">
                    <label className="flex items-center gap-2">
                      <Checkbox
                        checked={form.is_active}
                        onChange={(e) => { setForm({ ...form, is_active: e.target.checked }); }}
                      />
                      <span className={cn('text-sm', textColors.secondary)}>{t('catalog:isActive')}</span>
                    </label>
                    <label className="flex items-center gap-2">
                      <Checkbox
                        checked={form.is_available}
                        onChange={(e) => { setForm({ ...form, is_available: e.target.checked }); }}
                      />
                      <span className={cn('text-sm', textColors.secondary)}>{t('catalog:isAvailable')}</span>
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
                {canDelete && (
                  <Button
                    type="button"
                    variant="danger"
                    onClick={() => { setConfirmDeleteOpen(true) }}
                    disabled={deleteMutation.isPending}
                    aria-label={t('common:delete')}
                  >
                    <Trash2 className="h-4 w-4 mr-1" />
                    {t('common:delete')}
                  </Button>
                )}
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
                <h3 className={cn(tokens.heading.section, 'mb-4')}>{t('catalog:availability')}</h3>
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
                  <p className={cn('text-sm', textColors.tertiary)}>{t('common:loading')}</p>
                )}
                {availability && selectedLocationId && (
                  <div className="space-y-3">
                    <div className="flex items-center gap-4">
                      <div className={cn('text-2xl font-bold', textColors.primary)}>{availability.available_quantity}</div>
                      <div className={cn('text-sm', textColors.tertiary)}>{t('catalog:maxProducible')}</div>
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
                            <th className={cn('text-left py-1 font-medium', textColors.secondary)}>{getLabel('recipeLine')}</th>
                            <th className={cn('text-right py-1 font-medium', textColors.secondary)}>{t('catalog:required')}</th>
                            <th className={cn('text-right py-1 font-medium', textColors.secondary)}>{t('catalog:available')}</th>
                            <th className={cn('text-right py-1 font-medium', textColors.secondary)}>{t('catalog:maxProducible')}</th>
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
                  <p className={cn(textColors.tertiary, 'mb-4')}>{t('catalog:createRecipe')}</p>
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
                <MoneyInput
                  id="ci-price"
                  required
                  currency={currency}
                  min="0"
                  value={form.base_price}
                  onChange={(v) => { setForm({ ...form, base_price: v }); }}
                />
              </FormField>
              <FormField label={t('catalog:manualCost')} htmlFor="ci-manual-cost">
                <MoneyInput
                  id="ci-manual-cost"
                  currency={currency}
                  min="0"
                  value={form.manual_cost}
                  onChange={(v) => { setForm({ ...form, manual_cost: v }); }}
                  placeholder={t('catalog:manualCostPlaceholder')}
                />
                {form.manual_cost && computeMarginPercent(form.base_price, form.manual_cost) !== null && (
                  <p className={cn('mt-1 text-sm', textColors.tertiary)}>
                    {t('catalog:margin')}: {computeMarginPercent(form.base_price, form.manual_cost)}%
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
              <TaxConfigurationField
                label={t('catalog:taxRate')}
                value={form.tax_configuration_id}
                onChange={(configId, taxRate) => {
                  setForm({ ...form, tax_configuration_id: configId, tax_rate: taxRate })
                }}
              />
              <div className="flex items-center gap-6 pt-6">
                <label className="flex items-center gap-2">
                  <Checkbox
                    checked={form.is_active}
                    onChange={(e) => { setForm({ ...form, is_active: e.target.checked }); }}
                  />
                  <span className={cn('text-sm', textColors.secondary)}>{t('catalog:isActive')}</span>
                </label>
                <label className="flex items-center gap-2">
                  <Checkbox
                    checked={form.is_available}
                    onChange={(e) => { setForm({ ...form, is_available: e.target.checked }); }}
                  />
                  <span className={cn('text-sm', textColors.secondary)}>{t('catalog:isAvailable')}</span>
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

      <ConfirmDialog
        isOpen={confirmDeleteOpen}
        onClose={() => { setConfirmDeleteOpen(false) }}
        onConfirm={() => { void handleDelete() }}
        title={t('catalog:deleteCompositeItemTitle')}
        message={t('catalog:deleteCompositeItemMessage', { name: form.name })}
        confirmText={t('common:confirm')}
        cancelText={t('common:cancel')}
        variant="danger"
        isLoading={deleteMutation.isPending}
      />
    </div>
  )
}
