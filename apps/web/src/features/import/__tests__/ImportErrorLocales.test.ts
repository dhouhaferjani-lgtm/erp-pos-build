import { describe, expect, it } from 'vitest'

import ar from '@/locales/ar/import.json'
import en from '@/locales/en/import.json'
import fr from '@/locales/fr/import.json'
import { ERROR_TRANSLATION_KEYS } from '../errorCodes'

describe('import error locale parity', () => {
  it.each([
    ['en', en],
    ['fr', fr],
    ['ar', ar],
  ] as const)('has copy for every generated import error code in %s', (_locale, messages) => {
    const errorCodes = Object.keys(ERROR_TRANSLATION_KEYS)
    const known = new Set(errorCodes)

    expect(
      Object.keys(messages.errors)
        .filter((code) => known.has(code))
        .sort(),
    ).toEqual([...errorCodes].sort())
    for (const [code, copy] of Object.entries(messages.errors)) {
      if (known.has(code)) {
        expect(copy).toEqual(expect.any(String))
        expect(String(copy).trim()).not.toBe('')
      }
    }
  })
})
