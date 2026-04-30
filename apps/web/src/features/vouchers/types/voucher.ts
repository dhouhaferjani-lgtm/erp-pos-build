// ─── Enums ───────────────────────────────────────────────────────────────────

export type VoucherSource =
  | 'refund'
  | 'exchange_surplus'
  | 'goodwill'
  | 'loyalty_credit'
  | 'gift_card_purchase'
  | 'promotional'

export type VoucherStatus = 'Issued' | 'PartiallyRedeemed' | 'FullyRedeemed' | 'Voided' | 'Expired'

export type RedemptionMode = 'Bearer' | 'CustomerBound'

export type LedgerEvent =
  | 'Issued'
  | 'Redeemed'
  | 'Refunded'
  | 'Voided'
  | 'Expired'
  | 'Extended'
  | 'Transferred'
  | 'Adjusted'
  // Snake-case canonical value emitted by the backend's VoucherEvent enum (see
  // VoucherEvent::ExpiryExtended). Added by Codex review m2 (2026-04-30) so the
  // administrative expiry-extension ledger row deserializes cleanly.
  | 'expiry_extended'

// ─── Core Voucher ─────────────────────────────────────────────────────────────

export interface Voucher {
  id: string
  code: string
  source: VoucherSource
  status: VoucherStatus
  initial_balance: string
  current_balance: string
  currency: string
  redemption_mode: RedemptionMode
  expires_at: string | null
  partner_id: string | null
  partner_name: string | null
  terminal_id: string | null
  terminal_name: string | null
  cashier_id: string | null
  cashier_name: string | null
  created_at: string
  updated_at: string | null
}

// ─── Ledger Row ───────────────────────────────────────────────────────────────

export interface VoucherLedgerRow {
  id: string
  event: LedgerEvent
  amount: string
  running_balance: string
  receipt_id: string | null
  receipt_number: string | null
  terminal_id: string | null
  terminal_name: string | null
  user_id: string | null
  user_name: string | null
  policy_trigger: string | null
  notes: string | null
  occurred_at: string
}

// ─── Provenance shapes (source-specific) ────────────────────────────────────
// Keys match the backend's buildProvenance() output exactly.
// The discriminator lives at voucher.source — NOT inside the provenance object.

export interface RefundProvenance {
  source_receipt_id: string | null
  source_receipt_number: string | null
  credit_note_link: string | null
}

export interface GoodwillProvenance {
  issued_by_user_name: string | null
  notes: string | null
  authorized_by_user_id: string | null
  override_reason: string | null
}

export interface LoyaltyCreditProvenance {
  source_loyalty_transaction_id: string | null
}

export interface OtherProvenance {
  // No fields for GiftCard / Promotional in the current backend version
  [key: string]: unknown
}

// A single permissive union for provenance data — callers must use voucher.source
// as the discriminator to know which shape applies.
export type VoucherProvenance =
  | RefundProvenance
  | GoodwillProvenance
  | LoyaltyCreditProvenance
  | OtherProvenance
  | null

// ─── Voucher Detail (includes ledger + provenance) ───────────────────────────

export interface VoucherDetail extends Voucher {
  ledger: VoucherLedgerRow[]
  provenance: VoucherProvenance
}

// ─── List response ────────────────────────────────────────────────────────────

export interface VoucherListMeta {
  current_page: number
  last_page: number
  total: number
  per_page: number
}

export interface VoucherListResponse {
  data: Voucher[]
  meta: VoucherListMeta
}

// ─── Query params ─────────────────────────────────────────────────────────────

export interface VoucherListParams {
  source?: VoucherSource | ''
  status?: VoucherStatus | ''
  partner_id?: string
  search?: string
  page?: number
  per_page?: number
}

// ─── Mutation payloads ────────────────────────────────────────────────────────

export interface IssueGoodwillPayload {
  amount: string
  currency: string
  partner_id?: string | null
  redemption_mode: RedemptionMode
  expires_at?: string | null
  notes: string
  terminal_id: string
  second_admin_user_id?: string | null
}

export interface VoidVoucherPayload {
  reason: string
}

export interface TransferVoucherPayload {
  to_partner_id: string
  reason: string
}

export interface ExtendExpiryPayload {
  new_expires_at: string
  reason: string
}

// ─── Reservation settings (voucher-relevant subset) ─────────────────────────

export interface VoucherReservationSettings {
  goodwill_four_eyes_threshold: string
  goodwill_bearer_default_off: boolean
  voucher_default_expiry_days: number
}
