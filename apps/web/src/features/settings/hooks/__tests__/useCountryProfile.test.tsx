import { renderHook } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import type { Country } from '@/features/settings/types/country'

import { useCountryProfile } from '../useCountryProfile'

const useCountryMock = vi.hoisted(() => vi.fn())

vi.mock('../useCountries', () => ({
  useCountry: useCountryMock,
}))

function tnCountry(): Country {
  return {
    code: 'TN',
    name: 'Tunisia',
    native_name: 'تونس',
    currency_code: 'TND',
    currency_symbol: 'DT',
    phone_prefix: '216',
    date_format: 'DD/MM/YYYY',
    default_locale: 'fr',
    default_timezone: 'Africa/Tunis',
    is_active: true,
    tax_id_label: 'Matricule Fiscal',
    tax_id_regex: '^\\d{7}[A-Z]{2}\\d{3}$',
    created_at: '2026-01-01T00:00:00Z',
  }
}

beforeEach(() => {
  vi.clearAllMocks()
  useCountryMock.mockReturnValue({ data: undefined, isLoading: false })
})

describe('useCountryProfile', () => {
  it('maps the country row onto a CountryProfile', () => {
    useCountryMock.mockReturnValue({ data: tnCountry(), isLoading: false })

    const { result } = renderHook(() => useCountryProfile('TN'))

    expect(result.current.isLoading).toBe(false)
    expect(result.current.profile).toEqual({
      taxIdLabel: 'Matricule Fiscal',
      taxIdRegex: '^\\d{7}[A-Z]{2}\\d{3}$',
      phonePrefix: '216',
      currencyCode: 'TND',
      currencySymbol: 'DT',
      dateFormat: 'DD/MM/YYYY',
      timezone: 'Africa/Tunis',
    })
  })

  it('returns a null profile for a null code without querying (enabled semantics)', () => {
    useCountryMock.mockReturnValue({ data: tnCountry(), isLoading: false })

    const { result } = renderHook(() => useCountryProfile(null))

    // useCountry is enabled-guarded on the empty string — pass '' through.
    expect(useCountryMock).toHaveBeenCalledWith('')
    expect(result.current.profile).toBeNull()
    expect(result.current.isLoading).toBe(false)
  })

  it('returns a null profile for an undefined code', () => {
    const { result } = renderHook(() => useCountryProfile(undefined))

    expect(useCountryMock).toHaveBeenCalledWith('')
    expect(result.current.profile).toBeNull()
  })

  it('reports loading while the country row is being fetched', () => {
    useCountryMock.mockReturnValue({ data: undefined, isLoading: true })

    const { result } = renderHook(() => useCountryProfile('TN'))

    expect(result.current.isLoading).toBe(true)
    expect(result.current.profile).toBeNull()
  })
})
