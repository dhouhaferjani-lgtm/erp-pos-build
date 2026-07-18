import type { OffsetPaginationMeta } from '@/types/pagination'

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

/**
 * Ledger event values emitted by the backend's `VoucherEvent` enum (lowercase
 * snake_case storage values). Codex review R3 (2026-04-30) collapsed the
 * legacy PascalCase TS union onto the wire format so badge / colour / i18n
 * lookups don't fall through to the grey default on every real backend row.
 *
 * Backwards-compatible PascalCase variants are kept so any in-memory test
 * fixtures or code paths that still emit them continue to type-check while
 * we migrate consumers; the LedgerHistoryTable colour map keys both casings
 * to the same class set.
 */
export type LedgerEvent =
  // Canonical lowercase storage values (backend wire format).
  | 'issued'
  | 'redeemed'
  | 'partially_redeemed'
  | 'expired'
  | 'voided'
  | 'reversed'
  | 'transferred'
  | 'rounding_adjustment'
  | 'expiry_extended'
  // Legacy PascalCase variants — pre-R3 hand-fed test fixtures still emit
  // these; tolerated so the type is permissive but production rows always
  // arrive as the lowercase wire values above.
  | 'Issued'
  | 'Redeemed'
  | 'Refunded'
  | 'Voided'
  | 'Expired'
  | 'Extended'
  | 'Transferred'
  | 'Adjusted'

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

export interface VoucherListResponse {
  data: Voucher[]
  meta: OffsetPaginationMeta
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
