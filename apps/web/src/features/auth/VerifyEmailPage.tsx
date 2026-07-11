import { useEffect, useState } from 'react'
import { useSearchParams, useNavigate, Link } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { CheckCircle, XCircle, Loader2 } from 'lucide-react'
import { api } from '../../lib/api'
import { useAuthStore } from '../../stores/authStore'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface VerifyResponse {
  data: {
    message: string
    user: {
      id: string
      name: string
      email: string
      tenantId: string
      roles: string[]
      emailVerifiedAt: string
    }
  }
}

export function VerifyEmailPage() {
  const { t } = useTranslation(['auth', 'common'])
  const [searchParams] = useSearchParams()
  const navigate = useNavigate()
  const token = searchParams.get('token')
  // T6 Phase 0a: the verification link carries a signed tenant qualifier so the
  // pre-auth resolver opens the correct tenant DB before the token lookup
  // (topology r7 B1). Round-trip it back to the API.
  const tenant = searchParams.get('tenant')
  const setUser = useAuthStore((state) => state.setUser)
  const user = useAuthStore((state) => state.user)
  const [verificationAttempted, setVerificationAttempted] = useState(false)

  const verifyMutation = useMutation({
    mutationFn: async (verificationToken: string) => {
      const response = await api.post<VerifyResponse>('/auth/verify-email', {
        token: verificationToken,
        ...(tenant ? { tenant } : {}),
      })
      return response.data.data
    },
    onSuccess: (data) => {
      // Update user in store with verified email
      if (user) {
        setUser({
          ...user,
          email_verified_at: data.user.emailVerifiedAt,
        })
      }
      // Clear the session storage banner dismissal so user sees the success
      sessionStorage.removeItem('email_verification_banner_dismissed')
    },
  })

  useEffect(() => {
    if (token && !verificationAttempted) {
      setVerificationAttempted(true)
      verifyMutation.mutate(token)
    }
  }, [token, verificationAttempted, verifyMutation])

  const handleGoToDashboard = () => {
    void navigate('/', { replace: true })
  }

  const handleGoToLogin = () => {
    void navigate('/login', { replace: true })
  }

  // No token provided
  if (!token) {
    return (
      <div className={`min-h-screen flex items-center justify-center ${colorTokens.surface.page} py-12 px-4 sm:px-6 lg:px-8`}>
        <div className="max-w-md w-full text-center">
          <div className={`mx-auto flex items-center justify-center h-16 w-16 rounded-full ${colorTokens.intent.danger.bgSoft}`}>
            <XCircle className={`h-8 w-8 ${colorTokens.intent.danger.text}`} />
          </div>
          <h2 className={`mt-6 text-2xl font-bold ${colorTokens.text.primary}`}>
            {t('verification.invalidToken')}
          </h2>
          <p className={`mt-2 ${colorTokens.text.muted}`}>
            {t('verification.error')}
          </p>
          <Link
            to="/login"
            className={`mt-6 inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-lg ${colorTokens.text.inverse} ${colorTokens.intent.primary.bgStrong} ${colorTokens.intent.primary.bgStrongHover}`}
          >
            {t('register.signIn')}
          </Link>
        </div>
      </div>
    )
  }

  // Loading state
  if (verifyMutation.isPending) {
    return (
      <div className={`min-h-screen flex items-center justify-center ${colorTokens.surface.page} py-12 px-4 sm:px-6 lg:px-8`}>
        <div className="max-w-md w-full text-center">
          <Loader2 className={`mx-auto h-12 w-12 ${colorTokens.intent.primary.text} animate-spin`} />
          <h2 className={`mt-6 text-2xl font-bold ${colorTokens.text.primary}`}>
            {t('common:loading')}
          </h2>
        </div>
      </div>
    )
  }

  // Error state
  if (verifyMutation.isError) {
    const error = verifyMutation.error as { response?: { data?: { error?: { message?: string } } } }
    const errorMessage = error.response?.data?.error?.message ?? t('verification.error')

    return (
      <div className={`min-h-screen flex items-center justify-center ${colorTokens.surface.page} py-12 px-4 sm:px-6 lg:px-8`}>
        <div className="max-w-md w-full text-center">
          <div className={`mx-auto flex items-center justify-center h-16 w-16 rounded-full ${colorTokens.intent.danger.bgSoft}`}>
            <XCircle className={`h-8 w-8 ${colorTokens.intent.danger.text}`} />
          </div>
          <h2 className={`mt-6 text-2xl font-bold ${colorTokens.text.primary}`}>
            {t('verification.error')}
          </h2>
          <p className={`mt-2 ${colorTokens.text.muted}`}>{errorMessage}</p>
          <div className="mt-6 space-x-4">
            <Link
              to="/login"
              className={`inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-lg ${colorTokens.text.inverse} ${colorTokens.intent.primary.bgStrong} ${colorTokens.intent.primary.bgStrongHover}`}
            >
              {t('register.signIn')}
            </Link>
          </div>
        </div>
      </div>
    )
  }

  // Success state
  if (verifyMutation.isSuccess) {
    return (
      <div className={`min-h-screen flex items-center justify-center ${colorTokens.surface.page} py-12 px-4 sm:px-6 lg:px-8`}>
        <div className="max-w-md w-full text-center">
          <div className={`mx-auto flex items-center justify-center h-16 w-16 rounded-full ${colorTokens.intent.success.bgSoft}`}>
            <CheckCircle className={`h-8 w-8 ${colorTokens.intent.success.text}`} />
          </div>
          <h2 className={`mt-6 text-2xl font-bold ${colorTokens.text.primary}`}>
            {t('verification.success')}
          </h2>
          <p className={`mt-2 ${colorTokens.text.muted}`}>
            {t('verification.alreadyVerified')}
          </p>
          <button
            type="button"
            onClick={user ? handleGoToDashboard : handleGoToLogin}
            className={`mt-6 inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-lg ${colorTokens.text.inverse} ${colorTokens.intent.primary.bgStrong} ${colorTokens.intent.primary.bgStrongHover}`}
          >
            {user ? t('common:continue') : t('register.signIn')}
          </button>
        </div>
      </div>
    )
  }

  return null
}
