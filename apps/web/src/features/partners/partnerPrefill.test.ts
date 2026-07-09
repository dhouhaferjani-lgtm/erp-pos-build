import { describe, expect, it } from 'vitest'
import { readPartnerPrefill } from './partnerPrefill'

describe('readPartnerPrefill', () => {
  it('returns all 9 keys trimmed when the full object is valid', () => {
    const state = {
      partnerPrefill: {
        name: '  Acme Corp  ',
        vat_number: ' FR123456789 ',
        phone: ' +33123456789 ',
        email: ' contact@acme.test ',
        street_address: ' 12 Rue de Paris ',
        city: ' Paris ',
        state: ' Ile-de-France ',
        postal_code: ' 75001 ',
        country_code: ' FR ',
      },
    }

    expect(readPartnerPrefill(state)).toEqual({
      name: 'Acme Corp',
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

  it('keeps only the present keys for a partial object', () => {
    const state = {
      partnerPrefill: {
        name: 'Acme Corp',
        city: 'Paris',
      },
    }

    expect(readPartnerPrefill(state)).toEqual({
      name: 'Acme Corp',
      city: 'Paris',
    })
  })

  it('drops empty-string and whitespace-only values', () => {
    const state = {
      partnerPrefill: {
        name: 'Acme Corp',
        vat_number: '',
        phone: '   ',
        city: 'Paris',
      },
    }

    expect(readPartnerPrefill(state)).toEqual({
      name: 'Acme Corp',
      city: 'Paris',
    })
  })

  it('drops unknown/extra keys', () => {
    const state = {
      partnerPrefill: {
        name: 'Acme Corp',
        unknown_field: 'should be dropped',
        another_bogus_key: 42,
      },
    }

    expect(readPartnerPrefill(state)).toEqual({
      name: 'Acme Corp',
    })
  })

  it.each([
    ['null', null],
    ['a string', 'not-an-object'],
    ['a number', 42],
    ['an array', ['a', 'b']],
  ])('returns null when state is %s', (_label, state) => {
    expect(readPartnerPrefill(state)).toBeNull()
  })

  it('returns null when partnerPrefill is null', () => {
    expect(readPartnerPrefill({ partnerPrefill: null })).toBeNull()
  })

  it('returns null when partnerPrefill is a non-object primitive', () => {
    expect(readPartnerPrefill({ partnerPrefill: 'x' })).toBeNull()
  })

  it('returns null when partnerPrefill has only whitespace values', () => {
    expect(readPartnerPrefill({ partnerPrefill: { name: '   ' } })).toBeNull()
  })

  it('returns null when state has no partnerPrefill property', () => {
    expect(readPartnerPrefill({ someOtherKey: 'value' })).toBeNull()
  })
})
