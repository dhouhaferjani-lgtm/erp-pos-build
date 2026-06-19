import { useState, useRef, useEffect, useCallback } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useForm, useFieldArray, Controller } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, Plus, X, Layers } from 'lucide-react'
import { toast } from 'sonner'
import { api, apiPost, apiPatch } from '../../lib/api'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { cn } from '../../lib/utils'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { colors, tokens, textColors } from '../../lib/designTokens'
import { CategorySelect } from '../../components/catalog/CategorySelect'
import { StickyFormFooter } from '../../components/molecules/StickyFormFooter/StickyFormFooter'
import { PageHeader } from '../../components/molecules/PageHeader'
import { Button, Checkbox, FormField, Input, Textarea, MoneyInput } from '../../components/atoms'
import { BarcodeLookupInput } from './components/BarcodeLookupInput'
import { CatalogBanner } from './components/CatalogBanner'
import { useProductSubmission } from './api/platformQueries'
import type { LookupState, SuggestedProduct } from './types/platform'
import { ProductImageSection, ParapharmacyMetadataFields } from '../products/components'
import { ProductVariantMatrixEditor } from '../catalog/components/ProductVariantMatrixEditor'
import { useVariantsForProduct } from '../catalog/hooks/useVariants'
import { useCompanyConfig } from '../../contexts/CompanyConfigContext'
import { useCurrency } from '../../hooks/useCurrency'
import { useProductConfig } from '../../contexts/ProductConfigContext'
import { TaxConfigurationField } from '../../components/molecules/TaxConfigurationField'
import { inventoryProductsInvalidationPredicate } from './_invalidation'
import { buildProductPayload } from './productPayload'

interface Product {
  id: string
  name: string
  sku: string
  is_physical: boolean
  category_id: number | null
  description: string | null
  sale_price: string | null
  cost_price: string | null
  tax_rate: string | null
  default_tax_configuration_id: string | null
  unit: string | null
  barcode: string | null
  is_active: boolean
  oem_numbers: string[] | null
  cross_references: Array<{ brand: string; reference: string }> | null
  parapharmacy_metadata: ParapharmacyMetadata | null
  requires_batch_tracking: boolean
  default_shelf_life_days: number | null
  created_at: string
  updated_at: string | null
}

interface ProductResponse {
  data: Product
}

interface ParapharmacyMetadata {
  category: string
  dosage_form: string | null
  active_ingredients: Array<{ name: string; concentration: string }>
  usage_instructions: string | null
  warnings: string | null
  contraindications: string | null
  minimum_age: number | null
  age_restriction: string | null
  requires_consultation: boolean
  regulatory_code: string | null
  storage_requirements: string | null
}

export interface ProductFormData {
  name: string
  sku: string
  is_physical: boolean
  category_id: number | null
  description: string
  sale_price: string
  tax_rate: string
  tax_configuration_id: string | null
  unit: string
  barcode: string
  is_active: boolean
  oem_numbers: string[]
  cross_references: Array<{ brand: string; reference: string }>
  parapharmacy_metadata: ParapharmacyMetadata
  requires_batch_tracking: boolean
  default_shelf_life_days: number | null
}

export function ProductForm() {
  const { t } = useTranslation()
  const { id = '' } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const isEditing = id.length > 0
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const { config, hasModule } = useCompanyConfig()
  const isParapharmacy = config?.vertical === 'parapharmacy'
  const showBatchTracking = hasModule('BatchExpiry') || hasModule('Inventory')
  const { isOtospex } = useProductConfig()
  const { currency, decimals } = useCurrency()

  const [showVariants, setShowVariants] = useState(false)
  const [oemInput, setOemInput] = useState('')
  const [lookupState, setLookupState] = useState<LookupState>('idle')
  const [enrichmentOptIn, setEnrichmentOptIn] = useState(true)
  const suggestedProductRef = useRef<SuggestedProduct | null>(null)
  const [prefilledFields, setPrefilledFields] = useState<Set<string>>(new Set())

  const submissionMutation = useProductSubmission()

  const {
    register,
    handleSubmit,
    control,
    reset,
    watch,
    setValue,
    formState: { errors, isSubmitting },
  } = useForm<ProductFormData>({
    defaultValues: {
      name: '',
      sku: '',
      is_physical: true,
      category_id: null,
      description: '',
      sale_price: '',
      tax_rate: '',
      tax_configuration_id: null,
      unit: 'pcs',
      barcode: '',
      is_active: true,
      oem_numbers: [],
      cross_references: [],
      parapharmacy_metadata: {
        category: '',
        dosage_form: null,
        active_ingredients: [],
        usage_instructions: null,
        warnings: null,
        contraindications: null,
        minimum_age: null,
        age_restriction: null,
        requires_consultation: false,
        regulatory_code: null,
        storage_requirements: null,
      },
      requires_batch_tracking: false,
      default_shelf_life_days: null,
    },
  })

  const handleProductData = useCallback((data: SuggestedProduct) => {
    suggestedProductRef.current = data
    const filled = new Set<string>()
    if (data.name) {
      setValue('name', data.name, { shouldDirty: false })
      filled.add('name')
    }
    if (data.description) {
      setValue('description', data.description, { shouldDirty: false })
      filled.add('description')
    }
    if (data.barcode) {
      setValue('barcode', data.barcode, { shouldDirty: false })
    }
    setPrefilledFields(filled)
  }, [setValue])

  const handleLookupStateChange = useCallback((state: LookupState) => {
    setLookupState(state)
    if (state === 'idle') {
      suggestedProductRef.current = null
      setPrefilledFields(new Set())
    }
  }, [])

  const oemNumbers = watch('oem_numbers')
  const categoryId = watch('category_id')
  const requiresBatchTracking = watch('requires_batch_tracking')

  const { fields: crossRefFields, append: appendCrossRef, remove: removeCrossRef } = useFieldArray({
    control,
    name: 'cross_references',
  })

  // Fetch product data when editing
  const { data: product, isLoading } = useQuery({
    queryKey: tenantScopedKey(['product', id]),
    queryFn: async () => {
      const response = await api.get<ProductResponse>(`/products/${id}`)
      return response.data.data
    },
    enabled: isEditing && !!tenantId && !!companyId,
  })

  // Existing variants for this product (edit mode only). If any exist, default
  // the variants section open so the user lands on the matrix editor.
  const { data: existingVariants } = useVariantsForProduct(isEditing ? id : '')
  const hasExistingVariants = (existingVariants ?? []).length > 0

  const variantsToggleSeededRef = useRef(false)
  useEffect(() => {
    if (!variantsToggleSeededRef.current && hasExistingVariants) {
      variantsToggleSeededRef.current = true
      setShowVariants(true)
    }
  }, [hasExistingVariants])

  const hasPopulatedRef = useRef(false)

  // Populate form when product data loads
  useEffect(() => {
    if (product && !hasPopulatedRef.current) {
      hasPopulatedRef.current = true
      reset({
        name: product.name,
        sku: product.sku,
        is_physical: product.is_physical ?? true,
        category_id: product.category_id ?? null,
        description: product.description ?? '',
        sale_price: product.sale_price ?? '',
        tax_rate: product.tax_rate ?? '',
        tax_configuration_id: product.default_tax_configuration_id ?? null,
        unit: product.unit ?? 'pcs',
        barcode: product.barcode ?? '',
        is_active: product.is_active,
        oem_numbers: product.oem_numbers ?? [],
        cross_references: product.cross_references ?? [],
        parapharmacy_metadata: product.parapharmacy_metadata ?? {
          category: '',
          dosage_form: null,
          active_ingredients: [],
          usage_instructions: null,
          warnings: null,
          contraindications: null,
          minimum_age: null,
          age_restriction: null,
          requires_consultation: false,
          regulatory_code: null,
          storage_requirements: null,
        },
        requires_batch_tracking: product.requires_batch_tracking ?? false,
        default_shelf_life_days: product.default_shelf_life_days ?? null,
      })
    }
  }, [product, reset])

  const createMutation = useMutation({
    mutationFn: (data: ProductFormData) =>
      apiPost<Product>('/products', buildProductPayload(data, { isParapharmacy })),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: inventoryProductsInvalidationPredicate(tenantId, companyId),
      })
    },
  })

  const updateMutation = useMutation({
    mutationFn: (data: ProductFormData) =>
      apiPatch<Product>(`/products/${id}`, buildProductPayload(data, { isParapharmacy })),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: inventoryProductsInvalidationPredicate(tenantId, companyId),
        }),
        queryClient.invalidateQueries({ queryKey: tenantScopedKey(['product', id]) }),
      ])
      void navigate(`/inventory/products/${id}`)
    },
  })

  const onSubmit = async (data: ProductFormData) => {
    if (isEditing) {
      updateMutation.mutate(data)
      return
    }

    try {
      await createMutation.mutateAsync(data)

      if (lookupState === 'not_found' && enrichmentOptIn) {
        const payload: Parameters<typeof submissionMutation.mutate>[0] = {
          barcode: data.barcode || null,
          name: data.name,
          brand: suggestedProductRef.current?.brand ?? '',
        }
        if (data.description) {
          payload.description = data.description
        }
        submissionMutation.mutate(payload)
        toast.success(t('inventory:barcodeLookup.toastSavedWithEnrichment'))
      } else if (lookupState === 'found') {
        toast.success(t('inventory:barcodeLookup.toastSavedWithCatalog'))
      } else {
        toast.success(t('inventory:barcodeLookup.toastProductSaved'))
      }

      void navigate('/inventory/products')
    } catch {
      // Error handling via react-query
    }
  }

  const handleAddOem = () => {
    const trimmed = oemInput.trim()
    if (trimmed && !oemNumbers.includes(trimmed)) {
      setValue('oem_numbers', [...oemNumbers, trimmed])
      setOemInput('')
    }
  }

  const handleRemoveOem = (index: number) => {
    setValue('oem_numbers', oemNumbers.filter((_, i) => i !== index))
  }

  const handleOemKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter') {
      e.preventDefault()
      handleAddOem()
    }
  }

  if (isEditing && isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className={textColors.tertiary}>{t('status.loading')}</div>
      </div>
    )
  }

  return (
    <div className="flex min-h-full flex-col gap-6">
      {/* Header */}
      <PageHeader
        title={isEditing ? t('inventory:products.edit') : t('inventory:products.new')}
        breadcrumb={
          <Link
            to="/inventory/products"
            className={cn(
              'inline-flex items-center gap-2 text-sm',
              textColors.tertiary,
              textColors.hoverPrimary,
            )}
          >
            <ArrowLeft className="h-4 w-4" />
            {t('actions.back')}
          </Link>
        }
        className="mb-0"
      />

      {/* Form */}
      <form onSubmit={(e) => { void handleSubmit(onSubmit)(e) }} className="flex flex-1 flex-col gap-6">
        {/* Catalog Lookup Banner */}
        <CatalogBanner
          state={lookupState}
          confidenceTier={
            lookupState === 'found' && suggestedProductRef.current
              ? (suggestedProductRef.current.classification?.['enrichment_tier'] as string) ?? null
              : null
          }
        />

        {/* Basic Information */}
        <div className={tokens.card.base}>
          <h2 className={cn(tokens.heading.section, 'mb-4')}>{t('inventory:products.sections.basicInfo')}</h2>
          <div className="grid gap-6 sm:grid-cols-2">
            <FormField
              label={`${t('inventory:products.name')} *`}
              htmlFor="name"
              error={errors.name?.message}
            >
              <Input
                type="text"
                id="name"
                error={Boolean(errors.name)}
                className={prefilledFields.has('name') ? colors.success[50] : ''}
                {...register('name', {
                  required: t('inventory:products.nameRequired'),
                  onChange: () => {
                    setPrefilledFields((prev) => {
                      if (!prev.has('name')) return prev
                      const next = new Set(prev)
                      next.delete('name')
                      return next
                    })
                  },
                })}
              />
            </FormField>

            <FormField
              label={`${t('inventory:products.sku')} *`}
              htmlFor="sku"
              error={errors.sku?.message}
            >
              <Input
                type="text"
                id="sku"
                error={Boolean(errors.sku)}
                {...register('sku', { required: t('inventory:products.skuRequired') })}
              />
            </FormField>

            <div>
              <div className="flex items-center gap-2 mt-6">
                <Checkbox
                  id="is_physical"
                  {...register('is_physical')}
                />
                <label htmlFor="is_physical" className={tokens.label.base}>
                  {t('inventory:products.isPhysical')}
                </label>
              </div>
              <p className={tokens.helperText.base}>
                {t('inventory:products.isPhysicalHelper')}
              </p>
            </div>

            {showBatchTracking && (
              <div data-testid="batch-tracking-section">
                <div className="flex items-center gap-2 mt-6">
                  <Checkbox
                    id="requires_batch_tracking"
                    {...register('requires_batch_tracking')}
                  />
                  <label htmlFor="requires_batch_tracking" className={tokens.label.base}>
                    {t('inventory:products.requiresBatchTracking')}
                  </label>
                </div>
                <p className={tokens.helperText.base}>
                  {t('inventory:products.requiresBatchTrackingHelper')}
                </p>

                {requiresBatchTracking && (
                  <FormField
                    label={t('inventory:products.defaultShelfLifeDays')}
                    htmlFor="default_shelf_life_days"
                    className="mt-3"
                  >
                    <Input
                      type="number"
                      id="default_shelf_life_days"
                      min={0}
                      placeholder={t('inventory:products.defaultShelfLifeDaysPlaceholder')}
                      {...register('default_shelf_life_days', {
                        setValueAs: (value: string): number | null =>
                          value === '' || value === null ? null : Number(value),
                      })}
                    />
                  </FormField>
                )}
              </div>
            )}

            <FormField label={t('catalog.products.category')} htmlFor="category">
              <CategorySelect
                value={categoryId}
                onChange={(id) => { setValue('category_id', id); }}
                className="mt-1"
              />
            </FormField>

            <FormField label={t('inventory:products.unit')} htmlFor="unit">
              <Input
                type="text"
                id="unit"
                placeholder={t('inventory:products.unitPlaceholder')}
                {...register('unit')}
              />
            </FormField>

            <div>
              <BarcodeLookupInput
                onProductData={handleProductData}
                onLookupStateChange={handleLookupStateChange}
                defaultBarcode={product?.barcode ?? ''}
              />
              <input type="hidden" {...register('barcode')} />

              {/* Enrichment opt-in checkbox */}
              {lookupState === 'not_found' && (
                <div className={cn('mt-3 flex items-center gap-2.5 rounded-lg px-3.5 py-3', colors.neutral[100])}>
                  <Checkbox
                    id="enrichment-opt-in"
                    checked={enrichmentOptIn}
                    onChange={(e) => setEnrichmentOptIn(e.target.checked)}
                  />
                  <label htmlFor="enrichment-opt-in" className="text-sm">
                    <span className="font-medium">{t('inventory:barcodeLookup.enrichmentCheckbox')}</span>
                    <br />
                    <span className="text-xs opacity-70">{t('inventory:barcodeLookup.enrichmentDescription')}</span>
                  </label>
                </div>
              )}
            </div>

            <div className="flex items-center gap-2">
              <Checkbox
                id="is_active"
                {...register('is_active')}
              />
              <label htmlFor="is_active" className={tokens.label.base}>
                {t('active')}
              </label>
            </div>

            <FormField
              className="sm:col-span-2"
              label={t('inventory:products.description')}
              htmlFor="description"
            >
              <Textarea
                id="description"
                rows={3}
                className={prefilledFields.has('description') ? colors.success[50] : ''}
                {...register('description', {
                  onChange: () => {
                    setPrefilledFields((prev) => {
                      if (!prev.has('description')) return prev
                      const next = new Set(prev)
                      next.delete('description')
                      return next
                    })
                  },
                })}
              />
            </FormField>
          </div>
        </div>

        {/* Pricing */}
        <div className={tokens.card.base}>
          <h2 className={cn(tokens.heading.section, 'mb-4')}>{t('inventory:products.sections.pricing')}</h2>
          <div className="grid gap-6 sm:grid-cols-3">
            <FormField label={t('inventory:products.salePrice')} htmlFor="sale_price">
              <Controller
                name="sale_price"
                control={control}
                render={({ field }) => (
                  <MoneyInput
                    id="sale_price"
                    currency={currency}
                    value={field.value ?? ''}
                    onChange={field.onChange}
                    onBlur={field.onBlur}
                  />
                )}
              />
            </FormField>

            {/* Cost (WAC) - Read-only when editing */}
            {isEditing && product && (
              <FormField
                label={t('inventory:products.costWac')}
                htmlFor="cost_wac"
                helperText={t('inventory:products.costWacHelper')}
              >
                <Input
                  id="cost_wac"
                  type="text"
                  value={product.cost_price ? parseFloat(product.cost_price).toFixed(decimals) : (0).toFixed(decimals)}
                  readOnly
                  className={cn(colors.neutral[50], textColors.tertiary, 'cursor-not-allowed')}
                />
              </FormField>
            )}

            <TaxConfigurationField
              label={t('inventory:products.fields.taxRate', 'Tax Rate')}
              value={watch('tax_configuration_id')}
              onChange={(configId, taxRate) => {
                setValue('tax_configuration_id', configId)
                setValue('tax_rate', taxRate)
              }}
            />
          </div>
        </div>

        {/* Automotive Information - Otospex only */}
        {isOtospex && (
          <div className={tokens.card.base}>
            <h2 className={cn(tokens.heading.section, 'mb-4')}>{t('inventory:products.sections.automotiveInfo')}</h2>

            {/* OEM Numbers */}
            <div className="mb-6">
              <label className={cn(tokens.label.base, 'mb-2')}>
                {t('inventory:products.oemNumbers')}
              </label>
              <div className="flex gap-2">
                <Input
                  type="text"
                  className="mt-0 flex-1"
                  value={oemInput}
                  onChange={(e) => { setOemInput(e.target.value) }}
                  onKeyDown={handleOemKeyDown}
                  placeholder={t('inventory:products.oemPlaceholder')}
                />
                <Button
                  type="button"
                  variant="secondary"
                  className="gap-1"
                  onClick={handleAddOem}
                >
                  <Plus className="h-4 w-4" />
                  {t('actions.add')}
                </Button>
              </div>
              {oemNumbers.length > 0 && (
                <div className="mt-2 flex flex-wrap gap-2">
                  {oemNumbers.map((oem, index) => (
                    <span
                      key={index}
                      className={cn('inline-flex items-center gap-1 rounded-md px-2.5 py-1 text-sm font-mono', colors.neutral[100], textColors.secondary)}
                    >
                      {oem}
                      <button
                        type="button"
                        onClick={() => { handleRemoveOem(index) }}
                        className={cn(textColors.disabled, textColors.hoverSecondary)}
                      >
                        <X className="h-3.5 w-3.5" />
                      </button>
                    </span>
                  ))}
                </div>
              )}
            </div>

            {/* Cross References */}
            <div>
              <label className={cn(tokens.label.base, 'mb-2')}>
                {t('inventory:products.crossReferences')}
              </label>
              <div className="space-y-2">
                {crossRefFields.map((field, index) => (
                  <div key={field.id} className="flex gap-2">
                    <Input
                      type="text"
                      className="mt-0 flex-1"
                      // eslint-disable-next-line @typescript-eslint/restrict-template-expressions
                      {...register(`cross_references.${index}.brand` as const)}
                      placeholder={t('inventory:products.brand')}
                    />
                    <Input
                      type="text"
                      className="mt-0 flex-1"
                      // eslint-disable-next-line @typescript-eslint/restrict-template-expressions
                      {...register(`cross_references.${index}.reference` as const)}
                      placeholder={t('inventory:products.reference')}
                    />
                    <Button
                      type="button"
                      variant="secondary"
                      className="p-2"
                      onClick={() => { removeCrossRef(index) }}
                    >
                      <X className="h-4 w-4" />
                    </Button>
                  </div>
                ))}
              </div>
              <button
                type="button"
                onClick={() => { appendCrossRef({ brand: '', reference: '' }) }}
                className={cn('mt-2 inline-flex items-center gap-1 text-sm', textColors.brand)}
              >
                <Plus className="h-4 w-4" />
                {t('inventory:products.addCrossReference')}
              </button>
            </div>
          </div>
        )}

        {/* Parapharmacy Metadata - Only for parapharmacy vertical */}
        {isParapharmacy && (
          <ParapharmacyMetadataFields
            control={control}
            register={register}
            errors={errors}
          />
        )}

        {/* Product Images Section - Only when editing. ProductImageSection
            renders its own (translated) section header. */}
        {isEditing && id && (
          <div className={tokens.card.base}>
            <ProductImageSection productId={id} />
          </div>
        )}

        {/* Variants Section - Only when editing (matrix generation needs a persisted product id) */}
        {isEditing && id && (
          <div className={tokens.card.base}>
            <div className="mb-4 flex items-center justify-between">
              <div className="flex items-center gap-2">
                <Layers className={cn('h-5 w-5', textColors.disabled)} />
                <h2 className={tokens.heading.section}>
                  {t('catalog:variants.title')}
                </h2>
              </div>
              <label className="inline-flex items-center gap-2">
                <Checkbox
                  checked={showVariants}
                  onChange={(e) => { setShowVariants(e.target.checked) }}
                />
                <span className={cn('text-sm', textColors.secondary)}>
                  {t('catalog:variants.hasVariants')}
                </span>
              </label>
            </div>
            {showVariants && <ProductVariantMatrixEditor productId={id} />}
          </div>
        )}

        {/* Form Actions */}
        <StickyFormFooter>
          <Button
            type="button"
            variant="secondary"
            onClick={() => { void navigate('/inventory/products') }}
          >
            {t('actions.cancel')}
          </Button>
          <Button type="submit" variant="primary" disabled={isSubmitting}>
            {isSubmitting ? t('status.saving') : t('actions.save')}
          </Button>
        </StickyFormFooter>
      </form>
    </div>
  )
}
