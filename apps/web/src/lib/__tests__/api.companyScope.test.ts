import type { AxiosResponse, InternalAxiosRequestConfig } from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { api, handleCompanyScopeRejection } from '../api'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore, type Company } from '../../stores/companyStore'
import { useLocationStore } from '../../stores/locationStore'

/**
 * W2-1 — the company scope must never be able to deadlock the app.
 *
 * Campaign wave 2: a browser that already held another account's company
 * selection sent that id on EVERY request — including `/user/companies`, the
 * one call that could have taught it the truth. The server 403'd it, the
 * membership list never loaded, the switcher opened empty, and only
 * logout -> login recovered.
 *
 * Two independent guarantees are pinned here:
 *  1. bootstrap calls are sent WITHOUT `X-Company-Id`, so the membership list
 *     is always reachable no matter what the client believes;
 *  2. a typed scope rejection resets the selection exactly once — self-healing
 *     without a reset/refetch loop.
 */

const STALE_COMPANY_ID = '01a034af-94ea-713d-8ce0-462216bf6ab5'
const COMPANY_SELECTION_KEY = 'autoerp-company-selection'

const seen: InternalAxiosRequestConfig[] = []

function company(id: string): Company {
  return {
    id,
    name: 'Parapharmacie Élégance & Santé SARL',
    legalName: 'Parapharmacie Élégance & Santé SARL',
    taxId: null,
    countryCode: 'TN',
    currency: 'TND',
    locale: 'fr',
    timezone: 'Africa/Tunis',
    isPrimary: true,
  }
}

function selectStaleCompany(): void {
  useCompanyStore.setState({
    currentCompanyId: STALE_COMPANY_ID,
    companies: [company(STALE_COMPANY_ID)],
    isLoading: false,
  })
  localStorage.setItem(COMPANY_SELECTION_KEY, STALE_COMPANY_ID)
}

beforeEach(() => {
  seen.length = 0
  localStorage.clear()
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
  useLocationStore.getState().reset()

  api.defaults.adapter = (config) => {
    seen.push(config)
    return Promise.resolve({
      data: { data: [] },
      status: 200,
      statusText: 'OK',
      headers: {},
      config,
    } as AxiosResponse)
  }
})

describe('X-Company-Id on bootstrap calls', () => {
  it('attaches the selected company to an ordinary request', async () => {
    selectStaleCompany()

    await api.get('/products')

    expect(seen[0].headers['X-Company-Id']).toBe(STALE_COMPANY_ID)
  })

  it('never sends X-Company-Id on /user/companies (the membership bootstrap)', async () => {
    selectStaleCompany()

    await api.get('/user/companies')

    expect(seen[0].headers['X-Company-Id']).toBeUndefined()
  })

  it('never sends X-Company-Id on /auth/me', async () => {
    selectStaleCompany()

    await api.get('/auth/me')

    expect(seen[0].headers['X-Company-Id']).toBeUndefined()
  })

  it('ignores a query string when deciding a call is a bootstrap call', async () => {
    selectStaleCompany()

    await api.get('/user/companies', { params: { refresh: 1 } })

    expect(seen[0].headers['X-Company-Id']).toBeUndefined()
  })
})

describe('handleCompanyScopeRejection', () => {
  beforeEach(() => {
    vi.spyOn(console, 'warn').mockImplementation(() => undefined)
  })

  it('resets the stale selection on COMPANY_ACCESS_DENIED', () => {
    selectStaleCompany()

    expect(handleCompanyScopeRejection('COMPANY_ACCESS_DENIED')).toBe(true)

    expect(useCompanyStore.getState().currentCompanyId).toBeNull()
    expect(useCompanyStore.getState().companies).toEqual([])
    expect(localStorage.getItem(COMPANY_SELECTION_KEY)).toBeNull()
  })

  it('resets the stale selection on INVALID_COMPANY_ID', () => {
    selectStaleCompany()

    expect(handleCompanyScopeRejection('INVALID_COMPANY_ID')).toBe(true)

    expect(useCompanyStore.getState().currentCompanyId).toBeNull()
    expect(localStorage.getItem(COMPANY_SELECTION_KEY)).toBeNull()
  })

  it('also drops the previous company locations', () => {
    selectStaleCompany()
    useLocationStore.setState({ currentLocationId: 'loc-1', locations: [], isLoading: false })

    handleCompanyScopeRejection('COMPANY_ACCESS_DENIED')

    expect(useLocationStore.getState().currentLocationId).toBeNull()
  })

  it('is a no-op the SECOND time — the reset can never loop', () => {
    selectStaleCompany()

    expect(handleCompanyScopeRejection('COMPANY_ACCESS_DENIED')).toBe(true)
    expect(handleCompanyScopeRejection('COMPANY_ACCESS_DENIED')).toBe(false)
    expect(handleCompanyScopeRejection('INVALID_COMPANY_ID')).toBe(false)
  })

  it('leaves the selection alone for an unrelated 403 code', () => {
    selectStaleCompany()

    expect(handleCompanyScopeRejection('FORBIDDEN')).toBe(false)
    expect(handleCompanyScopeRejection('NO_COMPANY_ACCESS')).toBe(false)
    expect(handleCompanyScopeRejection(null)).toBe(false)

    expect(useCompanyStore.getState().currentCompanyId).toBe(STALE_COMPANY_ID)
    expect(localStorage.getItem(COMPANY_SELECTION_KEY)).toBe(STALE_COMPANY_ID)
  })
})
