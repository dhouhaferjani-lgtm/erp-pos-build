import { useEffect, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import { X, Loader2 } from 'lucide-react'
import { createLocation, type CreateLocationInput, transformLocationResponse } from '../../../features/location/api'
import { useInvalidateLocations } from '../../../features/location/LocationProvider'
import { isBranchTaxIdRequiredCountry } from '../../../features/location/branchTaxCountries'
import { useCountries } from '../../../features/settings/hooks/useCountries'
import { useCountryProfile } from '../../../features/settings/hooks/useCountryProfile'
import { getCountryPlaceholders } from '../../../lib/countryPlaceholders'
import { useCompany } from '../../../hooks/useCompany'
import { getErrorMessage } from '../../../lib/api'
import { cn } from '../../../lib/utils'
import { tokens, textColors } from '../../../lib/designTokens'
import { useLocationStore } from '../../../stores/locationStore'
import type { LocationType } from '../../../stores/locationStore'

interface AddLocationModalProps {
  isOpen: boolean
  onClose: () => void
}

/**
 * Modal for adding a new location
 */
export function AddLocationModal({ isOpen, onClose }: AddLocationModalProps) {
  const { t } = useTranslation(['common'])
  const navigate = useNavigate()
  const invalidateLocations = useInvalidateLocations()
  const setCurrentLocation = useLocationStore((state) => state.setCurrentLocation)
  const { currentCompany } = useCompany()
  const { data: countries } = useCountries()
  const companyCountryCode = currentCompany?.countryCode ?? ''

  const LOCATION_TYPES: { value: LocationType; label: string; description: string }[] = [
    { value: 'shop', label: t('common:locations.types.shop'), description: t('common:locations.typeDescriptions.shop') },
    { value: 'warehouse', label: t('common:locations.types.warehouse'), description: t('common:locations.typeDescriptions.warehouse') },
    { value: 'office', label: t('common:locations.types.office'), description: t('common:locations.typeDescriptions.office') },
    { value: 'mobile', label: t('common:locations.types.mobile'), description: t('common:locations.typeDescriptions.mobile') },
  ]

  const [formData, setFormData] = useState({
    name: '',
    type: 'shop' as LocationType,
    code: '',
    phone: '',
    email: '',
    addressStreet: '',
    addressCity: '',
    addressPostalCode: '',
    addressCountry: '',
    taxId: '',
    vatNumber: '',
    posEnabled: false,
  })

  const [error, setError] = useState<string | null>(null)

  // Default the country to the company country once, when the modal opens.
  // Never overrides a country the user already picked.
  useEffect(() => {
    if (isOpen && companyCountryCode !== '') {
      setFormData((prev) =>
        prev.addressCountry === '' ? { ...prev, addressCountry: companyCountryCode } : prev,
      )
    }
  }, [isOpen, companyCountryCode])

  const requiresTaxId =
    formData.type === 'shop' && isBranchTaxIdRequiredCountry(formData.addressCountry)

  // Country-profile-driven tax label + placeholders for the selected country
  const normalizedCountry = formData.addressCountry.trim().toUpperCase()
  const { profile: countryProfile } = useCountryProfile(normalizedCountry)
  const taxIdLabel = countryProfile?.taxIdLabel ?? t('common:locations.form.taxId')
  const countryPlaceholders = getCountryPlaceholders(normalizedCountry, countryProfile?.phonePrefix)

  const mutation = useMutation({
    mutationFn: async (input: CreateLocationInput) => {
      return createLocation(input)
    },
    onSuccess: (data) => {
      // Invalidate locations query to refetch the list
      void invalidateLocations()
      // Switch to the new location
      const transformed = transformLocationResponse(data)
      setCurrentLocation(transformed.id)
      // Close the modal
      onClose()
      // Quick-add captures the essentials; point at the full editor for the rest
      toast.success(t('common:locations.modal.createdCompleteDetails'), {
        action: {
          label: t('common:locations.manageLocations'),
          onClick: () => {
            void navigate('/settings/locations')
          },
        },
      })
      // Reset form
      setFormData({
        name: '',
        type: 'shop',
        code: '',
        phone: '',
        email: '',
        addressStreet: '',
        addressCity: '',
        addressPostalCode: '',
        addressCountry: '',
        taxId: '',
        vatNumber: '',
        posEnabled: false,
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
      setError(t('common:locations.modal.locationNameRequired'))
      return
    }

    if (requiresTaxId && !formData.taxId.trim()) {
      setError(t('common:locations.modal.taxIdRequired'))
      return
    }

    mutation.mutate({
      name: formData.name,
      type: formData.type,
      code: formData.code || undefined,
      phone: formData.phone || undefined,
      email: formData.email || undefined,
      addressStreet: formData.addressStreet || undefined,
      addressCity: formData.addressCity || undefined,
      addressPostalCode: formData.addressPostalCode || undefined,
      addressCountry: formData.addressCountry || undefined,
      taxId: formData.taxId || undefined,
      vatNumber: formData.vatNumber || undefined,
      posEnabled: formData.posEnabled,
    })
  }

  const handleChange = (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
    const { name, value, type } = e.target
    if (type === 'checkbox' && e.target instanceof HTMLInputElement) {
      const checked = e.target.checked
      setFormData((prev) => ({ ...prev, [name]: checked }))
    } else {
      setFormData((prev) => ({ ...prev, [name]: value }))
    }
  }

  if (!isOpen) return null

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-black/50">
      <div className="relative mx-4 w-full max-w-lg rounded-lg bg-white p-6 shadow-xl">
        {/* Header */}
        <div className="mb-6 flex items-center justify-between">
          <h2 className="text-xl font-semibold text-gray-900">{t('common:locations.modal.addTitle')}</h2>
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
          {/* Location Type */}
          <div>
            <label htmlFor="type" className="block text-sm font-medium text-gray-700">
              {t('common:locations.form.type')} <span className="text-red-500">*</span>
            </label>
            <select
              id="type"
              name="type"
              value={formData.type}
              onChange={handleChange}
              className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            >
              {LOCATION_TYPES.map((locationType) => (
                <option key={locationType.value} value={locationType.value}>
                  {locationType.label}
                </option>
              ))}
            </select>
            <p className="mt-1 text-xs text-gray-500">
              {LOCATION_TYPES.find((ltype) => ltype.value === formData.type)?.description}
            </p>
          </div>

          {/* Location Name */}
          <div>
            <label htmlFor="name" className="block text-sm font-medium text-gray-700">
              {t('common:locations.form.name')} <span className="text-red-500">*</span>
            </label>
            <input
              type="text"
              id="name"
              name="name"
              value={formData.name}
              onChange={handleChange}
              placeholder={t('common:locations.modal.namePlaceholder')}
              className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              required
            />
          </div>

          {/* Location Code */}
          <div>
            <label htmlFor="code" className="block text-sm font-medium text-gray-700">
              {t('common:locations.form.code')}
            </label>
            <input
              type="text"
              id="code"
              name="code"
              value={formData.code}
              onChange={handleChange}
              placeholder={t('common:locations.modal.codePlaceholder')}
              className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
            <p className="mt-1 text-xs text-gray-500">
              {t('common:common.leaveBlankForAutoGenerate')}
            </p>
          </div>

          {/* Email and Phone */}
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label htmlFor="email" className="block text-sm font-medium text-gray-700">
                {t('common:locations.form.email')}
              </label>
              <input
                type="email"
                id="email"
                name="email"
                value={formData.email}
                onChange={handleChange}
                placeholder={t('common:locations.modal.emailPlaceholder')}
                className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              />
            </div>
            <div>
              <label htmlFor="phone" className="block text-sm font-medium text-gray-700">
                {t('common:locations.form.phone')}
              </label>
              <input
                type="tel"
                id="phone"
                name="phone"
                value={formData.phone}
                onChange={handleChange}
                placeholder={t('common:locations.modal.phonePlaceholder')}
                className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              />
            </div>
          </div>

          {/* Address */}
          <div>
            <label htmlFor="addressStreet" className="block text-sm font-medium text-gray-700">
              {t('common:locations.form.address')}
            </label>
            <input
              type="text"
              id="addressStreet"
              name="addressStreet"
              value={formData.addressStreet}
              onChange={handleChange}
              placeholder={t('common:locations.modal.streetPlaceholder')}
              className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label htmlFor="addressCity" className="block text-sm font-medium text-gray-700">
                {t('common:locations.form.city')}
              </label>
              <input
                type="text"
                id="addressCity"
                name="addressCity"
                value={formData.addressCity}
                onChange={handleChange}
                placeholder={t('common:locations.modal.cityPlaceholder')}
                className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              />
            </div>
            <div>
              <label htmlFor="addressPostalCode" className="block text-sm font-medium text-gray-700">
                {t('common:locations.form.postalCode')}
              </label>
              <input
                type="text"
                id="addressPostalCode"
                name="addressPostalCode"
                value={formData.addressPostalCode}
                onChange={handleChange}
                placeholder={t('common:locations.modal.postalCodePlaceholder')}
                className={tokens.input.base}
              />
            </div>
          </div>

          {/* Country */}
          <div>
            <label htmlFor="addressCountry" className={tokens.label.base}>
              {t('common:locations.form.country')}
            </label>
            {countries && countries.length > 0 ? (
              <select
                id="addressCountry"
                name="addressCountry"
                value={formData.addressCountry}
                onChange={handleChange}
                className={tokens.select.base}
              >
                <option value="" />
                {countries.map((country) => (
                  <option key={country.code} value={country.code}>
                    {country.name}
                  </option>
                ))}
              </select>
            ) : (
              <input
                type="text"
                id="addressCountry"
                name="addressCountry"
                value={formData.addressCountry}
                onChange={handleChange}
                maxLength={2}
                className={tokens.input.base}
              />
            )}
          </div>

          {/* Branch tax identity — required for shops in branch-tax countries */}
          {requiresTaxId && (
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label htmlFor="taxId" className={tokens.label.base}>
                  {taxIdLabel} <span className={tokens.label.required}>*</span>
                </label>
                <input
                  type="text"
                  id="taxId"
                  name="taxId"
                  value={formData.taxId}
                  onChange={handleChange}
                  placeholder={countryPlaceholders.taxId}
                  className={tokens.input.base}
                />
                <p className={cn('mt-1 text-xs', textColors.tertiary)}>
                  {t('common:locations.form.taxIdRequiredHint')}
                </p>
              </div>
              <div>
                <label htmlFor="vatNumber" className={tokens.label.base}>
                  {t('common:locations.form.vatNumber')}
                </label>
                <input
                  type="text"
                  id="vatNumber"
                  name="vatNumber"
                  value={formData.vatNumber}
                  onChange={handleChange}
                  className={tokens.input.base}
                />
              </div>
            </div>
          )}

          {/* POS Enabled */}
          <div className="flex items-center gap-2">
            <input
              type="checkbox"
              id="posEnabled"
              name="posEnabled"
              checked={formData.posEnabled}
              onChange={handleChange}
              className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
            />
            <label htmlFor="posEnabled" className="text-sm font-medium text-gray-700">
              {t('common:locations.form.posEnabled')}
            </label>
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
              {t('common:locations.modal.createButton')}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
