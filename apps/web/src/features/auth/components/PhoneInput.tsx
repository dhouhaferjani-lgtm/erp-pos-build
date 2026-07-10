import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import { FormField } from '@/components/atoms/FormField/FormField'
import { getDialCode } from '../config/countryData'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface PhoneInputProps {
  countryCode: string
  value: string
  onChange: (value: string) => void
  error?: string
}

export function PhoneInput({ countryCode, value, onChange, error }: PhoneInputProps) {
  const { t } = useTranslation(['auth'])
  const dialCode = getDialCode(countryCode)

  return (
    <FormField
      label={t('auth:register.phone')}
      error={error}
      htmlFor="phone"
    >
      <div className="flex">
        {dialCode && (
          <span className={`inline-flex items-center rounded-s-lg border border-e-0 ${colorTokens.border.default} ${colorTokens.surface.muted} px-3 text-sm ${colorTokens.text.muted}`}>
            {dialCode}
          </span>
        )}
        <input
          type="tel"
          id="phone"
          value={value}
          onChange={(e) => { onChange(e.target.value) }}
          placeholder="612345678"
          className={cn(
            `block w-full border ${colorTokens.border.default} px-3 py-2 text-sm focus:${colorTokens.intent.primary.borderFocus} focus:outline-none focus:ring-1 ${colorTokens.focus.primaryRing}`,
            dialCode ? 'rounded-e-lg' : 'rounded-lg'
          )}
        />
      </div>
    </FormField>
  )
}
