import { describe, it, expect, vi, beforeEach } from 'vitest'
import { getLocations, getLocation, getTransactionLocations } from '../locations'
import { getScopedLocations } from '../scopedLocations'

vi.mock('@/lib/api', () => ({
  api: {
    get: vi.fn(),
  },
  apiGet: vi.fn(),
}))

import { api, apiGet } from '@/lib/api'

const mockApi = api as unknown as { get: ReturnType<typeof vi.fn> }
const mockApiGet = apiGet as unknown as ReturnType<typeof vi.fn>

// Real /api/v1/locations payload shape (captured live 2026-07-07): snake_case.
const rawLocation = {
  id: '66cc10a3-9647-44d3-8d1b-57ef180116e3',
  company_id: '019f2313-6594-7376-bba6-b9e751c1cab8',
  name: 'PharmaBio Sousse — Médina',
  code: 'STORE-SOU',
  type: 'shop',
  phone: '+216 73 100 003',
  email: 'sousse-medina@pharmabio.tn',
  address_street: '7 Rue Ali Belhouane',
  address_city: 'Sousse',
  address_postal_code: '4000',
  address_country: 'TN',
  tax_id: '1234567AM003',
  vat_number: null,
  legal_identifiers: { matricule_fiscal: '1234567AM003' },
  is_default: false,
  is_active: true,
  pos_enabled: true,
  onboarding_mode: true,
  pos_stock_policy_override: null,
  created_at: '2026-07-02T13:45:01+00:00',
  updated_at: '2026-07-07T10:46:47+00:00',
}

describe('locations api mapping', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('getLocations maps the snake_case API payload to the camelCase Location type', async () => {
    mockApiGet.mockResolvedValue([rawLocation])

    const [location] = await getLocations()

    expect(mockApiGet).toHaveBeenCalledWith('/locations')
    expect(location.isActive).toBe(true)
    expect(location.isDefault).toBe(false)
    expect(location.posEnabled).toBe(true)
    expect(location.companyId).toBe(rawLocation.company_id)
    expect(location.addressStreet).toBe(rawLocation.address_street)
    expect(location.addressCity).toBe(rawLocation.address_city)
    expect(location.addressPostalCode).toBe(rawLocation.address_postal_code)
    expect(location.addressCountry).toBe(rawLocation.address_country)
    expect(location.taxId).toBe(rawLocation.tax_id)
    expect(location.vatNumber).toBeNull()
    expect(location.createdAt).toBe(rawLocation.created_at)
    expect(location.updatedAt).toBe(rawLocation.updated_at)
    expect(location.name).toBe(rawLocation.name)
    expect(location.code).toBe(rawLocation.code)
  })

  it('getLocation maps the single-location payload the same way', async () => {
    mockApi.get.mockResolvedValue({ data: { data: rawLocation } })

    const location = await getLocation(rawLocation.id)

    expect(mockApi.get).toHaveBeenCalledWith(`/locations/${rawLocation.id}`)
    expect(location.isActive).toBe(true)
    expect(location.addressCity).toBe(rawLocation.address_city)
  })

  it('normalizes a nullable location code at the API boundary', async () => {
    mockApiGet.mockResolvedValue([{ ...rawLocation, code: null }])

    const [location] = await getLocations()

    expect(location.code).toBe('')
  })

  it('normalizes a nullable transaction location code at the API boundary', async () => {
    mockApiGet.mockResolvedValue([{
      id: rawLocation.id,
      name: rawLocation.name,
      code: null,
      type: rawLocation.type,
      is_default: false,
      is_active: true,
    }])

    const [location] = await getTransactionLocations()

    expect(location.code).toBe('')
  })

  it('maps scoped locations from the ungated company endpoint', async () => {
    mockApiGet.mockResolvedValue([{
      id: rawLocation.id,
      name: rawLocation.name,
      code: null,
      type: rawLocation.type,
      is_default: true,
      is_active: false,
    }])

    const [location] = await getScopedLocations()

    expect(mockApiGet).toHaveBeenCalledWith('/company/locations')
    expect(location).toEqual({
      id: rawLocation.id,
      name: rawLocation.name,
      code: '',
      type: rawLocation.type,
      isDefault: true,
      isActive: false,
    })
  })
})
