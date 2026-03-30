import { apiPost } from '@/lib/api';
import type { RecommendationRequest, RecommendationResponse } from '@/types/recommendations';

export async function fetchRecommendations(
  data: RecommendationRequest,
): Promise<RecommendationResponse> {
  return apiPost<RecommendationResponse>('/smart-prompts/recommendations', data);
}
