import { readdirSync, readFileSync } from 'node:fs'
import { extname, join } from 'node:path'
import { describe, expect, it } from 'vitest'

function sourceFiles(directory: string): string[] {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = join(directory, entry.name)
    if (entry.isDirectory()) return sourceFiles(path)
    return ['.ts', '.tsx'].includes(extname(entry.name)) ? [path] : []
  })
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}

describe('placement terminology migration', () => {
  it('has no stale zones.* translation keys in counting or settings source', () => {
    const roots = [
      join(process.cwd(), 'src/features/inventory-counting'),
      join(process.cwd(), 'src/features/settings'),
    ]
    const stale = roots
      .flatMap(sourceFiles)
      .filter((file) => /["'](?:inventory:)?zones\./.test(readFileSync(file, 'utf8')))

    expect(stale).toEqual([])
  })

  it('uses placement as the top-level inventory translation key in every locale', () => {
    for (const locale of ['en', 'fr', 'ar']) {
      const translation: unknown = JSON.parse(
        readFileSync(join(process.cwd(), `src/locales/${locale}/inventory.json`), 'utf8'),
      )
      expect(translation).toBeTypeOf('object')
      expect(translation).not.toBeNull()
      if (typeof translation !== 'object' || translation === null) throw new Error('Expected translation object')
      expect('placement' in translation).toBe(true)
      expect('zones' in translation).toBe(false)
    }
  })

  it('labels the legacy zone counting enum as Node / Zone in every locale', () => {
    const expected = {
      en: 'Node / Zone',
      fr: 'Nœud / Zone',
      ar: 'عقدة / منطقة',
    } as const

    for (const [locale, expectedLabel] of Object.entries(expected)) {
      const translation: unknown = JSON.parse(
        readFileSync(join(process.cwd(), `src/locales/${locale}/inventory.json`), 'utf8'),
      )

      if (!isRecord(translation) || !isRecord(translation.counting)) throw new Error(`Missing counting translations for ${locale}`)
      const { create, scopeTypes } = translation.counting
      if (!isRecord(scopeTypes) || !isRecord(create)) throw new Error(`Missing node-scope translations for ${locale}`)

      expect(scopeTypes.zone).toBe(expectedLabel)
      expect(create.zoneSelectionHelper).toBeTypeOf('string')
    }
  })
})
