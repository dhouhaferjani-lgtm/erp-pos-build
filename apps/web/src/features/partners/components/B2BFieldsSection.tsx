import { useTranslation } from 'react-i18next'
import type { UseFormRegister, UseFormWatch } from 'react-hook-form'
import { CheckCircle, XCircle, Loader2 } from 'lucide-react'
import { MoneyInput } from '@/components/atoms/MoneyInput'
import { useCompany } from '@/hooks/useCompany'
import { useTaxIdValidation } from '../hooks/useTaxIdValidation'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface B2BFormFields {
  company_legal_name: string
  business_registration_number: string
  payment_terms: string
  payment_terms_days: string
  credit_limit: string
  discount_percentage: string
  invoice_consolidation: boolean
  consolidation_frequency: string
}

interface B2BFieldsSectionProps {
  register: UseFormRegister<B2BFormFields & Record<string, unknown>>
  watch: UseFormWatch<B2BFormFields & Record<string, unknown>>
  setValue: (name: 'credit_limit', value: string) => void
  partnerId?: string | undefined
}

export function B2BFieldsSection({ register, watch, setValue, partnerId }: B2BFieldsSectionProps) {
  const { currentCompany } = useCompany()
  const currency = currentCompany?.currency ?? 'EUR'
  const { t } = useTranslation('sales')
  const paymentTerms = watch('payment_terms')
  const invoiceConsolidation = watch('invoice_consolidation')
  const taxIdValidation = useTaxIdValidation()

  const handleValidateTaxId = () => {
    if (partnerId) {
      taxIdValidation.mutate(partnerId)
    }
  }

  return (
    <div className={`rounded-lg border ${colorTokens.intent.primary.borderSubtle} ${colorTokens.intent.primary.bgSubtleAlphaLight} p-6`}>
      <h3 className={`mb-4 text-lg font-semibold ${colorTokens.text.primary}`}>
        {t('partners.b2b.title')}
      </h3>

      <div className="grid gap-6 sm:grid-cols-2">
        {/* Company Legal Name */}
        <div className="sm:col-span-2">
          <label
            htmlFor="company_legal_name"
            className={`block text-sm font-medium ${colorTokens.text.secondary}`}
          >
            {t('partners.b2b.companyLegalName')}
          </label>
          <input
            type="text"
            id="company_legal_name"
            {...register('company_legal_name')}
            className={`mt-1 block w-full rounded-lg border ${colorTokens.border.default} px-3 py-2 shadow-sm ${colorTokens.focus.primaryBorder} focus:outline-none focus:ring-1 ${colorTokens.focus.primaryRing}`}
          />
        </div>

        {/* Business Registration Number */}
        <div>
          <label
            htmlFor="business_registration_number"
            className={`block text-sm font-medium ${colorTokens.text.secondary}`}
          >
            {t('partners.b2b.registrationNumber')}
          </label>
          <div className="mt-1 flex gap-2">
            <input
              type="text"
              id="business_registration_number"
              {...register('business_registration_number')}
              className={`block w-full rounded-lg border ${colorTokens.border.default} px-3 py-2 shadow-sm ${colorTokens.focus.primaryBorder} focus:outline-none focus:ring-1 ${colorTokens.focus.primaryRing}`}
            />
            {partnerId && (
              <button
                type="button"
                onClick={handleValidateTaxId}
                disabled={taxIdValidation.isPending}
                className={`inline-flex items-center rounded-lg border ${colorTokens.border.default} ${colorTokens.surface.base} px-3 py-2 text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.intent.neutral.bgHover} disabled:opacity-50`}
              >
                {taxIdValidation.isPending ? (
                  <Loader2 className="h-4 w-4 animate-spin" />
                ) : (
                  t('partners.b2b.validateTaxId')
                )}
              </button>
            )}
          </div>
          {taxIdValidation.isSuccess && (
            <div
              className={`mt-1 flex items-center gap-1 text-sm ${
                taxIdValidation.data.is_valid ? colorTokens.intent.success.text : colorTokens.intent.danger.text
              }`}
            >
              {taxIdValidation.data.is_valid ? (
                <>
                  <CheckCircle className="h-4 w-4" />
                  {t('partners.b2b.taxIdValid')} ({taxIdValidation.data.format})
                </>
              ) : (
                <>
                  <XCircle className="h-4 w-4" />
                  {t('partners.b2b.taxIdInvalid')}
                  {taxIdValidation.data.errors.length > 0 && (
                    <span>: {taxIdValidation.data.errors[0]}</span>
                  )}
                </>
              )}
            </div>
          )}
        </div>

        {/* Payment Terms */}
        <div>
          <label
            htmlFor="payment_terms"
            className={`block text-sm font-medium ${colorTokens.text.secondary}`}
          >
            {t('partners.b2b.paymentTerms')}
          </label>
          <select
            id="payment_terms"
            {...register('payment_terms')}
            className={`mt-1 block w-full rounded-lg border ${colorTokens.border.default} px-3 py-2 shadow-sm ${colorTokens.focus.primaryBorder} focus:outline-none focus:ring-1 ${colorTokens.focus.primaryRing}`}
          >
            <option value="">{t('partners.b2b.selectPaymentTerms')}</option>
            <option value="immediate">{t('partners.paymentTerms.immediate')}</option>
            <option value="net_15">{t('partners.paymentTerms.net_15')}</option>
            <option value="net_30">{t('partners.paymentTerms.net_30')}</option>
            <option value="net_60">{t('partners.paymentTerms.net_60')}</option>
            <option value="net_90">{t('partners.paymentTerms.net_90')}</option>
            <option value="custom">{t('partners.paymentTerms.custom')}</option>
          </select>
        </div>

        {/* Custom Payment Terms Days */}
        {paymentTerms === 'custom' && (
          <div>
            <label
              htmlFor="payment_terms_days"
              className={`block text-sm font-medium ${colorTokens.text.secondary}`}
            >
              {t('partners.b2b.paymentTermsDays')}
            </label>
            <input
              type="number"
              id="payment_terms_days"
              min="1"
              max="365"
              {...register('payment_terms_days')}
              className={`mt-1 block w-full rounded-lg border ${colorTokens.border.default} px-3 py-2 shadow-sm ${colorTokens.focus.primaryBorder} focus:outline-none focus:ring-1 ${colorTokens.focus.primaryRing}`}
            />
          </div>
        )}

        {/* Credit Limit */}
        <div>
          <label
            htmlFor="credit_limit"
            className={`block text-sm font-medium ${colorTokens.text.secondary}`}
          >
            {t('partners.b2b.creditLimit')}
          </label>
          <MoneyInput
            id="credit_limit"
            value={watch('credit_limit') ?? ''}
            onChange={(v) => { setValue('credit_limit', v) }}
            currency={currency}
            min="0"
            className="mt-1 block w-full"
          />
        </div>

        {/* Discount Percentage */}
        <div>
          <label
            htmlFor="discount_percentage"
            className={`block text-sm font-medium ${colorTokens.text.secondary}`}
          >
            {t('partners.b2b.discountPercentage')}
          </label>
          <div className="relative mt-1">
            <input
              type="number"
              id="discount_percentage"
              step="0.01"
              min="0"
              max="100"
              {...register('discount_percentage')}
              className={`block w-full rounded-lg border ${colorTokens.border.default} px-3 py-2 pe-8 shadow-sm ${colorTokens.focus.primaryBorder} focus:outline-none focus:ring-1 ${colorTokens.focus.primaryRing}`}
            />
            <span className={`pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 ${colorTokens.text.subtle}`}>
              %
            </span>
          </div>
        </div>

        {/* Invoice Consolidation */}
        <div className="sm:col-span-2">
          <div className="flex items-center gap-3">
            <input
              type="checkbox"
              id="invoice_consolidation"
              {...register('invoice_consolidation')}
              className={`h-4 w-4 rounded ${colorTokens.border.default} ${colorTokens.intent.primary.text} ${colorTokens.focus.primaryRing}`}
            />
            <label
              htmlFor="invoice_consolidation"
              className={`text-sm font-medium ${colorTokens.text.secondary}`}
            >
              {t('partners.b2b.invoiceConsolidation')}
            </label>
          </div>
        </div>

        {/* Consolidation Frequency */}
        {invoiceConsolidation && (
          <div>
            <label
              htmlFor="consolidation_frequency"
              className={`block text-sm font-medium ${colorTokens.text.secondary}`}
            >
              {t('partners.b2b.consolidationFrequency')}
            </label>
            <select
              id="consolidation_frequency"
              {...register('consolidation_frequency')}
              className={`mt-1 block w-full rounded-lg border ${colorTokens.border.default} px-3 py-2 shadow-sm ${colorTokens.focus.primaryBorder} focus:outline-none focus:ring-1 ${colorTokens.focus.primaryRing}`}
            >
              <option value="">{t('partners.b2b.selectFrequency')}</option>
              <option value="weekly">{t('partners.consolidation.weekly')}</option>
              <option value="monthly">{t('partners.consolidation.monthly')}</option>
            </select>
          </div>
        )}
      </div>
    </div>
  )
}
