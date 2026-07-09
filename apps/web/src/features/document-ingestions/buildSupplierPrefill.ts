import type { PartnerPrefill } from '@/features/partners/partnerPrefill'
import type { ExtractedField } from './types'

const FIELD_SOURCE_LIST: ReadonlyArray<readonly [keyof PartnerPrefill, readonly string[]]> = [
  ['name', ['name']],
  ['vat_number', ['vat_number', 'tax_id', 'vat', 'tax_number']],
  ['phone', ['phone', 'phone_number', 'phoneno', 'telephone']],
  ['email', ['email']],
  ['street_address', ['street_address', 'address', 'street']],
  ['city', ['city']],
  ['state', ['state', 'region']],
  ['postal_code', ['postal_code', 'pincode', 'zip', 'zip_code']],
  ['country_code', ['country_code']],
]

const COUNTRY_CODE_PATTERN = /^[A-Za-z]{2}$/

function resolveField(
  supplier: Record<string, ExtractedField>,
  sourceKeys: readonly string[],
): string | undefined {
  for (const key of sourceKeys) {
    const field = supplier[key]
    if (field === undefined) {
      continue
    }
    const trimmed = field.value.trim()
    if (trimmed.length > 0) {
      return trimmed
    }
  }
  return undefined
}

export function buildSupplierPrefill(
  supplier: Record<string, ExtractedField> | undefined,
): PartnerPrefill {
  const result: PartnerPrefill = {}

  if (!supplier) {
    return result
  }

  for (const [targetKey, sourceKeys] of FIELD_SOURCE_LIST) {
    const value = resolveField(supplier, sourceKeys)
    if (value === undefined) {
      continue
    }

    if (targetKey === 'country_code') {
      if (COUNTRY_CODE_PATTERN.test(value)) {
        result.country_code = value.toUpperCase()
      }
      continue
    }

    result[targetKey] = value
  }

  return result
}
