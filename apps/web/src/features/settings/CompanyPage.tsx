import { useState, useRef } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  ArrowLeft,
  Building2,
  MapPin,
  Phone,
  Mail,
  Globe,
  Upload,
  Trash2,
  CheckCircle,
  XCircle,
  Loader2,
  Receipt,
} from 'lucide-react'
import { api, getErrorMessage } from '../../lib/api'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { countries } from '../../lib/countries'
import { ReceiptSettingsTab } from './components/ReceiptSettingsTab'

interface CompanySettings {
  name: string
  legal_name: string | null
  slug: string
  tax_id: string | null
  registration_number: string | null
  address: {
    street: string | null
    city: string | null
    postal_code: string | null
    country: string | null
  } | null
  phone: string | null
  email: string | null
  website: string | null
  logo_url: string | null
  primary_color: string
  country_code: string | null
  currency_code: string | null
  timezone: string
  date_format: string
  locale: string
}

interface CompanySettingsResponse {
  data: CompanySettings
}

type CompanyTab = 'general' | 'receipt'

export function CompanyPage() {
  const { t } = useTranslation(['settings', 'common'])
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const fileInputRef = useRef<HTMLInputElement>(null)
  const [activeTab, setActiveTab] = useState<CompanyTab>('general')
  const [notification, setNotification] = useState<{
    type: 'success' | 'error'
    message: string
  } | null>(null)

  // Fetch company settings
  const { data, isLoading, error } = useQuery({
    queryKey: tenantScopedKey(['company-settings']),
    queryFn: async () => {
      const response = await api.get<CompanySettingsResponse>('/settings/company')
      return response.data.data
    },
    enabled: tenantId !== null && companyId !== null,
  })

  // Form state
  const [formData, setFormData] = useState<Partial<CompanySettings>>({})
  const [isDirty, setIsDirty] = useState(false)

  // Initialize form data when settings load
  const settings = data
  if (settings && Object.keys(formData).length === 0) {
    setFormData({
      name: settings.name,
      legal_name: settings.legal_name,
      tax_id: settings.tax_id,
      registration_number: settings.registration_number,
      address: settings.address ?? { street: null, city: null, postal_code: null, country: null },
      phone: settings.phone,
      email: settings.email,
      website: settings.website,
      primary_color: settings.primary_color,
      country_code: settings.country_code,
      currency_code: settings.currency_code,
      timezone: settings.timezone,
      date_format: settings.date_format,
      locale: settings.locale,
    })
  }

  // Update mutation
  const updateMutation = useMutation({
    mutationFn: async (data: Partial<CompanySettings>): Promise<void> => {
      await api.patch('/settings/company', data)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: tenantScopedKey(['company-settings']) })
      setIsDirty(false)
      showNotification('success', t('settings:company.messages.saved'))
    },
    onError: (error) => {
      showNotification('error', getErrorMessage(error))
    },
  })

  // Logo upload mutation
  const uploadLogoMutation = useMutation({
    mutationFn: async (file: File): Promise<void> => {
      const formData = new FormData()
      formData.append('logo', file)
      await api.post('/settings/company/logo', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: tenantScopedKey(['company-settings']) })
      showNotification('success', t('settings:company.messages.logoUploaded'))
    },
    onError: (error) => {
      showNotification('error', getErrorMessage(error))
    },
  })

  // Logo delete mutation
  const deleteLogoMutation = useMutation({
    mutationFn: async (): Promise<void> => {
      await api.delete('/settings/company/logo')
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: tenantScopedKey(['company-settings']) })
      showNotification('success', t('settings:company.messages.logoDeleted'))
    },
    onError: (error) => {
      showNotification('error', getErrorMessage(error))
    },
  })

  const showNotification = (type: 'success' | 'error', message: string) => {
    setNotification({ type, message })
    setTimeout(() => { setNotification(null) }, 5000)
  }

  const handleInputChange = (field: string, value: string | null) => {
    setFormData((prev) => ({ ...prev, [field]: value }))
    setIsDirty(true)
  }

  const handleAddressChange = (field: 'street' | 'city' | 'postal_code' | 'country', value: string) => {
    setFormData((prev) => {
      const currentAddress = prev.address ?? { street: null, city: null, postal_code: null, country: null }
      return {
        ...prev,
        address: {
          ...currentAddress,
          [field]: value || null,
        },
      }
    })
    setIsDirty(true)
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    updateMutation.mutate(formData)
  }

  const handleLogoUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    if (file) {
      // Validate file size (2MB max)
      if (file.size > 2 * 1024 * 1024) {
        showNotification('error', t('settings:company.messages.logoTooLarge'))
        return
      }
      // Validate file type
      if (!['image/png', 'image/jpeg', 'image/svg+xml'].includes(file.type)) {
        showNotification('error', t('settings:company.messages.logoInvalidType'))
        return
      }
      uploadLogoMutation.mutate(file)
    }
  }

  const handleDeleteLogo = () => {
    if (confirm(t('settings:company.messages.confirmDeleteLogo'))) {
      deleteLogoMutation.mutate()
    }
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <Loader2 className="h-8 w-8 animate-spin text-blue-600" />
      </div>
    )
  }

  if (error) {
    return (
      <div className="rounded-lg bg-red-50 p-4 text-red-700">
        {t('settings:company.messages.loadError')}
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Notification */}
      {notification && (
        <div
          className={`fixed top-4 right-4 z-50 rounded-lg p-4 shadow-lg ${
            notification.type === 'success' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800'
          }`}
        >
          <div className="flex items-center gap-2">
            {notification.type === 'success' ? (
              <CheckCircle className="h-5 w-5" />
            ) : (
              <XCircle className="h-5 w-5" />
            )}
            {notification.message}
          </div>
        </div>
      )}

      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Link
            to="/settings"
            className="inline-flex items-center gap-2 text-sm text-gray-600 hover:text-gray-900"
          >
            <ArrowLeft className="h-4 w-4" />
            {t('common:actions.back')}
          </Link>
          <div>
            <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
              <Building2 className="h-6 w-6 text-green-500" />
              {t('settings:company.title')}
            </h1>
            <p className="text-gray-500">{t('settings:sections.company.description')}</p>
          </div>
        </div>
      </div>

      {/* Tabs */}
      <div className="border-b border-gray-200">
        <nav className="-mb-px flex gap-6" aria-label={t('settings:company.title')}>
          <button
            type="button"
            onClick={() => { setActiveTab('general') }}
            className={`flex items-center gap-2 border-b-2 px-1 py-3 text-sm font-medium transition-colors ${
              activeTab === 'general'
                ? 'border-blue-500 text-blue-600'
                : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
            }`}
          >
            <Building2 className="h-4 w-4" />
            {t('settings:company.tabs.general')}
          </button>
          <button
            type="button"
            onClick={() => { setActiveTab('receipt') }}
            className={`flex items-center gap-2 border-b-2 px-1 py-3 text-sm font-medium transition-colors ${
              activeTab === 'receipt'
                ? 'border-blue-500 text-blue-600'
                : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
            }`}
          >
            <Receipt className="h-4 w-4" />
            {t('settings:company.tabs.receipt')}
          </button>
        </nav>
      </div>

      {/* Tab Content */}
      {activeTab === 'receipt' ? (
        <ReceiptSettingsTab />
      ) : (
      <form onSubmit={handleSubmit}>
        <div className="grid gap-6 lg:grid-cols-2">
          {/* Company Information */}
          <div className="rounded-lg border border-gray-200 bg-white p-6">
            <h2 className="text-lg font-semibold text-gray-900 mb-4">{t('settings:company.sections.information')}</h2>
            <div className="space-y-4">
              <div>
                <label htmlFor="name" className="block text-sm font-medium text-gray-700">
                  {t('settings:company.fields.name')}
                </label>
                <input
                  type="text"
                  id="name"
                  value={formData.name ?? ''}
                  onChange={(e) => { handleInputChange('name', e.target.value) }}
                  required
                  className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                />
              </div>
              <div>
                <label htmlFor="legal_name" className="block text-sm font-medium text-gray-700">
                  {t('settings:company.fields.legalName')}
                </label>
                <input
                  type="text"
                  id="legal_name"
                  value={formData.legal_name ?? ''}
                  onChange={(e) => { handleInputChange('legal_name', e.target.value || null) }}
                  className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                />
              </div>
              <div>
                <label htmlFor="tax_id" className="block text-sm font-medium text-gray-700">
                  {t('settings:company.fields.taxId')}
                </label>
                <input
                  type="text"
                  id="tax_id"
                  value={formData.tax_id ?? ''}
                  onChange={(e) => { handleInputChange('tax_id', e.target.value || null) }}
                  placeholder="FR12345678901"
                  className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                />
              </div>
              <div>
                <label htmlFor="registration_number" className="block text-sm font-medium text-gray-700">
                  {t('settings:company.fields.registrationNumber')}
                </label>
                <input
                  type="text"
                  id="registration_number"
                  value={formData.registration_number ?? ''}
                  onChange={(e) => { handleInputChange('registration_number', e.target.value || null) }}
                  placeholder="123 456 789 RCS Paris"
                  className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                />
              </div>
            </div>
          </div>

          {/* Contact Information */}
          <div className="rounded-lg border border-gray-200 bg-white p-6">
            <h2 className="text-lg font-semibold text-gray-900 mb-4">{t('settings:company.sections.contact')}</h2>
            <div className="space-y-4">
              <div>
                <label htmlFor="street" className="block text-sm font-medium text-gray-700">
                  <MapPin className="inline h-4 w-4 mr-1" />
                  {t('settings:company.fields.street')}
                </label>
                <input
                  type="text"
                  id="street"
                  value={formData.address?.street ?? ''}
                  onChange={(e) => { handleAddressChange('street', e.target.value) }}
                  placeholder="123 Business Street"
                  className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label htmlFor="city" className="block text-sm font-medium text-gray-700">
                    {t('settings:company.fields.city')}
                  </label>
                  <input
                    type="text"
                    id="city"
                    value={formData.address?.city ?? ''}
                    onChange={(e) => { handleAddressChange('city', e.target.value) }}
                    placeholder="Paris"
                    className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                  />
                </div>
                <div>
                  <label htmlFor="postal_code" className="block text-sm font-medium text-gray-700">
                    {t('settings:company.fields.postalCode')}
                  </label>
                  <input
                    type="text"
                    id="postal_code"
                    value={formData.address?.postal_code ?? ''}
                    onChange={(e) => { handleAddressChange('postal_code', e.target.value) }}
                    placeholder="75001"
                    className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                  />
                </div>
              </div>
              <div>
                <label htmlFor="country" className="block text-sm font-medium text-gray-700">
                  {t('settings:company.fields.country')}
                </label>
                <select
                  id="country"
                  value={formData.address?.country ?? ''}
                  onChange={(e) => { handleAddressChange('country', e.target.value) }}
                  className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                >
                  <option value="">{t('common:selectCountry')}</option>
                  {countries.map((country) => (
                    <option key={country.code} value={country.code}>
                      {country.name}
                    </option>
                  ))}
                </select>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label htmlFor="phone" className="block text-sm font-medium text-gray-700">
                    <Phone className="inline h-4 w-4 mr-1" />
                    {t('settings:company.fields.phone')}
                  </label>
                  <input
                    type="text"
                    id="phone"
                    value={formData.phone ?? ''}
                    onChange={(e) => { handleInputChange('phone', e.target.value || null) }}
                    placeholder="+33 1 23 45 67 89"
                    className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                  />
                </div>
                <div>
                  <label htmlFor="email" className="block text-sm font-medium text-gray-700">
                    <Mail className="inline h-4 w-4 mr-1" />
                    {t('settings:company.fields.email')}
                  </label>
                  <input
                    type="email"
                    id="email"
                    value={formData.email ?? ''}
                    onChange={(e) => { handleInputChange('email', e.target.value || null) }}
                    placeholder="contact@company.com"
                    className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                  />
                </div>
              </div>
              <div>
                <label htmlFor="website" className="block text-sm font-medium text-gray-700">
                  <Globe className="inline h-4 w-4 mr-1" />
                  {t('settings:company.fields.website')}
                </label>
                <input
                  type="url"
                  id="website"
                  value={formData.website ?? ''}
                  onChange={(e) => { handleInputChange('website', e.target.value || null) }}
                  placeholder="https://www.company.com"
                  className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                />
              </div>
            </div>
          </div>

          {/* Logo & Branding */}
          <div className="rounded-lg border border-gray-200 bg-white p-6">
            <h2 className="text-lg font-semibold text-gray-900 mb-4">{t('settings:company.sections.branding')}</h2>
            <div className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">{t('settings:company.fields.logo')}</label>
                {settings?.logo_url ? (
                  <div className="flex items-center gap-4">
                    <img
                      src={settings.logo_url}
                      alt={t('settings:company.fields.logo')}
                      className="h-20 w-20 object-contain rounded-lg border border-gray-200 bg-white p-2"
                    />
                    <div className="flex flex-col gap-2">
                      <button
                        type="button"
                        onClick={() => { fileInputRef.current?.click() }}
                        disabled={uploadLogoMutation.isPending}
                        className="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                      >
                        <Upload className="h-4 w-4" />
                        {t('settings:company.actions.replaceLogo')}
                      </button>
                      <button
                        type="button"
                        onClick={handleDeleteLogo}
                        disabled={deleteLogoMutation.isPending}
                        className="inline-flex items-center gap-2 rounded-lg border border-red-300 px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50"
                      >
                        <Trash2 className="h-4 w-4" />
                        {t('common:actions.delete')}
                      </button>
                    </div>
                  </div>
                ) : (
                  <div
                    onClick={() => { fileInputRef.current?.click() }}
                    className="border-2 border-dashed border-gray-300 rounded-lg p-8 text-center cursor-pointer hover:border-blue-400 hover:bg-blue-50 transition-colors"
                  >
                    {uploadLogoMutation.isPending ? (
                      <Loader2 className="mx-auto h-12 w-12 animate-spin text-blue-600" />
                    ) : (
                      <>
                        <Upload className="mx-auto h-12 w-12 text-gray-400" />
                        <p className="mt-2 text-sm text-gray-600">{t('settings:company.messages.uploadLogo')}</p>
                        <p className="text-xs text-gray-400">{t('settings:company.messages.logoFormats')}</p>
                      </>
                    )}
                  </div>
                )}
                <input
                  ref={fileInputRef}
                  type="file"
                  accept="image/png,image/jpeg,image/svg+xml"
                  onChange={handleLogoUpload}
                  className="hidden"
                />
              </div>
              <div>
                <label htmlFor="primary_color" className="block text-sm font-medium text-gray-700">
                  {t('settings:company.fields.primaryColor')}
                </label>
                <div className="mt-1 flex items-center gap-3">
                  <input
                    type="color"
                    id="primary_color_picker"
                    value={formData.primary_color ?? '#2563EB'}
                    onChange={(e) => { handleInputChange('primary_color', e.target.value) }}
                    className="h-10 w-10 rounded-lg border border-gray-300 cursor-pointer"
                  />
                  <input
                    type="text"
                    id="primary_color"
                    value={formData.primary_color ?? '#2563EB'}
                    onChange={(e) => { handleInputChange('primary_color', e.target.value) }}
                    placeholder="#2563EB"
                    pattern="^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$"
                    className="block flex-1 rounded-lg border border-gray-300 px-3 py-2 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                  />
                </div>
              </div>
            </div>
          </div>

          {/* Regional Settings */}
          <div className="rounded-lg border border-gray-200 bg-white p-6">
            <h2 className="text-lg font-semibold text-gray-900 mb-4">{t('settings:company.sections.regional')}</h2>
            <div className="space-y-4">
              <div>
                <label htmlFor="currency_code" className="block text-sm font-medium text-gray-700">
                  {t('settings:company.fields.currency')}
                </label>
                <select
                  id="currency_code"
                  value={formData.currency_code ?? 'EUR'}
                  onChange={(e) => { handleInputChange('currency_code', e.target.value) }}
                  className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                >
                  <option value="EUR">EUR - Euro</option>
                  <option value="USD">USD - US Dollar</option>
                  <option value="GBP">GBP - British Pound</option>
                  <option value="TND">TND - Tunisian Dinar</option>
                  <option value="MAD">MAD - Moroccan Dirham</option>
                  <option value="DZD">DZD - Algerian Dinar</option>
                </select>
              </div>
              <div>
                <label htmlFor="timezone" className="block text-sm font-medium text-gray-700">
                  {t('settings:company.fields.timezone')}
                </label>
                <select
                  id="timezone"
                  value={formData.timezone ?? 'Europe/Paris'}
                  onChange={(e) => { handleInputChange('timezone', e.target.value) }}
                  className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                >
                  <option value="Europe/Paris">Europe/Paris (UTC+1)</option>
                  <option value="Europe/London">Europe/London (UTC+0)</option>
                  <option value="America/New_York">America/New_York (UTC-5)</option>
                  <option value="Africa/Tunis">Africa/Tunis (UTC+1)</option>
                  <option value="Africa/Casablanca">Africa/Casablanca (UTC+0)</option>
                  <option value="Africa/Algiers">Africa/Algiers (UTC+1)</option>
                </select>
              </div>
              <div>
                <label htmlFor="date_format" className="block text-sm font-medium text-gray-700">
                  {t('settings:company.fields.dateFormat')}
                </label>
                <select
                  id="date_format"
                  value={formData.date_format ?? 'DD/MM/YYYY'}
                  onChange={(e) => { handleInputChange('date_format', e.target.value) }}
                  className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                >
                  <option value="DD/MM/YYYY">DD/MM/YYYY (31/12/2025)</option>
                  <option value="MM/DD/YYYY">MM/DD/YYYY (12/31/2025)</option>
                  <option value="YYYY-MM-DD">YYYY-MM-DD (2025-12-31)</option>
                </select>
              </div>
              <div>
                <label htmlFor="locale" className="block text-sm font-medium text-gray-700">
                  {t('settings:company.fields.language')}
                </label>
                <select
                  id="locale"
                  value={formData.locale ?? 'fr'}
                  onChange={(e) => { handleInputChange('locale', e.target.value) }}
                  className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                >
                  <option value="fr">Français</option>
                  <option value="en">English</option>
                  <option value="ar">العربية</option>
                </select>
              </div>
            </div>
          </div>
        </div>

        {/* Save Button */}
        <div className="mt-6 flex justify-end gap-3">
          {isDirty && (
            <span className="text-sm text-amber-600 self-center">{t('settings:company.messages.unsavedChanges')}</span>
          )}
          <button
            type="submit"
            disabled={updateMutation.isPending || !isDirty}
            className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-6 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
          >
            {updateMutation.isPending ? (
              <>
                <Loader2 className="h-4 w-4 animate-spin" />
                {t('common:status.saving')}
              </>
            ) : (
              t('common:actions.save')
            )}
          </button>
        </div>
      </form>
      )}
    </div>
  )
}
