import { apiGet } from '@/lib/api'
import type { Country, CountryFilters } from '../types/country'

/**
 * Fetch active countries. apiGet already unwraps response.data.data,
 * so we get Country[] directly — do NOT double-unwrap.
 */
export async function getCountries(filters?: CountryFilters): Promise<Country[]> {
  const params = new URLSearchParams()

  if (filters?.is_active !== undefined) {
    params.append('is_active', filters.is_active ? '1' : '0')
  }

  const queryString = params.toString()
  const url = queryString ? `/countries?${queryString}` : '/countries'

  return apiGet<Country[]>(url)
}

export async function getCountry(code: string): Promise<Country> {
  return apiGet<Country>(`/countries/${code}`)
}
