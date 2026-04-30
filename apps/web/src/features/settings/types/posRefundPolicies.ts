import { z } from 'zod'

// ─── Enums ────────────────────────────────────────────────────────────────────

export type OutOfWindowPolicy = 'refuse' | 'voucher_only'
export type RefundDestination = 'original_payment' | 'cash' | 'store_voucher'
export type ProrationStrategy = 'proportional' | 'largest_first' | 'cashier_choice'

// ─── Nested shapes ────────────────────────────────────────────────────────────

export interface CustomerHistorySearchAlertThresholds {
  rejected_specificity_per_hour: number
  same_partner_per_day: number
  cross_company_immediate: boolean
}

// ─── Full settings shape (matches backend reservation_settings JSONB) ─────────

export interface PosRefundPolicies {
  // Section 1 — Return window
  customer_return_expiry_days: number
  customer_history_window_days: number
  out_of_window_policy: OutOfWindowPolicy

  // Section 2 — Manager override
  manager_override_threshold_amount: string
  manager_override_threshold_percent: string
  manager_override_required_for_no_receipt: boolean

  // Section 3 — Destinations & proration
  allowed_refund_destinations: RefundDestination[]
  proration_strategy: ProrationStrategy

  // Section 4 — Voucher defaults
  voucher_default_expiry_days: number
  voucher_transferable_default: boolean
  voucher_cash_refund_allowed: boolean

  // Section 5 — Daily caps
  daily_refund_cap_per_cashier: string | null
  daily_refund_cap_override_allowed: boolean

  // Section 6 — Customer-history privacy
  customer_history_search_max_per_cashier_per_day: number
  customer_history_search_alert_thresholds: CustomerHistorySearchAlertThresholds

  // Section 7 — Voucher rate limits
  voucher_lookup_per_terminal_per_day: number
  voucher_lookup_per_cashier_per_day: number
  voucher_lookup_failed_per_tenant_per_hour_alert: number
  voucher_lookup_failed_per_tenant_per_hour_block: number
  voucher_failed_attempts_auto_void: number

  // Section 8 — Goodwill controls
  goodwill_named_customer_threshold: string
  goodwill_four_eyes_threshold: string
  goodwill_daily_issuance_cap_per_user: string | null
  goodwill_bearer_default_off: boolean
}

// ─── Defaults ─────────────────────────────────────────────────────────────────

export const POS_REFUND_POLICY_DEFAULTS: PosRefundPolicies = {
  customer_return_expiry_days: 30,
  customer_history_window_days: 30,
  out_of_window_policy: 'voucher_only',

  manager_override_threshold_amount: '50.00',
  manager_override_threshold_percent: '10.00',
  manager_override_required_for_no_receipt: true,

  allowed_refund_destinations: ['original_payment', 'cash', 'store_voucher'],
  proration_strategy: 'proportional',

  voucher_default_expiry_days: 365,
  voucher_transferable_default: true,
  voucher_cash_refund_allowed: false,

  daily_refund_cap_per_cashier: null,
  daily_refund_cap_override_allowed: true,

  customer_history_search_max_per_cashier_per_day: 15,
  customer_history_search_alert_thresholds: {
    rejected_specificity_per_hour: 3,
    same_partner_per_day: 8,
    cross_company_immediate: true,
  },

  voucher_lookup_per_terminal_per_day: 200,
  voucher_lookup_per_cashier_per_day: 100,
  voucher_lookup_failed_per_tenant_per_hour_alert: 50,
  voucher_lookup_failed_per_tenant_per_hour_block: 200,
  voucher_failed_attempts_auto_void: 5,

  goodwill_named_customer_threshold: '100.00',
  goodwill_four_eyes_threshold: '250.00',
  goodwill_daily_issuance_cap_per_user: null,
  goodwill_bearer_default_off: true,
}

// ─── Zod schema ───────────────────────────────────────────────────────────────

const decimalStringSchema = z
  .string()
  .regex(/^\d+(\.\d{1,4})?$/, 'Must be a valid decimal number')

const nullableDecimalStringSchema = z
  .string()
  .regex(/^\d+(\.\d{1,4})?$/, 'Must be a valid decimal number')
  .nullable()

export const posRefundPoliciesSchema = z.object({
  // Section 1
  customer_return_expiry_days: z.number().int().min(0).max(90),
  customer_history_window_days: z.number().int().min(0).max(365),
  out_of_window_policy: z.enum(['refuse', 'voucher_only']),

  // Section 2
  manager_override_threshold_amount: decimalStringSchema,
  manager_override_threshold_percent: decimalStringSchema.refine(
    (v) => parseFloat(v) >= 0 && parseFloat(v) <= 100,
    { message: 'Must be between 0 and 100' }
  ),
  manager_override_required_for_no_receipt: z.boolean(),

  // Section 3
  allowed_refund_destinations: z
    .array(z.enum(['original_payment', 'cash', 'store_voucher']))
    .min(1, 'At least one destination required'),
  proration_strategy: z.enum(['proportional', 'largest_first', 'cashier_choice']),

  // Section 4
  voucher_default_expiry_days: z.number().int().min(1).max(3650),
  voucher_transferable_default: z.boolean(),
  voucher_cash_refund_allowed: z.boolean(),

  // Section 5
  daily_refund_cap_per_cashier: nullableDecimalStringSchema,
  daily_refund_cap_override_allowed: z.boolean(),

  // Section 6
  customer_history_search_max_per_cashier_per_day: z.number().int().min(0).max(1000),
  customer_history_search_alert_thresholds: z.object({
    rejected_specificity_per_hour: z.number().int().min(0),
    same_partner_per_day: z.number().int().min(0),
    cross_company_immediate: z.boolean(),
  }),

  // Section 7
  voucher_lookup_per_terminal_per_day: z.number().int().min(0).max(10000),
  voucher_lookup_per_cashier_per_day: z.number().int().min(0).max(10000),
  voucher_lookup_failed_per_tenant_per_hour_alert: z.number().int().min(0).max(10000),
  voucher_lookup_failed_per_tenant_per_hour_block: z.number().int().min(0).max(10000),
  voucher_failed_attempts_auto_void: z.number().int().min(1).max(100),

  // Section 8
  goodwill_named_customer_threshold: decimalStringSchema,
  goodwill_four_eyes_threshold: decimalStringSchema,
  goodwill_daily_issuance_cap_per_user: nullableDecimalStringSchema,
  goodwill_bearer_default_off: z.boolean(),
})

export type PosRefundPoliciesForm = z.infer<typeof posRefundPoliciesSchema>
