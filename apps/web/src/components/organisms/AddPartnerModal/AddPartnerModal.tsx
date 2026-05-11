import { useEffect } from 'react'
import { useLocation } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { useMutation, useQueryClient } from '@tanstack/react-query'
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

type PartnerType = 'customer' | 'supplier' | 'both'

interface Partner {
  id: string
  name: string
  type: PartnerType
  email: string | null
  phone: string | null
  address: string | null
  city: string | null
  postal_code: string | null
  country: string | null
  tax_id: string | null
  notes: string | null
}

interface PartnerFormData {
  name: string
  type: PartnerType | ''
  email: string
  phone: string
  address: string
  city: string
  postal_code: string
  country: string
  tax_id: string
  notes: string
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
  onSuccess?: (partner: Partner) => void
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
}: AddPartnerModalProps) {
  const { t } = useTranslation(['sales', 'common'])
  const location = useLocation()
  const queryClient = useQueryClient()

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
    formState: { errors },
  } = useForm<PartnerFormData>({
    defaultValues: {
      name: '',
      type: defaultType,
      email: '',
      phone: '',
      address: '',
      city: '',
      postal_code: '',
      country: '',
      tax_id: '',
      notes: '',
    },
  })

  // Reset form when modal opens
  useEffect(() => {
    if (isOpen) {
      reset({
        name: '',
        type: defaultType,
        email: '',
        phone: '',
        address: '',
        city: '',
        postal_code: '',
        country: '',
        tax_id: '',
        notes: '',
      })
    }
  }, [isOpen, defaultType, reset])

  // React Query mutation
  const mutation = useMutation({
    mutationFn: (data: PartnerFormData) => apiPost<Partner>('/partners', data),
    onSuccess: async (partner) => {
      await queryClient.invalidateQueries({ queryKey: tenantScopedKey(['partners']) })
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
              {...register('tax_id')}
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
              {...register('address')}
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

          {/* Country */}
          <FormField
            label={t('sales:partners.country')}
            htmlFor="partner-country"
          >
            <Input
              id="partner-country"
              {...register('country')}
              placeholder={t('sales:partners.country')}
            />
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
            <div className="rounded-lg bg-red-50 p-3 text-sm text-red-700">
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
