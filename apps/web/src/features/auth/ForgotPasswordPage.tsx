import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Mail, AlertCircle, ArrowLeft, CheckCircle } from 'lucide-react'
import { api, ensureCsrfCookie } from '../../lib/api'
import { useProductConfig } from '../../contexts/ProductConfigContext'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

export function ForgotPasswordPage() {
  const { t } = useTranslation(['auth', 'validation'])
  const { productName } = useProductConfig()

  const [email, setEmail] = useState('')
  const [emailError, setEmailError] = useState<string | undefined>()

  const forgotPasswordMutation = useMutation({
    mutationFn: async (emailAddress: string) => {
      await ensureCsrfCookie()
      await api.post('/auth/forgot-password', { email: emailAddress })
    },
    onError: () => {
      // intentionally empty — we always show success to prevent email enumeration
    },
  })

  const validateForm = (): boolean => {
    if (!email.trim()) {
      setEmailError(t('validation:required'))
      return false
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      setEmailError(t('validation:email'))
      return false
    }
    setEmailError(undefined)
    return true
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (validateForm()) {
      forgotPasswordMutation.mutate(email)
    }
  }

  return (
    <div className={`min-h-screen flex items-center justify-center ${colorTokens.surface.page} py-12 px-4 sm:px-6 lg:px-8`}>
      <div className="max-w-md w-full space-y-8">
        <div>
          <h1 className={`text-3xl font-bold text-center ${colorTokens.text.primary}`}>{productName}</h1>
          <h2 className={`mt-6 text-center text-xl font-semibold ${colorTokens.text.secondary}`}>
            {t('forgotPassword.title')}
          </h2>
          <p className={`mt-2 text-center text-sm ${colorTokens.text.subtle}`}>
            {t('forgotPassword.subtitle')}
          </p>
        </div>

        {forgotPasswordMutation.isSuccess ? (
          <div className={`rounded-md ${colorTokens.intent.success.bgSubtle} p-4`}>
            <div className="flex">
              <CheckCircle className={`h-5 w-5 ${colorTokens.intent.success.textFaint}`} />
              <div className="ms-3">
                <p className={`text-sm font-medium ${colorTokens.intent.success.textStronger}`}>
                  {t('forgotPassword.success')}
                </p>
              </div>
            </div>
            <div className="mt-6 text-center">
              <Link
                to="/login"
                className={`inline-flex items-center text-sm font-medium ${colorTokens.intent.primary.text} ${colorTokens.intent.primary.textHoverSubtle}`}
              >
                <ArrowLeft className="h-4 w-4 me-1" />
                {t('forgotPassword.backToLogin')}
              </Link>
            </div>
          </div>
        ) : (
          <form className="mt-8 space-y-6" onSubmit={handleSubmit}>
            {forgotPasswordMutation.isError && (
              <div className={`rounded-md ${colorTokens.intent.danger.bgSubtle} p-4`}>
                <div className="flex">
                  <AlertCircle className={`h-5 w-5 ${colorTokens.intent.danger.textFaint}`} />
                  <div className="ms-3">
                    <p className={`text-sm font-medium ${colorTokens.intent.danger.textStronger}`}>
                      {t('forgotPassword.error')}
                    </p>
                  </div>
                </div>
              </div>
            )}

            <div>
              <label htmlFor="email" className={`block text-sm font-medium ${colorTokens.text.secondary}`}>
                {t('forgotPassword.email')}
              </label>
              <input
                id="email"
                name="email"
                type="email"
                autoComplete="email"
                value={email}
                onChange={(e) => {
                  setEmail(e.target.value)
                  if (emailError) setEmailError(undefined)
                }}
                className={`mt-1 block w-full rounded-lg border px-3 py-2 shadow-sm focus:outline-none focus:ring-2 ${colorTokens.focus.primaryRing} ${
                  emailError
                    ? `${colorTokens.intent.danger.border} focus:${colorTokens.intent.danger.borderFocus} focus:${colorTokens.intent.danger.ring}`
                    : `${colorTokens.border.default} focus:${colorTokens.intent.primary.borderFocus}`
                }`}
                aria-invalid={!!emailError}
                aria-describedby={emailError ? 'email-error' : undefined}
              />
              {emailError && (
                <p id="email-error" className={`mt-1 text-sm ${colorTokens.intent.danger.text}`}>
                  {emailError}
                </p>
              )}
            </div>

            <button
              type="submit"
              disabled={forgotPasswordMutation.isPending}
              className={`group relative w-full flex justify-center py-2.5 px-4 border border-transparent text-sm font-medium rounded-lg ${colorTokens.text.inverse} ${colorTokens.intent.primary.bgStrong} ${colorTokens.intent.primary.bgStrongHover} focus:outline-none focus:ring-2 focus:ring-offset-2 ${colorTokens.focus.primaryRing} disabled:opacity-50 disabled:cursor-not-allowed transition-colors`}
            >
              <span className="absolute start-0 inset-y-0 flex items-center ps-3">
                <Mail className={`h-5 w-5 ${colorTokens.intent.primary.textSubtle} group-hover:${colorTokens.intent.primary.textFaint}`} />
              </span>
              {forgotPasswordMutation.isPending
                ? t('forgotPassword.sending')
                : t('forgotPassword.submit')}
            </button>

            <div className="text-center">
              <Link
                to="/login"
                className={`inline-flex items-center text-sm font-medium ${colorTokens.intent.primary.text} ${colorTokens.intent.primary.textHoverSubtle}`}
              >
                <ArrowLeft className="h-4 w-4 me-1" />
                {t('forgotPassword.backToLogin')}
              </Link>
            </div>
          </form>
        )}
      </div>
    </div>
  )
}
