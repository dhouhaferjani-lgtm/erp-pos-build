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
 */

export type CreditMode = 'customer' | 'invoice'
export type LineMode = 'all' | 'partial'

export interface CreditNoteSourceLine {
  id: string
  product_id?: string | null
  description?: string | null
  quantity: string | number
  unit_price: string | number
  tax_rate: string | number
}

export interface CreditNoteFormValues {
  partner_id: string | null
  issue_date: string
  reason: string
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

export interface CreditNotePayload {
  partner_id: string | null
  issue_date: string
  reason: string
  notes?: string | undefined
  source_invoice_id?: string
  amount?: string
  lines?: Record<string, unknown>[]
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
    payload.lines = lines.map((line) => ({
      product_id: line.product_id,
      description: line.description,
      quantity: line.quantity,
      unit_price: String(line.unit_price),
      tax_rate: line.tax_rate,
    }))
  }

  return payload
}
