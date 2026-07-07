import { describe, expect, it } from 'vitest'
import { transformLocationResponse } from '../api'

describe('transformLocationResponse tax fields', () => {
  it('maps tax_id, vat_number, legal_identifiers to camelCase', () => {
    const out = transformLocationResponse({
      id: '1',
      company_id: 'c',
      name: 'B',
      code: 'B',
      type: 'shop',
      phone: null,
      email: null,
      address_street: null,
      address_city: null,
      address_postal_code: null,
      address_country: 'FR',
      tax_id: '73282932000074',
      vat_number: 'FR40303265045',
      legal_identifiers: { siret: '73282932000074' },
      is_default: false,
      is_active: true,
      pos_enabled: true,
      onboarding_mode: false,
      pos_stock_policy_override: null,
      created_at: 'x',
      updated_at: 'y',
    })

    expect(out.taxId).toBe('73282932000074')
    expect(out.vatNumber).toBe('FR40303265045')
    expect(out.legalIdentifiers).toEqual({ siret: '73282932000074' })
  })
})
