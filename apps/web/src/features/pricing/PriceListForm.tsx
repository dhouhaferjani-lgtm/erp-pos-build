import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { ArrowLeft } from 'lucide-react'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { fetchPriceList, createPriceList, updatePriceList } from './api'
import { getErrorMessage, getFieldErrors } from '@/lib/api'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { Input } from '@/components/atoms/Input'
import { Select } from '@/components/atoms/Select'
import { Textarea } from '@/components/atoms/Textarea'
import { Checkbox } from '@/components/atoms/Checkbox'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'
import { priceListsInvalidationPredicate } from './_invalidation'
import type { PriceListFormData } from './types'

// Server validation errors we can attach to a matching form field. Every field
// listed here MUST have an inline renderer below — a `setError` on a field the
// form never displays is a silently dropped 422 (gate r1 F-6). Anything the
// backend rejects that is NOT in this list is collected into
// `unmappedServerErrors` and shown in the form-level alert instead.
const PRICE_LIST_FIELDS: readonly (keyof PriceListFormData)[] = [
  'code', 'name', 'description', 'currency', 'is_active', 'is_default', 'valid_from', 'valid_until',
]

function isPriceListField(field: string): field is keyof PriceListFormData {
  return (PRICE_LIST_FIELDS as readonly string[]).includes(field)
}

export function PriceListForm() {
  const { t } = useTranslation(['common', 'pricing'])
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { id } = useParams<{ id: string }>()
  const isEditing = Boolean(id)
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  // Backend 422 messages for fields this form has no input for. Rendered in the
  // form-level alert so no server message is silently dropped (gate r1 F-6).
  const [unmappedServerErrors, setUnmappedServerErrors] = useState<string[]>([])

  const {
    register,
    handleSubmit,
    reset,
    setError,
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

  // Reset form when existing data is loaded.
  // `fetchPriceList` resolves the PriceListDetail itself (apiGet already unwraps
  // `response.data.data`) — reading `.data` off it left the edit form empty.
  useEffect(() => {
    if (existingPriceList) {
      const data = existingPriceList
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

  // Map backend 422 validation errors onto the matching form fields so they
  // surface inline instead of failing silently (DEV-QA-015). Messages for
  // fields this form has no input for are NOT dropped — they go to the
  // form-level alert (gate r1 F-6).
  const applyServerErrors = (error: Error) => {
    const fieldErrors = getFieldErrors(error)
    if (!fieldErrors) {
      setUnmappedServerErrors([])
      return
    }
    const unmapped: string[] = []
    for (const [field, message] of Object.entries(fieldErrors)) {
      if (isPriceListField(field)) {
        setError(field, { type: 'server', message })
      } else {
        unmapped.push(message)
      }
    }
    setUnmappedServerErrors(unmapped)
  }

  const createMutation = useMutation({
    mutationFn: createPriceList,
    onSuccess: async (response) => {
      await queryClient.invalidateQueries({
        predicate: priceListsInvalidationPredicate(tenantId, companyId),
      })
      navigate(`/pricing/price-lists/${response.id}`)
    },
    onError: applyServerErrors,
  })

  const updateMutation = useMutation({
    mutationFn: (data: PriceListFormData) => updatePriceList(id!, data),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: priceListsInvalidationPredicate(tenantId, companyId),
        }),
        queryClient.invalidateQueries({ queryKey: ['price-list', id] }),
      ])
      navigate(`/pricing/price-lists/${id}`)
    },
    onError: applyServerErrors,
  })

  const onSubmit = (data: PriceListFormData) => {
    setUnmappedServerErrors([])
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
        <div className={`${colorTokens.text.subtle}`}>{t('common:status.loading')}</div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center gap-4">
        <Link
          to="/pricing/price-lists"
          className={`inline-flex items-center gap-2 text-sm ${colorTokens.text.muted} ${colorTokens.variants.hoverTextGray900}`}
        >
          <ArrowLeft className="h-4 w-4" />
          {t('common:actions.back')}
        </Link>
        <div>
          <PageHeaderTitle className={`text-2xl font-bold ${colorTokens.text.primary}`}>
            {isEditing
              ? t('pricing:priceLists.edit', 'Edit Price List')
              : t('pricing:priceLists.createNew', 'Create Price List')}
          </PageHeaderTitle>
        </div>
      </div>

      {/* Form */}
      <form
        onSubmit={(e) => void handleSubmit(onSubmit)(e)}
        className={`rounded-lg border ${colorTokens.border.subtle} bg-white p-6 space-y-6`}
      >
        <div className="grid gap-6 sm:grid-cols-2">
          {/* Code */}
          <div>
            <label htmlFor="code" className={`block text-sm font-medium ${colorTokens.text.secondary}`}>
              {t('pricing:priceLists.fields.code', 'Code')} *
            </label>
            <Input
              type="text"
              id="code"
              {...register('code', { required: t('pricing:validation.codeRequired', 'Code is required') })}
              error={Boolean(errors.code)}
              placeholder={t('pricing:priceLists.codePlaceholder')}
            />
            {errors.code && (
              <p className={`mt-1 text-sm ${colorTokens.intent.danger.text}`}>{errors.code.message}</p>
            )}
          </div>

          {/* Name */}
          <div>
            <label htmlFor="name" className={`block text-sm font-medium ${colorTokens.text.secondary}`}>
              {t('pricing:priceLists.fields.name', 'Name')} *
            </label>
            <Input
              type="text"
              id="name"
              {...register('name', { required: t('pricing:validation.nameRequired', 'Name is required') })}
              error={Boolean(errors.name)}
              placeholder={t('pricing:priceLists.namePlaceholder')}
            />
            {errors.name && (
              <p className={`mt-1 text-sm ${colorTokens.intent.danger.text}`}>{errors.name.message}</p>
            )}
          </div>

          {/* Currency */}
          <div>
            <label htmlFor="currency" className={`block text-sm font-medium ${colorTokens.text.secondary}`}>
              {t('pricing:priceLists.fields.currency', 'Currency')} *
            </label>
            <Select
              id="currency"
              {...register('currency', { required: true })}
              error={Boolean(errors.currency)}
            >
              <option value="TND">{t('pricing:priceLists.currencies.TND')}</option>
              <option value="EUR">{t('pricing:priceLists.currencies.EUR')}</option>
              <option value="USD">{t('pricing:priceLists.currencies.USD')}</option>
            </Select>
            {errors.currency && (
              <p className={`mt-1 text-sm ${colorTokens.intent.danger.text}`}>{errors.currency.message}</p>
            )}
          </div>

          {/* Description */}
          <div className="sm:col-span-2">
            <label htmlFor="description" className={`block text-sm font-medium ${colorTokens.text.secondary}`}>
              {t('pricing:priceLists.fields.description', 'Description')}
            </label>
            <Textarea
              id="description"
              {...register('description')}
              rows={3}
              error={Boolean(errors.description)}
              placeholder={t('pricing:priceLists.descriptionPlaceholder', 'Optional description for this price list')}
            />
            {errors.description && (
              <p className={`mt-1 text-sm ${colorTokens.intent.danger.text}`}>{errors.description.message}</p>
            )}
          </div>

          {/* Valid From */}
          <div>
            <label htmlFor="valid_from" className={`block text-sm font-medium ${colorTokens.text.secondary}`}>
              {t('pricing:priceLists.fields.validFrom', 'Valid From')}
            </label>
            <Input
              type="date"
              id="valid_from"
              {...register('valid_from')}
              error={Boolean(errors.valid_from)}
            />
            {errors.valid_from && (
              <p className={`mt-1 text-sm ${colorTokens.intent.danger.text}`}>{errors.valid_from.message}</p>
            )}
          </div>

          {/* Valid Until */}
          <div>
            <label htmlFor="valid_until" className={`block text-sm font-medium ${colorTokens.text.secondary}`}>
              {t('pricing:priceLists.fields.validUntil', 'Valid Until')}
            </label>
            <Input
              type="date"
              id="valid_until"
              {...register('valid_until', {
                validate: (value, formValues) =>
                  !value ||
                  !formValues.valid_from ||
                  value > formValues.valid_from ||
                  t(
                    'pricing:validation.validUntilAfterValidFrom',
                    'Valid Until must be after Valid From',
                  ),
              })}
              error={Boolean(errors.valid_until)}
            />
            {errors.valid_until && (
              <p className={`mt-1 text-sm ${colorTokens.intent.danger.text}`}>{errors.valid_until.message}</p>
            )}
          </div>

          {/* Toggles */}
          <div className="sm:col-span-2 flex flex-wrap gap-6">
            {/* Active */}
            <div>
              <label htmlFor="is_active" className="flex items-center gap-2 cursor-pointer">
                <Checkbox id="is_active" {...register('is_active')} />
                <span className={`text-sm ${colorTokens.text.secondary}`}>
                  {t('pricing:priceLists.fields.active', 'Active')}
                </span>
              </label>
              {errors.is_active && (
                <p className={`mt-1 text-sm ${colorTokens.intent.danger.text}`}>{errors.is_active.message}</p>
              )}
            </div>

            {/* Default */}
            <div>
              <label htmlFor="is_default" className="flex items-center gap-2 cursor-pointer">
                <Checkbox id="is_default" {...register('is_default')} />
                <span className={`text-sm ${colorTokens.text.secondary}`}>
                  {t('pricing:priceLists.fields.default', 'Default')}
                </span>
              </label>
              {errors.is_default && (
                <p className={`mt-1 text-sm ${colorTokens.intent.danger.text}`}>{errors.is_default.message}</p>
              )}
            </div>
          </div>
        </div>

        {/* Error message. Field-level 422s render inline above; anything the
            backend rejected that this form has no input for lands here, so no
            server message is silently dropped (gate r1 F-6). */}
        {mutation.error && (
          <div className={`rounded-lg ${colorTokens.intent.danger.bgSubtle} p-3 text-sm ${colorTokens.intent.danger.textStrong}`}>
            <p>{getErrorMessage(mutation.error)}</p>
            {unmappedServerErrors.length > 0 && (
              <ul className="mt-2 list-disc ps-5">
                {unmappedServerErrors.map((message) => (
                  <li key={message}>{message}</li>
                ))}
              </ul>
            )}
          </div>
        )}

        {/* Actions */}
        <div className={`flex justify-end gap-3 pt-4 border-t ${colorTokens.border.subtle}`}>
          <Link
            to="/pricing/price-lists"
            className={`rounded-lg border ${colorTokens.border.default} bg-white px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.variants.hoverBgGray50}`}
          >
            {t('common:actions.cancel')}
          </Link>
          <button
            type="submit"
            disabled={isSubmitting || mutation.isPending}
            className={`rounded-lg ${colorTokens.intent.primary.bgStrong} px-4 py-2 text-sm font-medium text-white ${colorTokens.intent.primary.bgStrongHover} disabled:opacity-50`}
          >
            {mutation.isPending ? t('common:status.saving') : t('common:actions.save')}
          </button>
        </div>
      </form>
    </div>
  )
}
