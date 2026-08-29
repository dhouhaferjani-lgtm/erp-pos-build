import { describe, expect, it } from 'vitest'

import { shouldShowPartnerB2BFields } from './partnerNature'

describe('shouldShowPartnerB2BFields', () => {
  it('is an idempotent pure function of a second-company legacy row', () => {
    const companyBRow = {
      business_registration_number: null,
      company_legal_name: null,
      credit_limit: null,
      customer_category: null,
      vat_number: '1234567ABC000',
    } as const

    expect(shouldShowPartnerB2BFields(companyBRow)).toBe(true)
    expect(shouldShowPartnerB2BFields(companyBRow)).toBe(true)
    expect(companyBRow.customer_category).toBeNull()
  })
})
