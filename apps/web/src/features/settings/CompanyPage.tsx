import { useState, useRef } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  ArrowLeft,
  Building2,
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
import { cn } from '../../lib/utils'
import { tokens, textColors, borderColors, colors } from '../../lib/designTokens'
import { Button } from '../../components/atoms/Button'
import { FormField } from '../../components/atoms/FormField'
import { Input } from '../../components/atoms/Input'
import { Select } from '../../components/atoms/Select'
import { StatusBadge } from '../../components/atoms/StatusBadge'
import { PageHeader } from '../../components/molecules/PageHeader'
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
        <Loader2 className={cn('h-8 w-8 animate-spin', textColors.brand)} />
      </div>
    )
  }

  if (error) {
    return (
      <div className={cn(tokens.alert.base, tokens.alert.error)}>
        {t('settings:company.messages.loadError')}
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Notification */}
      {notification && (
        <div className="fixed top-4 right-4 z-50">
          <StatusBadge
            tone={notification.type === 'success' ? 'success' : 'danger'}
            className="gap-2 px-4 py-3 text-sm shadow-lg"
          >
            {notification.type === 'success' ? (
              <CheckCircle className="h-5 w-5" />
            ) : (
              <XCircle className="h-5 w-5" />
            )}
            {notification.message}
          </StatusBadge>
        </div>
      )}

      {/* Header */}
      <PageHeader
        title={t('settings:company.title')}
        subtitle={t('settings:sections.company.description')}
        breadcrumb={
          <Link
            to="/settings"
            className={cn('inline-flex items-center gap-2 text-sm', textColors.tertiary, textColors.hoverPrimary)}
          >
            <ArrowLeft className="h-4 w-4" />
            {t('common:actions.back')}
          </Link>
        }
      />

      {/* Tabs */}
      <div className={cn('border-b', borderColors.light)}>
        <nav className="-mb-px flex gap-6" aria-label={t('settings:company.title')}>
          <button
            type="button"
            onClick={() => { setActiveTab('general') }}
            className={cn(
              'flex items-center gap-2 border-b-2 px-1 py-3 text-sm font-medium transition-colors',
              activeTab === 'general'
                ? cn(borderColors.primary, textColors.brand)
                : cn('border-transparent', textColors.tertiary, textColors.hoverSecondary, borderColors.hover),
            )}
          >
            <Building2 className="h-4 w-4" />
            {t('settings:company.tabs.general')}
          </button>
          <button
            type="button"
            onClick={() => { setActiveTab('receipt') }}
            className={cn(
              'flex items-center gap-2 border-b-2 px-1 py-3 text-sm font-medium transition-colors',
              activeTab === 'receipt'
                ? cn(borderColors.primary, textColors.brand)
                : cn('border-transparent', textColors.tertiary, textColors.hoverSecondary, borderColors.hover),
            )}
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
          <div className={tokens.card.base}>
            <h2 className={cn(tokens.heading.section, 'mb-4')}>{t('settings:company.sections.information')}</h2>
            <div className="space-y-4">
              <FormField label={t('settings:company.fields.name')} htmlFor="name">
                <Input
                  type="text"
                  id="name"
                  value={formData.name ?? ''}
                  onChange={(e) => { handleInputChange('name', e.target.value) }}
                  required
                />
              </FormField>
              <FormField label={t('settings:company.fields.legalName')} htmlFor="legal_name">
                <Input
                  type="text"
                  id="legal_name"
                  value={formData.legal_name ?? ''}
                  onChange={(e) => { handleInputChange('legal_name', e.target.value || null) }}
                />
              </FormField>
              <FormField label={t('settings:company.fields.taxId')} htmlFor="tax_id">
                <Input
                  type="text"
                  id="tax_id"
                  value={formData.tax_id ?? ''}
                  onChange={(e) => { handleInputChange('tax_id', e.target.value || null) }}
                  placeholder={t('settings:company.placeholders.taxId')}
                />
              </FormField>
              <FormField label={t('settings:company.fields.registrationNumber')} htmlFor="registration_number">
                <Input
                  type="text"
                  id="registration_number"
                  value={formData.registration_number ?? ''}
                  onChange={(e) => { handleInputChange('registration_number', e.target.value || null) }}
                  placeholder={t('settings:company.placeholders.registrationNumber')}
                />
              </FormField>
            </div>
          </div>

          {/* Contact Information */}
          <div className={tokens.card.base}>
            <h2 className={cn(tokens.heading.section, 'mb-4')}>{t('settings:company.sections.contact')}</h2>
            <div className="space-y-4">
              <FormField
                label={t('settings:company.fields.street')}
                htmlFor="street"
              >
                <Input
                  type="text"
                  id="street"
                  value={formData.address?.street ?? ''}
                  onChange={(e) => { handleAddressChange('street', e.target.value) }}
                  placeholder={t('settings:company.placeholders.street')}
                />
              </FormField>
              <div className="grid grid-cols-2 gap-4">
                <FormField label={t('settings:company.fields.city')} htmlFor="city">
                  <Input
                    type="text"
                    id="city"
                    value={formData.address?.city ?? ''}
                    onChange={(e) => { handleAddressChange('city', e.target.value) }}
                    placeholder={t('settings:company.placeholders.city')}
                  />
                </FormField>
                <FormField label={t('settings:company.fields.postalCode')} htmlFor="postal_code">
                  <Input
                    type="text"
                    id="postal_code"
                    value={formData.address?.postal_code ?? ''}
                    onChange={(e) => { handleAddressChange('postal_code', e.target.value) }}
                    placeholder="75001"
                  />
                </FormField>
              </div>
              <FormField label={t('settings:company.fields.country')} htmlFor="country">
                <Select
                  id="country"
                  value={formData.address?.country ?? ''}
                  onChange={(e) => { handleAddressChange('country', e.target.value) }}
                >
                  <option value="">{t('common:selectCountry')}</option>
                  {countries.map((country) => (
                    <option key={country.code} value={country.code}>
                      {country.name}
                    </option>
                  ))}
                </Select>
              </FormField>
              <div className="grid grid-cols-2 gap-4">
                <FormField label={t('settings:company.fields.phone')} htmlFor="phone">
                  <Input
                    type="text"
                    id="phone"
                    value={formData.phone ?? ''}
                    onChange={(e) => { handleInputChange('phone', e.target.value || null) }}
                    placeholder="+33 1 23 45 67 89"
                  />
                </FormField>
                <FormField label={t('settings:company.fields.email')} htmlFor="email">
                  <Input
                    type="email"
                    id="email"
                    value={formData.email ?? ''}
                    onChange={(e) => { handleInputChange('email', e.target.value || null) }}
                    placeholder="contact@company.com"
                  />
                </FormField>
              </div>
              <FormField label={t('settings:company.fields.website')} htmlFor="website">
                <Input
                  type="url"
                  id="website"
                  value={formData.website ?? ''}
                  onChange={(e) => { handleInputChange('website', e.target.value || null) }}
                  placeholder="https://www.company.com"
                />
              </FormField>
            </div>
          </div>

          {/* Logo & Branding */}
          <div className={tokens.card.base}>
            <h2 className={cn(tokens.heading.section, 'mb-4')}>{t('settings:company.sections.branding')}</h2>
            <div className="space-y-4">
              <div>
                <label className={cn(tokens.label.base, 'mb-2')}>{t('settings:company.fields.logo')}</label>
                {settings?.logo_url ? (
                  <div className="flex items-center gap-4">
                    <img
                      src={settings.logo_url}
                      alt={t('settings:company.fields.logo')}
                      className={cn('h-20 w-20 object-contain rounded-lg border p-2', borderColors.light, colors.white)}
                    />
                    <div className="flex flex-col gap-2">
                      <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        onClick={() => { fileInputRef.current?.click() }}
                        disabled={uploadLogoMutation.isPending}
                        className="gap-2"
                      >
                        <Upload className="h-4 w-4" />
                        {t('settings:company.actions.replaceLogo')}
                      </Button>
                      <Button
                        type="button"
                        variant="danger"
                        size="sm"
                        onClick={handleDeleteLogo}
                        disabled={deleteLogoMutation.isPending}
                        className="gap-2"
                      >
                        <Trash2 className="h-4 w-4" />
                        {t('common:actions.delete')}
                      </Button>
                    </div>
                  </div>
                ) : (
                  <div
                    onClick={() => { fileInputRef.current?.click() }}
                    className={cn(
                      'rounded-lg border-2 border-dashed p-8 text-center cursor-pointer transition-colors',
                      borderColors.default,
                      tokens.card.hoverPrimary,
                    )}
                  >
                    {uploadLogoMutation.isPending ? (
                      <Loader2 className={cn('mx-auto h-12 w-12 animate-spin', textColors.brand)} />
                    ) : (
                      <>
                        <Upload className={cn('mx-auto h-12 w-12', textColors.disabled)} />
                        <p className={cn('mt-2 text-sm', textColors.tertiary)}>{t('settings:company.messages.uploadLogo')}</p>
                        <p className={cn('text-xs', textColors.disabled)}>{t('settings:company.messages.logoFormats')}</p>
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
              <FormField label={t('settings:company.fields.primaryColor')} htmlFor="primary_color">
                <div className="flex items-center gap-3">
                  <input
                    type="color"
                    id="primary_color_picker"
                    aria-label={t('settings:company.fields.primaryColor')}
                    value={formData.primary_color ?? '#2563EB'}
                    onChange={(e) => { handleInputChange('primary_color', e.target.value) }}
                    className={cn('h-10 w-10 rounded-lg border cursor-pointer', borderColors.default)}
                  />
                  <Input
                    type="text"
                    id="primary_color"
                    value={formData.primary_color ?? '#2563EB'}
                    onChange={(e) => { handleInputChange('primary_color', e.target.value) }}
                    placeholder={t('settings:company.placeholders.primaryColor')}
                    pattern="^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$"
                    className="mt-0 flex-1"
                  />
                </div>
              </FormField>
            </div>
          </div>

          {/* Regional Settings */}
          <div className={tokens.card.base}>
            <h2 className={cn(tokens.heading.section, 'mb-4')}>{t('settings:company.sections.regional')}</h2>
            <div className="space-y-4">
              <FormField label={t('settings:company.fields.currency')} htmlFor="currency_code">
                <Select
                  id="currency_code"
                  value={formData.currency_code ?? 'EUR'}
                  onChange={(e) => { handleInputChange('currency_code', e.target.value) }}
                >
                  <option value="EUR">{t('settings:company.currencies.EUR')}</option>
                  <option value="USD">{t('settings:company.currencies.USD')}</option>
                  <option value="GBP">{t('settings:company.currencies.GBP')}</option>
                  <option value="TND">{t('settings:company.currencies.TND')}</option>
                  <option value="MAD">{t('settings:company.currencies.MAD')}</option>
                  <option value="DZD">{t('settings:company.currencies.DZD')}</option>
                </Select>
              </FormField>
              <FormField label={t('settings:company.fields.timezone')} htmlFor="timezone">
                <Select
                  id="timezone"
                  value={formData.timezone ?? 'Europe/Paris'}
                  onChange={(e) => { handleInputChange('timezone', e.target.value) }}
                >
                  <option value="Europe/Paris">{t('settings:company.timezones.EuropeParis')}</option>
                  <option value="Europe/London">{t('settings:company.timezones.EuropeLondon')}</option>
                  <option value="America/New_York">{t('settings:company.timezones.AmericaNewYork')}</option>
                  <option value="Africa/Tunis">{t('settings:company.timezones.AfricaTunis')}</option>
                  <option value="Africa/Casablanca">{t('settings:company.timezones.AfricaCasablanca')}</option>
                  <option value="Africa/Algiers">{t('settings:company.timezones.AfricaAlgiers')}</option>
                </Select>
              </FormField>
              <FormField label={t('settings:company.fields.dateFormat')} htmlFor="date_format">
                <Select
                  id="date_format"
                  value={formData.date_format ?? 'DD/MM/YYYY'}
                  onChange={(e) => { handleInputChange('date_format', e.target.value) }}
                >
                  <option value="DD/MM/YYYY">{t('settings:company.dateFormats.DDMMYYYY')}</option>
                  <option value="MM/DD/YYYY">{t('settings:company.dateFormats.MMDDYYYY')}</option>
                  <option value="YYYY-MM-DD">{t('settings:company.dateFormats.YYYYMMDD')}</option>
                </Select>
              </FormField>
              <FormField label={t('settings:company.fields.language')} htmlFor="locale">
                <Select
                  id="locale"
                  value={formData.locale ?? 'fr'}
                  onChange={(e) => { handleInputChange('locale', e.target.value) }}
                >
                  <option value="fr">{t('settings:company.languages.fr')}</option>
                  <option value="en">{t('settings:company.languages.en')}</option>
                  <option value="ar">{t('settings:company.languages.ar')}</option>
                </Select>
              </FormField>
            </div>
          </div>
        </div>

        {/* Save Button */}
        <div className="mt-6 flex justify-end gap-3">
          {isDirty && (
            <span className={cn('self-center text-sm', textColors.warningDark)}>{t('settings:company.messages.unsavedChanges')}</span>
          )}
          <Button
            type="submit"
            size="lg"
            disabled={updateMutation.isPending || !isDirty}
            className="gap-2"
          >
            {updateMutation.isPending ? (
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
      )}
    </div>
  )
}
