import { beforeEach, describe, expect, it, vi } from 'vitest'

const mockApiPost = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', () => ({
  apiPost: mockApiPost,
}))

import { createCompany } from './api'

describe('createCompany', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('maps the complete CompanyController formatter shape to a store Company', async () => {
    mockApiPost.mockResolvedValue({
      id: 'company-created',
      tenant_id: 'tenant-A',
      name: 'Created Company',
      legal_name: null,
      code: 'CREATED',
      country_code: 'TN',
      tax_id: '1234567ABC000',
      registration_number: 'B241234567',
      vat_number: 'TN1234567ABC000',
      email: 'finance@example.test',
      phone: '+21671000000',
      website: 'https://example.test',
      currency: 'TND',
      locale: 'fr_TN',
      timezone: 'Africa/Tunis',
      status: 'active',
      address_street: '1 Avenue Habib Bourguiba',
      address_street_2: null,
      address_city: 'Tunis',
      address_state: 'Tunis',
      address_postal_code: '1000',
      default_tax_rate: '19.00',
      default_tax_configuration_id: null,
      tax_status: 'taxable',
      default_target_margin: null,
      default_minimum_margin: null,
      default_max_discount_percent: null,
      discount_floor_mode: 'minimum_margin',
      price_entry_mode: 'tax_inclusive',
      created_at: '2026-08-29T10:00:00.000000Z',
      updated_at: '2026-08-29T10:00:00.000000Z',
    })

    const result = await createCompany({
      name: 'Created Company',
      countryCode: 'TN',
      currency: 'TND',
      locale: 'fr_TN',
      timezone: 'Africa/Tunis',
      taxId: '1234567ABC000',
    })

    expect(result.id).toBe('company-created')
    expect(result.name).toBe('Created Company')
    expect(result.legalName).toBe('Created Company')
    expect(result.taxId).toBe('1234567ABC000')
    expect(result.countryCode).toBe('TN')
    expect(result.currency).toBe('TND')
    expect(result.locale).toBe('fr_TN')
    expect(result.timezone).toBe('Africa/Tunis')
    expect(result.isPrimary).toBe(false)
  })
})
