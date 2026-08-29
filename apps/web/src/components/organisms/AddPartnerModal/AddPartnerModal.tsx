import { useEffect, useRef } from 'react'
import { useLocation } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { useMutation, useQuery, useQueryClient, type Query } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Loader2 } from 'lucide-react'
import { Modal, ModalHeader, ModalContent, ModalFooter } from '../Modal'
import { FormField } from '../../atoms/FormField'
import { Input } from '../../atoms/Input'
import { Select } from '../../atoms/Select'
import { Textarea } from '../../atoms/Textarea'
import { Button } from '../../atoms/Button'
import { apiPost } from '../../../lib/api'
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'
import { getCountries } from '../../../features/settings/api/country'
import type { PartnerPrefill } from '../../../features/partners/partnerPrefill'
import type { PartnerData } from '../../../features/partners/types'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

type PartnerType = 'customer' | 'supplier' | 'both'

interface PartnerFormData {
  name: string
  type: PartnerType | ''
  email: string
  phone: string
  street_address: string
  city: string
  postal_code: string
  country_code: string
  vat_number: string
  notes: string
}

function partnerCacheInvalidationPredicate(
  createdPartnerId: string,
  tenantId: string | null,
  companyId: string | null,
) {
  return (query: Query): boolean => {
    if (tenantId === null || companyId === null) {
      return false
    }
    const key = query.queryKey
    if (key.length < 3) {
      return false
    }
    if (key[key.length - 2] !== tenantId || key[key.length - 1] !== companyId) {
      return false
    }
    if (key[0] === 'partners' || key[0] === 'partners-search') {
      return true
    }
    if (key[0] === 'partner' && key[1] === createdPartnerId) {
      return true
    }
    return key[0] === 'pickers' && key[1] === 'partner'
  }
}

export interface AddPartnerModalProps {
  /**
   * Controls modal visibility
   */
  isOpen: boolean

  /**
   * Callback when modal should close
   */
  onClose: () => void

  /**
   * Context hint: 'customer' when creating from sales, 'supplier' when creating from purchases
   * If not provided, will be detected from URL path
   */
  partnerType?: 'customer' | 'supplier' | undefined

  /**
   * Callback after successful partner creation
   * Receives the newly created partner
   */
  onSuccess?: (partner: PartnerData) => void

  /**
   * Optional seed values applied on open. Keys use the shared PartnerPrefill
   * contract and ignores `state` (no such field here).
   */
  prefill?: PartnerPrefill
}

/**
 * AddPartnerModal - Context-aware partner creation modal
 *
 * Creates customers or suppliers with context-aware defaults.
 * When called from sales contexts, defaults to "customer".
 * When called from purchasing contexts, defaults to "supplier".
 *
 * @example
 * ```tsx
 * // In sales invoice form
 * <AddPartnerModal
 *   partnerType="customer"
 *   isOpen={isOpen}
 *   onClose={() => setIsOpen(false)}
 *   onSuccess={(partner) => setValue('partner_id', partner.id)}
 * />
 *
 * // In purchase order form
 * <AddPartnerModal
 *   partnerType="supplier"
 *   isOpen={isOpen}
 *   onClose={() => setIsOpen(false)}
 *   onSuccess={(partner) => setValue('partner_id', partner.id)}
 * />
 * ```
 */
export function AddPartnerModal({
  isOpen,
  onClose,
  partnerType,
  onSuccess,
  prefill,
}: AddPartnerModalProps) {
  const { t } = useTranslation(['sales', 'common'])
  const location = useLocation()
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const hasTenantScope = tenantId !== null && companyId !== null

  const { data: countries = [] } = useQuery({
    queryKey: tenantScopedKey(['countries', 'active']),
    queryFn: () => getCountries({ is_active: true }),
    enabled: hasTenantScope,
    staleTime: 10 * 60 * 1000,
  })

  // Context detection (follows PartnerForm pattern)
  const isCustomerContext =
    partnerType === 'customer' || location.pathname.includes('/sales')
  const isSupplierContext =
    partnerType === 'supplier' || location.pathname.includes('/purchases')

  // Pre-fill type based on context
  const defaultType: PartnerType | '' = isCustomerContext
    ? 'customer'
    : isSupplierContext
      ? 'supplier'
      : ''

  // Context-aware labels
  const entityLabel = isCustomerContext
    ? t('sales:partners.types.customer')
    : isSupplierContext
      ? t('sales:partners.types.supplier')
      : t('sales:partners.title')

  const modalTitle = `${t('common:actions.add')} ${entityLabel}`

  // Form state with React Hook Form
  const {
    register,
    handleSubmit,
    reset,
    watch,
    formState: { errors },
  } = useForm<PartnerFormData>({
    defaultValues: {
      name: prefill?.name ?? '',
      type: defaultType,
      email: prefill?.email ?? '',
      phone: prefill?.phone ?? '',
      street_address: prefill?.street_address ?? '',
      city: prefill?.city ?? '',
      postal_code: prefill?.postal_code ?? '',
      country_code: prefill?.country_code ?? '',
      vat_number: prefill?.vat_number ?? '',
      notes: '',
    },
  })

  const countryCode = watch('country_code')

  // Reset form only on the closed→open transition (also re-seeds from
  // prefill on every open). `prefill` is intentionally NOT a trigger here:
  // callers may pass a referentially-new but value-identical prefill object
  // on unrelated parent re-renders, and re-running reset() while the modal
  // is open would silently wipe whatever the user has typed so far.
  const wasOpenRef = useRef(false)
  useEffect(() => {
    if (isOpen && !wasOpenRef.current) {
      reset({
        name: prefill?.name ?? '',
        type: defaultType,
        email: prefill?.email ?? '',
        phone: prefill?.phone ?? '',
        street_address: prefill?.street_address ?? '',
        city: prefill?.city ?? '',
        postal_code: prefill?.postal_code ?? '',
        country_code: prefill?.country_code ?? '',
        vat_number: prefill?.vat_number ?? '',
        notes: '',
      })
    }
    wasOpenRef.current = isOpen
  }, [isOpen, prefill, defaultType, reset])

  // React Query mutation
  const mutation = useMutation({
    mutationFn: (data: PartnerFormData) => apiPost<PartnerData>('/partners', data),
    onSuccess: async (partner) => {
      await queryClient.invalidateQueries({
        predicate: partnerCacheInvalidationPredicate(partner.id, tenantId, companyId),
      })
      onSuccess?.(partner)
      onClose()
    },
  })

  const onSubmit = (data: PartnerFormData) => {
    mutation.mutate(data)
  }

  return (
    <Modal isOpen={isOpen} onClose={onClose}>
      <ModalHeader title={modalTitle} onClose={onClose} />

      <form onSubmit={(e) => { void handleSubmit(onSubmit)(e) }}>
        <ModalContent>
          {/* Name */}
          <FormField
            label={t('sales:partners.name')}
            htmlFor="partner-name"
            required
            error={errors.name?.message}
          >
            <Input
              id="partner-name"
              {...register('name', { required: t('common:validation.required') })}
              placeholder={t('sales:partners.name')}
            />
          </FormField>

          {/* Type - hidden when context is clear (customer from sales, supplier from purchases) */}
          {!isCustomerContext && !isSupplierContext && (
            <FormField
              label={t('sales:partners.type')}
              htmlFor="partner-type"
              required
              error={errors.type?.message}
            >
              <Select
                id="partner-type"
                {...register('type', { required: t('common:validation.required') })}
                error={!!errors.type}
              >
                <option value="">{t('common:actions.select')}</option>
                <option value="customer">{t('sales:partners.types.customer')}</option>
                <option value="supplier">{t('sales:partners.types.supplier')}</option>
                <option value="both">{t('sales:partners.types.both')}</option>
              </Select>
            </FormField>
          )}
          {/* Hidden input for type when context is known */}
          {(isCustomerContext || isSupplierContext) && (
            <input type="hidden" {...register('type')} />
          )}

          {/* Email and Phone */}
          <div className="grid grid-cols-2 gap-4">
            <FormField
              label={t('sales:partners.email')}
              htmlFor="partner-email"
              error={errors.email?.message}
            >
              <Input
                id="partner-email"
                type="email"
                {...register('email', {
                  pattern: {
                    value: /^[^\s@]+@[^\s@]+\.[^\s@]+$/,
                    message: t('common:validation.invalidEmail'),
                  },
                })}
                placeholder={t('sales:partners.email')}
              />
            </FormField>

            <FormField
              label={t('sales:partners.phone')}
              htmlFor="partner-phone"
            >
              <Input
                id="partner-phone"
                type="tel"
                {...register('phone')}
                placeholder={t('sales:partners.phone')}
              />
            </FormField>
          </div>

          {/* Tax ID */}
          <FormField
            label={t('sales:partners.taxId')}
            htmlFor="partner-tax-id"
          >
            <Input
              id="partner-tax-id"
              {...register('vat_number')}
              placeholder={t('sales:partners.taxId')}
            />
          </FormField>

          {/* Address */}
          <FormField
            label={t('sales:partners.address')}
            htmlFor="partner-address"
          >
            <Input
              id="partner-address"
              {...register('street_address')}
              placeholder={t('sales:partners.address')}
            />
          </FormField>

          {/* City and Postal Code */}
          <div className="grid grid-cols-2 gap-4">
            <FormField
              label={t('sales:partners.city')}
              htmlFor="partner-city"
            >
              <Input
                id="partner-city"
                {...register('city')}
                placeholder={t('sales:partners.city')}
              />
            </FormField>

            <FormField
              label={t('sales:partners.postalCode')}
              htmlFor="partner-postal-code"
            >
              <Input
                id="partner-postal-code"
                {...register('postal_code')}
                placeholder={t('sales:partners.postalCode')}
              />
            </FormField>
          </div>

          {/* Tax country — this control writes `country_code`, NOT the address
              `country` column. Labelled accordingly (merge-gate r1 FE-2). */}
          <FormField
            label={t('sales:partners.countryCode')}
            htmlFor="partner-country"
          >
            <Select
              id="partner-country"
              {...register('country_code')}
              // NOT redundant with `register`: the <option> list arrives from an
              // async countries query, so a value seeded by `reset()` before the
              // options exist is dropped by the DOM. This prop re-applies it on
              // the render that first has a matching option (pinned by the
              // prefill tests below).
              value={countryCode}
            >
              <option value="">{t('sales:partners.selectCountryCode')}</option>
              {countries.map((country) => (
                <option key={country.code} value={country.code}>
                  {t(`countries:${country.code}`, { defaultValue: country.name })} ({country.code})
                </option>
              ))}
            </Select>
          </FormField>

          {/* Notes */}
          <FormField
            label={t('sales:partners.notes')}
            htmlFor="partner-notes"
          >
            <Textarea
              id="partner-notes"
              rows={3}
              {...register('notes')}
              placeholder={t('sales:partners.notes')}
            />
          </FormField>

          {/* Error message */}
          {mutation.isError && (
            <div className={`rounded-lg ${colorTokens.intent.danger.bgSubtle} p-3 text-sm ${colorTokens.intent.danger.textStrong}`}>
              {mutation.error instanceof Error
                ? mutation.error.message
                : t('common:errorMessages.generic')}
            </div>
          )}
        </ModalContent>

        <ModalFooter>
          <Button
            type="button"
            variant="secondary"
            onClick={onClose}
            disabled={mutation.isPending}
          >
            {t('common:actions.cancel')}
          </Button>
          <Button
            type="submit"
            variant="primary"
            disabled={mutation.isPending}
          >
            {mutation.isPending && <Loader2 className="h-4 w-4 animate-spin" />}
            {t('common:actions.create')}
          </Button>
        </ModalFooter>
      </form>
    </Modal>
  )
}
