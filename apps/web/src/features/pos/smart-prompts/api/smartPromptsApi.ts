import { apiPost } from '@/lib/api'
import type { RecommendationRequest, RecommendationResponse } from '../types/recommendations'

export const smartPromptsApi = {
  getRecommendations: (data: RecommendationRequest): Promise<RecommendationResponse> =>
    apiPost<RecommendationResponse>('/smart-prompts/recommendations', data),
}
