import { AxiosError, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { api, handleCompanyScopeRejection } from '../api'
import { useAuthStore } from '../../stores/authStore'
import {
  clearDeniedCompanyIds,
  resolveCompanySelection,
  useCompanyStore,
  type Company,
} from '../../stores/companyStore'
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
const OTHER_COMPANY_ID = '3f1b8d02-6c1e-4a55-9d21-9a0f0b6e77aa'
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
  clearDeniedCompanyIds()

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

    expect(handleCompanyScopeRejection('COMPANY_ACCESS_DENIED', STALE_COMPANY_ID)).toBe(true)

    expect(useCompanyStore.getState().currentCompanyId).toBeNull()
    expect(useCompanyStore.getState().companies).toEqual([])
    expect(localStorage.getItem(COMPANY_SELECTION_KEY)).toBeNull()
  })

  it('resets the stale selection on INVALID_COMPANY_ID', () => {
    selectStaleCompany()

    expect(handleCompanyScopeRejection('INVALID_COMPANY_ID', STALE_COMPANY_ID)).toBe(true)

    expect(useCompanyStore.getState().currentCompanyId).toBeNull()
    expect(localStorage.getItem(COMPANY_SELECTION_KEY)).toBeNull()
  })

  it('also drops the previous company locations', () => {
    selectStaleCompany()
    useLocationStore.setState({ currentLocationId: 'loc-1', locations: [], isLoading: false })

    handleCompanyScopeRejection('COMPANY_ACCESS_DENIED', STALE_COMPANY_ID)

    expect(useLocationStore.getState().currentLocationId).toBeNull()
  })

  it('is a no-op the SECOND time — the reset can never loop', () => {
    selectStaleCompany()

    expect(handleCompanyScopeRejection('COMPANY_ACCESS_DENIED', STALE_COMPANY_ID)).toBe(true)
    expect(handleCompanyScopeRejection('COMPANY_ACCESS_DENIED', STALE_COMPANY_ID)).toBe(false)
    expect(handleCompanyScopeRejection('INVALID_COMPANY_ID', STALE_COMPANY_ID)).toBe(false)
  })

  it('leaves the selection alone for an unrelated 403 code', () => {
    selectStaleCompany()

    expect(handleCompanyScopeRejection('FORBIDDEN', STALE_COMPANY_ID)).toBe(false)
    expect(handleCompanyScopeRejection('NO_COMPANY_ACCESS', STALE_COMPANY_ID)).toBe(false)
    expect(handleCompanyScopeRejection(null, STALE_COMPANY_ID)).toBe(false)

    expect(useCompanyStore.getState().currentCompanyId).toBe(STALE_COMPANY_ID)
    expect(localStorage.getItem(COMPANY_SELECTION_KEY)).toBe(STALE_COMPANY_ID)
  })
})

describe('handleCompanyScopeRejection — the rejection must match what was SENT (F-3)', () => {
  beforeEach(() => {
    vi.spyOn(console, 'warn').mockImplementation(() => undefined)
  })

  it('ignores a 403 for a company that is NOT the current selection', () => {
    // The user switched STALE -> OTHER; a request issued moments earlier, still
    // carrying STALE, comes back 403. Wiping OTHER would silently undo the
    // user's deliberate switch.
    selectStaleCompany()
    useCompanyStore.setState({
      currentCompanyId: OTHER_COMPANY_ID,
      companies: [company(OTHER_COMPANY_ID)],
      isLoading: false,
    })
    localStorage.setItem(COMPANY_SELECTION_KEY, OTHER_COMPANY_ID)

    expect(handleCompanyScopeRejection('COMPANY_ACCESS_DENIED', STALE_COMPANY_ID)).toBe(false)

    expect(useCompanyStore.getState().currentCompanyId).toBe(OTHER_COMPANY_ID)
    expect(localStorage.getItem(COMPANY_SELECTION_KEY)).toBe(OTHER_COMPANY_ID)
  })

  it('ignores a 403 on a request that carried no company header', () => {
    selectStaleCompany()

    expect(handleCompanyScopeRejection('COMPANY_ACCESS_DENIED', null)).toBe(false)
    expect(useCompanyStore.getState().currentCompanyId).toBe(STALE_COMPANY_ID)
  })

  it('marks the denied company so the bootstrap cannot re-pick it (F-2)', () => {
    selectStaleCompany()

    handleCompanyScopeRejection('COMPANY_ACCESS_DENIED', STALE_COMPANY_ID)

    // The membership list still contains it — the server lists it, the
    // middleware denies it. resolveCompanySelection must not choose it again.
    expect(resolveCompanySelection([company(STALE_COMPANY_ID)], null)).toBeNull()
    expect(
      resolveCompanySelection([company(STALE_COMPANY_ID), company(OTHER_COMPANY_ID)], null),
    ).toBe(OTHER_COMPANY_ID)
  })
})

describe('the response interceptor feeds the SENT company id through', () => {
  beforeEach(() => {
    vi.spyOn(console, 'warn').mockImplementation(() => undefined)
    vi.spyOn(console, 'error').mockImplementation(() => undefined)
  })

  function denyWith(config: InternalAxiosRequestConfig, onSend?: () => void): Promise<never> {
    onSend?.()
    const response = {
      data: { error: { code: 'COMPANY_ACCESS_DENIED', message: 'denied' } },
      status: 403,
      statusText: 'Forbidden',
      headers: {},
      config,
    } as AxiosResponse
    return Promise.reject(new AxiosError('denied', 'ERR_BAD_REQUEST', config, {}, response))
  }

  it('resets when the denied company is still the current selection', async () => {
    selectStaleCompany()
    api.defaults.adapter = (config) => denyWith(config)

    await expect(api.get('/products')).rejects.toThrow()

    expect(useCompanyStore.getState().currentCompanyId).toBeNull()
    expect(localStorage.getItem(COMPANY_SELECTION_KEY)).toBeNull()
  })

  it('leaves a selection made WHILE the request was in flight alone', async () => {
    selectStaleCompany()
    api.defaults.adapter = (config) =>
      denyWith(config, () => {
        // the user switches company before the 403 lands
        useCompanyStore.setState({
          currentCompanyId: OTHER_COMPANY_ID,
          companies: [company(OTHER_COMPANY_ID)],
          isLoading: false,
        })
        localStorage.setItem(COMPANY_SELECTION_KEY, OTHER_COMPANY_ID)
      })

    await expect(api.get('/products')).rejects.toThrow()

    expect(useCompanyStore.getState().currentCompanyId).toBe(OTHER_COMPANY_ID)
    expect(localStorage.getItem(COMPANY_SELECTION_KEY)).toBe(OTHER_COMPANY_ID)
  })
})
