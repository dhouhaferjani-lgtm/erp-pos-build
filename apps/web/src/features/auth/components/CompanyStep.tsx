import { useTranslation } from 'react-i18next'
import { FormField } from '@/components/atoms/FormField/FormField'
import { Input } from '@/components/atoms/Input/Input'
import { PhoneInput } from './PhoneInput'
import type { RegisterFormData } from '../hooks/useRegisterForm'

interface CompanyStepProps {
  formData: { companyName: string; phoneLocal: string; countryCode: string }
  errors: Record<string, string | undefined>
  updateField: <K extends keyof RegisterFormData>(field: K, value: RegisterFormData[K]) => void
}

export function CompanyStep({ formData, errors, updateField }: CompanyStepProps) {
  const { t } = useTranslation(['auth'])

  return (
    <div className="space-y-4">
      <FormField
        label={t('auth:register.companyName')}
        htmlFor="reg-company"
        required
        error={errors['companyName']}
      >
        <Input
          id="reg-company"
          value={formData.companyName}
          onChange={(e) => { updateField('companyName', e.target.value) }}
          error={!!errors['companyName']}
        />
      </FormField>

      <PhoneInput
        countryCode={formData.countryCode}
        value={formData.phoneLocal}
        onChange={(value) => { updateField('phoneLocal', value) }}
        {...(errors['phoneLocal'] ? { error: errors['phoneLocal'] } : {})}
      />
    </div>
  )
}
