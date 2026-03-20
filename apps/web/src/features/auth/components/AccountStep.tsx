import { useState, useRef, useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { useMutation } from '@tanstack/react-query'
import { Eye, EyeOff } from 'lucide-react'
import { FormField } from '@/components/atoms/FormField/FormField'
import { Input } from '@/components/atoms/Input/Input'
import { api } from '@/lib/api'
import { PasswordStrength } from './PasswordStrength'
import type { RegisterFormData } from '../hooks/useRegisterForm'

interface AccountStepProps {
  formData: { name: string; email: string; password: string }
  errors: Record<string, string | undefined>
  updateField: <K extends keyof RegisterFormData>(field: K, value: RegisterFormData[K]) => void
}

export function AccountStep({ formData, errors, updateField }: AccountStepProps) {
  const { t } = useTranslation(['auth'])
  const [showPassword, setShowPassword] = useState(false)
  const [emailCheckError, setEmailCheckError] = useState<string | null>(null)
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  const checkEmailMutation = useMutation({
    mutationFn: async (email: string) => {
      const response = await api.post<{ data: { available: boolean } }>('/auth/check-email', { email })
      return response.data.data
    },
    onSuccess: (data) => {
      if (!data.available) {
        setEmailCheckError(t('auth:register.emailTaken'))
      } else {
        setEmailCheckError(null)
      }
    },
    onError: () => {
      // Silently ignore — registration will catch duplicates
      setEmailCheckError(null)
    },
  })

  const handleEmailChange = useCallback(
    (value: string) => {
      updateField('email', value)
      setEmailCheckError(null)

      if (debounceRef.current) {
        clearTimeout(debounceRef.current)
      }

      if (value && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
        debounceRef.current = setTimeout(() => {
          checkEmailMutation.mutate(value)
        }, 500)
      }
    },
    [updateField, checkEmailMutation],
  )

  const emailError = errors['email'] ?? emailCheckError ?? undefined

  return (
    <div className="space-y-4">
      <FormField label={t('auth:register.name')} htmlFor="reg-name" required error={errors['name']}>
        <Input
          id="reg-name"
          autoComplete="name"
          value={formData.name}
          onChange={(e) => { updateField('name', e.target.value) }}
          error={!!errors['name']}
        />
      </FormField>

      <FormField label={t('auth:register.email')} htmlFor="reg-email" required error={emailError}>
        <Input
          id="reg-email"
          type="email"
          autoComplete="email"
          value={formData.email}
          onChange={(e) => { handleEmailChange(e.target.value) }}
          error={!!emailError}
        />
      </FormField>

      <FormField
        label={t('auth:register.password')}
        htmlFor="reg-password"
        required
        error={errors['password']}
        helperText={t('auth:register.passwordHint')}
      >
        <div className="relative">
          <Input
            id="reg-password"
            type={showPassword ? 'text' : 'password'}
            autoComplete="new-password"
            value={formData.password}
            onChange={(e) => { updateField('password', e.target.value) }}
            error={!!errors['password']}
            className="pr-10"
          />
          <button
            type="button"
            onClick={() => { setShowPassword((prev) => !prev) }}
            className="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-gray-600"
            tabIndex={-1}
          >
            {showPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
          </button>
        </div>
        <PasswordStrength password={formData.password} />
      </FormField>
    </div>
  )
}
