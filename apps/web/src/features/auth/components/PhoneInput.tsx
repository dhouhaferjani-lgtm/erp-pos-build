import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import { FormField } from '@/components/atoms/FormField/FormField'
import { getDialCode } from '../config/countryData'

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
          <span className="inline-flex items-center rounded-s-lg border border-e-0 border-gray-300 bg-gray-100 px-3 text-sm text-gray-600">
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
            'block w-full border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500',
            dialCode ? 'rounded-e-lg' : 'rounded-lg'
          )}
        />
      </div>
    </FormField>
  )
}
