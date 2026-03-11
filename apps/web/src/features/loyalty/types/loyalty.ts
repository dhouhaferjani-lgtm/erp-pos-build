// Enums
export type ProgramType = 'points' | 'stamps' | 'visits' | 'cashback' | 'hybrid'
export type ProgramStatus = 'draft' | 'active' | 'paused' | 'archived'
export type MemberStatus = 'active' | 'inactive' | 'suspended'
export type EnrollmentStatus = 'active' | 'suspended' | 'opted_out'
export type RewardType = 'free_item' | 'discount_amount' | 'discount_percent' | 'choice' | 'credit' | 'external'
export type EarningRuleType = 'spend' | 'item' | 'category' | 'quantity' | 'visit' | 'threshold' | 'time'
export type QualificationType = 'spend' | 'points_earned' | 'visits' | 'manual'
export type TransactionType = 'earn' | 'redeem' | 'adjust' | 'expire' | 'transfer_in' | 'transfer_out'

// Program
export interface LoyaltyProgram {
  id: string
  name: string
  program_type: ProgramType
  status: ProgramStatus
  currency: string | null
  start_date: string | null
  end_date: string | null
  terms_and_conditions: string | null
  metadata: Record<string, unknown> | null
  created_at: string
  updated_at: string | null
}

export interface CreateProgramData {
  name: string
  program_type: ProgramType
  currency?: string | null
  start_date?: string | null
  end_date?: string | null
  terms_and_conditions?: string | null
}

export type UpdateProgramData = Partial<CreateProgramData>

// Earning Rule
export interface EarningRuleConditions {
  min_purchase_amount?: string | null
  max_purchase_amount?: string | null
  min_quantity?: number | null
  max_quantity?: number | null
  product_ids?: string[] | null
  category_ids?: string[] | null
  tier_ids?: string[] | null
  company_ids?: string[] | null
  time_start?: string | null
  time_end?: string | null
  day_of_week?: string[] | null
  first_purchase?: boolean | null
  new_customer?: boolean | null
}

export interface EarningRule {
  id: string
  program_id: string
  name: string
  rule_type: EarningRuleType
  priority: number
  is_active: boolean
  conditions: EarningRuleConditions
  reward_value: string
  reward_type: string
  start_date: string | null
  end_date: string | null
  max_earn_per_transaction: string | null
  max_earn_per_day: string | null
  created_at: string
  updated_at: string | null
}

export interface CreateEarningRuleData {
  name: string
  rule_type: EarningRuleType
  priority?: number
  conditions?: EarningRuleConditions
  reward_value: string
  reward_type?: string
  start_date?: string | null
  end_date?: string | null
  max_earn_per_transaction?: string | null
  max_earn_per_day?: string | null
}

export type UpdateEarningRuleData = Partial<CreateEarningRuleData>

// Reward
export interface QualifyingItems {
  product_ids?: string[] | null
  category_ids?: string[] | null
  excluded_product_ids?: string[] | null
  excluded_category_ids?: string[] | null
  min_price?: string | null
  max_price?: string | null
  all_products?: boolean | null
}

export interface Reward {
  id: string
  program_id: string
  name: string
  description: string | null
  reward_type: RewardType
  points_cost: string
  reward_value: string | null
  qualifying_items: QualifyingItems | null
  max_discount: string | null
  min_order_value: string | null
  tier_ids: string[] | null
  is_active: boolean
  quantity_available: number | null
  quantity_per_member: number | null
  start_date: string | null
  end_date: string | null
  created_at: string
  updated_at: string | null
}

export interface CreateRewardData {
  name: string
  description?: string | null
  reward_type: RewardType
  points_cost: string
  reward_value?: string | null
  qualifying_items?: QualifyingItems | null
  max_discount?: string | null
  min_order_value?: string | null
  tier_ids?: string[] | null
  quantity_available?: number | null
  quantity_per_member?: number | null
  start_date?: string | null
  end_date?: string | null
}

export type UpdateRewardData = Partial<CreateRewardData>

// Tier
export interface TierBenefits {
  discount_percent?: string | null
  free_shipping?: string[] | null
  priority_support?: string[] | null
  exclusive_rewards?: string[] | null
  bonus_points_multiplier?: string | null
  birthday_bonus?: string[] | null
  extended_expiry_days?: number | null
  welcome_bonus?: string | null
}

export interface Tier {
  id: string
  program_id: string
  name: string
  level: number
  icon: string | null
  color: string | null
  qualification_type: QualificationType
  qualification_threshold: string
  qualification_period_months: number | null
  earning_multiplier: string
  benefits: TierBenefits | null
  created_at: string
  updated_at: string | null
}

export interface CreateTierData {
  name: string
  level: number
  qualification_type: QualificationType
  qualification_threshold: string
  qualification_period_months?: number | null
  earning_multiplier?: string
  benefits?: TierBenefits | null
  icon?: string | null
  color?: string | null
}

export type UpdateTierData = Partial<CreateTierData>

// Stamp Card
export interface StampCard {
  id: string
  program_id: string
  name: string
  stamps_required: number
  stamps_per_item: number
  qualifying_items: QualifyingItems | null
  reward_id: string
  max_active_cards: number | null
  expiry_days: number | null
  created_at: string
  updated_at: string | null
}

export interface CreateStampCardData {
  name: string
  stamps_required: number
  stamps_per_item?: number
  qualifying_items?: QualifyingItems | null
  reward_id: string
  max_active_cards?: number | null
  expiry_days?: number | null
}

export type UpdateStampCardData = Partial<CreateStampCardData>

// Member
export interface LoyaltyMember {
  id: string
  customer_id: string | null
  phone: string
  email: string | null
  first_name: string | null
  last_name: string | null
  date_of_birth: string | null
  status: MemberStatus
  enrollment_date: string
  external_id: string | null
  created_at: string
  updated_at: string | null
}

export interface CreateMemberData {
  phone: string
  email?: string | null
  first_name?: string | null
  last_name?: string | null
  date_of_birth?: string | null
  customer_id?: string | null
}

export type UpdateMemberData = Partial<CreateMemberData>

export interface MemberListParams {
  page?: number
  per_page?: number
  search?: string
  status?: string
}

export interface MemberListResponse {
  data: LoyaltyMember[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

// Enrollment
export interface Enrollment {
  id: string
  program_id: string
  member_id: string
  current_balance: string
  lifetime_earned: string
  lifetime_redeemed: string
  current_tier_id: string | null
  tier_qualified_at: string | null
  status: EnrollmentStatus
  enrolled_at: string
  last_transaction_at: string | null
  created_at: string
  updated_at: string | null
  program?: LoyaltyProgram
  tier?: Tier
}

// Transaction
export interface LoyaltyTransaction {
  id: string
  enrollment_id: string
  transaction_type: TransactionType
  amount: string
  balance_before: string
  balance_after: string
  order_id: string | null
  reward_id: string | null
  earning_rule_id: string | null
  description: string | null
  metadata: Record<string, unknown> | null
  created_by: string | null
  created_at: string
  expires_at: string | null
}

export interface TransactionListResponse {
  data: LoyaltyTransaction[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

export interface AdjustPointsData {
  points: string
  reason: string
}
