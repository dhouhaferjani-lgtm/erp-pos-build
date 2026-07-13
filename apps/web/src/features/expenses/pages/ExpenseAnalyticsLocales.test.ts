import { describe, expect, it } from 'vitest'

import ar from '@/locales/ar/expenses.json'
import en from '@/locales/en/expenses.json'
import fr from '@/locales/fr/expenses.json'

function leafKeys(value: unknown, prefix = ''): string[] {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) return [prefix]

  return Object.entries(value).flatMap(([key, child]) =>
    leafKeys(child, prefix ? `${prefix}.${key}` : key),
  )
}

describe('expense analytics locale completeness', () => {
  it('keeps every analytics leaf present in English, French, and Arabic', () => {
    const english = leafKeys(en.analytics).sort()
    expect(english.length).toBeGreaterThan(0)
    expect(leafKeys(fr.analytics).sort()).toEqual(english)
    expect(leafKeys(ar.analytics).sort()).toEqual(english)
  })
})
