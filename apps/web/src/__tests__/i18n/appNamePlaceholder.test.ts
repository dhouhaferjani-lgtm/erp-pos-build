/**
 * OQ-1 / Wave 0 T14 — no brand literal survives in privacy or support-access copy.
 *
 * OWNER-DECISIONS:9 — "Do not bake any brand string into copy." Product names are
 * NOT final, so the app name must reach the copy through `{{appName}}`, supplied
 * at render time from `useProductConfig().productName` (`PRODUCT_INFO`,
 * `src/contexts/ProductConfigContext.tsx:31-40`).
 *
 * This is a DATA assertion over the locale bundles. It deliberately does NOT
 * prove that either page passes `{ appName: productName }` — only the two render
 * tests do that:
 *   - `src/pages/legal/__tests__/PrivacyPolicyPage.test.tsx`
 *   - `src/features/support-access/__tests__/TenantSupportAccessPage.test.tsx`
 *
 * Scope note: `UI-37`'s brand-SPELLING half (Syneriva vs Synerivia in the
 * enrichment copy) is Wave 4 and is not asserted here.
 */

import { describe, expect, it } from 'vitest'

import enCommon from '@/locales/en/common.json'
import frCommon from '@/locales/fr/common.json'
import arCommon from '@/locales/ar/common.json'
import enSupportAccess from '@/locales/en/support-access.json'
import frSupportAccess from '@/locales/fr/support-access.json'
import arSupportAccess from '@/locales/ar/support-access.json'

/** Every brand string the owner ruling forbids in this copy. */
const BRAND_LITERALS = /AutoERP|Otospex|IziPOS|Synerivia|Syneriva/

function collectStrings(node: unknown, acc: string[] = []): string[] {
  if (typeof node === 'string') {
    acc.push(node)
    return acc
  }
  if (typeof node === 'object' && node !== null) {
    for (const value of Object.values(node as Record<string, unknown>)) {
      collectStrings(value, acc)
    }
  }
  return acc
}

function privacySubtree(common: unknown): unknown {
  const legal = (common as Record<string, unknown>)['legal']
  if (typeof legal !== 'object' || legal === null) return {}
  return (legal as Record<string, unknown>)['privacy'] ?? {}
}

describe('OQ-1 — privacy copy carries no brand literal', () => {
  it.each([
    ['en', enCommon],
    ['fr', frCommon],
    ['ar', arCommon],
  ])('%s/common.json legal.privacy is brand-free', (_locale, bundle) => {
    const offenders = collectStrings(privacySubtree(bundle)).filter((s) => BRAND_LITERALS.test(s))

    expect(offenders).toEqual([])
  })

  it.each([
    ['en', enCommon],
    ['fr', frCommon],
    ['ar', arCommon],
  ])('%s/common.json privacy copy interpolates {{appName}} where the brand used to be', (
    _locale,
    bundle,
  ) => {
    const strings = collectStrings(privacySubtree(bundle))

    expect(strings.filter((s) => s.includes('{{appName}}')).length).toBeGreaterThanOrEqual(2)
  })
})

describe('OQ-1 — support-access copy carries no brand literal', () => {
  it.each([
    ['en', enSupportAccess],
    ['fr', frSupportAccess],
    ['ar', arSupportAccess],
  ])('%s/support-access.json is brand-free', (_locale, bundle) => {
    const offenders = collectStrings(bundle).filter((s) => BRAND_LITERALS.test(s))

    expect(offenders).toEqual([])
  })

  it.each([
    ['en', enSupportAccess],
    ['fr', frSupportAccess],
    ['ar', arSupportAccess],
  ])('%s/support-access.json subtitle interpolates {{appName}}', (_locale, bundle) => {
    const subtitle = (bundle as Record<string, unknown>)['subtitle']

    expect(typeof subtitle).toBe('string')
    expect(subtitle as string).toContain('{{appName}}')
  })
})

describe('OQ-1 — the dead appName key is gone', () => {
  // `grep -rn appName apps/web/src` outside src/locales/ returned zero, so the
  // key was a dead literal carrying a wrong brand. It is deleted rather than
  // repointed; the config value reaches copy via interpolation instead.
  it.each([
    ['en', enCommon],
    ['fr', frCommon],
    ['ar', arCommon],
  ])('%s/common.json no longer declares a top-level appName', (_locale, bundle) => {
    expect((bundle as Record<string, unknown>)['appName']).toBeUndefined()
  })
})
