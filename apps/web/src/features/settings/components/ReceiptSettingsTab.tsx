import { useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Loader2, Info } from 'lucide-react'
import { toast } from 'sonner'
import { api, getErrorMessage } from '../../../lib/api'
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { tokens, textColors } from '../../../lib/designTokens'
import { cn } from '../../../lib/utils'
import { Button } from '../../../components/atoms/Button/Button'
import { Checkbox } from '../../../components/atoms'
import { Input } from '../../../components/atoms/Input'
import { Textarea } from '../../../components/atoms/Textarea'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'

/**
 * Countries that require VAT breakdown on receipts
 */
const VAT_BREAKDOWN_REQUIRED_COUNTRIES = ['FR', 'TN', 'IT', 'MA', 'DZ']

/**
 * Countries that require fiscal information on receipts (NF525)
 */
const FISCAL_INFO_REQUIRED_COUNTRIES = ['FR']

/**
 * Countries that require payment details on receipts
 */
const PAYMENT_DETAILS_REQUIRED_COUNTRIES = ['FR', 'TN', 'IT']

/**
 * Schema for receipt settings form validation
 */
const receiptSettingsSchema = z.object({
  receipt_header: z
    .string()
    .max(500)
    .nullable()
    .transform((val) => (val === '' ? null : val)),
  receipt_footer: z
    .string()
    .max(500)
    .nullable()
    .transform((val) => (val === '' ? null : val)),
  receipt_thank_you: z
    .string()
    .max(200)
    .nullable()
    .transform((val) => (val === '' ? null : val)),
  receipt_show_vat_breakdown: z.boolean(),
  receipt_show_fiscal_info: z.boolean(),
  receipt_show_payment_details: z.boolean(),
  receipt_show_customer: z.boolean(),
  auto_print_receipts: z.boolean(),
})

type ReceiptSettingsFormData = z.infer<typeof receiptSettingsSchema>

interface ReceiptSettingsResponse {
  data: {
    receipt_header: string | null
    receipt_footer: string | null
    receipt_thank_you: string | null
    receipt_show_vat_breakdown: boolean
    receipt_show_fiscal_info: boolean
    receipt_show_payment_details: boolean
    receipt_show_customer: boolean
    auto_print_receipts: boolean
    receipt_logo: string | null
  }
}

interface LegalOverrideInfo {
  isForced: boolean
  tooltipKey: string
}

/**
 * Determines if a toggle should be forced on based on the company country code.
 */
function getVatBreakdownOverride(countryCode: string | null): LegalOverrideInfo {
  if (countryCode && VAT_BREAKDOWN_REQUIRED_COUNTRIES.includes(countryCode.toUpperCase())) {
    return { isForced: true, tooltipKey: 'settings:receipt.legal.requiredByLaw' }
  }
  return { isForced: false, tooltipKey: '' }
}

function getFiscalInfoOverride(countryCode: string | null): LegalOverrideInfo {
  if (countryCode && FISCAL_INFO_REQUIRED_COUNTRIES.includes(countryCode.toUpperCase())) {
    return { isForced: true, tooltipKey: 'settings:receipt.legal.requiredByNF525' }
  }
  return { isForced: false, tooltipKey: '' }
}

function getPaymentDetailsOverride(countryCode: string | null): LegalOverrideInfo {
  if (countryCode && PAYMENT_DETAILS_REQUIRED_COUNTRIES.includes(countryCode.toUpperCase())) {
    return { isForced: true, tooltipKey: 'settings:receipt.legal.requiredByLaw' }
  }
  return { isForced: false, tooltipKey: '' }
}

export function ReceiptSettingsTab() {
  const { t } = useTranslation(['settings', 'common'])
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const getCurrentCompany = useCompanyStore((state) => state.getCurrentCompany)
  const currentCompany = getCurrentCompany()
  const countryCode = currentCompany?.countryCode ?? null

  const vatOverride = getVatBreakdownOverride(countryCode)
  const fiscalOverride = getFiscalInfoOverride(countryCode)
  const paymentOverride = getPaymentDetailsOverride(countryCode)

  // Fetch receipt settings
  const { data, isLoading, error } = useQuery({
    queryKey: tenantScopedKey(['receipt-settings']),
    queryFn: async () => {
      const response = await api.get<ReceiptSettingsResponse>(
        `/companies/${currentCompany?.id ?? ''}/pos-settings`
      )
      return response.data.data
    },
    enabled: tenantId !== null && companyId !== null && !!currentCompany?.id,
  })

  const {
    register,
    handleSubmit,
    reset,
    setValue,
    watch,
    formState: { errors, isDirty },
  } = useForm<ReceiptSettingsFormData>({
    resolver: zodResolver(receiptSettingsSchema),
    defaultValues: {
      receipt_header: null,
      receipt_footer: null,
      receipt_thank_you: null,
      receipt_show_vat_breakdown: true,
      receipt_show_fiscal_info: true,
      receipt_show_payment_details: true,
      receipt_show_customer: true,
      auto_print_receipts: false,
    },
  })

  // Reset form when data loads
  useEffect(() => {
    if (data) {
      reset({
        receipt_header: data.receipt_header,
        receipt_footer: data.receipt_footer,
        receipt_thank_you: data.receipt_thank_you,
        receipt_show_vat_breakdown: vatOverride.isForced ? true : data.receipt_show_vat_breakdown,
        receipt_show_fiscal_info: fiscalOverride.isForced ? true : data.receipt_show_fiscal_info,
        receipt_show_payment_details: paymentOverride.isForced ? true : data.receipt_show_payment_details,
        receipt_show_customer: data.receipt_show_customer,
        auto_print_receipts: data.auto_print_receipts,
      })
    }
  }, [data, reset, vatOverride.isForced, fiscalOverride.isForced, paymentOverride.isForced])

  // Force values when overrides apply
  useEffect(() => {
    if (vatOverride.isForced) {
      setValue('receipt_show_vat_breakdown', true)
    }
    if (fiscalOverride.isForced) {
      setValue('receipt_show_fiscal_info', true)
    }
    if (paymentOverride.isForced) {
      setValue('receipt_show_payment_details', true)
    }
  }, [vatOverride.isForced, fiscalOverride.isForced, paymentOverride.isForced, setValue])

  // Save mutation
  const saveMutation = useMutation({
    mutationFn: async (formData: ReceiptSettingsFormData): Promise<void> => {
      await api.put(`/companies/${currentCompany?.id ?? ''}/receipt-settings`, formData)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['receipt-settings'] })
      toast.success(t('settings:receipt.messages.saved'))
    },
    onError: (err: unknown) => {
      toast.error(getErrorMessage(err))
    },
  })

  const onSubmit = (formData: ReceiptSettingsFormData) => {
    saveMutation.mutate(formData)
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <Loader2 className={`h-8 w-8 animate-spin ${textColors.brand}`} />
      </div>
    )
  }

  if (error) {
    return (
      <div className={tokens.alert.error}>
        {t('settings:receipt.messages.loadError')}
      </div>
    )
  }

  const headerValue = watch('receipt_header')
  const footerValue = watch('receipt_footer')
  const thankYouValue = watch('receipt_thank_you')

  return (
    <form onSubmit={(e) => { void handleSubmit(onSubmit)(e) }} className="space-y-6">
      {/* Custom Text Fields */}
      <div className={tokens.card.base}>
        <h2 className={cn(tokens.heading.section, 'mb-4')}>
          {t('settings:receipt.sections.customText')}
        </h2>
        <div className="space-y-4">
          {/* Receipt Header */}
          <div>
            <label htmlFor="receipt_header" className={tokens.label.base}>
              {t('settings:receipt.fields.header')}
            </label>
            <Textarea
              id="receipt_header"
              rows={3}
              maxLength={500}
              {...register('receipt_header')}
              placeholder={t('settings:receipt.placeholders.header')}
            />
            <div className="mt-1 flex justify-between">
              {errors.receipt_header ? (
                <p className={tokens.helperText.error}>{errors.receipt_header.message}</p>
              ) : (
                <p className={tokens.helperText.base}>{t('settings:receipt.help.header')}</p>
              )}
              <span className={tokens.helperText.base}>
                {(headerValue ?? '').length}/500
              </span>
            </div>
          </div>

          {/* Receipt Footer */}
          <div>
            <label htmlFor="receipt_footer" className={tokens.label.base}>
              {t('settings:receipt.fields.footer')}
            </label>
            <Textarea
              id="receipt_footer"
              rows={3}
              maxLength={500}
              {...register('receipt_footer')}
              placeholder={t('settings:receipt.placeholders.footer')}
            />
            <div className="mt-1 flex justify-between">
              {errors.receipt_footer ? (
                <p className={tokens.helperText.error}>{errors.receipt_footer.message}</p>
              ) : (
                <p className={tokens.helperText.base}>{t('settings:receipt.help.footer')}</p>
              )}
              <span className={tokens.helperText.base}>
                {(footerValue ?? '').length}/500
              </span>
            </div>
          </div>

          {/* Thank You Message */}
          <div>
            <label htmlFor="receipt_thank_you" className={tokens.label.base}>
              {t('settings:receipt.fields.thankYou')}
            </label>
            <Input
              type="text"
              id="receipt_thank_you"
              maxLength={200}
              {...register('receipt_thank_you')}
              placeholder={t('settings:receipt.placeholders.thankYou')}
            />
            <div className="mt-1 flex justify-between">
              {errors.receipt_thank_you ? (
                <p className={tokens.helperText.error}>{errors.receipt_thank_you.message}</p>
              ) : (
                <p className={tokens.helperText.base}>{t('settings:receipt.help.thankYou')}</p>
              )}
              <span className={tokens.helperText.base}>
                {(thankYouValue ?? '').length}/200
              </span>
            </div>
          </div>
        </div>
      </div>

      {/* Section Visibility Toggles */}
      <div className={tokens.card.base}>
        <h2 className={cn(tokens.heading.section, 'mb-4')}>
          {t('settings:receipt.sections.visibility')}
        </h2>
        <p className={`text-sm ${textColors.disabled} mb-4`}>
          {t('settings:receipt.sections.visibilityDescription')}
        </p>
        <div className="space-y-4">
          {/* VAT Breakdown */}
          <ToggleField
            id="receipt_show_vat_breakdown"
            label={t('settings:receipt.fields.showVatBreakdown')}
            description={t('settings:receipt.descriptions.showVatBreakdown')}
            override={vatOverride}
            register={register}
            t={t}
          />

          {/* Fiscal Info */}
          <ToggleField
            id="receipt_show_fiscal_info"
            label={t('settings:receipt.fields.showFiscalInfo')}
            description={t('settings:receipt.descriptions.showFiscalInfo')}
            override={fiscalOverride}
            register={register}
            t={t}
          />

          {/* Payment Details */}
          <ToggleField
            id="receipt_show_payment_details"
            label={t('settings:receipt.fields.showPaymentDetails')}
            description={t('settings:receipt.descriptions.showPaymentDetails')}
            override={paymentOverride}
            register={register}
            t={t}
          />

          {/* Customer Name */}
          <ToggleField
            id="receipt_show_customer"
            label={t('settings:receipt.fields.showCustomer')}
            description={t('settings:receipt.descriptions.showCustomer')}
            override={{ isForced: false, tooltipKey: '' }}
            register={register}
            t={t}
          />
        </div>
      </div>

      {/* Printing Options */}
      <div className={tokens.card.base}>
        <h2 className={cn(tokens.heading.section, 'mb-4')}>
          {t('settings:receipt.sections.printing')}
        </h2>
        <div className="space-y-4">
          <ToggleField
            id="auto_print_receipts"
            label={t('settings:receipt.fields.autoPrint')}
            description={t('settings:receipt.descriptions.autoPrint')}
            override={{ isForced: false, tooltipKey: '' }}
            register={register}
            t={t}
          />
        </div>
      </div>

      {/* Save Button */}
      <div className="flex justify-end gap-3">
        {isDirty && (
          <span className={cn('text-sm self-center', textColors.warningDark)}>
            {t('settings:company.messages.unsavedChanges')}
          </span>
        )}
        <Button
          type="submit"
          disabled={saveMutation.isPending || !isDirty}
          size="md"
          className="gap-2"
        >
          {saveMutation.isPending ? (
            <>
              <Loader2 className="h-4 w-4 animate-spin" />
              {t('common:status.saving')}
            </>
          ) : (
            t('common:actions.save')
          )}
        </Button>
      </div>
    </form>
  )
}

/**
 * Reusable toggle field component for receipt section visibility toggles.
 */
function ToggleField({
  id,
  label,
  description,
  override,
  register,
  t,
}: {
  id: keyof ReceiptSettingsFormData
  label: string
  description: string
  override: LegalOverrideInfo
  register: ReturnType<typeof useForm<ReceiptSettingsFormData>>['register']
  t: ReturnType<typeof useTranslation>['t']
}) {
  return (
    <div className="flex items-start gap-3">
      <div className="flex h-6 items-center">
        <Checkbox
          id={id}
          {...register(id)}
          disabled={override.isForced}
        />
      </div>
      <div className="flex-1">
        <label htmlFor={id} className={`text-sm font-medium ${override.isForced ? textColors.disabled : textColors.secondary}`}>
          {label}
        </label>
        <p className={tokens.helperText.base}>{description}</p>
        {override.isForced && (
          <div className={cn('mt-1 flex items-center gap-1 text-xs', textColors.warningDark)}>
            <Info className="h-3.5 w-3.5" />
            <span>{t(override.tooltipKey)}</span>
          </div>
        )}
      </div>
    </div>
  )
}
