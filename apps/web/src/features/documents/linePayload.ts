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
import type { DocumentType } from './DocumentListPage'

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
  // A TOTAL-entry-mode line that has not been priced yet carries a blank
  // `line_total` too (gate r2 C1 — the editor no longer synthesises 0.000 out of
  // an unentered total). `line_total` is a required numeric on the draft
  // endpoint as well, so it needs the same draft-only coercion.
  if (isBlank(payload.line_total)) {
    payload.line_total = '0'
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
  return lines
    .filter((line) => {
      if (isBlank(line.unit_price)) return true
      // TOTAL entry mode: the operator prices the line by its net amount, so an
      // unentered total is an unpriced line even if a unit price were somehow
      // present. Belt to the brace above (gate r2 C1).
      return (line.price_entry_mode ?? 'unit') === 'total' && isBlank(line.line_total)
    })
    .map((line) => line.id)
}

/**
 * Is this document type a PURCHASE (money leaving the company)? Its line price is
 * then a BUYING price and must never be seeded from the retail `sale_price` —
 * see {@link resolveLineUnitPriceDefault}.
 *
 * An EXHAUSTIVE `Record` over the document-type union on purpose (gate r1
 * finding 4): a `Set<string>` let a newly added or renamed purchase type fall
 * silently through to the sale-price branch — the exact defect this file fixes.
 * Adding a member to `DocumentType` now fails to compile until it is classified
 * here.
 *
 * `purchase_order` is the only purchase type actually routed through this editor
 * today (DocumentForm.tsx documentTypeToApiEndpoint); RFQ lines have their own
 * page and the RFQ → PO award carries the supplier's QUOTED price across
 * (PurchaseQuoteRequestAwardService.php:136), so it needs no default here.
 */
const IS_PURCHASE_DOCUMENT_TYPE: Record<DocumentType, boolean> = {
  quote: false,
  sales_order: false,
  invoice: false,
  credit_note: false,
  delivery_note: false,
  return_note: false,
  purchase_order: true,
}

/**
 * Lives here rather than in the editor component so BOTH consumers share one
 * classification: the line editor (which price to seed) and the edit-mode
 * hydration in DocumentForm (whether a persisted 0 means "never priced").
 */
export function isPurchaseDocumentType(documentType: DocumentType | undefined): boolean {
  return documentType !== undefined && IS_PURCHASE_DOCUMENT_TYPE[documentType]
}

/**
 * Unit price for a line loaded from the API into the editor.
 *
 * The draft autosave deliberately writes `'0'` for a line the operator never
 * priced (see {@link buildAutoSaveLinePayload}), which converts "unpriced" into
 * "priced at zero" at the persistence boundary. Re-opening the draft used to
 * load that `'0.000'` verbatim: no longer blank, so the submit guard waved it
 * through and the server's `required|numeric` rule accepts `'0'`. A price nobody
 * entered must not come back looking like one they did (gate r2 finding 2).
 *
 * Scoped to PURCHASE documents, matching the server-side confirm refusal in
 * `PurchaseOrderService::guardAgainstUnpricedLines`: on a purchase order a
 * non-bonus line at 0 is never valid, whereas a sales document may legitimately
 * carry one and the server accepts it — so a sales line is loaded untouched.
 *
 * String comparison via a zero-shaped regex, never `parseFloat` (rule 19).
 */
export function hydrateLineUnitPrice(
  unitPrice: string | number | null | undefined,
  documentType: DocumentType | undefined,
): string | number {
  if (!isPurchaseDocumentType(documentType)) {
    return unitPrice ?? ''
  }
  return isZeroMoney(unitPrice) ? '' : (unitPrice ?? '')
}

/** True for '', null, undefined, '0', '0.000', '00.00' — never via parseFloat. */
function isZeroMoney(value: string | number | null | undefined): boolean {
  if (isBlank(value)) return true
  return /^0*(\.0*)?$/.test(String(value).trim())
}
