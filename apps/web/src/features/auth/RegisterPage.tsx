import { useEffect, useRef } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { ChevronRight, ChevronLeft } from 'lucide-react'
import { toast } from 'sonner'
import { api, ensureCsrfCookie, getErrorMessage } from '../../lib/api'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
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
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

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
      void queryClient.invalidateQueries({ queryKey: tenantScopedKey(['auth', 'me']) })
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
        <div className={`${colorTokens.intent.ledger.bgInverse} px-6 py-4 md:hidden`}>
          <h1 className={`text-lg font-bold ${colorTokens.text.inverse}`}>{productName}</h1>
          <p className={`text-sm ${colorTokens.intent.ledger.textSubtle}`}>
            {t(`auth:brandPanel.${product}.tagline` as const)}
          </p>
        </div>

        <div ref={formContainerRef} className="mx-auto w-full max-w-lg px-6 py-8 md:py-12">
          <RegisterProgress currentStep={currentStep} totalSteps={TOTAL_STEPS} />

          <h2 className={`text-xl font-semibold ${colorTokens.text.primary}`}>{t(stepTitleKey)}</h2>
          <p className={`mt-1 mb-6 text-sm ${colorTokens.text.subtle}`}>{t(stepSubtitleKey)}</p>

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
                  className={`flex items-center rounded-lg border ${colorTokens.border.default} bg-white px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} hover:${colorTokens.surface.page} focus:outline-none focus:ring-2 ${colorTokens.focus.primaryRing} focus:ring-offset-2`}
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
                className={`flex items-center rounded-lg ${colorTokens.intent.primary.bgStrong} px-6 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.primary.bgStrongHover} focus:outline-none focus:ring-2 ${colorTokens.focus.primaryRing} focus:ring-offset-2`}
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
                className={`flex items-center text-sm ${colorTokens.text.subtle} ${colorTokens.intent.neutral.textHoverStrong}`}
              >
                <ChevronLeft className="mr-1 h-4 w-4" />
                {t('auth:register.back')}
              </button>
            </div>
          )}

          {/* Sign in link */}
          <p className={`mt-6 text-center text-sm ${colorTokens.text.muted}`}>
            {t('auth:register.alreadyHaveAccount')}{' '}
            <Link to="/login" className={`font-medium ${colorTokens.intent.primary.text} ${colorTokens.intent.primary.textHoverSubtle}`}>
              {t('auth:register.signIn')}
            </Link>
          </p>
        </div>
      </div>
    </div>
  )
}
