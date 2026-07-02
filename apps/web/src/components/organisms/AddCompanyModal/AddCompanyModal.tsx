import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { X, Loader2 } from 'lucide-react'
import { createCompany, type CreateCompanyInput } from '../../../features/company/api'
import { useInvalidateCompanies } from '../../../features/company/CompanyProvider'
import { getErrorMessage } from '../../../lib/api'
import { useCompanyStore } from '../../../stores/companyStore'

interface AddCompanyModalProps {
  isOpen: boolean
  onClose: () => void
}

/**
 * Country configuration for locale, currency, and timezone defaults
 */
interface CountryConfig {
  name: string
  code: string
  currency: string
  locale: string
  timezone: string
}

const COUNTRIES: CountryConfig[] = [
  { name: 'Tunisia', code: 'TN', currency: 'TND', locale: 'fr_TN', timezone: 'Africa/Tunis' },
  { name: 'France', code: 'FR', currency: 'EUR', locale: 'fr_FR', timezone: 'Europe/Paris' },
  { name: 'United Kingdom', code: 'GB', currency: 'GBP', locale: 'en_GB', timezone: 'Europe/London' },
  { name: 'Italy', code: 'IT', currency: 'EUR', locale: 'it_IT', timezone: 'Europe/Rome' },
  { name: 'Morocco', code: 'MA', currency: 'MAD', locale: 'ar_MA', timezone: 'Africa/Casablanca' },
  { name: 'Algeria', code: 'DZ', currency: 'DZD', locale: 'ar_DZ', timezone: 'Africa/Algiers' },
  { name: 'United States', code: 'US', currency: 'USD', locale: 'en_US', timezone: 'America/New_York' },
]

/**
 * Modal for adding a new company
 */
export function AddCompanyModal({ isOpen, onClose }: AddCompanyModalProps) {
  const { t } = useTranslation(['settings', 'common', 'countries'])
  const invalidateCompanies = useInvalidateCompanies()
  const setCurrentCompany = useCompanyStore((state) => state.setCurrentCompany)

  const [formData, setFormData] = useState({
    name: '',
    legalName: '',
    countryCode: 'TN',
    taxId: '',
    email: '',
    phone: '',
    addressStreet: '',
    addressCity: '',
    addressPostalCode: '',
  })

  const [error, setError] = useState<string | null>(null)

  const selectedCountry = COUNTRIES.find((c) => c.code === formData.countryCode) ?? COUNTRIES[0]

  const mutation = useMutation({
    mutationFn: async (input: CreateCompanyInput) => {
      return createCompany(input)
    },
    onSuccess: (data) => {
      // Invalidate companies query to refetch the list
      void invalidateCompanies()
      // Switch to the new company
      setCurrentCompany(data.id)
      // Close the modal
      onClose()
      // Reset form
      setFormData({
        name: '',
        legalName: '',
        countryCode: 'TN',
        taxId: '',
        email: '',
        phone: '',
        addressStreet: '',
        addressCity: '',
        addressPostalCode: '',
      })
      setError(null)
    },
    onError: (err: unknown) => {
      setError(getErrorMessage(err))
    },
  })

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    setError(null)

    if (!formData.name.trim()) {
      setError(t('settings:company.modal.companyNameRequired'))
      return
    }

    mutation.mutate({
      name: formData.name,
      legalName: formData.legalName || undefined,
      countryCode: formData.countryCode,
      currency: selectedCountry.currency,
      locale: selectedCountry.locale,
      timezone: selectedCountry.timezone,
      taxId: formData.taxId || undefined,
      email: formData.email || undefined,
      phone: formData.phone || undefined,
      addressStreet: formData.addressStreet || undefined,
      addressCity: formData.addressCity || undefined,
      addressPostalCode: formData.addressPostalCode || undefined,
    })
  }

  const handleChange = (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
    const { name, value } = e.target
    setFormData((prev) => ({ ...prev, [name]: value }))
  }
  const usesTunisiaDefaults = selectedCountry.code === 'TN'

  if (!isOpen) return null

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-black/50">
      <div className="relative mx-4 w-full max-w-lg rounded-lg bg-white p-6 shadow-xl">
        {/* Header */}
        <div className="mb-6 flex items-center justify-between">
          <h2 className="text-xl font-semibold text-gray-900">{t('settings:company.modal.title')}</h2>
          <button
            type="button"
            onClick={onClose}
            className="rounded-lg p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
            aria-label={t('common:actions.close')}
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        {/* Error */}
        {error && (
          <div className="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">
            {error}
          </div>
        )}

        {/* Form */}
        <form onSubmit={handleSubmit} className="space-y-4">
          {/* Country */}
          <div>
            <label htmlFor="countryCode" className="block text-sm font-medium text-gray-700">
              {t('settings:company.fields.country')} <span className="text-red-500">*</span>
            </label>
            <select
              id="countryCode"
              name="countryCode"
              value={formData.countryCode}
              onChange={handleChange}
              className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            >
              {COUNTRIES.map((country) => (
                <option key={country.code} value={country.code}>
                  {t(`countries:${country.code}`, { defaultValue: country.name })}
                </option>
              ))}
            </select>
            <p className="mt-1 text-xs text-gray-500">
              {t('settings:company.modal.currencyTimezone', {
                currency: selectedCountry.currency,
                timezone: selectedCountry.timezone,
              })}
            </p>
          </div>

          {/* Company Name */}
          <div>
            <label htmlFor="name" className="block text-sm font-medium text-gray-700">
              {t('settings:company.fields.name').replace(' *', '')} <span className="text-red-500">*</span>
            </label>
            <input
              type="text"
              id="name"
              name="name"
              value={formData.name}
              onChange={handleChange}
              placeholder={t('settings:company.modal.namePlaceholder')}
              className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              required
            />
          </div>

          {/* Legal Name */}
          <div>
            <label htmlFor="legalName" className="block text-sm font-medium text-gray-700">
              {t('settings:company.fields.legalName')}
            </label>
            <input
              type="text"
              id="legalName"
              name="legalName"
              value={formData.legalName}
              onChange={handleChange}
              placeholder={usesTunisiaDefaults
                ? t('settings:company.modal.tunisiaLegalNamePlaceholder')
                : t('settings:company.modal.legalNamePlaceholder')}
              className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
          </div>

          {/* Tax ID */}
          <div>
            <label htmlFor="taxId" className="block text-sm font-medium text-gray-700">
              {t('settings:company.fields.taxId')}
            </label>
            <input
              type="text"
              id="taxId"
              name="taxId"
              value={formData.taxId}
              onChange={handleChange}
              placeholder={usesTunisiaDefaults
                ? t('settings:company.modal.tunisiaTaxIdPlaceholder')
                : t('settings:company.modal.taxIdPlaceholder')}
              className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
          </div>

          {/* Email and Phone */}
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label htmlFor="email" className="block text-sm font-medium text-gray-700">
                {t('settings:company.fields.email')}
              </label>
              <input
                type="email"
                id="email"
                name="email"
                value={formData.email}
                onChange={handleChange}
                placeholder={t('settings:company.modal.emailPlaceholder')}
                className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              />
            </div>
            <div>
              <label htmlFor="phone" className="block text-sm font-medium text-gray-700">
                {t('settings:company.fields.phone')}
              </label>
              <input
                type="tel"
                id="phone"
                name="phone"
                value={formData.phone}
                onChange={handleChange}
                placeholder={usesTunisiaDefaults
                  ? t('settings:company.modal.tunisiaPhonePlaceholder')
                  : t('settings:company.modal.phonePlaceholder')}
                className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              />
            </div>
          </div>

          {/* Address */}
          <div>
            <label htmlFor="addressStreet" className="block text-sm font-medium text-gray-700">
              {t('settings:company.fields.street')}
            </label>
            <input
              type="text"
              id="addressStreet"
              name="addressStreet"
              value={formData.addressStreet}
              onChange={handleChange}
              placeholder={usesTunisiaDefaults
                ? t('settings:company.modal.tunisiaStreetPlaceholder')
                : t('settings:company.modal.streetPlaceholder')}
              className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label htmlFor="addressCity" className="block text-sm font-medium text-gray-700">
                {t('settings:company.fields.city')}
              </label>
              <input
                type="text"
                id="addressCity"
                name="addressCity"
                value={formData.addressCity}
                onChange={handleChange}
                placeholder={usesTunisiaDefaults
                  ? t('settings:company.modal.tunisiaCityPlaceholder')
                  : t('settings:company.modal.cityPlaceholder')}
                className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              />
            </div>
            <div>
              <label htmlFor="addressPostalCode" className="block text-sm font-medium text-gray-700">
                {t('settings:company.fields.postalCode')}
              </label>
              <input
                type="text"
                id="addressPostalCode"
                name="addressPostalCode"
                value={formData.addressPostalCode}
                onChange={handleChange}
                placeholder={usesTunisiaDefaults
                  ? t('settings:company.modal.tunisiaPostalCodePlaceholder')
                  : t('settings:company.modal.postalCodePlaceholder')}
                className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              />
            </div>
          </div>

          {/* Actions */}
          <div className="mt-6 flex justify-end gap-3">
            <button
              type="button"
              onClick={onClose}
              className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
              disabled={mutation.isPending}
            >
              {t('common:actions.cancel')}
            </button>
            <button
              type="submit"
              className="flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
              disabled={mutation.isPending}
            >
              {mutation.isPending && <Loader2 className="h-4 w-4 animate-spin" />}
              {t('settings:company.modal.createButton')}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
