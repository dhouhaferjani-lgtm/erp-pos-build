import { useEffect, useState } from 'react'
import { useSearchParams, useNavigate, Link } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { CheckCircle, XCircle, Loader2 } from 'lucide-react'
import { api } from '../../lib/api'
import { useAuthStore } from '../../stores/authStore'

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
  const setUser = useAuthStore((state) => state.setUser)
  const user = useAuthStore((state) => state.user)
  const [verificationAttempted, setVerificationAttempted] = useState(false)

  const verifyMutation = useMutation({
    mutationFn: async (verificationToken: string) => {
      const response = await api.post<VerifyResponse>('/auth/verify-email', {
        token: verificationToken,
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
      <div className="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
        <div className="max-w-md w-full text-center">
          <div className="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-red-100">
            <XCircle className="h-8 w-8 text-red-600" />
          </div>
          <h2 className="mt-6 text-2xl font-bold text-gray-900">
            {t('verification.invalidToken')}
          </h2>
          <p className="mt-2 text-gray-600">
            {t('verification.error')}
          </p>
          <Link
            to="/login"
            className="mt-6 inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-lg text-white bg-blue-600 hover:bg-blue-700"
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
      <div className="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
        <div className="max-w-md w-full text-center">
          <Loader2 className="mx-auto h-12 w-12 text-blue-600 animate-spin" />
          <h2 className="mt-6 text-2xl font-bold text-gray-900">
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
      <div className="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
        <div className="max-w-md w-full text-center">
          <div className="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-red-100">
            <XCircle className="h-8 w-8 text-red-600" />
          </div>
          <h2 className="mt-6 text-2xl font-bold text-gray-900">
            {t('verification.error')}
          </h2>
          <p className="mt-2 text-gray-600">{errorMessage}</p>
          <div className="mt-6 space-x-4">
            <Link
              to="/login"
              className="inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-lg text-white bg-blue-600 hover:bg-blue-700"
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
      <div className="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
        <div className="max-w-md w-full text-center">
          <div className="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-green-100">
            <CheckCircle className="h-8 w-8 text-green-600" />
          </div>
          <h2 className="mt-6 text-2xl font-bold text-gray-900">
            {t('verification.success')}
          </h2>
          <p className="mt-2 text-gray-600">
            {t('verification.alreadyVerified')}
          </p>
          <button
            type="button"
            onClick={user ? handleGoToDashboard : handleGoToLogin}
            className="mt-6 inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-lg text-white bg-blue-600 hover:bg-blue-700"
          >
            {user ? t('common:continue') : t('register.signIn')}
          </button>
        </div>
      </div>
    )
  }

  return null
}
