import { useEffect } from 'react'
import { useForm } from 'react-hook-form'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Loader2 } from 'lucide-react'
import { Modal, ModalHeader, ModalContent, ModalFooter } from '../../../components/organisms/Modal'
import { FormField } from '../../../components/atoms/FormField'
import { Input } from '../../../components/atoms/Input'
import { Select } from '../../../components/atoms/Select'
import { Button } from '../../../components/atoms/Button'
import { Textarea } from '../../../components/atoms/Textarea'
import { apiPost } from '../../../lib/api'
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { cn } from '../../../lib/utils'
import { tokens, textColors } from '../../../lib/designTokens'

interface PaymentMethod {
  id: string
  code: string
  name: string
  description?: string
  is_physical: boolean
  has_maturity: boolean
  requires_third_party: boolean
  is_push: boolean
  has_deducted_fees: boolean
  is_restricted: boolean
  fee_type: 'none' | 'fixed' | 'percentage' | 'mixed'
  fee_fixed?: string
  fee_percent?: string
  is_active: boolean
}

interface PaymentMethodFormData {
  code: string
  name: string
  description: string
  is_physical: boolean
  has_maturity: boolean
  requires_third_party: boolean
  is_push: boolean
  has_deducted_fees: boolean
  is_restricted: boolean
  fee_type: 'none' | 'fixed' | 'percentage' | 'mixed'
  fee_fixed: string
  fee_percent: string
}

export interface AddPaymentMethodModalProps {
  isOpen: boolean
  onClose: () => void
  onSuccess?: (method: PaymentMethod) => void
}

type CapabilityFlag =
  | 'is_physical'
  | 'has_maturity'
  | 'requires_third_party'
  | 'is_push'
  | 'has_deducted_fees'
  | 'is_restricted'

const CAPABILITY_FLAGS: readonly CapabilityFlag[] = [
  'is_physical',
  'has_maturity',
  'requires_third_party',
  'is_push',
  'has_deducted_fees',
  'is_restricted',
]

export function AddPaymentMethodModal({
  isOpen,
  onClose,
  onSuccess,
}: AddPaymentMethodModalProps) {
  const { t } = useTranslation(['treasury', 'common'])
  const queryClient = useQueryClient()

  const {
    register,
    handleSubmit,
    reset,
    watch,
    formState: { errors },
  } = useForm<PaymentMethodFormData>({
    defaultValues: {
      code: '',
      name: '',
      description: '',
      is_physical: false,
      has_maturity: false,
      requires_third_party: false,
      is_push: true,
      has_deducted_fees: false,
      is_restricted: false,
      fee_type: 'none',
      fee_fixed: '0.00',
      fee_percent: '0.00',
    },
  })

  useEffect(() => {
    if (isOpen) {
      reset({
        code: '',
        name: '',
        description: '',
        is_physical: false,
        has_maturity: false,
        requires_third_party: false,
        is_push: true,
        has_deducted_fees: false,
        is_restricted: false,
        fee_type: 'none',
        fee_fixed: '0.00',
        fee_percent: '0.00',
      })
    }
  }, [isOpen, reset])

  const feeType = watch('fee_type')
  const showFixedFee = feeType === 'fixed' || feeType === 'mixed'
  const showPercentFee = feeType === 'percentage' || feeType === 'mixed'

  const mutation = useMutation({
    mutationFn: (data: PaymentMethodFormData) => {
      const payload = {
        code: data.code,
        name: data.name,
        description: data.description || null,
        is_physical: data.is_physical,
        has_maturity: data.has_maturity,
        requires_third_party: data.requires_third_party,
        is_push: data.is_push,
        has_deducted_fees: data.has_deducted_fees,
        is_restricted: data.is_restricted,
        fee_type: data.fee_type,
        fee_fixed: showFixedFee ? data.fee_fixed : '0.00',
        fee_percent: showPercentFee ? data.fee_percent : '0.00',
      }
      return apiPost<{ data: PaymentMethod }>('/payment-methods', payload)
    },
    onSuccess: async (response) => {
      await queryClient.invalidateQueries({ queryKey: tenantScopedKey(['payment-methods']) })
      onSuccess?.(response.data)
      onClose()
    },
  })

  const onSubmit = (data: PaymentMethodFormData) => {
    mutation.mutate(data)
  }

  return (
    <Modal isOpen={isOpen} onClose={onClose} size="lg">
      <ModalHeader title={t('treasury:paymentMethods.new')} onClose={onClose} />

      <form onSubmit={(e) => { void handleSubmit(onSubmit)(e) }}>
        <ModalContent>
          <div className="space-y-6">
            {/* Basic Information */}
            <div className="space-y-4">
              <h3 className={tokens.heading.section}>
                {t('common:fields.basicInfo')}
              </h3>

              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <FormField
                  label={t('treasury:paymentMethods.code')}
                  htmlFor="method-code"
                  required
                  error={errors.code?.message}
                >
                  <Input
                    id="method-code"
                    {...register('code', { required: t('treasury:paymentMethods.form.codeRequired') })}
                    placeholder="CASH"
                  />
                </FormField>

                <FormField
                  label={t('treasury:paymentMethods.name')}
                  htmlFor="method-name"
                  required
                  error={errors.name?.message}
                >
                  <Input
                    id="method-name"
                    {...register('name', { required: t('treasury:paymentMethods.form.nameRequired') })}
                    placeholder={t('treasury:paymentMethods.form.namePlaceholder')}
                  />
                </FormField>
              </div>

              <FormField
                label={t('treasury:paymentMethods.description')}
                htmlFor="method-description"
              >
                <Textarea
                  id="method-description"
                  {...register('description')}
                  placeholder={t('treasury:paymentMethods.form.descriptionPlaceholder')}
                  rows={2}
                />
              </FormField>
            </div>

            {/* Capabilities */}
            <div className="space-y-4">
              <h3 className={tokens.heading.section}>
                {t('treasury:paymentMethods.capabilities')}
              </h3>

              <div className="space-y-3">
                {CAPABILITY_FLAGS.map((flag) => (
                  <label key={flag} className="flex items-start gap-3">
                    <input
                      type="checkbox"
                      {...register(flag)}
                      className={cn('mt-1', tokens.checkbox.base)}
                    />
                    <div className="flex-1">
                      <div className={cn('text-sm font-medium', textColors.secondary)}>
                        {t(`treasury:paymentMethods.flags.${flag}`)}
                      </div>
                      <div className={cn('text-xs', textColors.tertiary)}>
                        {t(`treasury:paymentMethods.flagDescriptions.${flag}`)}
                      </div>
                    </div>
                  </label>
                ))}
              </div>
            </div>

            {/* Fee Configuration */}
            <div className="space-y-4">
              <h3 className={tokens.heading.section}>
                {t('treasury:paymentMethods.feeConfig')}
              </h3>

              <FormField
                label={t('treasury:paymentMethods.type')}
                htmlFor="method-fee-type"
              >
                <Select id="method-fee-type" {...register('fee_type')}>
                  <option value="none">{t('treasury:paymentMethods.feeTypes.none')}</option>
                  <option value="fixed">{t('treasury:paymentMethods.feeTypes.fixed')}</option>
                  <option value="percentage">{t('treasury:paymentMethods.feeTypes.percentage')}</option>
                  <option value="mixed">{t('treasury:paymentMethods.feeTypes.mixed')}</option>
                </Select>
              </FormField>

              {showFixedFee && (
                <FormField
                  label={t('treasury:paymentMethods.form.feeAmount')}
                  htmlFor="method-fee-fixed"
                >
                  <Input
                    id="method-fee-fixed"
                    type="number"
                    step="0.01"
                    min="0"
                    {...register('fee_fixed')}
                    placeholder={t('treasury:paymentMethods.form.feeAmountPlaceholder')}
                  />
                </FormField>
              )}

              {showPercentFee && (
                <FormField
                  label={t('treasury:paymentMethods.form.feePercentage')}
                  htmlFor="method-fee-percent"
                >
                  <Input
                    id="method-fee-percent"
                    type="number"
                    step="0.01"
                    min="0"
                    max="100"
                    {...register('fee_percent')}
                    placeholder={t('treasury:paymentMethods.form.feePercentagePlaceholder')}
                  />
                </FormField>
              )}
            </div>
          </div>

          {mutation.isError && (
            <div className={cn('mt-4', tokens.alert.base, tokens.alert.error)}>
              {mutation.error instanceof Error
                ? mutation.error.message
                : t('common:errors.generic')}
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
            {t('common:actions.save')}
          </Button>
        </ModalFooter>
      </form>
    </Modal>
  )
}
