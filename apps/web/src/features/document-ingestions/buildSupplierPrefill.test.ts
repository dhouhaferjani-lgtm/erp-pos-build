import { describe, expect, it } from 'vitest'
import { buildSupplierPrefill } from './buildSupplierPrefill'
import type { ExtractedField } from './types'

function f(value: string): ExtractedField {
  return { value, confidence: 0.9, sourceBbox: null }
}

describe('buildSupplierPrefill', () => {
  it('maps all 9 canonical fields, trimmed', () => {
    const supplier = {
      name: f('  Acme Supplies  '),
      vat_number: f(' FR123456789 '),
      phone: f(' +33123456789 '),
      email: f(' contact@acme.test '),
      street_address: f(' 12 Rue de Paris '),
      city: f(' Paris '),
      state: f(' Ile-de-France '),
      postal_code: f(' 75001 '),
      country_code: f(' fr '),
    }

    expect(buildSupplierPrefill(supplier)).toEqual({
      name: 'Acme Supplies',
      vat_number: 'FR123456789',
      phone: '+33123456789',
      email: 'contact@acme.test',
      street_address: '12 Rue de Paris',
      city: 'Paris',
      state: 'Ile-de-France',
      postal_code: '75001',
      country_code: 'FR',
    })
  })

  it('resolves alias keys to the right targets', () => {
    const supplier = {
      tax_id: f('TN9876543'),
      phoneno: f('12345678'),
      address: f('45 Avenue Habib Bourguiba'),
      pincode: f('2080'),
      region: f('Ariana'),
    }

    expect(buildSupplierPrefill(supplier)).toEqual({
      vat_number: 'TN9876543',
      phone: '12345678',
      street_address: '45 Avenue Habib Bourguiba',
      postal_code: '2080',
      state: 'Ariana',
    })
  })

  it('prefers vat_number over tax_id when both are present', () => {
    const supplier = {
      vat_number: f('FR000111222'),
      tax_id: f('TN999888'),
    }

    expect(buildSupplierPrefill(supplier)).toEqual({
      vat_number: 'FR000111222',
    })
  })

  it('omits fields whose value is blank or whitespace-only', () => {
    const supplier = {
      name: f('Acme Supplies'),
      vat_number: f('   '),
      email: f(''),
      city: f('Paris'),
    }

    expect(buildSupplierPrefill(supplier)).toEqual({
      name: 'Acme Supplies',
      city: 'Paris',
    })
  })

  it('omits country_code when not a 2-letter code, uppercases valid 2-letter codes', () => {
    const withInvalid = buildSupplierPrefill({
      country_code: f('Tunisia'),
    })
    expect(withInvalid).toEqual({})

    const withValid = buildSupplierPrefill({
      country_code: f('fr'),
    })
    expect(withValid).toEqual({ country_code: 'FR' })
  })

  it('returns {} when supplier is undefined', () => {
    expect(buildSupplierPrefill(undefined)).toEqual({})
  })

  it('returns {} when supplier is an empty object', () => {
    expect(buildSupplierPrefill({})).toEqual({})
  })

  it('ignores unknown extra keys', () => {
    const supplier = {
      name: f('Acme Supplies'),
      unknown_field: f('whatever'),
      another_bogus_key: f('ignored'),
    }

    expect(buildSupplierPrefill(supplier)).toEqual({
      name: 'Acme Supplies',
    })
  })

  it('resolves further alias keys: vat, tax_number, phone_number, telephone, street, zip, zip_code', () => {
    expect(buildSupplierPrefill({ vat: f('VAT-1') })).toEqual({ vat_number: 'VAT-1' })
    expect(buildSupplierPrefill({ tax_number: f('VAT-2') })).toEqual({ vat_number: 'VAT-2' })
    expect(buildSupplierPrefill({ phone_number: f('555-1') })).toEqual({ phone: '555-1' })
    expect(buildSupplierPrefill({ telephone: f('555-2') })).toEqual({ phone: '555-2' })
    expect(buildSupplierPrefill({ street: f('Main St') })).toEqual({ street_address: 'Main St' })
    expect(buildSupplierPrefill({ zip: f('10001') })).toEqual({ postal_code: '10001' })
    expect(buildSupplierPrefill({ zip_code: f('10002') })).toEqual({ postal_code: '10002' })
  })

  it('falls through to a later alias when an earlier alias has a non-string value, without throwing', () => {
    const supplier = {
      vat_number: { value: 42 as unknown as string, confidence: 0.9, sourceBbox: null },
      tax_id: f('TN9876543'),
    }

    expect(() => buildSupplierPrefill(supplier)).not.toThrow()
    expect(buildSupplierPrefill(supplier)).toEqual({
      vat_number: 'TN9876543',
    })
  })

  it('omits a target whose only candidate has a non-string value, without throwing', () => {
    const supplier = {
      name: f('Acme Supplies'),
      vat_number: { value: 42 as unknown as string, confidence: 0.9, sourceBbox: null },
    }

    expect(() => buildSupplierPrefill(supplier)).not.toThrow()
    expect(buildSupplierPrefill(supplier)).toEqual({
      name: 'Acme Supplies',
    })
  })
})
