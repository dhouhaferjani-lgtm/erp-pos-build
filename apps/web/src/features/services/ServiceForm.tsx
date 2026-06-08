import { useEffect } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { ArrowLeft } from 'lucide-react'
import { api } from '../../lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { servicesInvalidationPredicate } from './_invalidation'
import type { Service, CreateServiceData, CategoriesResponse } from './types'
import { TaxConfigurationField } from '../../components/molecules/TaxConfigurationField'
import { MoneyInput, QuantityInput } from '@/components/atoms'

interface ServiceResponse {
  data: Service
}

interface ServiceFormData extends Omit<CreateServiceData, 'base_price' | 'hourly_rate' | 'tax_rate'> {
  base_price: string
  hourly_rate: string
  tax_rate: string
  tax_configuration_id: string | null
}

export function ServiceForm() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const { id } = useParams<{ id: string }>()
  const isEditing = Boolean(id)

  const {
    register,
    handleSubmit,
    reset,
    watch,
    setValue,
    formState: { errors, isSubmitting },
  } = useForm<ServiceFormData>({
    defaultValues: {
      code: '',
      name: '',
      description: '',
      category_id: '',
      pricing_type: 'flat_rate',
      base_price: '',
      currency: 'TND',
      default_duration_minutes: null,
      hourly_rate: '',
      tax_rate: '',
      tax_configuration_id: null,
      is_active: true,
    },
  })

  const pricingType = watch('pricing_type')
  // watch() types as T | undefined even when defaultValues guarantee a value;
  // non-null assertion is safe: currency always has defaultValue 'TND'.
  // eslint-disable-next-line @typescript-eslint/no-non-null-assertion
  const watchedCurrency = watch('currency')!

  // Fetch categories for dropdown
  const { data: categoriesData } = useQuery({
    queryKey: tenantScopedKey(['service-categories']),
    queryFn: async () => {
      const response = await api.get<CategoriesResponse>('/services/categories')
      return response.data
    },
    enabled: !!tenantId && !!companyId,
  })

  const categories = categoriesData?.data ?? []

  // Fetch existing service for editing
  const { data: existingService, isLoading: isLoadingService } = useQuery({
    queryKey: tenantScopedKey(['service', id]),
    queryFn: async () => {
      const response = await api.get<ServiceResponse>(`/services/${id}`)
      return response.data
    },
    enabled: isEditing && !!tenantId && !!companyId,
  })

  // Reset form when existing data is loaded
  useEffect(() => {
    if (existingService?.data) {
      const data = existingService.data
      reset({
        code: data.code,
        name: data.name,
        description: data.description ?? '',
        category_id: data.category_id ?? '',
        pricing_type: data.pricing_type,
        base_price: data.base_price ?? '',
        currency: data.currency,
        default_duration_minutes: data.default_duration_minutes ?? null,
        hourly_rate: data.hourly_rate ?? '',
        tax_rate: data.tax_rate ?? '',
        tax_configuration_id: data.default_tax_configuration_id ?? null,
        is_active: data.is_active,
      })
    }
  }, [existingService, reset])

  const createMutation = useMutation({
    mutationFn: async (data: ServiceFormData) => {
      const payload = {
        ...data,
        category_id: data.category_id || null,
        base_price: data.base_price || '0',
        hourly_rate: data.hourly_rate || null,
        tax_rate: data.tax_rate || null,
        default_duration_minutes: data.default_duration_minutes || null,
      }
      const response = await api.post<ServiceResponse>('/services', payload)
      return response.data
    },
    onSuccess: async (response) => {
      await queryClient.invalidateQueries({
        predicate: servicesInvalidationPredicate(tenantId, companyId),
      })
      navigate(`/services/${response.data.id}`)
    },
  })

  const updateMutation = useMutation({
    mutationFn: async (data: ServiceFormData) => {
      const payload = {
        ...data,
        category_id: data.category_id || null,
        base_price: data.base_price || '0',
        hourly_rate: data.hourly_rate || null,
        tax_rate: data.tax_rate || null,
        default_duration_minutes: data.default_duration_minutes || null,
      }
      const response = await api.put<ServiceResponse>(`/services/${id}`, payload)
      return response.data
    },
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: servicesInvalidationPredicate(tenantId, companyId),
        }),
        queryClient.invalidateQueries({ queryKey: tenantScopedKey(['service', id]) }),
      ])
      navigate(`/services/${id}`)
    },
  })

  const onSubmit = (data: ServiceFormData) => {
    if (isEditing) {
      updateMutation.mutate(data)
    } else {
      createMutation.mutate(data)
    }
  }

  const mutation = isEditing ? updateMutation : createMutation

  if (isEditing && isLoadingService) {
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
          to="/services"
          className="inline-flex items-center gap-2 text-sm text-gray-600 hover:text-gray-900"
        >
          <ArrowLeft className="h-4 w-4" />
          {t('actions.back')}
        </Link>
        <div>
          <h1 className="text-2xl font-bold text-gray-900">
            {isEditing
              ? t('services.editService', 'Edit Service')
              : t('services.createService', 'Create Service')}
          </h1>
        </div>
      </div>

      {/* Form */}
      <form
        onSubmit={(e) => void handleSubmit(onSubmit)(e)}
        className="space-y-6"
      >
        {/* Error display */}
        {mutation.error && (
          <div className="rounded-lg bg-red-50 p-4 text-red-700">
            {t('errors.saveFailed', 'Failed to save. Please try again.')}
          </div>
        )}

        {/* Basic Information */}
        <div className="rounded-lg border border-gray-200 bg-white p-6">
          <h2 className="text-lg font-semibold text-gray-900 mb-4">
            {t('services.basicInfo', 'Basic Information')}
          </h2>
          <div className="grid gap-6 sm:grid-cols-2">
            {/* Code */}
            <div>
              <label htmlFor="code" className="block text-sm font-medium text-gray-700">
                {t('services.fields.code', 'Code')} *
              </label>
              <input
                type="text"
                id="code"
                {...register('code', { required: t('validation.required', 'This field is required') })}
                className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                placeholder="SRV-001"
              />
              {errors.code && (
                <p className="mt-1 text-sm text-red-600">{errors.code.message}</p>
              )}
            </div>

            {/* Name */}
            <div>
              <label htmlFor="name" className="block text-sm font-medium text-gray-700">
                {t('fields.name', 'Name')} *
              </label>
              <input
                type="text"
                id="name"
                {...register('name', { required: t('validation.required', 'This field is required') })}
                className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                placeholder={t('services.namePlaceholder', 'Oil Change')}
              />
              {errors.name && (
                <p className="mt-1 text-sm text-red-600">{errors.name.message}</p>
              )}
            </div>

            {/* Category */}
            <div>
              <label htmlFor="category_id" className="block text-sm font-medium text-gray-700">
                {t('services.fields.category', 'Category')}
              </label>
              <select
                id="category_id"
                {...register('category_id')}
                className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              >
                <option value="">{t('services.noCategory', 'No Category')}</option>
                {categories.map((category) => (
                  <option key={category.id} value={category.id}>
                    {category.name}
                  </option>
                ))}
              </select>
            </div>

            {/* Status */}
            <div>
              <label htmlFor="is_active" className="block text-sm font-medium text-gray-700">
                {t('fields.status', 'Status')}
              </label>
              <select
                id="is_active"
                {...register('is_active')}
                className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              >
                <option value="true">{t('status.active')}</option>
                <option value="false">{t('status.inactive')}</option>
              </select>
            </div>

            {/* Description */}
            <div className="sm:col-span-2">
              <label htmlFor="description" className="block text-sm font-medium text-gray-700">
                {t('fields.description', 'Description')}
              </label>
              <textarea
                id="description"
                {...register('description')}
                rows={3}
                className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                placeholder={t('services.descriptionPlaceholder', 'Optional description for this service')}
              />
            </div>
          </div>
        </div>

        {/* Pricing Information */}
        <div className="rounded-lg border border-gray-200 bg-white p-6">
          <h2 className="text-lg font-semibold text-gray-900 mb-4">
            {t('services.pricing', 'Pricing')}
          </h2>
          <div className="grid gap-6 sm:grid-cols-2">
            {/* Pricing Type */}
            <div>
              <label htmlFor="pricing_type" className="block text-sm font-medium text-gray-700">
                {t('services.pricingType', 'Pricing Type')} *
              </label>
              <select
                id="pricing_type"
                {...register('pricing_type', { required: true })}
                className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              >
                <option value="flat_rate">{t('services.pricingTypes.flatRate', 'Flat Rate')}</option>
                <option value="hourly">{t('services.pricingTypes.hourly', 'Hourly')}</option>
                <option value="percentage">{t('services.pricingTypes.percentage', 'Percentage')}</option>
              </select>
            </div>

            {/* Currency */}
            <div>
              <label htmlFor="currency" className="block text-sm font-medium text-gray-700">
                {t('fields.currency', 'Currency')} *
              </label>
              <select
                id="currency"
                {...register('currency', { required: true })}
                className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              >
                <option value="TND">TND - Tunisian Dinar</option>
                <option value="EUR">EUR - Euro</option>
                <option value="USD">USD - US Dollar</option>
              </select>
            </div>

            {/* Base Price (for flat_rate) */}
            {pricingType === 'flat_rate' && (
              <div>
                <label htmlFor="base_price" className="block text-sm font-medium text-gray-700">
                  {t('services.fields.basePrice', 'Base Price')} *
                </label>
                <MoneyInput
                  id="base_price"
                  currency={watchedCurrency}
                  min="0"
                  value={watch('base_price') ?? ''}
                  onChange={(v) => { setValue('base_price', v, { shouldValidate: true }) }}
                  required={pricingType === 'flat_rate'}
                  placeholder="0.00"
                />
                {errors.base_price && (
                  <p className="mt-1 text-sm text-red-600">{errors.base_price.message}</p>
                )}
              </div>
            )}

            {/* Hourly Rate (for hourly) */}
            {pricingType === 'hourly' && (
              <>
                <div>
                  <label htmlFor="hourly_rate" className="block text-sm font-medium text-gray-700">
                    {t('services.fields.hourlyRate', 'Hourly Rate')} *
                  </label>
                  <MoneyInput
                    id="hourly_rate"
                    currency={watchedCurrency}
                    min="0"
                    value={watch('hourly_rate') ?? ''}
                    onChange={(v) => { setValue('hourly_rate', v, { shouldValidate: true }) }}
                    required={pricingType === 'hourly'}
                    placeholder="0.00"
                  />
                  {errors.hourly_rate && (
                    <p className="mt-1 text-sm text-red-600">{errors.hourly_rate.message}</p>
                  )}
                </div>
                <div>
                  <label htmlFor="default_duration_minutes" className="block text-sm font-medium text-gray-700">
                    {t('services.fields.defaultDuration', 'Default Duration (minutes)')}
                  </label>
                  <input
                    type="number"
                    step="1"
                    min="0"
                    id="default_duration_minutes"
                    {...register('default_duration_minutes', { valueAsNumber: true })}
                    className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                    placeholder="60"
                  />
                </div>
              </>
            )}

            {/* Percentage (for percentage) */}
            {pricingType === 'percentage' && (
              <div>
                <label htmlFor="base_price" className="block text-sm font-medium text-gray-700">
                  {t('services.fields.percentage', 'Percentage')} *
                </label>
                <div className="relative mt-1">
                  <QuantityInput
                    id="base_price"
                    decimalPlaces={2}
                    min="0"
                    max="100"
                    value={watch('base_price') ?? ''}
                    onChange={(v) => { setValue('base_price', v, { shouldValidate: true }) }}
                    required={pricingType === 'percentage'}
                    placeholder="10"
                    className="block w-full pe-8"
                  />
                  <span className="absolute inset-y-0 end-3 flex items-center text-gray-500">%</span>
                </div>
                {errors.base_price && (
                  <p className="mt-1 text-sm text-red-600">{errors.base_price.message}</p>
                )}
              </div>
            )}

            {/* Tax Rate */}
            <TaxConfigurationField
              label={t('services.fields.taxRate', 'Tax Rate')}
              value={watch('tax_configuration_id')}
              onChange={(configId, taxRate) => {
                setValue('tax_configuration_id', configId)
                setValue('tax_rate', taxRate)
              }}
            />
          </div>
        </div>

        {/* Form Actions */}
        <div className="flex items-center justify-end gap-3">
          <Link
            to="/services"
            className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
          >
            {t('actions.cancel')}
          </Link>
          <button
            type="submit"
            disabled={isSubmitting || mutation.isPending}
            className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
          >
            {mutation.isPending ? t('status.saving', 'Saving...') : t('actions.save')}
          </button>
        </div>
      </form>
    </div>
  )
}
