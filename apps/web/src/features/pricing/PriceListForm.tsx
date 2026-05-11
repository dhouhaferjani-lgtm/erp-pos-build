import { useEffect } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { ArrowLeft } from 'lucide-react'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { fetchPriceList, createPriceList, updatePriceList } from './api'
import { priceListsInvalidationPredicate } from './_invalidation'
import type { PriceListFormData } from './types'

export function PriceListForm() {
  const { t } = useTranslation(['common', 'pricing'])
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { id } = useParams<{ id: string }>()
  const isEditing = Boolean(id)
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<PriceListFormData>({
    defaultValues: {
      code: '',
      name: '',
      description: '',
      currency: 'TND',
      is_active: true,
      is_default: false,
      valid_from: null,
      valid_until: null,
    },
  })

  // Fetch existing price list for editing
  const { data: existingPriceList, isLoading: isLoadingPriceList } = useQuery({
    queryKey: tenantScopedKey(['price-list', id]),
    queryFn: () => fetchPriceList(id!),
    enabled: isEditing && !!tenantId && !!companyId,
  })

  // Reset form when existing data is loaded
  useEffect(() => {
    if (existingPriceList?.data) {
      const data = existingPriceList.data
      reset({
        code: data.code,
        name: data.name,
        description: data.description ?? '',
        currency: data.currency,
        is_active: data.is_active,
        is_default: data.is_default,
        valid_from: data.valid_from,
        valid_until: data.valid_until,
      })
    }
  }, [existingPriceList, reset])

  const createMutation = useMutation({
    mutationFn: createPriceList,
    onSuccess: async (response) => {
      await queryClient.invalidateQueries({
        predicate: priceListsInvalidationPredicate(tenantId, companyId),
      })
      navigate(`/pricing/price-lists/${response.data.id}`)
    },
  })

  const updateMutation = useMutation({
    mutationFn: (data: PriceListFormData) => updatePriceList(id!, data),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: priceListsInvalidationPredicate(tenantId, companyId),
        }),
        queryClient.invalidateQueries({ queryKey: tenantScopedKey(['price-list', id]) }),
      ])
      navigate(`/pricing/price-lists/${id}`)
    },
  })

  const onSubmit = (data: PriceListFormData) => {
    if (isEditing) {
      updateMutation.mutate(data)
    } else {
      createMutation.mutate(data)
    }
  }

  const mutation = isEditing ? updateMutation : createMutation

  if (isEditing && isLoadingPriceList) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className="text-gray-500">{t('common:status.loading')}</div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center gap-4">
        <Link
          to="/pricing/price-lists"
          className="inline-flex items-center gap-2 text-sm text-gray-600 hover:text-gray-900"
        >
          <ArrowLeft className="h-4 w-4" />
          {t('common:actions.back')}
        </Link>
        <div>
          <h1 className="text-2xl font-bold text-gray-900">
            {isEditing
              ? t('pricing:priceLists.edit', 'Edit Price List')
              : t('pricing:priceLists.createNew', 'Create Price List')}
          </h1>
        </div>
      </div>

      {/* Form */}
      <form
        onSubmit={(e) => void handleSubmit(onSubmit)(e)}
        className="rounded-lg border border-gray-200 bg-white p-6 space-y-6"
      >
        <div className="grid gap-6 sm:grid-cols-2">
          {/* Code */}
          <div>
            <label htmlFor="code" className="block text-sm font-medium text-gray-700">
              {t('pricing:priceLists.fields.code', 'Code')} *
            </label>
            <input
              type="text"
              id="code"
              {...register('code', { required: t('pricing:validation.codeRequired', 'Code is required') })}
              className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              placeholder="RETAIL"
            />
            {errors.code && (
              <p className="mt-1 text-sm text-red-600">{errors.code.message}</p>
            )}
          </div>

          {/* Name */}
          <div>
            <label htmlFor="name" className="block text-sm font-medium text-gray-700">
              {t('pricing:priceLists.fields.name', 'Name')} *
            </label>
            <input
              type="text"
              id="name"
              {...register('name', { required: t('pricing:validation.nameRequired', 'Name is required') })}
              className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              placeholder="Retail Prices"
            />
            {errors.name && (
              <p className="mt-1 text-sm text-red-600">{errors.name.message}</p>
            )}
          </div>

          {/* Currency */}
          <div>
            <label htmlFor="currency" className="block text-sm font-medium text-gray-700">
              {t('pricing:priceLists.fields.currency', 'Currency')} *
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

          {/* Description */}
          <div className="sm:col-span-2">
            <label htmlFor="description" className="block text-sm font-medium text-gray-700">
              {t('pricing:priceLists.fields.description', 'Description')}
            </label>
            <textarea
              id="description"
              {...register('description')}
              rows={3}
              className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              placeholder={t('pricing:priceLists.descriptionPlaceholder', 'Optional description for this price list')}
            />
          </div>

          {/* Valid From */}
          <div>
            <label htmlFor="valid_from" className="block text-sm font-medium text-gray-700">
              {t('pricing:priceLists.fields.validFrom', 'Valid From')}
            </label>
            <input
              type="date"
              id="valid_from"
              {...register('valid_from')}
              className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
          </div>

          {/* Valid Until */}
          <div>
            <label htmlFor="valid_until" className="block text-sm font-medium text-gray-700">
              {t('pricing:priceLists.fields.validUntil', 'Valid Until')}
            </label>
            <input
              type="date"
              id="valid_until"
              {...register('valid_until')}
              className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
          </div>

          {/* Toggles */}
          <div className="sm:col-span-2 flex flex-wrap gap-6">
            {/* Active */}
            <label htmlFor="is_active" className="flex items-center gap-2 cursor-pointer">
              <input
                type="checkbox"
                id="is_active"
                {...register('is_active')}
                className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
              />
              <span className="text-sm text-gray-700">
                {t('pricing:priceLists.fields.active', 'Active')}
              </span>
            </label>

            {/* Default */}
            <label htmlFor="is_default" className="flex items-center gap-2 cursor-pointer">
              <input
                type="checkbox"
                id="is_default"
                {...register('is_default')}
                className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
              />
              <span className="text-sm text-gray-700">
                {t('pricing:priceLists.fields.default', 'Default')}
              </span>
            </label>
          </div>
        </div>

        {/* Error message */}
        {mutation.error && (
          <div className="rounded-lg bg-red-50 p-3 text-sm text-red-700">
            {mutation.error instanceof Error
              ? mutation.error.message
              : t('common:status.error')}
          </div>
        )}

        {/* Actions */}
        <div className="flex justify-end gap-3 pt-4 border-t border-gray-200">
          <Link
            to="/pricing/price-lists"
            className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
          >
            {t('common:actions.cancel')}
          </Link>
          <button
            type="submit"
            disabled={isSubmitting || mutation.isPending}
            className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
          >
            {mutation.isPending ? t('common:status.saving') : t('common:actions.save')}
          </button>
        </div>
      </form>
    </div>
  )
}
