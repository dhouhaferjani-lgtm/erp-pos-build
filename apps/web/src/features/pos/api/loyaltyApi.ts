import { apiGet, apiPost } from '@/lib/api'

export interface LoyaltyMember {
  id: string
  tenant_id: string
  customer_id: string | null
  phone: string
  email: string | null
  first_name: string | null
  last_name: string | null
  status: string
  enrollment_date: string
  created_at: string
}

export interface LoyaltyEnrollment {
  id: string
  program_id: string
  member_id: string
  current_balance: string
  lifetime_earned: string
  lifetime_redeemed: string
  current_tier_id: string | null
  status: string
  enrolled_at: string
  last_transaction_at: string | null
}

export interface LoyaltyReward {
  id: string
  program_id: string
  name: string
  description: string | null
  points_cost: string
  reward_type: string
  reward_value: string | null
  is_active: boolean
}

export interface EarningPreview {
  points_to_earn: number
}

export interface MemberLookupResponse {
  member: LoyaltyMember
  enrollments: LoyaltyEnrollment[]
}

export interface RewardsResponse {
  current_balance: string
  rewards: LoyaltyReward[]
}

export interface CartItemForLoyalty {
  product_id: string
  category_id?: string | null
  quantity: number
  price: number
}

/**
 * Look up a loyalty member by phone number.
 */
export async function lookupMember(phone: string): Promise<MemberLookupResponse | null> {
  try {
    return await apiPost<MemberLookupResponse>('/loyalty/pos/member-lookup', { phone })
  } catch {
    return null
  }
}

/**
 * Preview points that would be earned for a cart.
 */
export async function previewEarning(
  enrollmentId: string,
  amount: string,
  items: CartItemForLoyalty[],
): Promise<EarningPreview> {
  return apiPost<EarningPreview>('/loyalty/pos/preview-earning', {
    enrollment_id: enrollmentId,
    amount,
    items,
  })
}

/**
 * Get redeemable rewards for an enrollment.
 */
export async function getRewards(enrollmentId: string): Promise<RewardsResponse> {
  return apiGet<RewardsResponse>(`/loyalty/pos/rewards/${enrollmentId}`)
}

/**
 * Redeem a reward. Deducts points and returns discount info.
 */
export async function redeemReward(
  enrollmentId: string,
  rewardId: string,
): Promise<{ id: string; points_spent: string; reward_value: string }> {
  return apiPost(`/loyalty/pos/redeem`, {
    enrollment_id: enrollmentId,
    reward_id: rewardId,
  })
}

/**
 * Earn points for a receipt (manual trigger after payment).
 */
export async function earnPoints(
  enrollmentId: string,
  receiptId: string,
  amount: string,
  items: CartItemForLoyalty[],
): Promise<{ id: string; points_earned: string }> {
  return apiPost(`/loyalty/pos/earn`, {
    enrollment_id: enrollmentId,
    receipt_id: receiptId,
    amount,
    items,
  })
}
