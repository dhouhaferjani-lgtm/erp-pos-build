import { useTranslation } from 'react-i18next'
import { FormField } from '@/components/atoms/FormField/FormField'
import { Select } from '@/components/atoms/Select/Select'
import { countries, getCountryName } from '@/lib/countries'
import { useProductConfig } from '@/contexts/ProductConfigContext'
import { PINNED_COUNTRIES } from '../config/countryData'
import { getVerticalsForProduct } from '../config/verticals'
import { VerticalCard } from './VerticalCard'
import type { RegisterFormData } from '../hooks/useRegisterForm'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface BusinessStepProps {
  formData: { countryCode: string; vertical: string }
  errors: Record<string, string | undefined>
  updateField: <K extends keyof RegisterFormData>(field: K, value: RegisterFormData[K]) => void
}

export function BusinessStep({ formData, errors, updateField }: BusinessStepProps) {
  const { t, i18n } = useTranslation(['auth'])
  const { product } = useProductConfig()
  const locale = i18n.language

  const verticals = getVerticalsForProduct(product)

  const pinnedCountries = countries.filter((c) => PINNED_COUNTRIES.includes(c.code))
  const remainingCountries = countries
    .filter((c) => !PINNED_COUNTRIES.includes(c.code))
    .sort((a, b) => getCountryName(a.code, locale).localeCompare(getCountryName(b.code, locale)))

  return (
    <div className="space-y-6">
      <FormField
        label={t('auth:register.country')}
        htmlFor="reg-country"
        required
        error={errors['countryCode']}
      >
        <Select
          id="reg-country"
          value={formData.countryCode}
          onChange={(e) => { updateField('countryCode', e.target.value) }}
          error={!!errors['countryCode']}
        >
          <option value="">{t('auth:register.selectCountry')}</option>
          {pinnedCountries.map((c) => (
            <option key={c.code} value={c.code}>
              {getCountryName(c.code, locale)}
            </option>
          ))}
          <option disabled>{'───────'}</option>
          {remainingCountries.map((c) => (
            <option key={c.code} value={c.code}>
              {getCountryName(c.code, locale)}
            </option>
          ))}
        </Select>
      </FormField>

      <div>
        <div
          role="listbox"
          aria-label={t('auth:vertical.title')}
          className="grid grid-cols-2 gap-3"
        >
          {verticals.map((v) => (
            <VerticalCard
              key={v.key}
              vertical={v}
              selected={formData.vertical === v.key}
              onSelect={() => { updateField('vertical', v.key) }}
            />
          ))}
        </div>
        {errors['vertical'] && (
          <p className={`mt-2 text-sm ${colorTokens.intent.danger.text}`}>{t('auth:vertical.required')}</p>
        )}
      </div>
    </div>
  )
}
