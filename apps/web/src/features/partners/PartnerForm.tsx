import { useEffect } from 'react'
import { Link, useNavigate, useParams, useLocation } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { ArrowLeft } from 'lucide-react'
import { api, apiPost, apiPatch } from '../../lib/api'
import { B2BFieldsSection } from './components/B2BFieldsSection'
import type { PartnerType } from './PartnerListPage'

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
  address: string | null
  city: string | null
  postal_code: string | null
  country: string | null
  tax_id: string | null
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
  address: string
  city: string
  postal_code: string
  country: string
  tax_id: string
  tax_status: 'REGISTERED' | 'NON_REGISTERED' | 'EXEMPT'
  exemption_reason: string
  exemption_valid_until: string
  notes: string
}

interface PartnerFormProps {
  partnerType?: PartnerType
}

export function PartnerForm({ partnerType }: PartnerFormProps) {
  const { t } = useTranslation()
  const { id = '' } = useParams<{ id: string }>()
  const location = useLocation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const isEditing = id.length > 0

  // Determine partner type from props or URL
  const isCustomerContext = partnerType === 'customer' || location.pathname.includes('/sales/customers')
  const isSupplierContext = partnerType === 'supplier' || location.pathname.includes('/purchases/suppliers')

  const basePath = isCustomerContext
    ? '/sales/customers'
    : isSupplierContext
      ? '/purchases/suppliers'
      : '/partners'

  const entityName = isCustomerContext
    ? t('navigation.customers').slice(0, -1)
    : isSupplierContext
      ? t('navigation.suppliers').slice(0, -1)
      : 'Partner'

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
    formState: { errors, isSubmitting },
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
      address: '',
      city: '',
      postal_code: '',
      country: '',
      tax_id: '',
      tax_status: 'REGISTERED',
      exemption_reason: '',
      exemption_valid_until: '',
      notes: '',
    },
  })

  const taxStatus = watch('tax_status')
  const customerCategory = watch('customer_category')

  // Fetch partner data when editing
  const { data: partner, isLoading } = useQuery({
    queryKey: ['partner', id],
    queryFn: async () => {
      const response = await api.get<{ data: Partner }>(`/partners/${id}`)
      return response.data.data
    },
    enabled: isEditing,
  })

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
        address: partner.address ?? '',
        city: partner.city ?? '',
        postal_code: partner.postal_code ?? '',
        country: partner.country ?? '',
        tax_id: partner.tax_id ?? '',
        tax_status: partner.tax_status || 'REGISTERED',
        exemption_reason: partner.exemption_reason ?? '',
        exemption_valid_until: partner.exemption_valid_until ?? '',
        notes: partner.notes ?? '',
      })
    }
  }, [partner, reset])

  const createMutation = useMutation({
    mutationFn: (data: PartnerFormData) => apiPost<Partner>('/partners', data),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['partners'] })
      void navigate(basePath)
    },
  })

  const updateMutation = useMutation({
    mutationFn: (data: PartnerFormData) =>
      apiPatch<Partner>(`/partners/${id}`, data),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['partners'] })
      void queryClient.invalidateQueries({ queryKey: ['partner', id] })
      void navigate(`${basePath}/${id}`)
    },
  })

  const onSubmit = (data: PartnerFormData) => {
    if (isEditing) {
      updateMutation.mutate(data)
    } else {
      createMutation.mutate(data)
    }
  }

  if (isEditing && isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className="text-gray-500">Loading...</div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center gap-4">
        <Link
          to={basePath}
          className="inline-flex items-center gap-2 text-sm text-gray-600 hover:text-gray-900"
        >
          <ArrowLeft className="h-4 w-4" />
          {t('actions.back')}
        </Link>
        <h1 className="text-2xl font-bold text-gray-900">
          {isEditing ? `${t('actions.edit')} ${entityName}` : `${t('actions.add')} ${entityName}`}
        </h1>
      </div>

      {/* Form */}
      <form onSubmit={(e) => { void handleSubmit(onSubmit)(e) }} className="space-y-6">
        <div className="rounded-lg border border-gray-200 bg-white p-6">
          <div className="grid gap-6 sm:grid-cols-2">
            {/* Name */}
            <div className="sm:col-span-2">
              <label
                htmlFor="name"
                className="block text-sm font-medium text-gray-700"
              >
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

            {/* Type */}
            <div>
              <label
                htmlFor="type"
                className="block text-sm font-medium text-gray-700"
              >
                Type *
              </label>
              <select
                id="type"
                {...register('type', { required: 'Type is required' })}
                className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              >
                <option value="">Select type</option>
                <option value="customer">Customer</option>
                <option value="supplier">Supplier</option>
                <option value="both">Both</option>
              </select>
              {errors.type && (
                <p className="mt-1 text-sm text-red-600">{errors.type.message}</p>
              )}
            </div>

            {/* Customer Category */}
            <div>
              <label
                htmlFor="customer_category"
                className="block text-sm font-medium text-gray-700"
              >
                {t('sales:partners.b2b.customerCategory')}
              </label>
              <select
                id="customer_category"
                {...register('customer_category')}
                className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              >
                <option value="">{t('sales:partners.b2b.selectCategory')}</option>
                <option value="individual">{t('sales:partners.b2b.individual')}</option>
                <option value="business">{t('sales:partners.b2b.business')}</option>
              </select>
            </div>

            {/* Email */}
            <div>
              <label
                htmlFor="email"
                className="block text-sm font-medium text-gray-700"
              >
                Email
              </label>
              <input
                type="email"
                id="email"
                {...register('email', {
                  pattern: {
                    value: /^[^\s@]+@[^\s@]+\.[^\s@]+$/,
                    message: 'Invalid email address',
                  },
                })}
                className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              />
              {errors.email && (
                <p className="mt-1 text-sm text-red-600">{errors.email.message}</p>
              )}
            </div>

            {/* Phone */}
            <div>
              <label
                htmlFor="phone"
                className="block text-sm font-medium text-gray-700"
              >
                Phone
              </label>
              <input
                type="tel"
                id="phone"
                {...register('phone')}
                className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              />
            </div>

            {/* Tax ID */}
            <div>
              <label
                htmlFor="tax_id"
                className="block text-sm font-medium text-gray-700"
              >
                {t('sales:partners.taxId')}
              </label>
              <input
                type="text"
                id="tax_id"
                {...register('tax_id')}
                className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              />
            </div>

            {/* Tax Status */}
            <div className="sm:col-span-2">
              <label
                htmlFor="tax_status"
                className="block text-sm font-medium text-gray-700"
              >
                {t('sales:partners.taxInfo.status')}
              </label>
              <select
                id="tax_status"
                {...register('tax_status')}
                className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              >
                <option value="REGISTERED">{t('sales:partners.taxInfo.statusRegistered')}</option>
                <option value="NON_REGISTERED">{t('sales:partners.taxInfo.statusNonRegistered')}</option>
                <option value="EXEMPT">{t('sales:partners.taxInfo.statusExempt')}</option>
              </select>
            </div>

            {/* Exemption Fields (shown only when EXEMPT) */}
            {taxStatus === 'EXEMPT' && (
              <>
                <div className="sm:col-span-2">
                  <label
                    htmlFor="exemption_reason"
                    className="block text-sm font-medium text-gray-700"
                  >
                    {t('sales:partners.taxInfo.exemptionReason')}
                  </label>
                  <textarea
                    id="exemption_reason"
                    rows={3}
                    {...register('exemption_reason')}
                    placeholder={t('sales:partners.taxInfo.exemptionReasonPlaceholder')}
                    className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                  />
                </div>

                <div>
                  <label
                    htmlFor="exemption_valid_until"
                    className="block text-sm font-medium text-gray-700"
                  >
                    {t('sales:partners.taxInfo.validUntil')}
                  </label>
                  <input
                    type="date"
                    id="exemption_valid_until"
                    {...register('exemption_valid_until')}
                    className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                  />
                </div>

                <div>
                  <label className="block text-sm font-medium text-gray-700">
                    {t('sales:partners.taxInfo.certificate')}
                  </label>
                  <p className="mt-1 text-xs text-gray-500">
                    {t('sales:partners.taxInfo.certificateHint')}
                  </p>
                  <div className="mt-2">
                    <input
                      type="file"
                      accept=".pdf,.jpg,.jpeg,.png"
                      className="block w-full text-sm text-gray-500 file:me-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100"
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
            partnerId={isEditing ? id : undefined}
          />
        )}

        <div className="rounded-lg border border-gray-200 bg-white p-6">
          <div className="grid gap-6 sm:grid-cols-2">
            {/* Address */}
            <div className="sm:col-span-2">
              <label
                htmlFor="address"
                className="block text-sm font-medium text-gray-700"
              >
                Address
              </label>
              <input
                type="text"
                id="address"
                {...register('address')}
                className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              />
            </div>

            {/* City */}
            <div>
              <label
                htmlFor="city"
                className="block text-sm font-medium text-gray-700"
              >
                City
              </label>
              <input
                type="text"
                id="city"
                {...register('city')}
                className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              />
            </div>

            {/* Postal Code */}
            <div>
              <label
                htmlFor="postal_code"
                className="block text-sm font-medium text-gray-700"
              >
                Postal Code
              </label>
              <input
                type="text"
                id="postal_code"
                {...register('postal_code')}
                className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              />
            </div>

            {/* Country */}
            <div>
              <label
                htmlFor="country"
                className="block text-sm font-medium text-gray-700"
              >
                Country
              </label>
              <input
                type="text"
                id="country"
                {...register('country')}
                className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              />
            </div>

            {/* Notes */}
            <div className="sm:col-span-2">
              <label
                htmlFor="notes"
                className="block text-sm font-medium text-gray-700"
              >
                Notes
              </label>
              <textarea
                id="notes"
                rows={4}
                {...register('notes')}
                className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              />
            </div>
          </div>
        </div>

        {/* Form Actions */}
        <div className="flex items-center justify-end gap-4">
          <Link
            to={basePath}
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
