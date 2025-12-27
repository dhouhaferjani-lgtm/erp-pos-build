import { useState, useRef, useEffect } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useForm, useFieldArray } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, Plus, X } from 'lucide-react'
import { api, apiPost, apiPatch } from '../../lib/api'
import { CategorySelect } from '../../components/catalog/CategorySelect'

type ProductType = 'part' | 'service' | 'consumable'

interface Product {
  id: string
  name: string
  sku: string
  type: ProductType
  category_id: number | null
  description: string | null
  sale_price: string | null
  purchase_price: string | null
  tax_rate: string | null
  unit: string | null
  barcode: string | null
  is_active: boolean
  oem_numbers: string[] | null
  cross_references: Array<{ brand: string; reference: string }> | null
  created_at: string
  updated_at: string | null
}

interface ProductResponse {
  data: Product
}

interface ProductFormData {
  name: string
  sku: string
  type: ProductType
  category_id: number | null
  description: string
  sale_price: string
  purchase_price: string
  tax_rate: string
  unit: string
  barcode: string
  is_active: boolean
  oem_numbers: string[]
  cross_references: Array<{ brand: string; reference: string }>
}

export function ProductForm() {
  const { t } = useTranslation()
  const { id = '' } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const isEditing = id.length > 0

  const [oemInput, setOemInput] = useState('')

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
      type: 'part',
      category_id: null,
      description: '',
      sale_price: '',
      purchase_price: '',
      tax_rate: '19',
      unit: 'pcs',
      barcode: '',
      is_active: true,
      oem_numbers: [],
      cross_references: [],
    },
  })

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
        type: product.type,
        category_id: product.category_id ?? null,
        description: product.description ?? '',
        sale_price: product.sale_price ?? '',
        purchase_price: product.purchase_price ?? '',
        tax_rate: product.tax_rate ?? '19',
        unit: product.unit ?? 'pcs',
        barcode: product.barcode ?? '',
        is_active: product.is_active,
        oem_numbers: product.oem_numbers ?? [],
        cross_references: product.cross_references ?? [],
      })
    }
  }, [product, reset])

  const createMutation = useMutation({
    mutationFn: (data: ProductFormData) => apiPost<Product>('/products', data),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['products'] })
      void navigate('/inventory/products')
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

  const onSubmit = (data: ProductFormData) => {
    if (isEditing) {
      updateMutation.mutate(data)
    } else {
      createMutation.mutate(data)
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
    <div className="space-y-6">
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
      <form onSubmit={(e) => { void handleSubmit(onSubmit)(e) }} className="space-y-6">
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
                {...register('name', { required: 'Name is required' })}
                className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
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
              <label htmlFor="type" className="block text-sm font-medium text-gray-700">
                Type *
              </label>
              <select
                id="type"
                {...register('type', { required: 'Type is required' })}
                className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              >
                <option value="part">Part</option>
                <option value="service">Service</option>
                <option value="consumable">Consumable</option>
              </select>
              {errors.type && (
                <p className="mt-1 text-sm text-red-600">{errors.type.message}</p>
              )}
            </div>

            <div>
              <label htmlFor="category" className="block text-sm font-medium text-gray-700">
                {t('catalog.products.category')}
              </label>
              <CategorySelect
                value={categoryId}
                onChange={(id) => setValue('category_id', id)}
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
              <label htmlFor="barcode" className="block text-sm font-medium text-gray-700">
                Barcode
              </label>
              <input
                type="text"
                id="barcode"
                {...register('barcode')}
                className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              />
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
                {...register('description')}
                className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
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

            <div>
              <label htmlFor="purchase_price" className="block text-sm font-medium text-gray-700">
                Purchase Price
              </label>
              <div className="relative mt-1">
                <span className="absolute inset-y-0 start-0 flex items-center ps-3 text-gray-500">
                  $
                </span>
                <input
                  type="number"
                  step="0.01"
                  id="purchase_price"
                  {...register('purchase_price')}
                  className="block w-full rounded-lg border border-gray-300 ps-7 pe-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                />
              </div>
            </div>

            <div>
              <label htmlFor="tax_rate" className="block text-sm font-medium text-gray-700">
                Tax Rate (%)
              </label>
              <div className="relative mt-1">
                <input
                  type="number"
                  step="0.01"
                  id="tax_rate"
                  {...register('tax_rate')}
                  className="block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                />
                <span className="absolute inset-y-0 end-0 flex items-center pe-3 text-gray-500">
                  %
                </span>
              </div>
            </div>
          </div>
        </div>

        {/* Automotive Information */}
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

        {/* Form Actions */}
        <div className="flex items-center justify-end gap-4">
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
        </div>
      </form>
    </div>
  )
}
