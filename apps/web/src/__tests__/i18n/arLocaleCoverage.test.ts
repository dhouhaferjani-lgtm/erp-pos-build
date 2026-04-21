/**
 * AutoSpecs Arabic locale coverage test.
 *
 * Asserts that every EN key in an AutoSpecs Priority 1 namespace has a
 * corresponding AR key (recursively for nested objects). Missing keys are
 * a Tunisia Go-Live blocker (🟠-4 finding from the AutoSpecs Ops audit) and
 * will fail the build with a clear message naming each missing path.
 *
 * Scope: only AutoSpecs-facing namespaces are enforced here. Non-AutoSpecs
 * namespaces (e.g. loyalty, parapharmacy, coffee-shop) are tracked as a
 * separate IziPOS localization concern.
 */
import { describe, it, expect } from 'vitest'

// Priority 1 — AutoSpecs core namespaces (and shared foundations AutoSpecs pages render)
import enCommon from '../../locales/en/common.json'
import enValidation from '../../locales/en/validation.json'
import enWorkshopBundles from '../../locales/en/workshop-bundles.json'
import enWorkshopTechnicians from '../../locales/en/workshop-technicians.json'
import enWorkshopWorkOrders from '../../locales/en/workshop-work-orders.json'
import enVehicles from '../../locales/en/vehicles.json'
import enVehicleOwnership from '../../locales/en/vehicle-ownership.json'
import enScheduling from '../../locales/en/scheduling.json'
import enPickers from '../../locales/en/pickers.json'

import arCommon from '../../locales/ar/common.json'
import arValidation from '../../locales/ar/validation.json'
import arWorkshopBundles from '../../locales/ar/workshop-bundles.json'
import arWorkshopTechnicians from '../../locales/ar/workshop-technicians.json'
import arWorkshopWorkOrders from '../../locales/ar/workshop-work-orders.json'
import arVehicles from '../../locales/ar/vehicles.json'
import arVehicleOwnership from '../../locales/ar/vehicle-ownership.json'
import arScheduling from '../../locales/ar/scheduling.json'
import arPickers from '../../locales/ar/pickers.json'

type Json = string | number | boolean | null | Json[] | { [key: string]: Json }

function flattenKeys(value: Json, prefix = ''): string[] {
  if (value === null || typeof value !== 'object' || Array.isArray(value)) {
    return [prefix]
  }
  const paths: string[] = []
  for (const [k, v] of Object.entries(value)) {
    const next = prefix === '' ? k : `${prefix}.${k}`
    if (v !== null && typeof v === 'object' && !Array.isArray(v)) {
      paths.push(...flattenKeys(v as Json, next))
    } else {
      paths.push(next)
    }
  }
  return paths
}

function findMissingKeys(source: Json, target: Json): string[] {
  const sourceKeys = new Set(flattenKeys(source))
  const targetKeys = new Set(flattenKeys(target))
  const missing: string[] = []
  for (const key of sourceKeys) {
    if (!targetKeys.has(key)) {
      missing.push(key)
    }
  }
  return missing.sort()
}

const namespaces = [
  { name: 'common', en: enCommon as Json, ar: arCommon as Json },
  { name: 'validation', en: enValidation as Json, ar: arValidation as Json },
  { name: 'workshop-bundles', en: enWorkshopBundles as Json, ar: arWorkshopBundles as Json },
  { name: 'workshop-technicians', en: enWorkshopTechnicians as Json, ar: arWorkshopTechnicians as Json },
  { name: 'workshop-work-orders', en: enWorkshopWorkOrders as Json, ar: arWorkshopWorkOrders as Json },
  { name: 'vehicles', en: enVehicles as Json, ar: arVehicles as Json },
  { name: 'vehicle-ownership', en: enVehicleOwnership as Json, ar: arVehicleOwnership as Json },
  { name: 'scheduling', en: enScheduling as Json, ar: arScheduling as Json },
  { name: 'pickers', en: enPickers as Json, ar: arPickers as Json },
] as const

describe('AutoSpecs Arabic locale coverage (🟠-4 Tunisia Go-Live blocker)', () => {
  for (const ns of namespaces) {
    it(`ar/${ns.name}.json covers every key in en/${ns.name}.json`, () => {
      const missing = findMissingKeys(ns.en, ns.ar)
      expect(
        missing,
        `ar/${ns.name}.json is missing ${missing.length} key(s) present in en/${ns.name}.json:\n  - ${missing.join('\n  - ')}`,
      ).toEqual([])
    })
  }

  it('catches missing keys when they exist (self-test of the helper)', () => {
    const source = { a: { b: 'x', c: 'y' } }
    const target = { a: { b: 'x' } }
    expect(findMissingKeys(source, target)).toEqual(['a.c'])
  })
})
