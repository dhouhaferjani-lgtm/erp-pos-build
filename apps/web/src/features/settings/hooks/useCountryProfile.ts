import { useCountry } from './useCountries'

/**
 * Frontend-friendly (camelCase) view over the seeded `countries` row —
 * the per-country labels/formats the settings forms need.
 */
export interface CountryProfile {
  taxIdLabel: string | null
  taxIdRegex: string | null
  phonePrefix: string | null
  currencyCode: string | null
  currencySymbol: string | null
  dateFormat: string | null
  timezone: string | null
}

export interface UseCountryProfileResult {
  profile: CountryProfile | null
  isLoading: boolean
}

/**
 * Resolve the country profile for a country code. A null/undefined/empty code
 * resolves to a null profile without querying — `useCountry` is
 * enabled-guarded on the empty string, so the query never fires.
 */
export function useCountryProfile(countryCode: string | null | undefined): UseCountryProfileResult {
  const code = countryCode ?? ''
  const { data, isLoading } = useCountry(code)

  if (code === '' || data === undefined) {
    return { profile: null, isLoading: code === '' ? false : isLoading }
  }

  return {
    profile: {
      taxIdLabel: data.tax_id_label,
      taxIdRegex: data.tax_id_regex,
      phonePrefix: data.phone_prefix,
      currencyCode: data.currency_code,
      currencySymbol: data.currency_symbol,
      dateFormat: data.date_format,
      timezone: data.default_timezone,
    },
    isLoading,
  }
}
