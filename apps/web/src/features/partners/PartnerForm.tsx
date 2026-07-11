import { useEffect, useRef } from 'react'
import { Link, useNavigate, useParams, useLocation } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useForm, Controller } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, Loader2 } from 'lucide-react'
import { toast } from 'sonner'
import { api, apiPost, apiPatch, getErrorMessage, isApiError } from '../../lib/api'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { tokens } from '../../lib/designTokens'
import { cn } from '../../lib/utils'
import { FormField } from '../../components/atoms/FormField/FormField'
import { Input } from '../../components/atoms/Input/Input'
import { Select } from '../../components/atoms/Select/Select'
import { Textarea } from '../../components/atoms/Textarea/Textarea'
import { Button } from '../../components/atoms/Button/Button'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { B2BFieldsSection } from './components/B2BFieldsSection'
import { getCountries } from '../settings/api/country'
import type { Country } from '../settings/types/country'
import type { PartnerType } from './PartnerListPage'
import { partnersInvalidationPredicate } from './_invalidation'
import { readPartnerPrefill } from './partnerPrefill'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'

const PINNED_COUNTRY_CODES = ['FR', 'TN', 'GB', 'IT', 'MA', 'DZ', 'US']

interface Partner {
  id: string
  name: string
  type: 'customer' | 'supplier' | 'both'
  customer_category: 'individual' | 'business' | null
  company_legal_name: string | null
  business_registration_number: string | null
  payment_terms: string | null
  payment_terms_days: number | null
  credit_limit: string | null
  discount_percentage: string | null
  invoice_consolidation: boolean
  consolidation_frequency: string | null
  email: string | null
  phone: string | null
  street_address: string | null
  city: string | null
  state: string | null
  postal_code: string | null
  country: string | null
  country_code: string | null
  vat_number: string | null
  tax_status: 'REGISTERED' | 'NON_REGISTERED' | 'EXEMPT'
  exemption_reason: string | null
  exemption_certificate_path: string | null
  exemption_valid_until: string | null
  notes: string | null
}

interface PartnerFormData {
  name: string
  type: 'customer' | 'supplier' | 'both' | ''
  customer_category: 'individual' | 'business' | ''
  company_legal_name: string
  business_registration_number: string
  payment_terms: string
  payment_terms_days: string
  credit_limit: string
  discount_percentage: string
  invoice_consolidation: boolean
  consolidation_frequency: string
  email: string
  phone: string
  street_address: string
  city: string
  state: string
  postal_code: string
  country: string
  country_code: string
  vat_number: string
  tax_status: 'REGISTERED' | 'NON_REGISTERED' | 'EXEMPT'
  exemption_reason: string
  exemption_valid_until: string
  notes: string
}

interface PartnerFormProps {
  partnerType?: PartnerType
}

/**
 * Sort countries with pinned ones at the top, rest alphabetically by name.
 */
function sortCountries(countries: Country[]): { pinned: Country[]; rest: Country[] } {
  const pinned: Country[] = []
  const rest: Country[] = []

  for (const country of countries) {
    if (PINNED_COUNTRY_CODES.includes(country.code)) {
      pinned.push(country)
    } else {
      rest.push(country)
    }
  }

  // Sort pinned by their order in the PINNED_COUNTRY_CODES array
  pinned.sort((a, b) => PINNED_COUNTRY_CODES.indexOf(a.code) - PINNED_COUNTRY_CODES.indexOf(b.code))
  // Sort rest alphabetically by name
  rest.sort((a, b) => a.name.localeCompare(b.name))

  return { pinned, rest }
}

/**
 * Extract field-level validation errors from a 422 API error response.
 * Laravel returns: { error: { code: "VALIDATION_ERROR", errors: { field: ["msg"] } } }
 */
function getFieldErrors(error: unknown): Record<string, string> | null {
  if (!isApiError(error)) return null
  const data = error.response?.data as { error?: { errors?: Record<string, string[]> } } | undefined
  const errors = data?.error?.errors
  if (!errors) return null

  const result: Record<string, string> = {}
  for (const [field, messages] of Object.entries(errors)) {
    if (Array.isArray(messages) && messages.length > 0) {
      result[field] = messages[0]
    }
  }
  return result
}

export function PartnerForm({ partnerType }: PartnerFormProps) {
  const { t } = useTranslation(['sales', 'common', 'countries'])
  const { id = '' } = useParams<{ id: string }>()
  const location = useLocation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const isEditing = id.length > 0
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const currentCompany = useCompanyStore((state) => state.getCurrentCompany())
  const hasTenantScope = tenantId !== null && companyId !== null
  const defaultCountryCode = currentCompany?.countryCode ?? 'TN'

  // Determine partner type from props or URL
  const isCustomerContext = partnerType === 'customer' || location.pathname.includes('/sales/customers')
  const isSupplierContext = partnerType === 'supplier' || location.pathname.includes('/purchases/suppliers')

  const basePath = isCustomerContext
    ? '/sales/customers'
    : isSupplierContext
      ? '/purchases/suppliers'
      : '/partners'

  const entityName = isCustomerContext
    ? t('navigation.customers', { ns: 'common' }).slice(0, -1)
    : isSupplierContext
      ? t('navigation.suppliers', { ns: 'common' }).slice(0, -1)
      : t('sales:partners.title').slice(0, -1)

  // Determine default partner type based on context
  const defaultType = isCustomerContext
    ? 'customer'
    : isSupplierContext
      ? 'supplier'
      : ''

  const {
    register,
    handleSubmit,
    reset,
    watch,
    control,
    setValue,
    setError,
    formState: { errors },
  } = useForm<PartnerFormData>({
    defaultValues: {
      name: '',
      type: defaultType,
      customer_category: '',
      company_legal_name: '',
      business_registration_number: '',
      payment_terms: '',
      payment_terms_days: '',
      credit_limit: '',
      discount_percentage: '',
      invoice_consolidation: false,
      consolidation_frequency: '',
      email: '',
      phone: '',
      street_address: '',
      city: '',
      state: '',
      postal_code: '',
      country: defaultCountryCode,
      country_code: defaultCountryCode,
      vat_number: '',
      tax_status: 'REGISTERED',
      exemption_reason: '',
      exemption_valid_until: '',
      notes: '',
    },
  })

  const taxStatus = watch('tax_status')
  const customerCategory = watch('customer_category')
  const addressCountry = watch('country')
  const taxCountry = watch('country_code')
  const usesTunisiaLabels = addressCountry === 'TN' || taxCountry === 'TN' || defaultCountryCode === 'TN'

  // Fetch countries for dropdown
  const { data: countries = [] } = useQuery({
    queryKey: tenantScopedKey(['countries', 'active']),
    queryFn: () => getCountries({ is_active: true }),
    enabled: hasTenantScope,
    staleTime: 10 * 60 * 1000, // 10 minutes — countries rarely change
  })

  const { pinned: pinnedCountries, rest: otherCountries } = sortCountries(countries)

  // Fetch partner data when editing
  const { data: partner, isLoading } = useQuery({
    queryKey: tenantScopedKey(['partner', id]),
    queryFn: async () => {
      const response = await api.get<{ data: Partner }>(`/partners/${id}`)
      return response.data.data
    },
    enabled: isEditing && hasTenantScope,
  })

  // Prefill create-mode form from scan-review navigation state (Scan-to-Document).
  // Additive only: never runs in edit mode (where `reset(partner)` below owns the
  // form), never touches commercial fields, and applies at most once per mount.
  const prefillAppliedRef = useRef(false)
  useEffect(() => {
    if (isEditing || prefillAppliedRef.current) {
      return
    }

    const prefill = readPartnerPrefill(location.state)
    if (!prefill) {
      return
    }

    prefillAppliedRef.current = true

    if (prefill.name !== undefined) setValue('name', prefill.name)
    if (prefill.vat_number !== undefined) setValue('vat_number', prefill.vat_number)
    if (prefill.phone !== undefined) setValue('phone', prefill.phone)
    if (prefill.email !== undefined) setValue('email', prefill.email)
    if (prefill.street_address !== undefined) setValue('street_address', prefill.street_address)
    if (prefill.city !== undefined) setValue('city', prefill.city)
    if (prefill.state !== undefined) setValue('state', prefill.state)
    if (prefill.postal_code !== undefined) setValue('postal_code', prefill.postal_code)
  }, [isEditing, location.state, setValue])

  // Country is applied separately: it must be validated against the loaded country
  // list so a syntactically valid but unknown scanned code (e.g. "XX") is never held
  // in form state and submitted (the backend would reject it at save). Gated on the
  // list resolving; applies at most once, leaving the company default when unknown.
  const prefillCountryAppliedRef = useRef(false)
  useEffect(() => {
    if (isEditing || prefillCountryAppliedRef.current) {
      return
    }
    const code = readPartnerPrefill(location.state)?.country_code
    if (code === undefined) {
      prefillCountryAppliedRef.current = true
      return
    }
    if (countries.length === 0) {
      return
    }
    prefillCountryAppliedRef.current = true
    if (countries.some((country) => country.code === code)) {
      setValue('country', code)
      setValue('country_code', code)
    }
  }, [isEditing, countries, location.state, setValue])

  // Populate form when partner data loads
  useEffect(() => {
    if (partner) {
      reset({
        name: partner.name,
        type: partner.type,
        customer_category: partner.customer_category ?? '',
        company_legal_name: partner.company_legal_name ?? '',
        business_registration_number: partner.business_registration_number ?? '',
        payment_terms: partner.payment_terms ?? '',
        payment_terms_days: partner.payment_terms_days != null ? String(partner.payment_terms_days) : '',
        credit_limit: partner.credit_limit ?? '',
        discount_percentage: partner.discount_percentage ?? '',
        invoice_consolidation: partner.invoice_consolidation,
        consolidation_frequency: partner.consolidation_frequency ?? '',
        email: partner.email ?? '',
        phone: partner.phone ?? '',
        street_address: partner.street_address ?? '',
        city: partner.city ?? '',
        state: partner.state ?? '',
        postal_code: partner.postal_code ?? '',
        country: partner.country ?? '',
        country_code: partner.country_code ?? '',
        vat_number: partner.vat_number ?? '',
        tax_status: partner.tax_status,
        exemption_reason: partner.exemption_reason ?? '',
        exemption_valid_until: partner.exemption_valid_until ?? '',
        notes: partner.notes ?? '',
      })
    }
  }, [partner, reset])

  /**
   * Handle mutation errors: show toast + map field-level errors from 422.
   */
  const handleMutationError = (error: unknown) => {
    const fieldErrors = getFieldErrors(error)
    if (fieldErrors && Object.keys(fieldErrors).length > 0) {
      // Map backend field errors to form fields
      for (const [field, message] of Object.entries(fieldErrors)) {
        setError(field as keyof PartnerFormData, { type: 'server', message })
      }
      toast.error(t('sales:partners.messages.saveFailed'))
    } else {
      toast.error(getErrorMessage(error))
    }
  }

  const createMutation = useMutation({
    mutationFn: (data: PartnerFormData) => apiPost<Partner>('/partners', data),
    onSuccess: async () => {
      toast.success(t('sales:partners.messages.created'))
      await queryClient.invalidateQueries({
        predicate: partnersInvalidationPredicate(tenantId, companyId),
      })
      void navigate(basePath)
    },
    onError: handleMutationError,
  })

  const updateMutation = useMutation({
    mutationFn: (data: PartnerFormData) =>
      apiPatch<Partner>(`/partners/${id}`, data),
    onSuccess: async () => {
      toast.success(t('sales:partners.messages.updated'))
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: partnersInvalidationPredicate(tenantId, companyId),
        }),
        queryClient.invalidateQueries({ queryKey: tenantScopedKey(['partner', id]) }),
      ])
      void navigate(`${basePath}/${id}`)
    },
    onError: handleMutationError,
  })

  const onSubmit = (data: PartnerFormData) => {
    // Clean up empty strings to null for optional fields
    const cleaned = {
      ...data,
      customer_category: data.customer_category || null,
      payment_terms: data.payment_terms || null,
      payment_terms_days: data.payment_terms_days || null,
      country: data.country || null,
      country_code: data.country_code || null,
      vat_number: data.vat_number || null,
      street_address: data.street_address || null,
      city: data.city || null,
      state: data.state || null,
      postal_code: data.postal_code || null,
      email: data.email || null,
      phone: data.phone || null,
      notes: data.notes || null,
      exemption_reason: data.exemption_reason || null,
      exemption_valid_until: data.exemption_valid_until || null,
      credit_limit: data.credit_limit || null,
      discount_percentage: data.discount_percentage || null,
      consolidation_frequency: data.consolidation_frequency || null,
      company_legal_name: data.company_legal_name || null,
      business_registration_number: data.business_registration_number || null,
    }

    if (isEditing) {
      updateMutation.mutate(cleaned as unknown as PartnerFormData)
    } else {
      createMutation.mutate(cleaned as unknown as PartnerFormData)
    }
  }

  const isSaving = createMutation.isPending || updateMutation.isPending

  if (isEditing && isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <Loader2 className={`h-6 w-6 animate-spin ${colorTokens.text.disabled}`} />
        <span className={`ms-2 ${colorTokens.text.subtle}`}>{t('common:status.loading')}</span>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center gap-4">
        <Link
          to={basePath}
          className={`inline-flex items-center gap-2 text-sm ${colorTokens.text.muted} ${colorTokens.intent.neutral.textHoverStrongest}`}
        >
          <ArrowLeft className="h-4 w-4" />
          {t('common:actions.back')}
        </Link>
        <PageHeaderTitle className={`text-2xl font-bold ${colorTokens.text.primary}`}>
          {isEditing ? `${t('common:actions.edit')} ${entityName}` : `${t('common:actions.add')} ${entityName}`}
        </PageHeaderTitle>
      </div>

      {/* Form */}
      <form onSubmit={(e) => { void handleSubmit(onSubmit)(e) }} className="space-y-6">
        {/* General Information */}
        <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-6`}>
          <h3 className={`mb-4 text-lg font-semibold ${colorTokens.text.primary}`}>
            {t('sales:partners.generalInfo')}
          </h3>
          <div className="grid gap-6 sm:grid-cols-2">
            {/* Name */}
            <FormField
              label={t('sales:partners.name')}
              htmlFor="name"
              required
              error={errors.name?.message}
              className="sm:col-span-2"
            >
              <Input
                id="name"
                {...register('name', { required: t('sales:partners.validation.nameRequired') })}
                error={!!errors.name}
              />
            </FormField>

            {/* Type */}
            <FormField
              label={t('sales:partners.type')}
              htmlFor="type"
              required
              error={errors.type?.message}
            >
              <Select
                id="type"
                {...register('type', { required: t('sales:partners.validation.typeRequired') })}
                error={!!errors.type}
              >
                <option value="">{t('sales:partners.selectType')}</option>
                <option value="customer">{t('sales:partners.types.customer')}</option>
                <option value="supplier">{t('sales:partners.types.supplier')}</option>
                <option value="both">{t('sales:partners.types.both')}</option>
              </Select>
            </FormField>

            {/* Customer Category */}
            <FormField
              label={t('sales:partners.b2b.customerCategory')}
              htmlFor="customer_category"
            >
              <Select
                id="customer_category"
                {...register('customer_category')}
              >
                <option value="">{t('sales:partners.b2b.selectCategory')}</option>
                <option value="individual">{t('sales:partners.b2b.individual')}</option>
                <option value="business">{t('sales:partners.b2b.business')}</option>
              </Select>
            </FormField>

            {/* Email */}
            <FormField
              label={t('sales:partners.email')}
              htmlFor="email"
              error={errors.email?.message}
            >
              <Input
                type="email"
                id="email"
                {...register('email', {
                  pattern: {
                    value: /^[^\s@]+@[^\s@]+\.[^\s@]+$/,
                    message: t('common:validation.invalidEmail'),
                  },
                })}
                error={!!errors.email}
              />
            </FormField>

            {/* Phone */}
            <FormField
              label={t('sales:partners.phone')}
              htmlFor="phone"
            >
              <Input
                type="tel"
                id="phone"
                {...register('phone')}
              />
            </FormField>

            {/* VAT Number */}
            <FormField
              label={usesTunisiaLabels ? t('sales:partners.taxRegistrationNumber') : t('sales:partners.vatNumber')}
              htmlFor="vat_number"
              error={errors.vat_number?.message}
            >
              <Input
                id="vat_number"
                {...register('vat_number')}
                error={!!errors.vat_number}
              />
            </FormField>

            {/* Country Code (for VAT validation) */}
            <FormField
              label={t('sales:partners.countryCode')}
              htmlFor="country_code"
              error={errors.country_code?.message}
            >
              <Controller
                name="country_code"
                control={control}
                render={({ field }) => (
                  <Select
                    id="country_code"
                    value={field.value}
                    onChange={field.onChange}
                    onBlur={field.onBlur}
                    error={!!errors.country_code}
                  >
                    <option value="">{t('sales:partners.selectCountryCode')}</option>
                    {pinnedCountries.length > 0 && (
                      <>
                        {pinnedCountries.map((c) => (
                          <option key={c.code} value={c.code}>
                            {t(`countries:${c.code}`, { defaultValue: c.name })} ({c.code})
                          </option>
                        ))}
                        <option disabled>──────────</option>
                      </>
                    )}
                    {otherCountries.map((c) => (
                      <option key={c.code} value={c.code}>
                        {t(`countries:${c.code}`, { defaultValue: c.name })} ({c.code})
                      </option>
                    ))}
                  </Select>
                )}
              />
            </FormField>

            {/* Tax Status */}
            <FormField
              label={t('sales:partners.taxInfo.status')}
              htmlFor="tax_status"
              className="sm:col-span-2"
            >
              <Select
                id="tax_status"
                {...register('tax_status')}
              >
                <option value="REGISTERED">{t('sales:partners.taxInfo.statusRegistered')}</option>
                <option value="NON_REGISTERED">{t('sales:partners.taxInfo.statusNonRegistered')}</option>
                <option value="EXEMPT">{t('sales:partners.taxInfo.statusExempt')}</option>
              </Select>
            </FormField>

            {/* Exemption Fields (shown only when EXEMPT) */}
            {taxStatus === 'EXEMPT' && (
              <>
                <FormField
                  label={t('sales:partners.taxInfo.exemptionReason')}
                  htmlFor="exemption_reason"
                  className="sm:col-span-2"
                >
                  <Textarea
                    id="exemption_reason"
                    rows={3}
                    {...register('exemption_reason')}
                    placeholder={t('sales:partners.taxInfo.exemptionReasonPlaceholder')}
                  />
                </FormField>

                <FormField
                  label={t('sales:partners.taxInfo.validUntil')}
                  htmlFor="exemption_valid_until"
                >
                  <Input
                    type="date"
                    id="exemption_valid_until"
                    {...register('exemption_valid_until')}
                  />
                </FormField>

                <div>
                  <label className={`block text-sm font-medium ${colorTokens.text.secondary}`}>
                    {t('sales:partners.taxInfo.certificate')}
                  </label>
                  <p className={`mt-1 text-xs ${colorTokens.text.subtle}`}>
                    {t('sales:partners.taxInfo.certificateHint')}
                  </p>
                  <div className="mt-2">
                    <input
                      type="file"
                      accept=".pdf,.jpg,.jpeg,.png"
                      className={`block w-full text-sm ${colorTokens.text.subtle} file:me-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold ${colorTokens.intent.primary.fileBgSubtle} ${colorTokens.intent.primary.fileText} ${colorTokens.intent.primary.fileBgHoverSoft}`}
                    />
                  </div>
                </div>
              </>
            )}
          </div>
        </div>

        {/* B2B Fields Section — shown only when customer_category is 'business' */}
        {customerCategory === 'business' && (
          <B2BFieldsSection
            register={register as never}
            watch={watch as never}
            setValue={setValue}
            partnerId={isEditing ? id : undefined}
          />
        )}

        {/* Address Section */}
        <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-6`}>
          <h3 className={`mb-4 text-lg font-semibold ${colorTokens.text.primary}`}>
            {t('sales:partners.addressInfo')}
          </h3>
          <div className="grid gap-6 sm:grid-cols-2">
            {/* Street Address */}
            <FormField
              label={t('sales:partners.streetAddress')}
              htmlFor="street_address"
              error={errors.street_address?.message}
              className="sm:col-span-2"
            >
              <Input
                id="street_address"
                {...register('street_address')}
                error={!!errors.street_address}
              />
            </FormField>

            {/* City */}
            <FormField
              label={t('sales:partners.city')}
              htmlFor="city"
              error={errors.city?.message}
            >
              <Input
                id="city"
                {...register('city')}
                error={!!errors.city}
              />
            </FormField>

            {/* State */}
            <FormField
              label={usesTunisiaLabels ? t('sales:partners.governorate') : t('sales:partners.state')}
              htmlFor="state"
              error={errors.state?.message}
            >
              <Input
                id="state"
                {...register('state')}
                error={!!errors.state}
              />
            </FormField>

            {/* Postal Code */}
            <FormField
              label={t('sales:partners.postalCode')}
              htmlFor="postal_code"
              error={errors.postal_code?.message}
            >
              <Input
                id="postal_code"
                {...register('postal_code')}
                error={!!errors.postal_code}
              />
            </FormField>

            {/* Country */}
            <FormField
              label={t('sales:partners.country')}
              htmlFor="country"
              error={errors.country?.message}
            >
              <Controller
                name="country"
                control={control}
                render={({ field }) => (
                  <Select
                    id="country"
                    value={field.value}
                    onChange={field.onChange}
                    onBlur={field.onBlur}
                    error={!!errors.country}
                  >
                    <option value="">{t('sales:partners.selectCountry')}</option>
                    {pinnedCountries.length > 0 && (
                      <>
                        {pinnedCountries.map((c) => (
                          <option key={c.code} value={c.code}>
                            {t(`countries:${c.code}`, { defaultValue: c.name })}
                          </option>
                        ))}
                        <option disabled>──────────</option>
                      </>
                    )}
                    {otherCountries.map((c) => (
                      <option key={c.code} value={c.code}>
                        {t(`countries:${c.code}`, { defaultValue: c.name })}
                      </option>
                    ))}
                  </Select>
                )}
              />
            </FormField>

            {/* Notes */}
            <FormField
              label={t('sales:partners.notes')}
              htmlFor="notes"
              className="sm:col-span-2"
            >
              <Textarea
                id="notes"
                rows={4}
                {...register('notes')}
              />
            </FormField>
          </div>
        </div>

        {/* Form Actions */}
        <div className="flex items-center justify-end gap-4">
          <Link
            to={basePath}
            className={cn(tokens.button.base, tokens.button.secondary, tokens.button.sizes.md)}
          >
            {t('common:actions.cancel')}
          </Link>
          <Button
            type="submit"
            disabled={isSaving}
            className="gap-2"
          >
            {isSaving && <Loader2 className="h-4 w-4 animate-spin" />}
            {isSaving ? t('common:status.saving') : t('common:actions.save')}
          </Button>
        </div>
      </form>
    </div>
  )
}
