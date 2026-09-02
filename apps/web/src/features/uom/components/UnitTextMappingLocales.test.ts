import { describe, expect, it } from 'vitest'
import ar from '@/locales/ar/uom.json'
import en from '@/locales/en/uom.json'
import fr from '@/locales/fr/uom.json'

describe('unit text mapping locales', () => {
  it.each([
    ['English', en.unmapped],
    ['French', fr.unmapped],
    ['Arabic', ar.unmapped],
  ])('%s carries the complete mapping action copy', (_language, unmapped) => {
    expect(unmapped.title).not.toBe('')
    expect(unmapped.columns.source).not.toBe('')
    expect(unmapped.columns.count).not.toBe('')
    expect(unmapped.columns.target).not.toBe('')
    expect(unmapped.confirm).toContain('{{source}}')
    expect(unmapped.confirm).toContain('{{code}}')
    expect(unmapped.targetLabel).toContain('{{source}}')
    expect(unmapped.pendingImports).toContain('{{count}}')
    expect(unmapped).toHaveProperty('successAliasStored')
  })

  it('does not serve English panel copy for French or Arabic', () => {
    expect(fr.unmapped.title).not.toBe(en.unmapped.title)
    expect(ar.unmapped.title).not.toBe(en.unmapped.title)
  })
})
