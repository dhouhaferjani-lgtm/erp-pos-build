import { useMemo } from 'react'

export interface BankAccountValidationResult {
  status: 'empty' | 'valid' | 'invalid' | 'unsupported'
  normalized: string
  derivedIban: string | null
  errors: string[]
}

interface CountryBankingConfig {
  ribLength: number
  ibanLength: number
}

const COUNTRY_CONFIG: Partial<Record<string, CountryBankingConfig>> = {
  TN: { ribLength: 20, ibanLength: 24 },
}

function mod97(value: string): number {
  let remainder = 0
  for (const character of value) {
    const digit = character.charCodeAt(0) - 48
    remainder = ((remainder * 10) + digit) % 97
  }
  return remainder
}

function expandLetters(value: string): string {
  let expanded = ''
  for (const character of value) {
    const code = character.charCodeAt(0)
    expanded += code >= 65 && code <= 90 ? String(code - 55) : character
  }
  return expanded
}

function normalizeRib(value: string): string {
  return value.replace(/[\s-]/gu, '')
}

function normalizeIban(value: string): string {
  return value.replace(/\s/gu, '').toUpperCase()
}

function deriveIban(rib: string, country: string): string {
  const countryNumeric = expandLetters(country)
  const checkDigits = String(98 - mod97(`${rib}${countryNumeric}00`)).padStart(2, '0')
  return `${country}${checkDigits}${rib}`
}

function validateRib(value: string, country: string): BankAccountValidationResult {
  const normalized = normalizeRib(value)
  if (normalized === '') {
    return { status: 'empty', normalized, derivedIban: null, errors: [] }
  }

  const config = COUNTRY_CONFIG[country]
  if (config === undefined) {
    return { status: 'unsupported', normalized, derivedIban: null, errors: ['unsupported_country'] }
  }
  if (!/^\d+$/u.test(normalized)) {
    return { status: 'invalid', normalized, derivedIban: null, errors: ['invalid_format'] }
  }
  if (normalized.length !== config.ribLength) {
    return { status: 'invalid', normalized, derivedIban: null, errors: ['invalid_length'] }
  }

  const body = normalized.slice(0, -2)
  const expectedKey = String(97 - mod97(`${body}00`)).padStart(2, '0')
  if (normalized.slice(-2) !== expectedKey) {
    return { status: 'invalid', normalized, derivedIban: null, errors: ['invalid_checksum'] }
  }

  return {
    status: 'valid',
    normalized,
    derivedIban: deriveIban(normalized, country),
    errors: [],
  }
}

function validateIban(value: string): BankAccountValidationResult {
  const normalized = normalizeIban(value)
  if (normalized === '') {
    return { status: 'empty', normalized, derivedIban: null, errors: [] }
  }
  if (!/^[A-Z]{2}\d{2}[A-Z0-9]+$/u.test(normalized)) {
    return { status: 'invalid', normalized, derivedIban: null, errors: ['invalid_format'] }
  }

  const country = normalized.slice(0, 2)
  const config = COUNTRY_CONFIG[country]
  if (config === undefined) {
    return { status: 'unsupported', normalized, derivedIban: null, errors: ['unsupported_country'] }
  }
  if (normalized.length !== config.ibanLength) {
    return { status: 'invalid', normalized, derivedIban: null, errors: ['invalid_length'] }
  }

  const rearranged = `${normalized.slice(4)}${normalized.slice(0, 4)}`
  if (mod97(expandLetters(rearranged)) !== 1) {
    return { status: 'invalid', normalized, derivedIban: null, errors: ['invalid_checksum'] }
  }

  return { status: 'valid', normalized, derivedIban: normalized, errors: [] }
}

export function useBankAccountValidation(
  value: string,
  country: string,
  kind: 'rib' | 'iban',
): BankAccountValidationResult {
  return useMemo(() => {
    const countryCode = country.trim().toUpperCase()
    return kind === 'rib' ? validateRib(value, countryCode) : validateIban(value)
  }, [country, kind, value])
}
