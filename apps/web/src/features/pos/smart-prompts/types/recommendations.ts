export interface Recommendation {
  product_id: string
  product_name: string
  score: string
  reason: string
  strategy: string
}

export interface RecommendationResponse {
  recommendations: Recommendation[]
  context: 'cart' | 'checkout' | 'reorder'
  generated_at: string
}

export interface RecommendationRequest {
  product_ids: string[]
  context?: 'cart' | 'checkout' | 'reorder'
  limit?: number
  skin_type?: string | null
  customer_id?: string | null
}
