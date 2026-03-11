import { useState } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { UserPlus, AlertCircle, Building2, User, CheckCircle, ChevronRight, ChevronLeft, Store } from 'lucide-react'
import { api, ensureCsrfCookie } from '../../lib/api'
import { useAuthStore } from '../../stores/authStore'
import { useProductConfig } from '../../contexts/ProductConfigContext'

interface Country {
  code: string
  name: string
  flag: string
}

interface RegisterFormData {
  // Step 1: Account Details
  name: string
  email: string
  password: string
  password_confirmation: string
  // Step 2: Company Information
  country_code: string
  company_name: string
  company_legal_name: string
  tax_id: string
  phone: string
  // Step 3: Vertical Selection
  vertical: string
  // Step 4: Review
  acceptTerms: boolean
}

interface RegisterResponseUser {
  id: string
  name: string
  email: string
  tenantId: string
  roles: string[]
}

interface RegisterResponse {
  data: {
    user: RegisterResponseUser
    token: string
    tokenType: string
  }
}

interface FormErrors {
  name?: string
  email?: string
  password?: string
  password_confirmation?: string
  country_code?: string
  company_name?: string
  company_legal_name?: string
  tax_id?: string
  phone?: string
  vertical?: string
  acceptTerms?: string
  general?: string
}

const WIZARD_STEPS = ['account', 'company', 'vertical', 'review'] as const
type WizardStep = typeof WIZARD_STEPS[number]

export function RegisterPage() {
  const { t } = useTranslation(['auth', 'validation', 'common'])
  const navigate = useNavigate()
  const setAuth = useAuthStore((state) => state.setAuth)
  const { productName } = useProductConfig()

  const [currentStep, setCurrentStep] = useState<WizardStep>('account')
  const [formData, setFormData] = useState<RegisterFormData>({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    country_code: '',
    company_name: '',
    company_legal_name: '',
    tax_id: '',
    phone: '',
    vertical: '',
    acceptTerms: false,
  })
  const [errors, setErrors] = useState<FormErrors>({})

  // Fetch countries for dropdown
  const { data: countries = [] } = useQuery<Country[]>({
    queryKey: ['countries'],
    queryFn: async () => {
      const response = await api.get<{ data: Country[] }>('/countries')
      return response.data.data
    },
  })

  const registerMutation = useMutation({
    mutationFn: async (data: RegisterFormData) => {
      // First, ensure CSRF cookie is set (required for Sanctum SPA auth)
      await ensureCsrfCookie()
      const payload = {
        name: data.name,
        email: data.email,
        password: data.password,
        password_confirmation: data.password_confirmation,
        country_code: data.country_code,
        company_name: data.company_name,
        company_legal_name: data.company_legal_name || undefined,
        tax_id: data.tax_id || undefined,
        phone: data.phone || undefined,
        vertical: data.vertical,
        platform: 'web',
      }
      const response = await api.post<RegisterResponse>('/auth/register', payload)
      return response.data.data
    },
    onSuccess: (data) => {
      // Cookie is set automatically by Sanctum - just update UI state
      const user = {
        id: data.user.id,
        name: data.user.name,
        email: data.user.email,
        tenant_id: data.user.tenantId,
        roles: data.user.roles,
        email_verified_at: null, // New users are not verified yet
      }
      setAuth(user)
      void navigate('/', { replace: true })
    },
    onError: (error: unknown) => {
      const apiError = error as { response?: { data?: { error?: { message?: string } } } }
      const message = apiError.response?.data?.error?.message ?? t('errors.registrationFailed')
      setErrors({ general: message })
    },
  })

  const validateStep = (step: WizardStep): boolean => {
    const newErrors: FormErrors = {}

    if (step === 'account') {
      if (!formData.name.trim()) {
        newErrors.name = t('validation:required')
      }
      if (!formData.email.trim()) {
        newErrors.email = t('validation:required')
      } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(formData.email)) {
        newErrors.email = t('validation:email')
      }
      if (!formData.password.trim()) {
        newErrors.password = t('validation:required')
      } else if (formData.password.length < 8) {
        newErrors.password = t('validation:minLength', { count: 8 })
      }
      if (!formData.password_confirmation.trim()) {
        newErrors.password_confirmation = t('validation:required')
      } else if (formData.password !== formData.password_confirmation) {
        newErrors.password_confirmation = t('register.passwordMismatch')
      }
    }

    if (step === 'company') {
      if (!formData.country_code) {
        newErrors.country_code = t('validation:required')
      }
      if (!formData.company_name.trim()) {
        newErrors.company_name = t('validation:required')
      }
    }

    if (step === 'vertical') {
      if (!formData.vertical) {
        newErrors.vertical = t('auth:vertical.required')
      }
    }

    if (step === 'review') {
      if (!formData.acceptTerms) {
        newErrors.acceptTerms = t('register.acceptTermsRequired')
      }
    }

    setErrors(newErrors)
    return Object.keys(newErrors).length === 0
  }

  const handleNext = () => {
    if (!validateStep(currentStep)) return

    const currentIndex = WIZARD_STEPS.indexOf(currentStep)
    if (currentIndex < WIZARD_STEPS.length - 1) {
      setCurrentStep(WIZARD_STEPS[currentIndex + 1])
    }
  }

  const handleBack = () => {
    const currentIndex = WIZARD_STEPS.indexOf(currentStep)
    if (currentIndex > 0) {
      setCurrentStep(WIZARD_STEPS[currentIndex - 1])
    }
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!validateStep('review')) return
    registerMutation.mutate(formData)
  }

  const handleChange = (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
    const { name, value, type } = e.target
    const newValue = type === 'checkbox' ? (e.target as HTMLInputElement).checked : value
    setFormData((prev) => ({ ...prev, [name]: newValue }))
    if (errors[name as keyof FormErrors]) {
      setErrors((prev) => ({ ...prev, [name]: undefined }))
    }
  }

  const stepIndex = WIZARD_STEPS.indexOf(currentStep)

  const renderStepIndicator = () => (
    <div className="flex items-center justify-center mb-8">
      {WIZARD_STEPS.map((step, index) => (
        <div key={step} className="flex items-center">
          <div
            className={`flex items-center justify-center w-10 h-10 rounded-full border-2 transition-colors ${
              index < stepIndex
                ? 'bg-green-500 border-green-500 text-white'
                : index === stepIndex
                  ? 'bg-blue-600 border-blue-600 text-white'
                  : 'bg-white border-gray-300 text-gray-500'
            }`}
          >
            {index < stepIndex ? (
              <CheckCircle className="w-5 h-5" />
            ) : index === 0 ? (
              <User className="w-5 h-5" />
            ) : index === 1 ? (
              <Building2 className="w-5 h-5" />
            ) : index === 2 ? (
              <Store className="w-5 h-5" />
            ) : (
              <CheckCircle className="w-5 h-5" />
            )}
          </div>
          <div
            className={`ms-2 me-4 text-sm font-medium ${
              index <= stepIndex ? 'text-gray-900' : 'text-gray-500'
            }`}
          >
            {t(`register.step${index + 1}Title`)}
          </div>
          {index < WIZARD_STEPS.length - 1 && (
            <div
              className={`w-12 h-0.5 me-4 ${
                index < stepIndex ? 'bg-green-500' : 'bg-gray-300'
              }`}
            />
          )}
        </div>
      ))}
    </div>
  )

  const renderAccountStep = () => (
    <div className="space-y-4">
      <div>
        <label htmlFor="name" className="block text-sm font-medium text-gray-700">
          {t('register.name')}
        </label>
        <input
          id="name"
          name="name"
          type="text"
          autoComplete="name"
          value={formData.name}
          onChange={handleChange}
          className={`mt-1 block w-full rounded-lg border px-3 py-2 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 ${
            errors.name ? 'border-red-300' : 'border-gray-300'
          }`}
        />
        {errors.name && <p className="mt-1 text-sm text-red-600">{errors.name}</p>}
      </div>

      <div>
        <label htmlFor="email" className="block text-sm font-medium text-gray-700">
          {t('register.email')}
        </label>
        <input
          id="email"
          name="email"
          type="email"
          autoComplete="email"
          value={formData.email}
          onChange={handleChange}
          className={`mt-1 block w-full rounded-lg border px-3 py-2 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 ${
            errors.email ? 'border-red-300' : 'border-gray-300'
          }`}
        />
        {errors.email && <p className="mt-1 text-sm text-red-600">{errors.email}</p>}
      </div>

      <div>
        <label htmlFor="password" className="block text-sm font-medium text-gray-700">
          {t('register.password')}
        </label>
        <input
          id="password"
          name="password"
          type="password"
          autoComplete="new-password"
          value={formData.password}
          onChange={handleChange}
          className={`mt-1 block w-full rounded-lg border px-3 py-2 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 ${
            errors.password ? 'border-red-300' : 'border-gray-300'
          }`}
        />
        {errors.password && <p className="mt-1 text-sm text-red-600">{errors.password}</p>}
        <p className="mt-1 text-xs text-gray-500">{t('register.passwordHint')}</p>
      </div>

      <div>
        <label htmlFor="password_confirmation" className="block text-sm font-medium text-gray-700">
          {t('register.confirmPassword')}
        </label>
        <input
          id="password_confirmation"
          name="password_confirmation"
          type="password"
          autoComplete="new-password"
          value={formData.password_confirmation}
          onChange={handleChange}
          className={`mt-1 block w-full rounded-lg border px-3 py-2 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 ${
            errors.password_confirmation ? 'border-red-300' : 'border-gray-300'
          }`}
        />
        {errors.password_confirmation && (
          <p className="mt-1 text-sm text-red-600">{errors.password_confirmation}</p>
        )}
      </div>
    </div>
  )

  const renderCompanyStep = () => (
    <div className="space-y-4">
      <div>
        <label htmlFor="country_code" className="block text-sm font-medium text-gray-700">
          {t('register.country')}
        </label>
        <select
          id="country_code"
          name="country_code"
          value={formData.country_code}
          onChange={handleChange}
          className={`mt-1 block w-full rounded-lg border px-3 py-2 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 ${
            errors.country_code ? 'border-red-300' : 'border-gray-300'
          }`}
        >
          <option value="">{t('register.selectCountry')}</option>
          {countries.map((country) => (
            <option key={country.code} value={country.code}>
              {country.flag} {country.name}
            </option>
          ))}
        </select>
        {errors.country_code && <p className="mt-1 text-sm text-red-600">{errors.country_code}</p>}
      </div>

      <div>
        <label htmlFor="company_name" className="block text-sm font-medium text-gray-700">
          {t('register.companyName')}
        </label>
        <input
          id="company_name"
          name="company_name"
          type="text"
          value={formData.company_name}
          onChange={handleChange}
          className={`mt-1 block w-full rounded-lg border px-3 py-2 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 ${
            errors.company_name ? 'border-red-300' : 'border-gray-300'
          }`}
        />
        {errors.company_name && <p className="mt-1 text-sm text-red-600">{errors.company_name}</p>}
      </div>

      <div>
        <label htmlFor="company_legal_name" className="block text-sm font-medium text-gray-700">
          {t('register.companyLegalName')}
          <span className="text-gray-400 font-normal ms-1">({t('common:optional')})</span>
        </label>
        <input
          id="company_legal_name"
          name="company_legal_name"
          type="text"
          value={formData.company_legal_name}
          onChange={handleChange}
          className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
        />
      </div>

      <div>
        <label htmlFor="tax_id" className="block text-sm font-medium text-gray-700">
          {t('register.taxId')}
          <span className="text-gray-400 font-normal ms-1">({t('common:optional')})</span>
        </label>
        <input
          id="tax_id"
          name="tax_id"
          type="text"
          value={formData.tax_id}
          onChange={handleChange}
          className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
        />
      </div>

      <div>
        <label htmlFor="phone" className="block text-sm font-medium text-gray-700">
          {t('register.phone')}
          <span className="text-gray-400 font-normal ms-1">({t('common:optional')})</span>
        </label>
        <input
          id="phone"
          name="phone"
          type="tel"
          value={formData.phone}
          onChange={handleChange}
          className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
        />
      </div>
    </div>
  )

  const renderVerticalStep = () => {
    const verticals = [
      { value: 'retail', icon: '🏪' },
      { value: 'pharmacy', icon: '💊' },
      { value: 'restaurant', icon: '🍽️' },
      { value: 'coffee_shop', icon: '☕' },
      { value: 'fashion', icon: '👔' },
      { value: 'parapharmacy', icon: '🧴' },
    ]

    return (
      <div className="space-y-4">
        <div className="text-center mb-6">
          <h3 className="text-lg font-semibold text-gray-900">{t('auth:vertical.title')}</h3>
          <p className="mt-2 text-sm text-gray-600">{t('auth:vertical.description')}</p>
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          {verticals.map((vertical) => (
            <button
              key={vertical.value}
              type="button"
              onClick={() => {
                setFormData((prev) => ({ ...prev, vertical: vertical.value }))
                if (errors.vertical) {
                  setErrors((prev) => ({ ...prev, vertical: undefined }))
                }
              }}
              className={`rounded-lg border-2 p-4 text-left transition-all hover:shadow-md ${
                formData.vertical === vertical.value
                  ? 'border-blue-500 bg-blue-50 shadow-md'
                  : 'border-gray-300 hover:border-gray-400'
              }`}
            >
              <div className="flex items-start space-x-3">
                <span className="text-3xl">{vertical.icon}</span>
                <div className="flex-1">
                  <h4 className="font-semibold text-gray-900">
                    {t(`auth:verticals.${vertical.value}.label`)}
                  </h4>
                  <p className="mt-1 text-sm text-gray-600">
                    {t(`auth:verticals.${vertical.value}.description`)}
                  </p>
                </div>
              </div>
            </button>
          ))}
        </div>

        {errors.vertical && <p className="mt-2 text-sm text-red-600">{errors.vertical}</p>}
      </div>
    )
  }

  const selectedCountry = countries.find((c) => c.code === formData.country_code)

  const renderReviewStep = () => (
    <div className="space-y-6">
      <div className="bg-gray-50 rounded-lg p-4">
        <h3 className="text-sm font-medium text-gray-900 mb-3">{t('register.step1Title')}</h3>
        <dl className="space-y-2 text-sm">
          <div className="flex justify-between">
            <dt className="text-gray-500">{t('register.name')}</dt>
            <dd className="text-gray-900">{formData.name}</dd>
          </div>
          <div className="flex justify-between">
            <dt className="text-gray-500">{t('register.email')}</dt>
            <dd className="text-gray-900">{formData.email}</dd>
          </div>
        </dl>
      </div>

      <div className="bg-gray-50 rounded-lg p-4">
        <h3 className="text-sm font-medium text-gray-900 mb-3">{t('register.step2Title')}</h3>
        <dl className="space-y-2 text-sm">
          <div className="flex justify-between">
            <dt className="text-gray-500">{t('register.country')}</dt>
            <dd className="text-gray-900">
              {selectedCountry ? `${selectedCountry.flag} ${selectedCountry.name}` : '-'}
            </dd>
          </div>
          <div className="flex justify-between">
            <dt className="text-gray-500">{t('register.companyName')}</dt>
            <dd className="text-gray-900">{formData.company_name}</dd>
          </div>
          {formData.company_legal_name && (
            <div className="flex justify-between">
              <dt className="text-gray-500">{t('register.companyLegalName')}</dt>
              <dd className="text-gray-900">{formData.company_legal_name}</dd>
            </div>
          )}
          {formData.tax_id && (
            <div className="flex justify-between">
              <dt className="text-gray-500">{t('register.taxId')}</dt>
              <dd className="text-gray-900">{formData.tax_id}</dd>
            </div>
          )}
          {formData.phone && (
            <div className="flex justify-between">
              <dt className="text-gray-500">{t('register.phone')}</dt>
              <dd className="text-gray-900">{formData.phone}</dd>
            </div>
          )}
        </dl>
      </div>

      <div className="bg-gray-50 rounded-lg p-4">
        <h3 className="text-sm font-medium text-gray-900 mb-3">{t('register.step3Title')}</h3>
        <dl className="space-y-2 text-sm">
          <div className="flex justify-between">
            <dt className="text-gray-500">{t('auth:vertical.label')}</dt>
            <dd className="text-gray-900">{t(`auth:verticals.${formData.vertical}.label`)}</dd>
          </div>
        </dl>
      </div>

      <div className="flex items-start">
        <input
          id="acceptTerms"
          name="acceptTerms"
          type="checkbox"
          checked={formData.acceptTerms}
          onChange={handleChange}
          className={`h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 ${
            errors.acceptTerms ? 'border-red-300' : ''
          }`}
        />
        <label htmlFor="acceptTerms" className="ms-2 text-sm text-gray-600">
          {t('register.acceptTerms')}
        </label>
      </div>
      {errors.acceptTerms && <p className="text-sm text-red-600">{errors.acceptTerms}</p>}
    </div>
  )

  return (
    <div className="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
      <div className="max-w-2xl w-full space-y-8">
        <div>
          <h1 className="text-3xl font-bold text-center text-gray-900">{productName}</h1>
          <h2 className="mt-6 text-center text-xl font-semibold text-gray-700">
            {t('register.title')}
          </h2>
        </div>

        {renderStepIndicator()}

        <form className="bg-white shadow-lg rounded-xl p-8" onSubmit={handleSubmit}>
          {errors.general && (
            <div className="rounded-md bg-red-50 p-4 mb-6">
              <div className="flex">
                <AlertCircle className="h-5 w-5 text-red-400" />
                <div className="ms-3">
                  <p className="text-sm font-medium text-red-800">{errors.general}</p>
                </div>
              </div>
            </div>
          )}

          {currentStep === 'account' && renderAccountStep()}
          {currentStep === 'company' && renderCompanyStep()}
          {currentStep === 'vertical' && renderVerticalStep()}
          {currentStep === 'review' && renderReviewStep()}

          <div className="flex justify-between mt-8">
            {stepIndex > 0 ? (
              <button
                type="button"
                onClick={handleBack}
                className="flex items-center px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
              >
                <ChevronLeft className="w-4 h-4 me-1" />
                {t('common:back')}
              </button>
            ) : (
              <div />
            )}

            {currentStep !== 'review' ? (
              <button
                type="button"
                onClick={handleNext}
                className="flex items-center px-6 py-2 text-sm font-medium text-white bg-blue-600 border border-transparent rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
              >
                {t('common:next')}
                <ChevronRight className="w-4 h-4 ms-1" />
              </button>
            ) : (
              <button
                type="submit"
                disabled={registerMutation.isPending}
                className="flex items-center px-6 py-2 text-sm font-medium text-white bg-blue-600 border border-transparent rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed"
              >
                <UserPlus className="w-4 h-4 me-2" />
                {registerMutation.isPending ? t('register.creating') : t('register.createAccount')}
              </button>
            )}
          </div>
        </form>

        <p className="text-center text-sm text-gray-600">
          {t('register.alreadyHaveAccount')}{' '}
          <Link to="/login" className="font-medium text-blue-600 hover:text-blue-500">
            {t('register.signIn')}
          </Link>
        </p>
      </div>
    </div>
  )
}
