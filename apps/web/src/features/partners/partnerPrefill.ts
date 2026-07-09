export interface PartnerPrefill {
  name?: string
  vat_number?: string
  phone?: string
  email?: string
  street_address?: string
  city?: string
  state?: string
  postal_code?: string
  country_code?: string
}

const PARTNER_PREFILL_KEYS = [
  'name',
  'vat_number',
  'phone',
  'email',
  'street_address',
  'city',
  'state',
  'postal_code',
  'country_code',
] as const

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

export function readPartnerPrefill(state: unknown): PartnerPrefill | null {
  if (!isRecord(state)) {
    return null
  }

  const candidate = state['partnerPrefill']
  if (!isRecord(candidate)) {
    return null
  }

  const result: PartnerPrefill = {}

  for (const key of PARTNER_PREFILL_KEYS) {
    const value = candidate[key]
    if (typeof value !== 'string') {
      continue
    }
    const trimmed = value.trim()
    if (trimmed.length === 0) {
      continue
    }
    result[key] = trimmed
  }

  return Object.keys(result).length > 0 ? result : null
}
