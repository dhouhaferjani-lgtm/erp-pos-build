import { useTranslation } from 'react-i18next'
import type { UseFormRegister, UseFormWatch } from 'react-hook-form'
import { CheckCircle, XCircle, Loader2 } from 'lucide-react'
import { useTaxIdValidation } from '../hooks/useTaxIdValidation'

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
  partnerId?: string | undefined
}

export function B2BFieldsSection({ register, watch, partnerId }: B2BFieldsSectionProps) {
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
    <div className="rounded-lg border border-blue-200 bg-blue-50/30 p-6">
      <h3 className="mb-4 text-lg font-semibold text-gray-900">
        {t('partners.b2b.title')}
      </h3>

      <div className="grid gap-6 sm:grid-cols-2">
        {/* Company Legal Name */}
        <div className="sm:col-span-2">
          <label
            htmlFor="company_legal_name"
            className="block text-sm font-medium text-gray-700"
          >
            {t('partners.b2b.companyLegalName')}
          </label>
          <input
            type="text"
            id="company_legal_name"
            {...register('company_legal_name')}
            className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
          />
        </div>

        {/* Business Registration Number */}
        <div>
          <label
            htmlFor="business_registration_number"
            className="block text-sm font-medium text-gray-700"
          >
            {t('partners.b2b.registrationNumber')}
          </label>
          <div className="mt-1 flex gap-2">
            <input
              type="text"
              id="business_registration_number"
              {...register('business_registration_number')}
              className="block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
            {partnerId && (
              <button
                type="button"
                onClick={handleValidateTaxId}
                disabled={taxIdValidation.isPending}
                className="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
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
                taxIdValidation.data.is_valid ? 'text-green-600' : 'text-red-600'
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
            className="block text-sm font-medium text-gray-700"
          >
            {t('partners.b2b.paymentTerms')}
          </label>
          <select
            id="payment_terms"
            {...register('payment_terms')}
            className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
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
              className="block text-sm font-medium text-gray-700"
            >
              {t('partners.b2b.paymentTermsDays')}
            </label>
            <input
              type="number"
              id="payment_terms_days"
              min="1"
              max="365"
              {...register('payment_terms_days')}
              className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
          </div>
        )}

        {/* Credit Limit */}
        <div>
          <label
            htmlFor="credit_limit"
            className="block text-sm font-medium text-gray-700"
          >
            {t('partners.b2b.creditLimit')}
          </label>
          <input
            type="number"
            id="credit_limit"
            step="0.01"
            min="0"
            {...register('credit_limit')}
            className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
          />
        </div>

        {/* Discount Percentage */}
        <div>
          <label
            htmlFor="discount_percentage"
            className="block text-sm font-medium text-gray-700"
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
              className="block w-full rounded-lg border border-gray-300 px-3 py-2 pe-8 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
            <span className="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-gray-500">
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
              className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
            />
            <label
              htmlFor="invoice_consolidation"
              className="text-sm font-medium text-gray-700"
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
              className="block text-sm font-medium text-gray-700"
            >
              {t('partners.b2b.consolidationFrequency')}
            </label>
            <select
              id="consolidation_frequency"
              {...register('consolidation_frequency')}
              className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
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
