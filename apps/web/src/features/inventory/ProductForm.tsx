import { useState, useRef, useEffect, useCallback } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useForm, useFieldArray } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, Plus, X, Image } from 'lucide-react'
import { toast } from 'sonner'
import { api, apiPost, apiPatch } from '../../lib/api'
import { colors } from '../../lib/designTokens'
import { CategorySelect } from '../../components/catalog/CategorySelect'
import { StickyFormFooter } from '../../components/molecules/StickyFormFooter/StickyFormFooter'
import { BarcodeLookupInput } from './components/BarcodeLookupInput'
import { CatalogBanner } from './components/CatalogBanner'
import { useProductSubmission } from './api/platformQueries'
import type { LookupState, SuggestedProduct } from './types/platform'
import { ProductImageSection, ParapharmacyMetadataFields } from '../products/components'
import { useCompanyConfig } from '../../contexts/CompanyConfigContext'
import { useCurrency } from '../../hooks/useCurrency'
import { useProductConfig } from '../../contexts/ProductConfigContext'
import { TaxConfigurationField } from '../../components/molecules/TaxConfigurationField'

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

interface ProductFormData {
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
}

export function ProductForm() {
  const { t } = useTranslation()
  const { id = '' } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const isEditing = id.length > 0
  const { config } = useCompanyConfig()
  const isParapharmacy = config?.vertical === 'parapharmacy'
  const { isOtospex } = useProductConfig()
  const { decimals } = useCurrency()

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

  const { fields: crossRefFields, append: appendCrossRef, remove: removeCrossRef } = useFieldArray({
    control,
    name: 'cross_references',
  })

  // Fetch product data when editing
  const { data: product, isLoading } = useQuery({
    queryKey: ['product', id],
    queryFn: async () => {
      const response = await api.get<ProductResponse>(`/products/${id}`)
      return response.data.data
    },
    enabled: isEditing,
  })

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
      })
    }
  }, [product, reset])

  const createMutation = useMutation({
    mutationFn: (data: ProductFormData) => apiPost<Product>('/products', data),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['products'] })
    },
  })

  const updateMutation = useMutation({
    mutationFn: (data: ProductFormData) => apiPatch<Product>(`/products/${id}`, data),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['products'] })
      void queryClient.invalidateQueries({ queryKey: ['product', id] })
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
        <div className="text-gray-500">{t('status.loading')}</div>
      </div>
    )
  }

  return (
    <div className="flex min-h-full flex-col gap-6">
      {/* Header */}
      <div className="flex items-center gap-4">
        <Link
          to="/inventory/products"
          className="inline-flex items-center gap-2 text-sm text-gray-600 hover:text-gray-900"
        >
          <ArrowLeft className="h-4 w-4" />
          {t('actions.back')}
        </Link>
        <h1 className="text-2xl font-bold text-gray-900">
          {isEditing ? t('actions.edit') : t('actions.add')} Product
        </h1>
      </div>

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
        <div className="rounded-lg border border-gray-200 bg-white p-6">
          <h2 className="mb-4 text-lg font-semibold text-gray-900">Basic Information</h2>
          <div className="grid gap-6 sm:grid-cols-2">
            <div>
              <label htmlFor="name" className="block text-sm font-medium text-gray-700">
                Name *
              </label>
              <input
                type="text"
                id="name"
                {...register('name', {
                  required: 'Name is required',
                  onChange: () => {
                    setPrefilledFields((prev) => {
                      if (!prev.has('name')) return prev
                      const next = new Set(prev)
                      next.delete('name')
                      return next
                    })
                  },
                })}
                className={`mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 ${prefilledFields.has('name') ? colors.success[50] : ''}`}
              />
              {errors.name && (
                <p className="mt-1 text-sm text-red-600">{errors.name.message}</p>
              )}
            </div>

            <div>
              <label htmlFor="sku" className="block text-sm font-medium text-gray-700">
                SKU *
              </label>
              <input
                type="text"
                id="sku"
                {...register('sku', { required: 'SKU is required' })}
                className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              />
              {errors.sku && (
                <p className="mt-1 text-sm text-red-600">{errors.sku.message}</p>
              )}
            </div>

            <div>
              <div className="flex items-center gap-2 mt-6">
                <input
                  type="checkbox"
                  id="is_physical"
                  {...register('is_physical')}
                  className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                />
                <label htmlFor="is_physical" className="text-sm font-medium text-gray-700">
                  {t('inventory:products.isPhysical')}
                </label>
              </div>
              <p className="mt-1 text-xs text-gray-500">
                {t('inventory:products.isPhysicalHelper')}
              </p>
            </div>

            <div>
              <label htmlFor="category" className="block text-sm font-medium text-gray-700">
                {t('catalog.products.category')}
              </label>
              <CategorySelect
                value={categoryId}
                onChange={(id) => { setValue('category_id', id); }}
                className="mt-1"
              />
            </div>

            <div>
              <label htmlFor="unit" className="block text-sm font-medium text-gray-700">
                Unit
              </label>
              <input
                type="text"
                id="unit"
                {...register('unit')}
                placeholder="pcs, kg, hours..."
                className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              />
            </div>

            <div>
              <BarcodeLookupInput
                onProductData={handleProductData}
                onLookupStateChange={handleLookupStateChange}
                defaultBarcode={product?.barcode ?? ''}
              />
              <input type="hidden" {...register('barcode')} />

              {/* Enrichment opt-in checkbox */}
              {lookupState === 'not_found' && (
                <div className="mt-3 flex items-center gap-2.5 rounded-lg bg-neutral-100 px-3.5 py-3">
                  <input
                    type="checkbox"
                    id="enrichment-opt-in"
                    checked={enrichmentOptIn}
                    onChange={(e) => setEnrichmentOptIn(e.target.checked)}
                    className="h-4 w-4 rounded"
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
              <input
                type="checkbox"
                id="is_active"
                {...register('is_active')}
                className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
              />
              <label htmlFor="is_active" className="text-sm font-medium text-gray-700">
                Active
              </label>
            </div>

            <div className="sm:col-span-2">
              <label htmlFor="description" className="block text-sm font-medium text-gray-700">
                Description
              </label>
              <textarea
                id="description"
                rows={3}
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
                className={`mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 ${prefilledFields.has('description') ? colors.success[50] : ''}`}
              />
            </div>
          </div>
        </div>

        {/* Pricing */}
        <div className="rounded-lg border border-gray-200 bg-white p-6">
          <h2 className="mb-4 text-lg font-semibold text-gray-900">Pricing</h2>
          <div className="grid gap-6 sm:grid-cols-3">
            <div>
              <label htmlFor="sale_price" className="block text-sm font-medium text-gray-700">
                Sale Price
              </label>
              <div className="relative mt-1">
                <span className="absolute inset-y-0 start-0 flex items-center ps-3 text-gray-500">
                  $
                </span>
                <input
                  type="number"
                  step="0.01"
                  id="sale_price"
                  {...register('sale_price')}
                  className="block w-full rounded-lg border border-gray-300 ps-7 pe-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                />
              </div>
            </div>

            {/* Cost (WAC) - Read-only when editing */}
            {isEditing && product && (
              <div>
                <label className="block text-sm font-medium text-gray-700">
                  Cost (WAC)
                </label>
                <div className="relative mt-1">
                  <span className="absolute inset-y-0 start-0 flex items-center ps-3 text-gray-500">
                    $
                  </span>
                  <input
                    type="text"
                    value={product.cost_price ? parseFloat(product.cost_price).toFixed(decimals) : (0).toFixed(decimals)}
                    readOnly
                    className="block w-full rounded-lg border border-gray-200 bg-gray-50 ps-7 pe-3 py-2 text-gray-600 cursor-not-allowed"
                  />
                </div>
                <p className="mt-1 text-xs text-gray-500">
                  Automatically calculated from purchase operations
                </p>
              </div>
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
          <div className="rounded-lg border border-gray-200 bg-white p-6">
            <h2 className="mb-4 text-lg font-semibold text-gray-900">Automotive Information</h2>

            {/* OEM Numbers */}
            <div className="mb-6">
              <label className="block text-sm font-medium text-gray-700 mb-2">
                OEM Numbers
              </label>
              <div className="flex gap-2">
                <input
                  type="text"
                  value={oemInput}
                  onChange={(e) => { setOemInput(e.target.value) }}
                  onKeyDown={handleOemKeyDown}
                  placeholder="Enter OEM number"
                  className="flex-1 rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                />
                <button
                  type="button"
                  onClick={handleAddOem}
                  className="inline-flex items-center gap-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                >
                  <Plus className="h-4 w-4" />
                  Add
                </button>
              </div>
              {oemNumbers.length > 0 && (
                <div className="mt-2 flex flex-wrap gap-2">
                  {oemNumbers.map((oem, index) => (
                    <span
                      key={index}
                      className="inline-flex items-center gap-1 rounded-md bg-gray-100 px-2.5 py-1 text-sm font-mono text-gray-700"
                    >
                      {oem}
                      <button
                        type="button"
                        onClick={() => { handleRemoveOem(index) }}
                        className="text-gray-400 hover:text-gray-600"
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
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Cross References
              </label>
              <div className="space-y-2">
                {crossRefFields.map((field, index) => (
                  <div key={field.id} className="flex gap-2">
                    <input
                      type="text"
                      // eslint-disable-next-line @typescript-eslint/restrict-template-expressions
                      {...register(`cross_references.${index}.brand` as const)}
                      placeholder="Brand"
                      className="flex-1 rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                    />
                    <input
                      type="text"
                      // eslint-disable-next-line @typescript-eslint/restrict-template-expressions
                      {...register(`cross_references.${index}.reference` as const)}
                      placeholder="Reference"
                      className="flex-1 rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                    />
                    <button
                      type="button"
                      onClick={() => { removeCrossRef(index) }}
                      className="inline-flex items-center rounded-lg border border-gray-300 bg-white p-2 text-gray-400 hover:bg-gray-50 hover:text-gray-600"
                    >
                      <X className="h-4 w-4" />
                    </button>
                  </div>
                ))}
              </div>
              <button
                type="button"
                onClick={() => { appendCrossRef({ brand: '', reference: '' }) }}
                className="mt-2 inline-flex items-center gap-1 text-sm text-blue-600 hover:text-blue-800"
              >
                <Plus className="h-4 w-4" />
                Add Cross Reference
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

        {/* Product Images Section - Only when editing */}
        {isEditing && id && (
          <div className="rounded-lg border border-gray-200 bg-white p-6">
            <div className="mb-4 flex items-center gap-2">
              <Image className="h-5 w-5 text-gray-400" />
              <h2 className="text-lg font-semibold text-gray-900">Product Images</h2>
            </div>
            <ProductImageSection productId={id} />
          </div>
        )}

        {/* Form Actions */}
        <StickyFormFooter>
          <Link
            to="/inventory/products"
            className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
          >
            {t('actions.cancel')}
          </Link>
          <button
            type="submit"
            disabled={isSubmitting}
            className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50 transition-colors"
          >
            {isSubmitting ? t('status.saving') : t('actions.save')}
          </button>
        </StickyFormFooter>
      </form>
    </div>
  )
}
