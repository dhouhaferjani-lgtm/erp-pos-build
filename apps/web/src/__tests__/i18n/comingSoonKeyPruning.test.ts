/**
 * UI-43 / Wave 0 T8-c — the four orphaned "coming soon" locale key groups.
 *
 * These four groups had ZERO code consumers anywhere in `apps/web/src`: they were
 * copy left behind by features that never shipped their placeholder. T8-c prunes
 * them from every locale bundle that carried them.
 *
 * The test is deliberately two-sided, because the risk here is over-deletion:
 *  - the four orphaned groups must be ABSENT from en/fr/ar (where each existed); and
 *  - the three LIVE "coming soon" keys must still RESOLVE. Those are owned by other
 *    waves and must survive untouched:
 *      * `common:pos.featureComingSoon`      -> ShiftOperationsMenu.tsx:122 (UI-45, Wave 4)
 *      * `pricing:priceLists.addItemComingSoon` / `…assignPartnerComingSoon`
 *                                            -> PriceListDetailPage (UI-13, Wave 1)
 *      * `parts-catalog:vinPlate.comingSoon` -> PartsCatalogPage.tsx:348
 *
 * Asserting on the locale JSON directly (rather than through a rendered component)
 * is deliberate: this is a DATA contract about which keys exist in which bundle.
 */

import { describe, expect, it } from 'vitest'

import enPos from '@/locales/en/pos.json'
import frPos from '@/locales/fr/pos.json'
import enInventory from '@/locales/en/inventory.json'
import frInventory from '@/locales/fr/inventory.json'
import arInventory from '@/locales/ar/inventory.json'
import enCommon from '@/locales/en/common.json'
import frCommon from '@/locales/fr/common.json'
import arCommon from '@/locales/ar/common.json'
import enPricing from '@/locales/en/pricing.json'
import frPricing from '@/locales/fr/pricing.json'
import enPartsCatalog from '@/locales/en/parts-catalog.json'
import frPartsCatalog from '@/locales/fr/parts-catalog.json'
import arPartsCatalog from '@/locales/ar/parts-catalog.json'

/** Resolves a dotted key path, returning `undefined` when any segment is missing. */
function lookup(bundle: unknown, dottedPath: string): unknown {
  return dottedPath.split('.').reduce<unknown>((node, segment) => {
    if (typeof node !== 'object' || node === null) return undefined
    return (node as Record<string, unknown>)[segment]
  }, bundle)
}

const ORPHANED: [label: string, bundle: unknown, path: string][] = [
  ['en/pos', enPos, 'advancedPayments.discountComingSoon'],
  ['fr/pos', frPos, 'advancedPayments.discountComingSoon'],

  ['en/inventory', enInventory, 'products.messages.stockComingSoon'],
  ['fr/inventory', frInventory, 'products.messages.stockComingSoon'],

  ['en/inventory', enInventory, 'counting.detailsComingSoon'],
  ['fr/inventory', frInventory, 'counting.detailsComingSoon'],
  ['ar/inventory', arInventory, 'counting.detailsComingSoon'],

  ['en/inventory', enInventory, 'counting.create.categorySelectionComingSoon'],
  ['fr/inventory', frInventory, 'counting.create.categorySelectionComingSoon'],
  ['ar/inventory', arInventory, 'counting.create.categorySelectionComingSoon'],

  ['en/inventory', enInventory, 'counting.create.categorySelectionComingSoonHint'],
  ['fr/inventory', frInventory, 'counting.create.categorySelectionComingSoonHint'],
  ['ar/inventory', arInventory, 'counting.create.categorySelectionComingSoonHint'],
]

const LIVE: [label: string, bundle: unknown, path: string][] = [
  ['en/common', enCommon, 'pos.featureComingSoon'],
  ['fr/common', frCommon, 'pos.featureComingSoon'],
  ['ar/common', arCommon, 'pos.featureComingSoon'],

  ['en/pricing', enPricing, 'priceLists.addItemComingSoon'],
  ['fr/pricing', frPricing, 'priceLists.addItemComingSoon'],
  ['en/pricing', enPricing, 'priceLists.assignPartnerComingSoon'],
  ['fr/pricing', frPricing, 'priceLists.assignPartnerComingSoon'],

  ['en/parts-catalog', enPartsCatalog, 'vinPlate.comingSoon'],
  ['fr/parts-catalog', frPartsCatalog, 'vinPlate.comingSoon'],
  ['ar/parts-catalog', arPartsCatalog, 'vinPlate.comingSoon'],
]

describe('UI-43 T8-c — orphaned "coming soon" locale keys are pruned', () => {
  it.each(ORPHANED)('%s no longer carries %s', (_label, bundle, dottedPath) => {
    expect(lookup(bundle, dottedPath)).toBeUndefined()
  })

  it('leaves no residual "ComingSoon" leaf in the pruned inventory/pos subtrees', () => {
    const residual: string[] = []
    const walk = (node: unknown, prefix: string, label: string): void => {
      if (typeof node !== 'object' || node === null) return
      for (const [key, value] of Object.entries(node as Record<string, unknown>)) {
        const dotted = prefix ? `${prefix}.${key}` : key
        if (/ComingSoon/i.test(key)) residual.push(`${label}:${dotted}`)
        walk(value, dotted, label)
      }
    }
    walk(enInventory, '', 'en/inventory')
    walk(frInventory, '', 'fr/inventory')
    walk(arInventory, '', 'ar/inventory')
    walk(enPos, '', 'en/pos')
    walk(frPos, '', 'fr/pos')

    expect(residual).toEqual([])
  })
})

describe('UI-43 T8-c — the three live "coming soon" keys survive', () => {
  it.each(LIVE)('%s still resolves %s', (_label, bundle, dottedPath) => {
    const value = lookup(bundle, dottedPath)
    expect(typeof value).toBe('string')
    expect(value).not.toBe('')
  })
})

describe('UI-43 T8-c — EN/FR key alignment does not regress', () => {
  const leafPaths = (node: unknown, prefix = ''): string[] => {
    if (typeof node !== 'object' || node === null) return [prefix]
    return Object.entries(node as Record<string, unknown>).flatMap(([key, value]) =>
      leafPaths(value, prefix ? `${prefix}.${key}` : key),
    )
  }

  it.each([
    ['inventory', enInventory, frInventory],
    ['pos', enPos, frPos],
  ])('%s: every EN leaf that mentions ComingSoon has an FR counterpart', (_ns, en, fr) => {
    const frLeaves = new Set(leafPaths(fr))
    const enComingSoon = leafPaths(en).filter((p) => /ComingSoon/i.test(p))

    for (const path of enComingSoon) {
      expect(frLeaves.has(path)).toBe(true)
    }
  })
})
