import { useState } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, ArrowRight, Check, Building2, Globe, Mail, Loader2 } from 'lucide-react'
import { useMutation } from '@tanstack/react-query'
import { createCompany, type CreateCompanyInput } from './api'
import { useInvalidateCompanies } from './CompanyProvider'
import { getErrorMessage } from '../../lib/api'
import { useCompanyStore } from '../../stores/companyStore'
import { cn } from '@/lib/utils'
import { tokens, textColors, colors, borderColors } from '@/lib/designTokens'
import { Button, FormField, Input } from '../../components/atoms'
import { PageHeader } from '../../components/molecules/PageHeader'

const STEPS = ['country', 'company', 'contact', 'review'] as const
type Step = (typeof STEPS)[number]

interface CountryConfig {
  name: string
  code: string
  currency: string
  locale: string
  timezone: string
}

const COUNTRIES: CountryConfig[] = [
  { name: 'France', code: 'FR', currency: 'EUR', locale: 'fr_FR', timezone: 'Europe/Paris' },
  { name: 'Tunisia', code: 'TN', currency: 'TND', locale: 'ar_TN', timezone: 'Africa/Tunis' },
  { name: 'United Kingdom', code: 'GB', currency: 'GBP', locale: 'en_GB', timezone: 'Europe/London' },
  { name: 'Italy', code: 'IT', currency: 'EUR', locale: 'it_IT', timezone: 'Europe/Rome' },
  { name: 'Morocco', code: 'MA', currency: 'MAD', locale: 'ar_MA', timezone: 'Africa/Casablanca' },
  { name: 'Algeria', code: 'DZ', currency: 'DZD', locale: 'ar_DZ', timezone: 'Africa/Algiers' },
  { name: 'United States', code: 'US', currency: 'USD', locale: 'en_US', timezone: 'America/New_York' },
]

export function CompanyOnboardingPage() {
  const { t } = useTranslation(['common', 'settings'])
  const navigate = useNavigate()
  const invalidateCompanies = useInvalidateCompanies()
  const setCurrentCompany = useCompanyStore((state) => state.setCurrentCompany)

  const [currentStep, setCurrentStep] = useState<Step>('country')
  const [formData, setFormData] = useState({
    countryCode: 'FR',
    name: '',
    legalName: '',
    taxId: '',
    email: '',
    phone: '',
    addressStreet: '',
    addressCity: '',
    addressPostalCode: '',
  })
  const [error, setError] = useState<string | null>(null)

  const stepIndex = STEPS.indexOf(currentStep)
  const selectedCountry = COUNTRIES.find((c) => c.code === formData.countryCode) ?? COUNTRIES[0]

  const mutation = useMutation({
    mutationFn: async (input: CreateCompanyInput) => {
      return createCompany(input)
    },
    onSuccess: (data) => {
      void invalidateCompanies()
      setCurrentCompany(data.id)
      navigate('/dashboard')
    },
    onError: (err: unknown) => {
      setError(getErrorMessage(err))
    },
  })

  const canProceed = () => {
    switch (currentStep) {
      case 'country':
        return !!formData.countryCode
      case 'company':
        return !!formData.name.trim()
      case 'contact':
        return true // Optional fields
      case 'review':
        return true
      default:
        return false
    }
  }

  const nextStep = () => {
    setError(null)
    const nextIndex = stepIndex + 1
    if (nextIndex < STEPS.length) {
      setCurrentStep(STEPS[nextIndex])
    }
  }

  const prevStep = () => {
    setError(null)
    const prevIndex = stepIndex - 1
    if (prevIndex >= 0) {
      setCurrentStep(STEPS[prevIndex])
    }
  }

  const handleSubmit = () => {
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

  return (
    <div className={cn('min-h-screen py-12 px-4 sm:px-6 lg:px-8', tokens.table.header)}>
      <div className="max-w-3xl mx-auto">
        {/* Header */}
        <Link
          to="/dashboard"
          className={cn(
            'inline-flex items-center text-sm mb-4',
            textColors.tertiary,
            textColors.hoverSecondary
          )}
        >
          <ArrowLeft className="w-4 h-4 me-1" />
          {t('common:back')}
        </Link>
        <PageHeader
          title={t('settings:company.modal.title')}
          subtitle={t('settings:company.modal.subtitle')}
          breadcrumb={<Building2 className={cn('w-8 h-8', textColors.brand)} />}
        />

        {/* Progress Steps */}
        <div className="mb-8">
          <div className="flex items-center justify-between">
            {STEPS.map((step, index) => {
              const isActive = index === stepIndex
              const isCompleted = index < stepIndex
              return (
                <div key={step} className="flex items-center flex-1">
                  <div
                    className={cn(
                      'w-10 h-10 rounded-full flex items-center justify-center text-sm font-medium',
                      isActive && cn(colors.primary[600], textColors.inverse),
                      isCompleted && cn(colors.success[600], textColors.inverse),
                      !isActive &&
                        !isCompleted &&
                        cn(colors.neutral[200], textColors.tertiary)
                    )}
                  >
                    {isCompleted ? <Check className="w-5 h-5" /> : index + 1}
                  </div>
                  <span
                    className={cn(
                      'ms-2 text-sm font-medium hidden sm:inline',
                      isActive && textColors.brand,
                      isCompleted && textColors.success,
                      !isActive && !isCompleted && textColors.tertiary
                    )}
                  >
                    {t(`settings:company.modal.steps.${step}`)}
                  </span>
                  {index < STEPS.length - 1 && (
                    <div
                      className={cn(
                        'flex-1 h-0.5 mx-4',
                        isCompleted ? colors.success[600] : colors.neutral[200]
                      )}
                    />
                  )}
                </div>
              )
            })}
          </div>
        </div>

        {/* Error Alert */}
        {error && (
          <div className={cn('mb-6', tokens.alert.base, tokens.alert.error)}>
            {error}
          </div>
        )}

        {/* Step Content */}
        <div className={cn(tokens.card.base, 'p-8 mb-6')}>
          {/* Step 1: Country Selection */}
          {currentStep === 'country' && (
            <div className="space-y-6">
              <div>
                <h2 className={cn(tokens.heading.section, 'mb-2 flex items-center gap-2')}>
                  <Globe className={cn('w-6 h-6', textColors.brand)} />
                  {t('settings:company.modal.selectCountry')}
                </h2>
                <p className={cn('text-sm', textColors.tertiary)}>
                  {t('settings:company.modal.selectCountryHint')}
                </p>
              </div>

              <div className="space-y-3">
                {COUNTRIES.map((country) => (
                  <button
                    key={country.code}
                    type="button"
                    onClick={() => { setFormData({ ...formData, countryCode: country.code }); }}
                    className={cn(
                      'w-full text-start p-4 rounded-lg border-2 transition-all',
                      formData.countryCode === country.code
                        ? cn(borderColors.primary, colors.primary[50])
                        : cn(borderColors.light, borderColors.hover)
                    )}
                  >
                    <div className="flex items-center justify-between">
                      <div>
                        <p className={cn('font-medium', textColors.primary)}>{country.name}</p>
                        <p className={cn('text-sm', textColors.tertiary)}>
                          {country.currency} • {country.timezone}
                        </p>
                      </div>
                      {formData.countryCode === country.code && (
                        <Check className={cn('w-5 h-5', textColors.brand)} />
                      )}
                    </div>
                  </button>
                ))}
              </div>
            </div>
          )}

          {/* Step 2: Company Information */}
          {currentStep === 'company' && (
            <div className="space-y-6">
              <div>
                <h2 className={cn(tokens.heading.section, 'mb-2 flex items-center gap-2')}>
                  <Building2 className={cn('w-6 h-6', textColors.brand)} />
                  {t('settings:company.sections.information')}
                </h2>
                <p className={cn('text-sm', textColors.tertiary)}>
                  {t('settings:company.modal.enterCompanyDetails')}
                </p>
              </div>

              <div className="space-y-4">
                <FormField
                  label={t('settings:company.fields.companyName')}
                  htmlFor="name"
                  required
                >
                  <Input
                    type="text"
                    id="name"
                    value={formData.name}
                    onChange={(e) => { setFormData({ ...formData, name: e.target.value }); }}
                    placeholder={t('settings:company.modal.namePlaceholder')}
                    autoFocus
                  />
                </FormField>

                <FormField
                  label={t('settings:company.fields.legalName')}
                  htmlFor="legalName"
                  helperText={t('settings:company.modal.legalNameHint')}
                >
                  <Input
                    type="text"
                    id="legalName"
                    value={formData.legalName}
                    onChange={(e) => { setFormData({ ...formData, legalName: e.target.value }); }}
                    placeholder={t('settings:company.modal.legalNamePlaceholder')}
                  />
                </FormField>

                <FormField
                  label={t('settings:company.fields.taxId')}
                  htmlFor="taxId"
                >
                  <Input
                    type="text"
                    id="taxId"
                    value={formData.taxId}
                    onChange={(e) => { setFormData({ ...formData, taxId: e.target.value }); }}
                    placeholder={t('settings:company.modal.taxIdPlaceholder')}
                  />
                </FormField>
              </div>
            </div>
          )}

          {/* Step 3: Contact Information */}
          {currentStep === 'contact' && (
            <div className="space-y-6">
              <div>
                <h2 className={cn(tokens.heading.section, 'mb-2 flex items-center gap-2')}>
                  <Mail className={cn('w-6 h-6', textColors.brand)} />
                  {t('settings:company.sections.contact')}
                </h2>
                <p className={cn('text-sm', textColors.tertiary)}>
                  {t('settings:company.modal.contactInfoHint')}
                </p>
              </div>

              <div className="space-y-4">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <FormField label={t('settings:company.fields.email')} htmlFor="email">
                    <Input
                      type="email"
                      id="email"
                      value={formData.email}
                      onChange={(e) => { setFormData({ ...formData, email: e.target.value }); }}
                      placeholder={t('settings:company.modal.emailPlaceholder')}
                    />
                  </FormField>

                  <FormField label={t('settings:company.fields.phone')} htmlFor="phone">
                    <Input
                      type="tel"
                      id="phone"
                      value={formData.phone}
                      onChange={(e) => { setFormData({ ...formData, phone: e.target.value }); }}
                      placeholder={t('settings:company.modal.phonePlaceholder')}
                    />
                  </FormField>
                </div>

                <FormField label={t('settings:company.fields.street')} htmlFor="addressStreet">
                  <Input
                    type="text"
                    id="addressStreet"
                    value={formData.addressStreet}
                    onChange={(e) => { setFormData({ ...formData, addressStreet: e.target.value }); }}
                    placeholder={t('settings:company.modal.streetPlaceholder')}
                  />
                </FormField>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <FormField label={t('settings:company.fields.city')} htmlFor="addressCity">
                    <Input
                      type="text"
                      id="addressCity"
                      value={formData.addressCity}
                      onChange={(e) => { setFormData({ ...formData, addressCity: e.target.value }); }}
                      placeholder={t('settings:company.modal.cityPlaceholder')}
                    />
                  </FormField>

                  <FormField
                    label={t('settings:company.fields.postalCode')}
                    htmlFor="addressPostalCode"
                  >
                    <Input
                      type="text"
                      id="addressPostalCode"
                      value={formData.addressPostalCode}
                      onChange={(e) => { setFormData({ ...formData, addressPostalCode: e.target.value }); }}
                      placeholder={t('settings:company.modal.postalCodePlaceholder')}
                    />
                  </FormField>
                </div>
              </div>
            </div>
          )}

          {/* Step 4: Review */}
          {currentStep === 'review' && (
            <div className="space-y-6">
              <div>
                <h2 className={cn(tokens.heading.section, 'mb-2 flex items-center gap-2')}>
                  <Check className={cn('w-6 h-6', textColors.brand)} />
                  {t('settings:company.modal.reviewTitle')}
                </h2>
                <p className={cn('text-sm', textColors.tertiary)}>
                  {t('settings:company.modal.reviewHint')}
                </p>
              </div>

              <div className="space-y-4">
                <div className={cn('rounded-lg p-4', colors.neutral[50])}>
                  <h3 className={cn('font-semibold mb-3', textColors.primary)}>{t('settings:company.modal.regionalSettings')}</h3>
                  <dl className="grid grid-cols-1 gap-2 text-sm">
                    <div className="flex justify-between">
                      <dt className={textColors.tertiary}>{t('settings:company.fields.country')}:</dt>
                      <dd className="font-medium">{selectedCountry.name}</dd>
                    </div>
                    <div className="flex justify-between">
                      <dt className={textColors.tertiary}>{t('settings:company.fields.currency')}:</dt>
                      <dd className="font-medium">{selectedCountry.currency}</dd>
                    </div>
                    <div className="flex justify-between">
                      <dt className={textColors.tertiary}>{t('settings:company.fields.timezone')}:</dt>
                      <dd className="font-medium">{selectedCountry.timezone}</dd>
                    </div>
                  </dl>
                </div>

                <div className={cn('rounded-lg p-4', colors.neutral[50])}>
                  <h3 className={cn('font-semibold mb-3', textColors.primary)}>{t('settings:company.sections.information')}</h3>
                  <dl className="grid grid-cols-1 gap-2 text-sm">
                    <div className="flex justify-between">
                      <dt className={textColors.tertiary}>{t('settings:company.fields.companyName')}:</dt>
                      <dd className="font-medium">{formData.name}</dd>
                    </div>
                    {formData.legalName && (
                      <div className="flex justify-between">
                        <dt className={textColors.tertiary}>{t('settings:company.fields.legalName')}:</dt>
                        <dd className="font-medium">{formData.legalName}</dd>
                      </div>
                    )}
                    {formData.taxId && (
                      <div className="flex justify-between">
                        <dt className={textColors.tertiary}>{t('settings:company.fields.taxId')}:</dt>
                        <dd className="font-medium">{formData.taxId}</dd>
                      </div>
                    )}
                  </dl>
                </div>

                {(formData.email || formData.phone || formData.addressStreet) && (
                  <div className={cn('rounded-lg p-4', colors.neutral[50])}>
                    <h3 className={cn('font-semibold mb-3', textColors.primary)}>{t('settings:company.sections.contact')}</h3>
                    <dl className="grid grid-cols-1 gap-2 text-sm">
                      {formData.email && (
                        <div className="flex justify-between">
                          <dt className={textColors.tertiary}>{t('settings:company.fields.email')}:</dt>
                          <dd className="font-medium">{formData.email}</dd>
                        </div>
                      )}
                      {formData.phone && (
                        <div className="flex justify-between">
                          <dt className={textColors.tertiary}>{t('settings:company.fields.phone')}:</dt>
                          <dd className="font-medium">{formData.phone}</dd>
                        </div>
                      )}
                      {formData.addressStreet && (
                        <div>
                          <dt className={cn('mb-1', textColors.tertiary)}>{t('settings:company.fields.street')}:</dt>
                          <dd className="font-medium">
                            {formData.addressStreet}
                            {(formData.addressCity || formData.addressPostalCode) && (
                              <>
                                <br />
                                {[formData.addressPostalCode, formData.addressCity].filter(Boolean).join(' ')}
                              </>
                            )}
                          </dd>
                        </div>
                      )}
                    </dl>
                  </div>
                )}
              </div>
            </div>
          )}
        </div>

        {/* Navigation */}
        <div className="flex justify-between">
          <Button
            type="button"
            variant="secondary"
            size="lg"
            onClick={prevStep}
            disabled={stepIndex === 0 || mutation.isPending}
          >
            <ArrowLeft className="w-4 h-4 me-2" />
            {t('common:previous')}
          </Button>

          {currentStep === 'review' ? (
            <Button
              type="button"
              variant="primary"
              size="lg"
              onClick={handleSubmit}
              disabled={!canProceed() || mutation.isPending}
            >
              {mutation.isPending && <Loader2 className="w-4 h-4 me-2 animate-spin" />}
              {mutation.isPending ? t('common:status.creating') : t('settings:company.modal.createButton')}
            </Button>
          ) : (
            <Button
              type="button"
              variant="primary"
              size="lg"
              onClick={nextStep}
              disabled={!canProceed()}
            >
              {t('common:next')}
              <ArrowRight className="w-4 h-4 ms-2" />
            </Button>
          )}
        </div>
      </div>
    </div>
  )
}
