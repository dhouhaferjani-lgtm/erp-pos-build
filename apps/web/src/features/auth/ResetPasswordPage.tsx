import { useState, useMemo } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { KeyRound, AlertCircle, CheckCircle, Check, X } from 'lucide-react'
import { api, ensureCsrfCookie } from '../../lib/api'
import { useProductConfig } from '../../contexts/ProductConfigContext'

interface ResetFormData {
  password: string
  password_confirmation: string
}

interface FormErrors {
  password?: string
  password_confirmation?: string
  general?: string
}

export function ResetPasswordPage() {
  const { t } = useTranslation(['auth', 'validation'])
  const { productName } = useProductConfig()
  const [searchParams] = useSearchParams()

  const token = searchParams.get('token')
  const email = searchParams.get('email')
  // T6 Phase 0a: the reset link carries a signed tenant qualifier so the
  // pre-auth resolver can open the correct tenant DB before the password
  // broker runs (topology r7 B1). Round-trip it back to the API.
  const tenant = searchParams.get('tenant')

  const [formData, setFormData] = useState<ResetFormData>({
    password: '',
    password_confirmation: '',
  })
  const [errors, setErrors] = useState<FormErrors>({})

  const requirements = useMemo(() => {
    const pw = formData.password
    return {
      length: pw.length >= 10,
      uppercase: /[A-Z]/.test(pw),
      lowercase: /[a-z]/.test(pw),
      number: /\d/.test(pw),
      symbol: /[^A-Za-z0-9]/.test(pw),
    }
  }, [formData.password])

  const allRequirementsMet = Object.values(requirements).every(Boolean)

  const resetMutation = useMutation({
    mutationFn: async (data: ResetFormData) => {
      await ensureCsrfCookie()
      await api.post('/auth/reset-password', {
        token,
        email,
        password: data.password,
        password_confirmation: data.password_confirmation,
        ...(tenant ? { tenant } : {}),
      })
    },
    onError: (error: unknown) => {
      const apiError = error as { response?: { data?: { error?: { message?: string } } } }
      const message = apiError.response?.data?.error?.message ?? t('resetPassword.error')
      setErrors({ general: message })
    },
  })

  if (!token || !email) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
        <div className="max-w-md w-full space-y-8">
          <div>
            <h1 className="text-3xl font-bold text-center text-gray-900">{productName}</h1>
          </div>
          <div className="rounded-md bg-red-50 p-4">
            <div className="flex">
              <AlertCircle className="h-5 w-5 text-red-400" />
              <div className="ms-3">
                <p className="text-sm font-medium text-red-800">
                  {t('resetPassword.invalidLink')}
                </p>
              </div>
            </div>
          </div>
          <div className="text-center">
            <Link
              to="/forgot-password"
              className="text-sm font-medium text-blue-600 hover:text-blue-500"
            >
              {t('forgotPassword.submit')}
            </Link>
          </div>
        </div>
      </div>
    )
  }

  const validateForm = (): boolean => {
    const newErrors: FormErrors = {}

    if (!formData.password) {
      newErrors.password = t('validation:required')
    } else if (!allRequirementsMet) {
      newErrors.password = t('register.passwordHint')
    }

    if (!formData.password_confirmation) {
      newErrors.password_confirmation = t('validation:required')
    } else if (formData.password !== formData.password_confirmation) {
      newErrors.password_confirmation = t('register.passwordMismatch')
    }

    setErrors(newErrors)
    return Object.keys(newErrors).length === 0
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (validateForm()) {
      resetMutation.mutate(formData)
    }
  }

  const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const { name, value } = e.target
    setFormData((prev) => ({ ...prev, [name]: value }))
    if (errors[name as keyof FormErrors]) {
      setErrors((prev) => ({ ...prev, [name]: undefined }))
    }
  }

  return (
    <div className="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
      <div className="max-w-md w-full space-y-8">
        <div>
          <h1 className="text-3xl font-bold text-center text-gray-900">{productName}</h1>
          <h2 className="mt-6 text-center text-xl font-semibold text-gray-700">
            {t('resetPassword.title')}
          </h2>
          <p className="mt-2 text-center text-sm text-gray-500">
            {t('resetPassword.subtitle')}
          </p>
        </div>

        {resetMutation.isSuccess ? (
          <div className="space-y-6">
            <div className="rounded-md bg-green-50 p-4">
              <div className="flex">
                <CheckCircle className="h-5 w-5 text-green-400" />
                <div className="ms-3">
                  <p className="text-sm font-medium text-green-800">
                    {t('resetPassword.success')}
                  </p>
                </div>
              </div>
            </div>
            <div className="text-center">
              <Link
                to="/login"
                className="inline-flex items-center justify-center w-full py-2.5 px-4 border border-transparent text-sm font-medium rounded-lg text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors"
              >
                {t('resetPassword.goToLogin')}
              </Link>
            </div>
          </div>
        ) : (
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
                <label htmlFor="password" className="block text-sm font-medium text-gray-700">
                  {t('resetPassword.password')}
                </label>
                <input
                  id="password"
                  name="password"
                  type="password"
                  autoComplete="new-password"
                  value={formData.password}
                  onChange={handleChange}
                  className={`mt-1 block w-full rounded-lg border px-3 py-2 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 ${
                    errors.password
                      ? 'border-red-300 focus:border-red-500 focus:ring-red-500'
                      : 'border-gray-300 focus:border-blue-500'
                  }`}
                  aria-invalid={!!errors.password}
                  aria-describedby="password-requirements"
                />
                {errors.password && (
                  <p className="mt-1 text-sm text-red-600">{errors.password}</p>
                )}

                {formData.password.length > 0 && (
                  <div id="password-requirements" className="mt-3 space-y-1.5">
                    <p className="text-xs font-medium text-gray-600">
                      {t('resetPassword.requirements.title')}
                    </p>
                    <RequirementItem
                      met={requirements.length}
                      label={t('resetPassword.requirements.length')}
                    />
                    <RequirementItem
                      met={requirements.uppercase}
                      label={t('resetPassword.requirements.uppercase')}
                    />
                    <RequirementItem
                      met={requirements.lowercase}
                      label={t('resetPassword.requirements.lowercase')}
                    />
                    <RequirementItem
                      met={requirements.number}
                      label={t('resetPassword.requirements.number')}
                    />
                    <RequirementItem
                      met={requirements.symbol}
                      label={t('resetPassword.requirements.symbol')}
                    />
                  </div>
                )}
              </div>

              <div>
                <label
                  htmlFor="password_confirmation"
                  className="block text-sm font-medium text-gray-700"
                >
                  {t('resetPassword.confirmPassword')}
                </label>
                <input
                  id="password_confirmation"
                  name="password_confirmation"
                  type="password"
                  autoComplete="new-password"
                  value={formData.password_confirmation}
                  onChange={handleChange}
                  className={`mt-1 block w-full rounded-lg border px-3 py-2 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 ${
                    errors.password_confirmation
                      ? 'border-red-300 focus:border-red-500 focus:ring-red-500'
                      : 'border-gray-300 focus:border-blue-500'
                  }`}
                  aria-invalid={!!errors.password_confirmation}
                />
                {errors.password_confirmation && (
                  <p className="mt-1 text-sm text-red-600">{errors.password_confirmation}</p>
                )}
              </div>
            </div>

            <button
              type="submit"
              disabled={resetMutation.isPending}
              className="group relative w-full flex justify-center py-2.5 px-4 border border-transparent text-sm font-medium rounded-lg text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
            >
              <span className="absolute start-0 inset-y-0 flex items-center ps-3">
                <KeyRound className="h-5 w-5 text-blue-500 group-hover:text-blue-400" />
              </span>
              {resetMutation.isPending
                ? t('resetPassword.resetting')
                : t('resetPassword.submit')}
            </button>

            <div className="text-center">
              <Link
                to="/login"
                className="text-sm font-medium text-blue-600 hover:text-blue-500"
              >
                {t('forgotPassword.backToLogin')}
              </Link>
            </div>
          </form>
        )}
      </div>
    </div>
  )
}

function RequirementItem({ met, label }: { met: boolean; label: string }) {
  return (
    <div className="flex items-center gap-1.5">
      {met ? (
        <Check className="h-3.5 w-3.5 text-green-500" />
      ) : (
        <X className="h-3.5 w-3.5 text-gray-400" />
      )}
      <span className={`text-xs ${met ? 'text-green-600' : 'text-gray-500'}`}>{label}</span>
    </div>
  )
}
