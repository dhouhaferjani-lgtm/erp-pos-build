/**
 * Document line → API payload.
 *
 * Extracted from DocumentForm so the payload rules are unit-testable on their own
 * and so the component file does not accumulate non-component exports.
 *
 * The central rule here is the SPLIT between the two wire payloads (W2-6 gate r1
 * finding 1): the draft autosave tolerates an unpriced line, the real submit does
 * not.
 */

import type { DocumentLine } from '../../components/documents/DocumentLineEditor'

export interface LinePayload {
  product_id?: string
  service_id?: string
  quantity: string | number
  unit_price: string | number
  line_total: string | number
  price_entry_mode: 'unit' | 'total'
  discount_percent: string | null
  discount_amount: string | null
  free_quantity?: string | number
  tax_rate?: string | number
  tax_configuration_id?: string
}

/**
 * True when a value is absent or blank — used for the tax fields, where an
 * empty string means "the line says nothing", not "zero".
 */
function isBlank(value: string | number | null | undefined): boolean {
  return value === null || value === undefined || String(value).trim() === ''
}

/**
 * Campaign defect N-1 (P0): decide what a line says about tax.
 *
 * `DocumentLineTaxResolver` prefers the tax CONFIGURATION over any
 * denormalised rate — but its FIRST branch short-circuits on an explicit
 * `tax_rate`, so sending both means the configuration is never consulted. The
 * form used to send `tax_rate` on every line and `tax_configuration_id` on
 * none, which is how a product on the 7 % band came out of the API taxed at the
 * company's 19 % default with the right rate showing on screen throughout.
 *
 * So the line states ONE thing:
 *  - It knows its configuration (picked from the per-line selector, or
 *    inherited from the product's `default_tax_configuration_id`): send the id
 *    and let the server read the rate off the configuration itself. The rate
 *    displayed here came from that same configuration, so nothing is lost —
 *    but the server no longer has to trust a number the client copied.
 *  - It does not (a free-text service line, a document loaded from the server
 *    before configurations were tracked on lines): send the rate, exactly as
 *    before. `'0.00'` is a stated exemption and IS sent; only a truly blank
 *    rate is omitted, which lets the backend resolve from the product.
 */
function applyLineTax(payload: LinePayload, line: DocumentLine): void {
  const configurationId = line.tax_configuration_id
  if (typeof configurationId === 'string' && configurationId.trim() !== '') {
    payload.tax_configuration_id = configurationId
    return
  }
  if (!isBlank(line.tax_rate)) {
    payload.tax_rate = line.tax_rate
  }
}

/**
 * True when a free_quantity value is empty or represents zero (e.g. '', '0',
 * '0.0000'). String-based check on purpose — never parseFloat on quantities.
 */
function isZeroFreeQuantity(value: DocumentLine['free_quantity']): boolean {
  if (value === null || value === undefined) return true
  const trimmed = String(value).trim()
  return trimmed === '' || /^0+(\.0+)?$/.test(trimmed)
}

/**
 * Pure helper: maps a DocumentLine to the API line payload shared by BOTH
 * the autosave draft payload and the submit payload (single source of truth
 * so the two sites cannot drift). Exported for unit testing.
 *
 * `free_quantity` is OMITTED when empty/zero: the backend
 * (Create/UpdateDocumentRequest) prohibits `lines.*.free_quantity` whenever
 * the purchase-bonus module gate is disabled for the company, so sending the
 * default '0' fails every document creation with a 422
 * ("Le champ lines.0.free_quantity est interdit."). A real non-zero bonus
 * quantity (purchase flow with the module enabled) is sent exactly as entered.
 *
 * The TAX half lives here too (campaign defect N-1) rather than at the two call
 * sites, which had already drifted apart — the autosave payload sent
 * `line.tax_rate || 0` while submit sent `line.tax_rate` raw. One decision, one
 * place. See {@link applyLineTax}.
 */
export function buildLinePayload(line: DocumentLine): LinePayload {
  const payload: LinePayload = {
    quantity: line.quantity,
    // A blank price is passed through UNTOUCHED. `lines.*.unit_price` is
    // `required|numeric` on Create/UpdateDocumentRequest, and that rejection is
    // a feature: a line with no price must never be saved. The client blocks it
    // first (see `findBlankPriceLineIds`) so the operator gets an inline
    // message instead of a 422. The draft-autosave payload — and ONLY that one
    // — coerces the blank; see {@link buildAutoSaveLinePayload}.
    unit_price: line.unit_price,
    line_total: line.line_total,
    price_entry_mode: line.price_entry_mode ?? 'unit',
    discount_percent: line.discount_percent ?? null,
    discount_amount: line.discount_amount ?? null,
  }
  if (line.is_service) {
    if (line.service_id !== undefined && line.service_id.trim() !== '') {
      payload.service_id = line.service_id
    }
  } else {
    payload.product_id = line.product_id
  }
  if (!isZeroFreeQuantity(line.free_quantity)) {
    payload.free_quantity = line.free_quantity as string | number
  }
  applyLineTax(payload, line)
  return payload
}

/**
 * Line payload for the DRAFT AUTOSAVE only.
 *
 * W2-6 gives a purchase line with no `products.purchase_price` an EMPTY price so
 * the operator must type it. Autosave fires at keystroke frequency, so an empty
 * cell would otherwise produce a stream of failed draft saves and trip the
 * autosave-failed / beforeunload guard. A draft is not a document: coercing the
 * blank to '0' HERE keeps the draft saving while the line is visibly worth
 * nothing — and it never becomes the retail price.
 *
 * This coercion must NEVER be applied to {@link buildLinePayload}, which feeds
 * the real create/update submit (gate r1 finding 1): there a blank price is
 * blocked client-side and, failing that, rejected by the server.
 */
export function buildAutoSaveLinePayload(line: DocumentLine): LinePayload {
  const payload = buildLinePayload(line)
  if (isBlank(payload.unit_price)) {
    payload.unit_price = '0'
  }
  return payload
}

/**
 * Ids of lines that carry no unit price. A blank price is only ever produced by
 * the W2-6 EMPTY purchase default or by an operator clearing the cell
 * (`MoneyInput` emits '' on clear) — both must block the submit, on EVERY
 * document type. Sales documents used to 422 server-side for exactly this; the
 * client message is added, the refusal is kept.
 */
export function findBlankPriceLineIds(lines: DocumentLine[]): string[] {
  return lines.filter((line) => isBlank(line.unit_price)).map((line) => line.id)
}
