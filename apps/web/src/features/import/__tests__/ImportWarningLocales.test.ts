import { describe, expect, it } from 'vitest'

import ar from '@/locales/ar/import.json'
import en from '@/locales/en/import.json'
import fr from '@/locales/fr/import.json'
import { WARNING_TRANSLATION_KEYS } from '../warningCodes'

describe('import warning locale parity', () => {
  const enrichmentCodes = [
    'enrichment_not_found',
    'enrichment_unavailable',
    'enrichment_invalid_barcode',
    'enrichment_cap_exceeded',
    'enrichment_vertical_not_supported',
    'enrichment_barcode_missing',
  ] as const

  it('registers every import-enrichment warning code', () => {
    for (const code of enrichmentCodes) {
      expect(WARNING_TRANSLATION_KEYS).toHaveProperty(code, true)
    }
  })

  it.each([
    ['en', en],
    ['fr', fr],
    ['ar', ar],
  ] as const)('has copy for every generated warning code in %s', (_locale, messages) => {
    const warningCodes = Object.keys(WARNING_TRANSLATION_KEYS)
    const known = new Set(warningCodes)

    expect(
      Object.keys(messages.warnings)
        .filter((code) => known.has(code))
        .sort(),
    ).toEqual([...warningCodes].sort())
    for (const [code, copy] of Object.entries(messages.warnings)) {
      if (known.has(code)) {
        expect(copy).toEqual(expect.any(String))
        expect(copy.trim()).not.toBe('')
      }
    }

    expect(messages.duplicates.refusedSummary).toEqual(expect.any(String))
    expect(messages.duplicates.refusedSummary.trim()).not.toBe('')
    expect(messages.duplicates.bucket.refused).toEqual(expect.any(String))
    expect(messages.duplicates.bucket.refused.trim()).not.toBe('')
  })
})
