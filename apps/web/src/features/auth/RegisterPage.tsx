import { useEffect, useRef } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { ChevronRight, ChevronLeft } from 'lucide-react'
import { toast } from 'sonner'
import { api, ensureCsrfCookie, getErrorMessage } from '../../lib/api'
import { useAuthStore } from '../../stores/authStore'
import { useProductConfig } from '../../contexts/ProductConfigContext'
import { useRegisterForm } from './hooks/useRegisterForm'
import { useCountryDetect } from './hooks/useCountryDetect'
import { RegisterProgress } from './components/RegisterProgress'
import { RegisterBrandPanel } from './components/RegisterBrandPanel'
import { AccountStep } from './components/AccountStep'
import { BusinessStep } from './components/BusinessStep'
import { CompanyStep } from './components/CompanyStep'
import { ReviewStep } from './components/ReviewStep'

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

const TOTAL_STEPS = 4

export function RegisterPage() {
  const { t } = useTranslation(['auth', 'common'])
  const navigate = useNavigate()
  const setAuth = useAuthStore((state) => state.setAuth)
  const queryClient = useQueryClient()
  const { product, productName } = useProductConfig()
  const formContainerRef = useRef<HTMLDivElement>(null)

  const {
    currentStep,
    formData,
    errors,
    updateField,
    goToNext,
    goBack,
    goToStep,
    buildPayload,
  } = useRegisterForm()

  const { detectedCountry } = useCountryDetect()

  // Auto-fill country when detected and not already set
  useEffect(() => {
    if (detectedCountry && !formData.countryCode) {
      updateField('countryCode', detectedCountry)
    }
  }, [detectedCountry, formData.countryCode, updateField])

  // Hash navigation
  useEffect(() => {
    window.location.hash = `#step-${currentStep}`
  }, [currentStep])

  // Focus first input on step change
  useEffect(() => {
    const container = formContainerRef.current
    if (!container) return
    const firstInput = container.querySelector<HTMLElement>('input, select')
    if (firstInput) {
      firstInput.focus()
    }
  }, [currentStep])

  const registerMutation = useMutation({
    mutationFn: async () => {
      await ensureCsrfCookie()
      const payload = buildPayload()
      const response = await api.post<RegisterResponse>('/auth/register', payload)
      return response.data.data
    },
    onSuccess: (data) => {
      const user = {
        id: data.user.id,
        name: data.user.name,
        email: data.user.email,
        tenant_id: data.user.tenantId,
        roles: data.user.roles,
        email_verified_at: null,
      }
      setAuth(user, data.token)
      void queryClient.invalidateQueries({ queryKey: ['auth', 'me'] })
      void navigate('/', { replace: true })
    },
    onError: (error: unknown) => {
      const message = getErrorMessage(error)
      toast.error(message || t('auth:errors.registrationFailed'))
    },
  })

  function handleSubmit(): void {
    registerMutation.mutate()
  }

  function handleNext(): void {
    goToNext()
  }

  const stepTitleKey = `auth:register.step${currentStep}Title` as const
  const stepSubtitleKey = `auth:register.step${currentStep}Subtitle` as const

  return (
    <div className="flex min-h-screen">
      {/* Brand panel — hidden on mobile */}
      <RegisterBrandPanel />

      {/* Form panel */}
      <div className="flex flex-1 flex-col">
        {/* Mobile header — visible only on mobile */}
        <div className="bg-slate-900 px-6 py-4 md:hidden">
          <h1 className="text-lg font-bold text-white">{productName}</h1>
          <p className="text-sm text-slate-400">
            {t(`auth:brandPanel.${product}.tagline` as const)}
          </p>
        </div>

        <div ref={formContainerRef} className="mx-auto w-full max-w-lg px-6 py-8 md:py-12">
          <RegisterProgress currentStep={currentStep} totalSteps={TOTAL_STEPS} />

          <h2 className="text-xl font-semibold text-gray-900">{t(stepTitleKey)}</h2>
          <p className="mt-1 mb-6 text-sm text-gray-500">{t(stepSubtitleKey)}</p>

          {currentStep === 1 && (
            <AccountStep
              formData={formData}
              errors={errors}
              updateField={updateField}
            />
          )}
          {currentStep === 2 && (
            <BusinessStep
              formData={formData}
              errors={errors}
              updateField={updateField}
            />
          )}
          {currentStep === 3 && (
            <CompanyStep
              formData={formData}
              errors={errors}
              updateField={updateField}
            />
          )}
          {currentStep === 4 && (
            <ReviewStep
              formData={formData}
              errors={errors}
              updateField={updateField}
              onGoToStep={goToStep}
              onSubmit={handleSubmit}
              isSubmitting={registerMutation.isPending}
            />
          )}

          {/* Navigation buttons */}
          {currentStep < TOTAL_STEPS && (
            <div className="mt-8 flex justify-between">
              {currentStep > 1 ? (
                <button
                  type="button"
                  onClick={goBack}
                  className="flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                >
                  <ChevronLeft className="mr-1 h-4 w-4" />
                  {t('auth:register.back')}
                </button>
              ) : (
                <div />
              )}
              <button
                type="button"
                onClick={handleNext}
                className="flex items-center rounded-lg bg-blue-600 px-6 py-2 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
              >
                {t('auth:register.next')}
                <ChevronRight className="ml-1 h-4 w-4" />
              </button>
            </div>
          )}

          {currentStep === TOTAL_STEPS && currentStep > 1 && (
            <div className="mt-4">
              <button
                type="button"
                onClick={goBack}
                className="flex items-center text-sm text-gray-500 hover:text-gray-700"
              >
                <ChevronLeft className="mr-1 h-4 w-4" />
                {t('auth:register.back')}
              </button>
            </div>
          )}

          {/* Sign in link */}
          <p className="mt-6 text-center text-sm text-gray-600">
            {t('auth:register.alreadyHaveAccount')}{' '}
            <Link to="/login" className="font-medium text-blue-600 hover:text-blue-500">
              {t('auth:register.signIn')}
            </Link>
          </p>
        </div>
      </div>
    </div>
  )
}
