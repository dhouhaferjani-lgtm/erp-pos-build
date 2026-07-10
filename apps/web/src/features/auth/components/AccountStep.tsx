import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Eye, EyeOff } from 'lucide-react'
import { FormField } from '@/components/atoms/FormField/FormField'
import { Input } from '@/components/atoms/Input/Input'
import { PasswordStrength } from './PasswordStrength'
import type { RegisterFormData } from '../hooks/useRegisterForm'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface AccountStepProps {
  formData: { name: string; email: string; password: string }
  errors: Record<string, string | undefined>
  updateField: <K extends keyof RegisterFormData>(field: K, value: RegisterFormData[K]) => void
}

// T6 Phase 0a: the global /auth/check-email availability probe was removed.
// Email uniqueness is per-tenant now (the same email may belong to several
// organizations), so a global "is this email taken?" check is meaningless —
// collisions surface per-tenant at register submit.
export function AccountStep({ formData, errors, updateField }: AccountStepProps) {
  const { t } = useTranslation(['auth'])
  const [showPassword, setShowPassword] = useState(false)

  const emailError = errors['email'] ?? undefined

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
          onChange={(e) => { updateField('email', e.target.value) }}
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
            className={`absolute inset-y-0 right-0 flex items-center pr-3 ${colorTokens.text.disabled} hover:${colorTokens.text.muted}`}
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
