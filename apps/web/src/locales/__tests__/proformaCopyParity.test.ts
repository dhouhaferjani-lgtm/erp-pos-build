/**
 * C-F0w — the web's proforma copy IS the backend's proforma copy.
 *
 * WHY. The PDF says `Proforma — non-fiscal document` because `lang/en/documents.php`
 * says so; the web detail pages say it because `locales/en/sales.json` says so. Two
 * files, one sentence, no mechanism keeping them equal — which is the defect class
 * the C-F0 conventions gate named F-C1/F-C2 inside the backend and told this lane to
 * close one level up. A customer who prints the invoice and a colleague who reads
 * the screen must be looking at the same document.
 *
 * The PHP arrays are read as TEXT rather than executed: the block is a flat list of
 * `'key' => 'literal'` pairs, and a test that could run PHP would be a much larger
 * dependency than the thing it guards. If someone ever makes those values dynamic,
 * this test fails loudly rather than passing vacuously — the extractor returns no
 * keys and the key-set assertion goes red.
 */
import { describe, it, expect } from 'vitest'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

import enSales from '../en/sales.json'
import frSales from '../fr/sales.json'
import arSales from '../ar/sales.json'

const LOCALES = ['en', 'fr', 'ar'] as const
type Locale = (typeof LOCALES)[number]

const WEB_BUNDLES: Record<Locale, Record<string, unknown>> = {
  en: enSales as Record<string, unknown>,
  fr: frSales as Record<string, unknown>,
  ar: arSales as Record<string, unknown>,
}

/** web key → backend key, inside `documents.*`. */
const PROFORMA_KEYS: Record<string, string> = {
  title: 'title',
  detail: 'detail',
  estimatedTotal: 'estimated_total',
  stampDuty: 'stamp_duty',
  discount: 'discount',
  adjustment: 'adjustment',
}

const CREDIT_NOTE_KEYS: Record<string, string> = {
  balanceNote: 'balance_note',
}

const langDir = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '../../../../api/lang'
)

/**
 * The `'key' => '…'` pairs of one top-level block in a `lang/*.php` array.
 * Handles both quote styles: `fr` writes `"…"` where the sentence has an
 * apostrophe, `ar` and `en` write `'…'`.
 */
function phpBlock(locale: Locale, block: string): Record<string, string> {
  const source = fs.readFileSync(path.join(langDir, locale, 'documents.php'), 'utf8')
  const opening = new RegExp(`'${block}'\\s*=>\\s*\\[`).exec(source)
  if (opening === null) {
    return {}
  }

  const body = source.slice(opening.index + opening[0].length)
  const end = body.indexOf('\n    ],')
  const scope = end === -1 ? body : body.slice(0, end)

  const pairs: Record<string, string> = {}
  const entry = /'([a-z_]+)'\s*=>\s*(?:'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)")/g
  for (const match of scope.matchAll(entry)) {
    const groups: (string | undefined)[] = match
    const key = groups[1]
    const raw = groups[2] ?? groups[3] ?? ''
    if (key === undefined) {
      continue
    }
    pairs[key] = raw.replace(/\\'/g, "'").replace(/\\"/g, '"').replace(/\\\\/g, '\\')
  }

  return pairs
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}

function webBlock(locale: Locale, block: string): Record<string, string> {
  const documents = WEB_BUNDLES[locale]['documents']
  if (!isRecord(documents)) {
    return {}
  }
  const node = documents[block]
  if (!isRecord(node)) {
    return {}
  }

  return Object.fromEntries(
    Object.entries(node).filter(
      (pair): pair is [string, string] => typeof pair[1] === 'string'
    )
  )
}

describe('proforma copy parity', () => {
  it.each(LOCALES)('%s authors every proforma key — no locale left on English', (locale) => {
    const web = webBlock(locale, 'proforma')

    expect(Object.keys(web).sort()).toEqual(Object.keys(PROFORMA_KEYS).sort())
    for (const value of Object.values(web)) {
      expect(value.trim()).not.toBe('')
    }
  })

  it.each(LOCALES)('%s authors the credit-note balance note', (locale) => {
    const web = webBlock(locale, 'creditNote')

    expect(Object.keys(web).sort()).toEqual(Object.keys(CREDIT_NOTE_KEYS).sort())
  })

  it.each(LOCALES)('%s proforma copy is VERBATIM the backend copy', (locale) => {
    const php = phpBlock(locale, 'proforma')
    const web = webBlock(locale, 'proforma')

    expect(Object.keys(php).length).toBeGreaterThan(0)
    for (const [webKey, phpKey] of Object.entries(PROFORMA_KEYS)) {
      expect(web[webKey], `documents.proforma.${webKey} (${locale})`).toBe(php[phpKey])
    }
  })

  it.each(LOCALES)('%s credit-note copy is VERBATIM the backend copy', (locale) => {
    const php = phpBlock(locale, 'credit_note')
    const web = webBlock(locale, 'creditNote')

    expect(Object.keys(php).length).toBeGreaterThan(0)
    for (const [webKey, phpKey] of Object.entries(CREDIT_NOTE_KEYS)) {
      expect(web[webKey], `documents.creditNote.${webKey} (${locale})`).toBe(php[phpKey])
    }
  })

  it.each(LOCALES)('%s no longer carries the retired posting marker copy', (locale) => {
    const creditNotes = WEB_BUNDLES[locale]['creditNotes']
    const marker = isRecord(creditNotes) ? creditNotes['postingMarker'] : undefined

    expect(isRecord(marker)).toBe(true)
    // The cancelled arm survives — a sealed-then-cancelled document is NOT a
    // proforma and keeps its own message.
    const entries = isRecord(marker) ? marker : {}
    expect(entries['cancelledTitle']).toBeTypeOf('string')
    expect(entries['title']).toBeUndefined()
    expect(entries['detail']).toBeUndefined()
  })

  it('the three locales say three different things', () => {
    const titles = LOCALES.map((locale) => webBlock(locale, 'proforma')['title'])

    expect(new Set(titles).size).toBe(LOCALES.length)
  })
})
