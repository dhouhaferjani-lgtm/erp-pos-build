import { useTranslation } from 'react-i18next'
import { Pencil } from 'lucide-react'
import { useProductConfig } from '@/contexts/ProductConfigContext'
import { getCountryName } from '@/lib/countries'
import { getDialCode } from '../config/countryData'
import { getVerticalsForProduct } from '../config/verticals'
import type { RegisterFormData } from '../hooks/useRegisterForm'

interface ReviewStepProps {
  formData: RegisterFormData
  errors: Record<string, string | undefined>
  updateField: <K extends keyof RegisterFormData>(field: K, value: RegisterFormData[K]) => void
  onGoToStep: (step: number) => void
  onSubmit: () => void
  isSubmitting: boolean
}

export function ReviewStep({
  formData,
  errors,
  updateField,
  onGoToStep,
  onSubmit,
  isSubmitting,
}: ReviewStepProps) {
  const { t, i18n } = useTranslation(['auth'])
  const { product } = useProductConfig()
  const locale = i18n.language

  const verticals = getVerticalsForProduct(product)
  const selectedVertical = verticals.find((v) => v.key === formData.vertical)
  const VerticalIcon = selectedVertical?.icon

  const dialCode = getDialCode(formData.countryCode)
  const fullPhone = formData.phoneLocal
    ? `${dialCode}${formData.phoneLocal}`
    : null

  return (
    <div className="space-y-4">
      {/* Account section */}
      <div className="relative rounded-lg border border-gray-200 bg-white p-4">
        <button
          type="button"
          onClick={() => { onGoToStep(1) }}
          className="absolute right-3 top-3 p-1 text-gray-400 hover:text-gray-600"
          aria-label={t('auth:register.editSection')}
        >
          <Pencil className="h-4 w-4" />
        </button>
        <h3 className="text-sm font-semibold text-gray-900">{t('auth:register.step1Title')}</h3>
        <dl className="mt-2 space-y-1 text-sm">
          <div className="flex justify-between">
            <dt className="text-gray-500">{t('auth:register.name')}</dt>
            <dd className="text-gray-900">{formData.name}</dd>
          </div>
          <div className="flex justify-between">
            <dt className="text-gray-500">{t('auth:register.email')}</dt>
            <dd className="text-gray-900">{formData.email}</dd>
          </div>
        </dl>
      </div>

      {/* Business section */}
      <div className="relative rounded-lg border border-gray-200 bg-white p-4">
        <button
          type="button"
          onClick={() => { onGoToStep(2) }}
          className="absolute right-3 top-3 p-1 text-gray-400 hover:text-gray-600"
          aria-label={t('auth:register.editSection')}
        >
          <Pencil className="h-4 w-4" />
        </button>
        <h3 className="text-sm font-semibold text-gray-900">{t('auth:register.step2Title')}</h3>
        <dl className="mt-2 space-y-1 text-sm">
          <div className="flex justify-between">
            <dt className="text-gray-500">{t('auth:register.country')}</dt>
            <dd className="text-gray-900">{getCountryName(formData.countryCode, locale)}</dd>
          </div>
          {selectedVertical && (
            <div className="flex items-center justify-between">
              <dt className="text-gray-500">{t('auth:vertical.label')}</dt>
              <dd className="flex items-center gap-1.5 text-gray-900">
                {VerticalIcon && <VerticalIcon className="h-4 w-4" />}
                {t(selectedVertical.labelKey)}
              </dd>
            </div>
          )}
        </dl>
      </div>

      {/* Company section */}
      <div className="relative rounded-lg border border-gray-200 bg-white p-4">
        <button
          type="button"
          onClick={() => { onGoToStep(3) }}
          className="absolute right-3 top-3 p-1 text-gray-400 hover:text-gray-600"
          aria-label={t('auth:register.editSection')}
        >
          <Pencil className="h-4 w-4" />
        </button>
        <h3 className="text-sm font-semibold text-gray-900">{t('auth:register.step3Title')}</h3>
        <dl className="mt-2 space-y-1 text-sm">
          <div className="flex justify-between">
            <dt className="text-gray-500">{t('auth:register.companyName')}</dt>
            <dd className="text-gray-900">{formData.companyName}</dd>
          </div>
          {fullPhone && (
            <div className="flex justify-between">
              <dt className="text-gray-500">{t('auth:register.phone')}</dt>
              <dd className="text-gray-900">{fullPhone}</dd>
            </div>
          )}
        </dl>
      </div>

      {/* Terms checkbox */}
      <div>
        <label className="flex items-start gap-2">
          <input
            type="checkbox"
            checked={formData.acceptedTerms}
            onChange={(e) => { updateField('acceptedTerms', e.target.checked) }}
            className="mt-0.5 h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
          />
          <span className="text-sm text-gray-600">
            {t('auth:register.acceptTerms')}{' '}
            <a href="/terms" className="text-blue-600 hover:underline" target="_blank" rel="noopener noreferrer">
              {t('auth:register.termsLink')}
            </a>
            {' & '}
            <a href="/privacy" className="text-blue-600 hover:underline" target="_blank" rel="noopener noreferrer">
              {t('auth:register.privacyLink')}
            </a>
          </span>
        </label>
        {errors['acceptedTerms'] && (
          <p className="mt-1 text-sm text-red-600">{errors['acceptedTerms']}</p>
        )}
      </div>

      {/* Submit button */}
      <button
        type="button"
        onClick={onSubmit}
        disabled={!formData.acceptedTerms || isSubmitting}
        className="w-full rounded-lg bg-blue-600 px-6 py-3 text-sm font-medium text-white transition-colors hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
      >
        {isSubmitting ? t('auth:register.creating') : t('auth:register.createAccount')}
      </button>
    </div>
  )
}
