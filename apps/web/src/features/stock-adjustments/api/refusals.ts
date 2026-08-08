/**
 * Stock-adjustment refusal handling (DPA V7 / F11).
 *
 * Two jobs:
 *
 *  1. Map every one of the backend's typed refusal codes to a translated,
 *     ACTIONABLE message key. A code with no entry would reach the user as a raw
 *     backend sentence.
 *  2. NARROW `details` from `unknown` to a shape the dialog can render. This is
 *     mandatory, not decorative: `extractErrorCode`'s `details` is typed
 *     `Record<string, unknown>` and this directory is inside the strict ESLint
 *     block, where `no-unsafe-member-access` makes a bare `details.lines[0].x`
 *     an error — and, more importantly, an unchecked cast here would crash the
 *     dialog on a payload shape that changed server-side.
 */
export const STOCK_ADJUSTMENT_REFUSAL_CODES = [
  'STOCK_MOVED_SINCE_AUTHORING',
  'ADJUSTMENT_EXCEEDS_AVAILABLE',
  'INSUFFICIENT_BATCH_STOCK',
  'BATCH_REQUIRED_FOR_LINE',
  'BATCH_NOT_APPLICABLE',
  'USE_BATCH_WRITE_OFF',
  'LINE_TENANT_MISMATCH',
  'INVALID_ADJUSTMENT_STATE',
  'ADJUSTMENT_ALREADY_CORRECTED',
  'CANNOT_CORRECT_A_CORRECTION',
  'LOCATION_ACCESS_DENIED',
  // Returned by the controller's two-leg check when a create-only operator asks
  // to post (or to override a guard) — reachable from every authoring surface,
  // so it needs a message like the rest.
  'POST_PERMISSION_REQUIRED',
  // A correction's lines are DERIVED by the server, so replacing them is
  // refused. The UI never offers it (re-anchor is hidden on a contra), but the
  // code is reachable by any client and needs a message like the rest.
  'CONTRA_LINES_IMMUTABLE',
] as const

export type StockAdjustmentRefusalCode = (typeof STOCK_ADJUSTMENT_REFUSAL_CODES)[number]

/** The two codes the operator can explicitly acknowledge and re-submit. */
export const ACKNOWLEDGEABLE_REFUSAL_CODES = [
  'STOCK_MOVED_SINCE_AUTHORING',
  'ADJUSTMENT_EXCEEDS_AVAILABLE',
] as const satisfies readonly StockAdjustmentRefusalCode[]

export type AcknowledgeableRefusalCode = (typeof ACKNOWLEDGEABLE_REFUSAL_CODES)[number]

/** `code -> i18n key` in the `stock-adjustments` namespace, with a fallback. */
export const REFUSAL_MESSAGE_KEYS: Record<StockAdjustmentRefusalCode, string> = {
  STOCK_MOVED_SINCE_AUTHORING: 'refusal.STOCK_MOVED_SINCE_AUTHORING',
  ADJUSTMENT_EXCEEDS_AVAILABLE: 'refusal.ADJUSTMENT_EXCEEDS_AVAILABLE',
  INSUFFICIENT_BATCH_STOCK: 'refusal.INSUFFICIENT_BATCH_STOCK',
  BATCH_REQUIRED_FOR_LINE: 'refusal.BATCH_REQUIRED_FOR_LINE',
  BATCH_NOT_APPLICABLE: 'refusal.BATCH_NOT_APPLICABLE',
  USE_BATCH_WRITE_OFF: 'refusal.USE_BATCH_WRITE_OFF',
  LINE_TENANT_MISMATCH: 'refusal.LINE_TENANT_MISMATCH',
  INVALID_ADJUSTMENT_STATE: 'refusal.INVALID_ADJUSTMENT_STATE',
  ADJUSTMENT_ALREADY_CORRECTED: 'refusal.ADJUSTMENT_ALREADY_CORRECTED',
  CANNOT_CORRECT_A_CORRECTION: 'refusal.CANNOT_CORRECT_A_CORRECTION',
  LOCATION_ACCESS_DENIED: 'refusal.LOCATION_ACCESS_DENIED',
  POST_PERMISSION_REQUIRED: 'refusal.POST_PERMISSION_REQUIRED',
  CONTRA_LINES_IMMUTABLE: 'refusal.CONTRA_LINES_IMMUTABLE',
}

export const GENERIC_REFUSAL_MESSAGE_KEY = 'refusal.generic'

/**
 * The override flag that acknowledges THIS refusal — and only this one.
 *
 * Sending both flags on every "Apply anyway" (a) silently disables the OTHER
 * guard for that post without the operator ever being told, and (b) writes a
 * permanent header claim that they overrode it, which the detail page then
 * renders as fact. D15a.3 says the button sets `acknowledge_stale` OR
 * `ignore_reservations` respectively; this is that mapping, in one place, so the
 * three call sites cannot drift apart.
 */
export function overrideFlagFor(
  code: AcknowledgeableRefusalCode,
): { acknowledge_stale: true } | { ignore_reservations: true } {
  return code === 'STOCK_MOVED_SINCE_AUTHORING'
    ? { acknowledge_stale: true }
    : { ignore_reservations: true }
}

export function refusalMessageKey(code: string | undefined): string {
  if (code !== undefined && isRefusalCode(code)) {
    return REFUSAL_MESSAGE_KEYS[code]
  }
  return GENERIC_REFUSAL_MESSAGE_KEY
}

export function isRefusalCode(code: string): code is StockAdjustmentRefusalCode {
  return (STOCK_ADJUSTMENT_REFUSAL_CODES as readonly string[]).includes(code)
}

export function isAcknowledgeableRefusalCode(code: string): code is AcknowledgeableRefusalCode {
  return (ACKNOWLEDGEABLE_REFUSAL_CODES as readonly string[]).includes(code)
}

/** One refused line. `line_id` is NULL whenever nothing was persisted. */
export interface StaleLine {
  line_id: string | null
  product_id: string
  variant_id: string | null
  batch_uuid: string | null
  observed_before: string
  quantity_before: string
  quantity_decimals: number
}

export interface AvailabilityRefusal {
  product_id: string
  location_id: string
  quantity_before: string
  reserved: string
  available: string
  delta_quantity: string
  quantity_decimals: number
  overridable: boolean
}

export interface ApiErrorEnvelope {
  code: string | undefined
  message: string | undefined
  details: unknown
}

/** Pull the typed `{code, message, details}` envelope out of an axios error. */
export function extractRefusal(error: unknown): ApiErrorEnvelope | null {
  const response = readRecord(readUnknown(error, 'response'))
  const data = readRecord(readUnknown(response, 'data'))
  const envelope = readRecord(readUnknown(data, 'error'))

  if (envelope === null) {
    return null
  }

  return {
    code: readString(envelope, 'code') ?? undefined,
    message: readString(envelope, 'message') ?? undefined,
    details: readUnknown(envelope, 'details'),
  }
}

/**
 * `details.lines[]` for STOCK_MOVED_SINCE_AUTHORING.
 *
 * Returns null rather than throwing on a malformed payload: a refusal dialog
 * that crashes is strictly worse than one that falls back to the generic
 * message.
 */
export function narrowStaleDetails(details: unknown): StaleLine[] | null {
  const record = readRecord(details)
  const rawLines = readUnknown(record, 'lines')

  if (!Array.isArray(rawLines)) {
    return null
  }

  const lines: StaleLine[] = []

  for (const entry of rawLines) {
    const raw = readRecord(entry)
    const productId = readString(raw, 'product_id')
    const observedBefore = readString(raw, 'observed_before')
    const quantityBefore = readString(raw, 'quantity_before')
    const quantityDecimals = readNumber(raw, 'quantity_decimals')

    if (
      productId === null ||
      observedBefore === null ||
      quantityBefore === null ||
      quantityDecimals === null
    ) {
      return null
    }

    lines.push({
      line_id: readString(raw, 'line_id'),
      product_id: productId,
      variant_id: readString(raw, 'variant_id'),
      batch_uuid: readString(raw, 'batch_uuid'),
      observed_before: observedBefore,
      quantity_before: quantityBefore,
      quantity_decimals: quantityDecimals,
    })
  }

  return lines.length > 0 ? lines : null
}

/** `details` for ADJUSTMENT_EXCEEDS_AVAILABLE (D1a's audited override). */
export function narrowAvailabilityDetails(details: unknown): AvailabilityRefusal | null {
  const raw = readRecord(details)

  const productId = readString(raw, 'product_id')
  const locationId = readString(raw, 'location_id')
  const quantityBefore = readString(raw, 'quantity_before')
  const reserved = readString(raw, 'reserved')
  const available = readString(raw, 'available')
  const deltaQuantity = readString(raw, 'delta_quantity')
  const quantityDecimals = readNumber(raw, 'quantity_decimals')

  if (
    productId === null ||
    locationId === null ||
    quantityBefore === null ||
    reserved === null ||
    available === null ||
    deltaQuantity === null ||
    quantityDecimals === null
  ) {
    return null
  }

  return {
    product_id: productId,
    location_id: locationId,
    quantity_before: quantityBefore,
    reserved,
    available,
    delta_quantity: deltaQuantity,
    quantity_decimals: quantityDecimals,
    overridable: readUnknown(raw, 'overridable') === true,
  }
}

// ---------------------------------------------------------------------------
// Narrowing primitives. Bracket access throughout: the tsconfig sets
// `noPropertyAccessFromIndexSignature`, which is the same discipline in the type
// system that the null checks above are at runtime.
// ---------------------------------------------------------------------------

function readRecord(value: unknown): Record<string, unknown> | null {
  // A type PREDICATE rather than an assertion: the strict block forbids the
  // narrowing cast, and a predicate is the honest form anyway — it says "I
  // checked", not "trust me".
  return isRecord(value) ? value : null
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}

function readUnknown(source: unknown, key: string): unknown {
  const record = readRecord(source)
  return record === null ? undefined : record[key]
}

function readString(source: unknown, key: string): string | null {
  const value = readUnknown(source, key)
  return typeof value === 'string' ? value : null
}

function readNumber(source: unknown, key: string): number | null {
  const value = readUnknown(source, key)
  return typeof value === 'number' ? value : null
}
