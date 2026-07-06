import { useState, useRef } from 'react'
import { Link } from 'react-router-dom'
import { Trans, useTranslation } from 'react-i18next'
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
  ShieldCheck,
} from 'lucide-react'
import { api, getErrorMessage } from '../../lib/api'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { useCompany } from '../../hooks/useCompany'
import { useCountryProfile } from './hooks/useCountryProfile'
import { getCountryPlaceholders } from '../../lib/countryPlaceholders'
import { countries } from '../../lib/countries'
import { cn } from '../../lib/utils'
import { tokens, textColors, borderColors, colors } from '../../lib/designTokens'
import { Button } from '../../components/atoms/Button'
import { FormField } from '../../components/atoms/FormField'
import { Input } from '../../components/atoms/Input'
import { Select } from '../../components/atoms/Select'
import { StatusBadge } from '../../components/atoms/StatusBadge'
import { Toggle } from '../../components/atoms/Toggle'
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

type ProcurementPreset = 'complet' | 'standard' | 'leger'

interface ProcurementPolicy {
  company_id: string
  preset: ProcurementPreset | null
  bill_control_mode: 'received'
  match_mode: 'two_way' | 'three_way'
  match_enforcement: 'warn' | 'block'
  variance_tolerance_percent: string
  variance_tolerance_max_amount: string
  allow_receipt_first: boolean
  allow_invoice_first: boolean
  invoice_first_requires_approval: boolean
}

interface ProcurementPolicyResponse {
  data: ProcurementPolicy
}

type ProcurementPolicyPayload =
  | { preset: ProcurementPreset }
  | {
      bill_control_mode: 'received'
      match_mode: 'two_way' | 'three_way'
      match_enforcement: 'warn' | 'block'
      variance_tolerance_percent: string
      variance_tolerance_max_amount: string
      allow_receipt_first: boolean
      allow_invoice_first: boolean
      invoice_first_requires_approval: boolean
    }

type CompanyTab = 'general' | 'procurement' | 'receipt'

const procurementPresets: ProcurementPreset[] = ['complet', 'standard', 'leger']

const procurementPresetEntryPoints: Record<ProcurementPreset, Pick<ProcurementPolicy, 'allow_receipt_first' | 'allow_invoice_first'>> = {
  complet: { allow_receipt_first: false, allow_invoice_first: false },
  standard: { allow_receipt_first: true, allow_invoice_first: false },
  leger: { allow_receipt_first: true, allow_invoice_first: true },
}

export function CompanyPage() {
  const { t } = useTranslation(['settings', 'common', 'purchases'])
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const { currentCompany } = useCompany()
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

  const {
    data: procurementPolicy,
    isLoading: isProcurementLoading,
    error: procurementError,
  } = useQuery({
    queryKey: tenantScopedKey(['procurement-policy']),
    queryFn: async () => {
      const response = await api.get<ProcurementPolicyResponse>('/procurement-policies')
      return response.data.data
    },
    enabled: tenantId !== null && companyId !== null,
  })

  // Form state
  const [formData, setFormData] = useState<Partial<CompanySettings>>({})
  const [isDirty, setIsDirty] = useState(false)
  const [procurementForm, setProcurementForm] = useState<Partial<ProcurementPolicy>>({})

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

  if (procurementPolicy && Object.keys(procurementForm).length === 0) {
    setProcurementForm({
      bill_control_mode: procurementPolicy.bill_control_mode,
      match_mode: procurementPolicy.match_mode,
      match_enforcement: procurementPolicy.match_enforcement,
      variance_tolerance_percent: procurementPolicy.variance_tolerance_percent,
      variance_tolerance_max_amount: procurementPolicy.variance_tolerance_max_amount,
      allow_receipt_first: procurementPolicy.allow_receipt_first,
      allow_invoice_first: procurementPolicy.allow_invoice_first,
      invoice_first_requires_approval: procurementPolicy.invoice_first_requires_approval,
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

  const updateProcurementMutation = useMutation({
    mutationFn: async (payload: ProcurementPolicyPayload): Promise<ProcurementPolicy> => {
      const response = await api.put<ProcurementPolicyResponse>('/procurement-policies', payload)
      return response.data.data
    },
    onSuccess: async (policy) => {
      setProcurementForm({
        bill_control_mode: policy.bill_control_mode,
        match_mode: policy.match_mode,
        match_enforcement: policy.match_enforcement,
        variance_tolerance_percent: policy.variance_tolerance_percent,
        variance_tolerance_max_amount: policy.variance_tolerance_max_amount,
        allow_receipt_first: policy.allow_receipt_first,
        allow_invoice_first: policy.allow_invoice_first,
        invoice_first_requires_approval: policy.invoice_first_requires_approval,
      })
      await queryClient.invalidateQueries({ queryKey: tenantScopedKey(['procurement-policy']) })
      showNotification('success', t('settings:company.procurement.messages.saved'))
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

  const handlePresetSelect = (preset: ProcurementPreset) => {
    updateProcurementMutation.mutate({ preset })
  }

  const handleProcurementFieldChange = (field: keyof ProcurementPolicy, value: string) => {
    setProcurementForm((prev) => ({ ...prev, [field]: value }))
  }

  const handleProcurementToggleChange = (
    field: 'allow_receipt_first' | 'allow_invoice_first' | 'invoice_first_requires_approval',
    value: boolean,
  ) => {
    setProcurementForm((prev) => ({ ...prev, [field]: value }))
  }

  const handleProcurementSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    updateProcurementMutation.mutate({
      bill_control_mode: 'received',
      match_mode: (procurementForm.match_mode ?? 'three_way') as 'two_way' | 'three_way',
      match_enforcement: (procurementForm.match_enforcement ?? 'warn') as 'warn' | 'block',
      variance_tolerance_percent: procurementForm.variance_tolerance_percent ?? '2.00',
      variance_tolerance_max_amount: procurementForm.variance_tolerance_max_amount ?? '1.000',
      allow_receipt_first: procurementForm.allow_receipt_first ?? false,
      allow_invoice_first: procurementForm.allow_invoice_first ?? false,
      invoice_first_requires_approval: procurementForm.invoice_first_requires_approval ?? true,
    })
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
  const companyCountryCode = formData.country_code ?? formData.address?.country ?? settings?.country_code ?? 'TN'
  const { profile: countryProfile } = useCountryProfile(companyCountryCode)
  const placeholders = getCountryPlaceholders(companyCountryCode, countryProfile?.phonePrefix)
  // Scope banner needs a company name to be meaningful; degrade to no banner
  // (never a malformed sentence) when company context is absent.
  const companyDisplayName = currentCompany?.name ?? settings?.name ?? ''

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

      {/* Company-scope banner: these settings apply company-wide; branch data lives in Locations & Branches */}
      {companyDisplayName !== '' && (
        <div className={cn(tokens.alert.base, tokens.alert.info)}>
          <Trans
            i18nKey="settings:company.scopeBanner"
            values={{ company: companyDisplayName }}
            components={{
              locationsLink: <Link to="/settings/locations" className="font-medium underline" />,
            }}
          />
        </div>
      )}

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
          <button
            type="button"
            onClick={() => { setActiveTab('procurement') }}
            className={cn(
              'flex items-center gap-2 border-b-2 px-1 py-3 text-sm font-medium transition-colors',
              activeTab === 'procurement'
                ? cn(borderColors.primary, textColors.brand)
                : cn('border-transparent', textColors.tertiary, textColors.hoverSecondary, borderColors.hover),
            )}
          >
            <ShieldCheck className="h-4 w-4" />
            {t('settings:company.tabs.procurement')}
          </button>
        </nav>
      </div>

      {/* Tab Content */}
      {activeTab === 'receipt' ? (
        <ReceiptSettingsTab />
      ) : activeTab === 'procurement' ? (
        <div className="space-y-6">
          {isProcurementLoading ? (
            <div className="flex items-center justify-center py-10">
              <Loader2 className={cn('h-6 w-6 animate-spin', textColors.brand)} />
            </div>
          ) : procurementError ? (
            <div className={cn(tokens.alert.base, tokens.alert.error)}>
              {t('settings:company.procurement.messages.loadError')}
            </div>
          ) : (
            <>
              <div className="grid gap-4 lg:grid-cols-3">
                {procurementPresets.map((preset) => {
                  const isActive = procurementPolicy?.preset === preset
                  const entryPoints = procurementPresetEntryPoints[preset]
                  return (
                    <button
                      key={preset}
                      type="button"
                      onClick={() => { handlePresetSelect(preset) }}
                      disabled={updateProcurementMutation.isPending}
                      aria-pressed={isActive}
                      className={cn(
                        'min-h-40 rounded-lg border bg-white p-5 text-left shadow-sm transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60',
                        isActive ? 'border-blue-500 ring-1 ring-blue-500' : cn(borderColors.light, 'hover:border-blue-300'),
                      )}
                    >
                      <div className="flex items-start justify-between gap-3">
                        <h2 className={cn(tokens.heading.section, 'text-base')}>
                          {t(`settings:company.procurement.presets.${preset}.title`)}
                        </h2>
                        {isActive && <CheckCircle className={cn('h-5 w-5 shrink-0', textColors.brand)} />}
                      </div>
                      <p className={cn('mt-2 text-sm', textColors.secondary)}>
                        {t(`settings:company.procurement.presets.${preset}.description`)}
                      </p>
                      <div className="mt-4 flex flex-wrap gap-2">
                        <StatusBadge tone="info" className="text-xs">
                          {t(`settings:company.procurement.modes.${preset === 'leger' ? 'two_way' : 'three_way'}`)}
                        </StatusBadge>
                        <StatusBadge tone={preset === 'complet' ? 'danger' : 'warning'} className="text-xs">
                          {t(`settings:company.procurement.enforcement.${preset === 'complet' ? 'block' : 'warn'}`)}
                        </StatusBadge>
                        <StatusBadge tone={entryPoints.allow_receipt_first ? 'success' : 'neutral'} className="text-xs">
                          {t(`purchases:settings.procurement.entryPoints.receiptFirst.${entryPoints.allow_receipt_first ? 'on' : 'off'}`)}
                        </StatusBadge>
                        <StatusBadge tone={entryPoints.allow_invoice_first ? 'success' : 'neutral'} className="text-xs">
                          {t(`purchases:settings.procurement.entryPoints.invoiceFirst.${entryPoints.allow_invoice_first ? 'on' : 'off'}`)}
                        </StatusBadge>
                      </div>
                    </button>
                  )
                })}
              </div>

              <form onSubmit={handleProcurementSubmit} className={tokens.card.base}>
                <div className="mb-4 flex items-center justify-between gap-4">
                  <div>
                    <h2 className={cn(tokens.heading.section, 'mb-1')}>
                      {t('settings:company.procurement.advanced.title')}
                    </h2>
                    <p className={cn('text-sm', textColors.secondary)}>
                      {t('settings:company.procurement.advanced.description')}
                    </p>
                  </div>
                  {procurementPolicy?.preset === null && (
                    <StatusBadge tone="neutral" className="shrink-0 text-xs">
                      {t('settings:company.procurement.custom')}
                    </StatusBadge>
                  )}
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                  <FormField label={t('settings:company.procurement.fields.billControlMode')} htmlFor="bill_control_mode">
                    <Select id="bill_control_mode" value="received" disabled>
                      <option value="received">{t('settings:company.procurement.billControl.received')}</option>
                    </Select>
                  </FormField>
                  <FormField label={t('settings:company.procurement.fields.matchMode')} htmlFor="match_mode">
                    <Select
                      id="match_mode"
                      value={procurementForm.match_mode ?? 'three_way'}
                      onChange={(e) => { handleProcurementFieldChange('match_mode', e.target.value) }}
                    >
                      <option value="three_way">{t('settings:company.procurement.modes.three_way')}</option>
                      <option value="two_way">{t('settings:company.procurement.modes.two_way')}</option>
                    </Select>
                  </FormField>
                  <FormField label={t('settings:company.procurement.fields.matchEnforcement')} htmlFor="match_enforcement">
                    <Select
                      id="match_enforcement"
                      value={procurementForm.match_enforcement ?? 'warn'}
                      onChange={(e) => { handleProcurementFieldChange('match_enforcement', e.target.value) }}
                    >
                      <option value="warn">{t('settings:company.procurement.enforcement.warn')}</option>
                      <option value="block">{t('settings:company.procurement.enforcement.block')}</option>
                    </Select>
                  </FormField>
                  <FormField label={t('settings:company.procurement.fields.tolerancePercent')} htmlFor="variance_tolerance_percent">
                    <Input
                      id="variance_tolerance_percent"
                      type="number"
                      min="0"
                      step="0.01"
                      value={procurementForm.variance_tolerance_percent ?? '2.00'}
                      onChange={(e) => { handleProcurementFieldChange('variance_tolerance_percent', e.target.value) }}
                    />
                  </FormField>
                  <FormField label={t('settings:company.procurement.fields.toleranceAmount')} htmlFor="variance_tolerance_max_amount">
                    <Input
                      id="variance_tolerance_max_amount"
                      type="number"
                      min="0"
                      step="0.001"
                      value={procurementForm.variance_tolerance_max_amount ?? '1.000'}
                      onChange={(e) => { handleProcurementFieldChange('variance_tolerance_max_amount', e.target.value) }}
                    />
                  </FormField>
                </div>

                <div className={cn('mt-5 grid gap-3 border-t pt-5 lg:grid-cols-3', borderColors.light)}>
                  <div className={cn('flex items-start justify-between gap-3 rounded-md border p-3', borderColors.light)}>
                    <span className="min-w-0">
                      <span className={cn('block text-sm font-medium', textColors.primary)}>
                        {t('purchases:settings.procurement.entryPoints.allowReceiptFirst')}
                      </span>
                      <span className={cn('mt-1 block text-xs leading-5', textColors.tertiary)}>
                        {t('purchases:settings.procurement.entryPoints.allowReceiptFirstHelp')}
                      </span>
                    </span>
                    <Toggle
                      aria-label={t('purchases:settings.procurement.entryPoints.allowReceiptFirst')}
                      checked={procurementForm.allow_receipt_first ?? false}
                      onChange={(e) => { handleProcurementToggleChange('allow_receipt_first', e.target.checked) }}
                    />
                  </div>
                  <div className={cn('flex items-start justify-between gap-3 rounded-md border p-3', borderColors.light)}>
                    <span className="min-w-0">
                      <span className={cn('block text-sm font-medium', textColors.primary)}>
                        {t('purchases:settings.procurement.entryPoints.allowInvoiceFirst')}
                      </span>
                      <span className={cn('mt-1 block text-xs leading-5', textColors.tertiary)}>
                        {t('purchases:settings.procurement.entryPoints.allowInvoiceFirstHelp')}
                      </span>
                    </span>
                    <Toggle
                      aria-label={t('purchases:settings.procurement.entryPoints.allowInvoiceFirst')}
                      checked={procurementForm.allow_invoice_first ?? false}
                      onChange={(e) => { handleProcurementToggleChange('allow_invoice_first', e.target.checked) }}
                    />
                  </div>
                  <div className={cn('flex items-start justify-between gap-3 rounded-md border p-3', borderColors.light)}>
                    <span className="min-w-0">
                      <span className={cn('block text-sm font-medium', textColors.primary)}>
                        {t('purchases:settings.procurement.entryPoints.invoiceFirstRequiresApproval')}
                      </span>
                      <span className={cn('mt-1 block text-xs leading-5', textColors.tertiary)}>
                        {t('purchases:settings.procurement.entryPoints.invoiceFirstRequiresApprovalHelp')}
                      </span>
                    </span>
                    <Toggle
                      aria-label={t('purchases:settings.procurement.entryPoints.invoiceFirstRequiresApproval')}
                      checked={procurementForm.invoice_first_requires_approval ?? true}
                      onChange={(e) => { handleProcurementToggleChange('invoice_first_requires_approval', e.target.checked) }}
                    />
                  </div>
                </div>

                <div className="mt-6 flex justify-end">
                  <Button
                    type="submit"
                    disabled={updateProcurementMutation.isPending}
                    className="gap-2"
                  >
                    {updateProcurementMutation.isPending ? (
                      <>
                        <Loader2 className="h-4 w-4 animate-spin" />
                        {t('common:status.saving')}
                      </>
                    ) : (
                      t('settings:company.procurement.actions.saveAdvanced')
                    )}
                  </Button>
                </div>
              </form>
            </>
          )}
        </div>
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
              <FormField label={countryProfile?.taxIdLabel ?? t('settings:company.fields.taxId')} htmlFor="tax_id">
                <Input
                  type="text"
                  id="tax_id"
                  value={formData.tax_id ?? ''}
                  onChange={(e) => { handleInputChange('tax_id', e.target.value || null) }}
                  placeholder={placeholders.taxId}
                />
              </FormField>
              <FormField label={t('settings:company.fields.registrationNumber')} htmlFor="registration_number">
                <Input
                  type="text"
                  id="registration_number"
                  value={formData.registration_number ?? ''}
                  onChange={(e) => { handleInputChange('registration_number', e.target.value || null) }}
                  placeholder={placeholders.registrationNumber}
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
                  placeholder={placeholders.street}
                />
              </FormField>
              <div className="grid grid-cols-2 gap-4">
                <FormField label={t('settings:company.fields.city')} htmlFor="city">
                  <Input
                    type="text"
                    id="city"
                    value={formData.address?.city ?? ''}
                    onChange={(e) => { handleAddressChange('city', e.target.value) }}
                    placeholder={placeholders.city}
                  />
                </FormField>
                <FormField label={t('settings:company.fields.postalCode')} htmlFor="postal_code">
                  <Input
                    type="text"
                    id="postal_code"
                    value={formData.address?.postal_code ?? ''}
                    onChange={(e) => { handleAddressChange('postal_code', e.target.value) }}
                    placeholder={placeholders.postalCode}
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
                    placeholder={placeholders.phone}
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
                  value={formData.currency_code ?? 'TND'}
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
                  value={formData.timezone ?? countryProfile?.timezone ?? 'Africa/Tunis'}
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
