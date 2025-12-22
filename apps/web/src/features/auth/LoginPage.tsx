import { useState } from 'react'
import { useNavigate, useLocation, Link } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { LogIn, AlertCircle } from 'lucide-react'
import { api } from '../../lib/api'
import { useAuthStore } from '../../stores/authStore'

interface LoginFormData {
  email: string
  password: string
}

interface LoginResponseUser {
  id: string
  name: string
  email: string
  tenantId: string
  roles: string[]
  emailVerifiedAt: string | null
}

interface LoginResponse {
  data: {
    user: LoginResponseUser
    token: string
    tokenType: string
  }
}

interface FormErrors {
  email?: string
  password?: string
  general?: string
}

export function LoginPage() {
  const { t } = useTranslation(['auth', 'validation'])
  const navigate = useNavigate()
  const location = useLocation()
  const setAuth = useAuthStore((state) => state.setAuth)

  const [formData, setFormData] = useState<LoginFormData>({
    email: '',
    password: '',
  })
  const [errors, setErrors] = useState<FormErrors>({})

  const loginMutation = useMutation({
    mutationFn: async (data: LoginFormData) => {
      // Login and get token
      const response = await api.post<LoginResponse>('/auth/login', data)
      return response.data.data
    },
    onSuccess: (data) => {
      // Map tenantId to tenant_id for store compatibility
      const user = {
        id: data.user.id,
        name: data.user.name,
        email: data.user.email,
        tenant_id: data.user.tenantId,
        roles: data.user.roles,
        email_verified_at: data.user.emailVerifiedAt,
      }
      setAuth(user, data.token)
      // Redirect to the page they were trying to access, or home
      const locationState = location.state as { from?: { pathname: string } } | null
      const from = locationState?.from?.pathname ?? '/'
      void navigate(from, { replace: true })
    },
    onError: (error: unknown) => {
      const apiError = error as { response?: { data?: { error?: { message?: string } } } }
      const message = apiError.response?.data?.error?.message ?? t('errors.invalidCredentials')
      setErrors({ general: message })
    },
  })

  const validateForm = (): boolean => {
    const newErrors: FormErrors = {}

    if (!formData.email.trim()) {
      newErrors.email = t('validation:required')
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(formData.email)) {
      newErrors.email = t('validation:email')
    }

    if (!formData.password.trim()) {
      newErrors.password = t('validation:required')
    }

    setErrors(newErrors)
    return Object.keys(newErrors).length === 0
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (validateForm()) {
      loginMutation.mutate(formData)
    }
  }

  const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const { name, value } = e.target
    setFormData((prev) => ({ ...prev, [name]: value }))
    // Clear error when user starts typing
    if (errors[name as keyof FormErrors]) {
      setErrors((prev) => ({ ...prev, [name]: undefined }))
    }
  }

  return (
    <div className="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
      <div className="max-w-md w-full space-y-8">
        <div>
          <h1 className="text-3xl font-bold text-center text-gray-900">{t('common:appName')}</h1>
          <h2 className="mt-6 text-center text-xl font-semibold text-gray-700">
            {t('login.subtitle')}
          </h2>
        </div>

        <form className="mt-8 space-y-6" onSubmit={handleSubmit}>
          {errors.general && (
            <div className="rounded-md bg-red-50 p-4">
              <div className="flex">
                <AlertCircle className="h-5 w-5 text-red-400" />
                <div className="ms-3">
                  <p className="text-sm font-medium text-red-800">{errors.general}</p>
                </div>
              </div>
            </div>
          )}

          <div className="space-y-4">
            <div>
              <label htmlFor="email" className="block text-sm font-medium text-gray-700">
                {t('login.email')}
              </label>
              <input
                id="email"
                name="email"
                type="email"
                autoComplete="email"
                value={formData.email}
                onChange={handleChange}
                className={`mt-1 block w-full rounded-lg border px-3 py-2 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 ${
                  errors.email
                    ? 'border-red-300 focus:border-red-500 focus:ring-red-500'
                    : 'border-gray-300 focus:border-blue-500'
                }`}
                aria-invalid={!!errors.email}
                aria-describedby={errors.email ? 'email-error' : undefined}
              />
              {errors.email && (
                <p id="email-error" className="mt-1 text-sm text-red-600">
                  {errors.email}
                </p>
              )}
            </div>

            <div>
              <label htmlFor="password" className="block text-sm font-medium text-gray-700">
                {t('login.password')}
              </label>
              <input
                id="password"
                name="password"
                type="password"
                autoComplete="current-password"
                value={formData.password}
                onChange={handleChange}
                className={`mt-1 block w-full rounded-lg border px-3 py-2 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 ${
                  errors.password
                    ? 'border-red-300 focus:border-red-500 focus:ring-red-500'
                    : 'border-gray-300 focus:border-blue-500'
                }`}
                aria-invalid={!!errors.password}
                aria-describedby={errors.password ? 'password-error' : undefined}
              />
              {errors.password && (
                <p id="password-error" className="mt-1 text-sm text-red-600">
                  {errors.password}
                </p>
              )}
            </div>
          </div>

          <button
            type="submit"
            disabled={loginMutation.isPending}
            className="group relative w-full flex justify-center py-2.5 px-4 border border-transparent text-sm font-medium rounded-lg text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
          >
            <span className="absolute start-0 inset-y-0 flex items-center ps-3">
              <LogIn className="h-5 w-5 text-blue-500 group-hover:text-blue-400" />
            </span>
            {loginMutation.isPending ? t('login.signingIn') : t('login.submit')}
          </button>

          <p className="text-center text-sm text-gray-600 mt-4">
            {t('register.alreadyHaveAccount', { defaultValue: "Don't have an account?" })}{' '}
            <Link to="/register" className="font-medium text-blue-600 hover:text-blue-500">
              {t('login.signUp', { defaultValue: 'Sign up' })}
            </Link>
          </p>
        </form>
      </div>
    </div>
  )
}
