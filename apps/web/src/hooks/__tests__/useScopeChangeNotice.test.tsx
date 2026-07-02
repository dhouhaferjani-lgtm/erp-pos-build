import { describe, it, expect, beforeEach, vi } from 'vitest'
import { renderHook, act } from '@testing-library/react'
import { useScopeChangeNotice } from '../useScopeChangeNotice'
import { useCompanyStore, type Company } from '../../stores/companyStore'
import { useLocationStore, type Location } from '../../stores/locationStore'

const toastInfo = vi.fn()
vi.mock('sonner', () => ({
  toast: { info: (...args: unknown[]) => { toastInfo(...args) } },
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) =>
      opts ? `${key}:${JSON.stringify(opts)}` : key,
  }),
}))

const companies: Company[] = [
  {
    id: 'company-a',
    name: 'Company A',
    legalName: 'Company A SARL',
    taxId: null,
    countryCode: 'FR',
    currency: 'EUR',
    locale: 'fr',
    timezone: 'Europe/Paris',
  },
  {
    id: 'company-b',
    name: 'Company B',
    legalName: 'Company B SARL',
    taxId: null,
    countryCode: 'TN',
    currency: 'TND',
    locale: 'fr',
    timezone: 'Africa/Tunis',
  },
]

function makeLocation(id: string, name: string): Location {
  return {
    id,
    companyId: 'company-a',
    name,
    code: id,
    type: 'shop',
    phone: null,
    email: null,
    addressStreet: null,
    addressCity: null,
    addressPostalCode: null,
    addressCountry: null,
    isDefault: false,
    isActive: true,
    posEnabled: true,
    createdAt: '2026-01-01T00:00:00Z',
    updatedAt: '2026-01-01T00:00:00Z',
  }
}

const locations: Location[] = [makeLocation('loc-1', 'Main Shop'), makeLocation('loc-2', 'Second Shop')]

describe('useScopeChangeNotice', () => {
  beforeEach(() => {
    toastInfo.mockClear()
    localStorage.clear()
    useCompanyStore.getState().reset()
    useLocationStore.getState().reset()
  })

  it('does not toast on initial scope resolution', () => {
    renderHook(() => { useScopeChangeNotice() })

    act(() => {
      useCompanyStore.getState().setCompanies(companies)
      useLocationStore.getState().setLocations(locations)
    })

    expect(toastInfo).not.toHaveBeenCalled()
  })

  it('toasts when the company switches', () => {
    renderHook(() => { useScopeChangeNotice() })

    act(() => {
      useCompanyStore.getState().setCompanies(companies)
      useCompanyStore.getState().setCurrentCompany('company-a')
      useLocationStore.getState().setLocations(locations)
      useLocationStore.getState().setCurrentLocation('loc-1')
    })

    toastInfo.mockClear()

    act(() => {
      useCompanyStore.getState().setCurrentCompany('company-b')
    })

    expect(toastInfo).toHaveBeenCalledTimes(1)
    expect(String(toastInfo.mock.calls[0][0])).toContain('Company B')
  })

  it('toasts when the location switches', () => {
    renderHook(() => { useScopeChangeNotice() })

    act(() => {
      useCompanyStore.getState().setCompanies(companies)
      useCompanyStore.getState().setCurrentCompany('company-a')
      useLocationStore.getState().setLocations(locations)
      useLocationStore.getState().setCurrentLocation('loc-1')
    })

    toastInfo.mockClear()

    act(() => {
      useLocationStore.getState().setCurrentLocation('loc-2')
    })

    expect(toastInfo).toHaveBeenCalledTimes(1)
    expect(String(toastInfo.mock.calls[0][0])).toContain('Second Shop')
  })
})
