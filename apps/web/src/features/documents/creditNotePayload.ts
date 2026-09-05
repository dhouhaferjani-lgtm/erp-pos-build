/**
 * Credit-note request payload builder.
 *
 * Centralises the shape the `POST /credit-notes` endpoint expects so both the
 * page and its tests share one contract (F-STG-4):
 *
 * - **From invoice, 'all'** → credit every invoice line at its full quantity via
 *   the line-based path. The backend has no separate "whole invoice" endpoint;
 *   sending neither `amount` nor `lines` previously 422'd ("montant obligatoire").
 * - **From invoice, 'partial'** → the operator-selected lines and quantities.
 * - **From customer (standalone)** → manual lines whose `unit_price` is emitted
 *   as a decimal STRING (precision contract, rule 19 — never a float/number).
 *
 * There is no generated DTO for the credit-note REQUEST body (the generated
 * types cover responses; `CreditNoteReason` is the only credit-note symbol in
 * `packages/shared/types/generated.d.ts`), so the request line shapes below are
 * declared here — once — and are the only hand-written description of that wire
 * contract (rule 22, one surface per concept). The SOURCE line type is a
 * `Pick<DocumentLine, …>` rather than a restatement, for the same reason.
 */

import type { DocumentLine } from '@/components/documents/DocumentLineEditor'
import { findBlankPriceLineIds, isBlank } from './linePayload'

export type CreditMode = 'customer' | 'invoice'
export type LineMode = 'all' | 'partial'

/**
 * The GENERATED credit-note reason union (rule 7). `generated.d.ts` declares its
 * namespaces inside `declare global`, so `App.Modules.Document.Domain.Enums.*`
 * is ambient — no import, and no hand-written copy of the six literals here.
 * Gate r2 NEW-3: this field was typed as a bare `string`, wider than both the
 * backend enum and the page's own zod schema.
 */
export type CreditNoteReason = App.Modules.Document.Domain.Enums.CreditNoteReason

/**
 * The subset of a `DocumentLine` this builder reads. Derived from the editor's
 * type, never restated, so the two cannot drift.
 */
export type CreditNoteSourceLine = Pick<
  DocumentLine,
  | 'id'
  | 'product_id'
  | 'description'
  | 'quantity'
  | 'unit_price'
  | 'tax_rate'
  // `line_total` and `price_entry_mode` are carried so this type satisfies
  // `PricedLine` and the ONE blank-price predicate can be reused here
  // (gate r2 NEW-4) instead of a weaker local copy of it.
  | 'line_total'
  | 'price_entry_mode'
>

export interface CreditNoteFormValues {
  partner_id: string | null
  issue_date: string
  reason: CreditNoteReason
  notes?: string | undefined
  source_invoice_id?: string | undefined
}

export interface BuildCreditNotePayloadArgs {
  data: CreditNoteFormValues
  creditMode: CreditMode
  lineMode: LineMode
  lines: CreditNoteSourceLine[]
  selectedLineIds: Set<string>
  lineQuantities: Map<string, number>
}

/**
 * Invoice-linked line: a reference to an existing invoice line plus the
 * quantity being credited. Carries no money — the backend re-prices from the
 * source line (`CreditNoteService::createLineBasedCreditNote`).
 */
export interface CreditNoteInvoiceLinePayload {
  line_id: string
  quantity: string | number
}

/**
 * Standalone (customer) line. `unit_price` is a decimal STRING because
 * `CreditNoteController::store()` validates it as
 * `required|string|regex:/^\d+(\.\d{1,3})?$/` — a number, or a blank string,
 * is refused there.
 */
export interface CreditNoteManualLinePayload {
  product_id: string | null
  description: string | null
  quantity: string | number
  unit_price: string
  tax_rate: string | number
}

export type CreditNoteLinePayload = CreditNoteInvoiceLinePayload | CreditNoteManualLinePayload

export interface CreditNotePayload {
  partner_id: string | null
  issue_date: string
  reason: CreditNoteReason
  notes?: string | undefined
  source_invoice_id?: string
  amount?: string
  lines?: CreditNoteLinePayload[]
}

/**
 * Money on the wire is a decimal STRING (rule 19). Every price this builder
 * sees is already a string — the API returns decimal strings and `MoneyInput`
 * emits them — so the `number` arm of `DocumentLine['unit_price']` exists only
 * for legacy callers and is stringified WITHOUT any float round-trip
 * (no `parseFloat`, no `toFixed`).
 */
function toDecimalString(value: string | number): string {
  return typeof value === 'string' ? value : String(value)
}

/**
 * Ids of standalone (customer-mode) lines the `POST /credit-notes` validator
 * would refuse — the ONE definition of "this line is not submittable", used by
 * BOTH the page's pre-submit guard and the builder's wire-side strip, so the
 * brace and the belt cannot diverge (gate r2 NEW-2 + NEW-4).
 *
 * Two required fields, both `required` on the standalone rule set
 * (`CreditNoteController::store()`), both blank on a freshly added editor line
 * (`DocumentLineEditor::handleAddBlankLine()` — `unit_price: ''`,
 * `description: ''`):
 *
 * - **price** — delegated to the shared {@link findBlankPriceLineIds}, which also
 *   catches a TOTAL-entry line with no `line_total`;
 * - **description** — `lines.*.description` is `required|string|max:500`. A
 *   product-picked line gets it from the product; a free-text line does not, and
 *   before this guard the operator got `The lines.0.description field is
 *   required` as an opaque toast about a field they cannot see.
 */
export function findIncompleteCreditNoteLineIds(lines: readonly CreditNoteSourceLine[]): string[] {
  const blankPrice = new Set(findBlankPriceLineIds(lines))

  return lines
    .filter((line) => blankPrice.has(line.id) || isBlank(line.description))
    .map((line) => line.id)
}

/** True when the line has no unit price (as opposed to no designation). */
export function isUnpricedCreditNoteLine(line: CreditNoteSourceLine): boolean {
  return findBlankPriceLineIds([line]).length > 0
}

/** True when the line has no designation (as opposed to no unit price). */
export function isUndescribedCreditNoteLine(line: CreditNoteSourceLine): boolean {
  return isBlank(line.description)
}

export function buildCreditNotePayload({
  data,
  creditMode,
  lineMode,
  lines,
  selectedLineIds,
  lineQuantities,
}: BuildCreditNotePayloadArgs): CreditNotePayload {
  const payload: CreditNotePayload = {
    partner_id: data.partner_id,
    issue_date: data.issue_date,
    reason: data.reason,
    notes: data.notes,
  }

  if (creditMode === 'invoice' && data.source_invoice_id) {
    payload.source_invoice_id = data.source_invoice_id

    if (lineMode === 'partial') {
      payload.lines = Array.from(selectedLineIds).map((lineId) => ({
        line_id: lineId,
        quantity: lineQuantities.get(lineId) ?? 0,
      }))
    } else {
      // 'all' — credit the entire invoice line-by-line at full quantity. Pass
      // the canonical quantity through unchanged (no float coercion — rule 19);
      // the backend accepts a numeric string on the line-based path.
      payload.lines = lines.map((line) => ({
        line_id: line.id,
        quantity: line.quantity,
      }))
    }
  } else {
    // Customer (standalone) — money fields as strings (rule 19).
    //
    // An INCOMPLETE line is STRIPPED, never serialised: `lines.*.unit_price` and
    // `lines.*.description` are both `required` server-side, so a blank one 422s
    // with a message about a field the operator cannot see. The page refuses the
    // submit first (same predicate, so the operator gets an inline message);
    // this filter is the wire-side belt to that brace.
    const incomplete = new Set(findIncompleteCreditNoteLineIds(lines))
    payload.lines = lines
      .filter((line) => !incomplete.has(line.id))
      .map((line) => ({
        product_id: line.product_id,
        description: line.description,
        quantity: line.quantity,
        unit_price: toDecimalString(line.unit_price),
        tax_rate: line.tax_rate,
      }))
  }

  return payload
}
