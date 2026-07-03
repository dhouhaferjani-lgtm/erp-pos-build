import { describe, expect, it } from 'vitest'

import { getCountryPlaceholders } from '../countryPlaceholders'

describe('getCountryPlaceholders', () => {
  it('returns Tunisian examples for TN', () => {
    const p = getCountryPlaceholders('TN')
    expect(p).toEqual({
      postalCode: '1000',
      city: 'Tunis',
      phone: '+216 71 123 456',
      taxId: '1234567AM000',
      registrationNumber: 'B011234562022',
      street: '10 Avenue Habib Bourguiba',
    })
  })

  it('returns French examples for FR', () => {
    expect(getCountryPlaceholders('FR').taxId).toBe('FR12345678901')
    expect(getCountryPlaceholders('FR').postalCode).toBe('75001')
  })

  it('returns Moroccan examples for MA', () => {
    expect(getCountryPlaceholders('MA').postalCode).toBe('20000')
    expect(getCountryPlaceholders('MA').city).toBe('Casablanca')
    expect(getCountryPlaceholders('MA').taxId).toContain('ICE')
  })

  it('returns neutral examples with the given phone prefix for unknown countries', () => {
    const p = getCountryPlaceholders('DE', '49')
    expect(p.phone).toBe('+49 …')
    expect(p.city).toBe('') // neutral: let the label speak
    expect(p.postalCode).toBe('')
    expect(p.taxId).toBe('')
  })

  it('returns fully empty neutral placeholders without a phone prefix', () => {
    const p = getCountryPlaceholders('DE')
    expect(p).toEqual({
      postalCode: '',
      city: '',
      phone: '',
      taxId: '',
      registrationNumber: '',
      street: '',
    })
  })

  it('never returns French examples for non-FR countries', () => {
    expect(getCountryPlaceholders('DE', '49').postalCode).not.toBe('75001')
    expect(getCountryPlaceholders(null).postalCode).not.toBe('75001')
    expect(getCountryPlaceholders(undefined).city).not.toBe('Paris')
  })

  it('tolerates lowercase / untrimmed codes', () => {
    expect(getCountryPlaceholders(' tn ').postalCode).toBe('1000')
  })
})
